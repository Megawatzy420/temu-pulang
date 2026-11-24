<?php

namespace Tests\Feature\Printing;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PrintService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintServiceQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Shift $shift;

    protected ShopSetting $setting;

    protected PrintService $printService;

    protected PaymentService $paymentService;

    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Buka shift'
        );

        $this->setting = ShopSetting::current();
        $this->setting->update([
            'printer_name' => 'POS80 Printer',
            'printer_paper_width' => 80,
            'printer_enabled' => true,
            'kitchen_printer_enabled' => false,
            'kitchen_printer_name' => null,
        ]);
        $this->setting->refresh();

        $this->printService = app(PrintService::class);
        $this->paymentService = app(PaymentService::class);
        $this->orderService = app(OrderService::class);
    }

    protected function createOrder(int $quantity = 1): Order
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        return $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => $quantity],
                ],
            ]
        );
    }

    public function test_queue_order_receipt_creates_pending_job(): void
    {
        $order = $this->createOrder();
        $tx = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'TRX-TEST-001',
            'daily_sequence' => 1,
            'business_date' => '2026-11-14',
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'total_amount' => $order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => 'test-fp',
            'status' => 'completed',
        ]);

        $this->printService->queueOrderReceipt($order, $tx, $this->setting);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80 Printer',
            'paper_width' => 80,
            'status' => 'pending',
            'attempts' => 0,
            'is_reprint' => false,
        ]);
    }

    public function test_queue_order_receipt_creates_kitchen_ticket_when_enabled(): void
    {
        $this->setting->update([
            'kitchen_printer_enabled' => true,
            'kitchen_printer_name' => 'KitchenPrinter',
        ]);
        $this->setting->refresh();

        $order = $this->createOrder();
        $tx = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'TRX-TEST-002',
            'daily_sequence' => 2,
            'business_date' => '2026-11-14',
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'total_amount' => $order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => 'test-fp-2',
            'status' => 'completed',
        ]);

        $this->printService->queueOrderReceipt($order, $tx, $this->setting);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'job_type' => 'kitchen_ticket',
            'printer_destination' => 'KitchenPrinter',
            'status' => 'pending',
        ]);
    }

    public function test_no_jobs_created_when_printer_is_disabled(): void
    {
        $this->setting->update([
            'printer_enabled' => false,
        ]);
        $this->setting->refresh();

        $order = $this->createOrder();
        $tx = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'TRX-TEST-003',
            'daily_sequence' => 3,
            'business_date' => '2026-11-14',
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'total_amount' => $order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => 'test-fp-3',
            'status' => 'completed',
        ]);

        $this->printService->queueOrderReceipt($order, $tx, $this->setting);

        $this->assertDatabaseMissing('print_jobs', [
            'order_id' => $order->id,
        ]);
    }

    public function test_settle_payment_automatically_enqueues_receipt_job(): void
    {
        $order = $this->createOrder();

        $tx = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount],
            ],
            idempotencyKey: (string) Str::uuid()
        );

        $this->assertNotNull($tx);
        $this->assertSame('completed', $tx->status);
        $this->assertSame($this->cashier->name, $tx->cashier_name);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'status' => 'pending',
        ]);
    }

    public function test_payment_succeeds_even_if_print_service_throws_exception(): void
    {
        // Mock PrintService to simulate an unhandled exception during queueOrderReceipt
        $mockPrintService = \Mockery::mock(PrintService::class);
        $mockPrintService->shouldReceive('queueOrderReceipt')
            ->once()
            ->andThrow(new \RuntimeException('Printer queue unreachable'));

        $this->app->instance(PrintService::class, $mockPrintService);

        $order = $this->createOrder();

        // Settle payment should complete successfully and NOT roll back
        $tx = app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount],
            ],
            idempotencyKey: (string) Str::uuid()
        );

        $this->assertNotNull($tx);
        $this->assertSame('completed', $tx->status);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $tx->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_STATUS_PAID,
        ]);
    }
}
