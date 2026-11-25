<?php

namespace Tests\Feature\Refund;

use App\Livewire\Orders\OrderIndex;
use App\Livewire\Orders\OrderRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OrderRefundScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Shift refund screen test'
        );
    }

    /**
     * Create a settled order with a single payment.
     */
    protected function createPaidOrder(string $method = 'cash', int $quantity = 2): array
    {
        $product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();

        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => 'dine_in',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
        ]);

        $total = $order->total_amount;
        if ($method === 'qris') {
            $qrisConf = app(PaymentService::class)->createQrisConfirmation(
                order: $order,
                cashier: $this->cashier,
                expectedAmount: $total,
                referenceNumber: 'QRIS-REF-'.Str::random(6)
            );
            $tender = [
                'payment_method' => 'qris',
                'amount' => $total,
                'qris_confirmation_id' => $qrisConf->id,
            ];
        } else {
            $tender = [
                'payment_method' => 'cash',
                'amount' => $total,
                'tendered_amount' => $total,
                'change_amount' => 0,
            ];
        }

        $tx = app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [$tender],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        $order->refresh();
        $payment = $tx->payments()->firstOrFail();

        return [$order, $payment];
    }

    public function test_owner_can_process_cash_refund_with_open_shift(): void
    {
        [$order, $payment] = $this->createPaidOrder('cash');

        $component = Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertStatus(200)
            ->assertSee($order->order_number)
            ->assertSee('Rp '.number_format($payment->remainingRefundableBalance(), 0, ',', '.'))
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Pelanggan membatalkan pesanan')
            ->call('submitRefund')
            ->assertHasNoErrors();

        $tx = RefundTransaction::where('order_id', $order->id)->firstOrFail();
        $component->assertSet('completedRefundNumber', $tx->refund_transaction_number)
            ->assertSet('updatedPaymentStatus', Order::PAYMENT_STATUS_REFUNDED)
            ->assertSee($tx->refund_transaction_number);
    }

    public function test_owner_can_process_non_cash_refund_without_open_shift(): void
    {
        [$order, $payment] = $this->createPaidOrder('qris');

        app(ShiftService::class)->closeShift($this->shift, $this->cashier, 100000);

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'qris_reversal')
            ->set('reason', 'QRIS reversal tanpa shift aktif')
            ->call('submitRefund')
            ->assertHasNoErrors()
            ->assertSet('updatedPaymentStatus', Order::PAYMENT_STATUS_REFUNDED);

        $this->assertSame(1, RefundTransaction::where('order_id', $order->id)->count());
    }

    public function test_cash_refund_with_no_open_shift_is_rejected(): void
    {
        [$order, $payment] = $this->createPaidOrder('cash');

        app(ShiftService::class)->closeShift($this->shift, $this->cashier, 100000 + $payment->amount);

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Coba refund tunai saat shift tutup')
            ->call('submitRefund')
            ->assertSet('errorMessage', __('messages.refund.no_open_shift_cash'));

        $this->assertSame(0, RefundTransaction::where('order_id', $order->id)->count());
    }

    public function test_over_refund_is_rejected_with_translated_message(): void
    {
        [$order, $payment] = $this->createPaidOrder('cash');

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount + 5000)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Nominal melebihi saldo')
            ->call('submitRefund')
            ->assertSet('errorMessage', __('messages.refund.error_over_refund'));

        $this->assertSame(0, RefundTransaction::where('order_id', $order->id)->count());
    }

    public function test_unpaid_and_fully_refunded_orders_are_rejected(): void
    {
        $product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();

        $unpaidOrder = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => 'dine_in',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        // Unpaid order rejected
        $fakePayment = Payment::forceCreate([
            'payment_transaction_id' => PaymentTransaction::create([
                'ulid' => (string) Str::ulid(),
                'payment_transaction_number' => 'PAY-TEST-001',
                'daily_sequence' => 999,
                'business_date' => now('Asia/Jakarta')->toDateString(),
                'order_id' => $unpaidOrder->id,
                'shift_id' => $this->shift->id,
                'user_id' => $this->cashier->id,
                'total_amount' => $unpaidOrder->total_amount,
                'status' => 'completed',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', 'unpaid-test'),
            ])->id,
            'order_id' => $unpaidOrder->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => $unpaidOrder->total_amount,
            'tendered_amount' => $unpaidOrder->total_amount,
            'change_amount' => 0,
        ]);

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $unpaidOrder])
            ->set("allocations.{$fakePayment->id}.amount", $fakePayment->amount)
            ->set("allocations.{$fakePayment->id}.refund_method", 'cash')
            ->set('reason', 'Test unpaid')
            ->call('submitRefund')
            ->assertSet('errorMessage', __('messages.refund.error_unpaid_order'));

        // Fully refunded order rejected
        [$paidOrder, $payment] = $this->createPaidOrder('cash');
        app(RefundService::class)->processRefund(
            authorizer: $this->owner,
            order: $paidOrder,
            allocations: [['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash']],
            reason: 'Full refund first',
            shift: $this->shift
        );
        $paidOrder->refresh();

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $paidOrder])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Second refund attempt')
            ->call('submitRefund')
            ->assertSet('errorMessage', __('messages.refund.error_already_refunded'));
    }

    public function test_idempotent_retry_returns_same_refund_transaction(): void
    {
        [$order, $payment] = $this->createPaidOrder('cash');

        $component = Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Retry idempotency test');

        $firstKey = $component->get('idempotencyKey');
        $component->call('submitRefund');
        $firstNumber = $component->get('completedRefundNumber');

        // Retry with same idempotencyKey and same parameters
        $component->call('submitRefund')
            ->assertSet('completedRefundNumber', $firstNumber)
            ->assertSet('idempotencyKey', $firstKey);

        $this->assertSame(1, RefundTransaction::where('order_id', $order->id)->count());
    }

    public function test_cashier_is_blocked_from_refund_route_action_and_order_index_link(): void
    {
        [$order, $payment] = $this->createPaidOrder('cash');

        $response = $this->actingAs($this->cashier)
            ->get(route('orders.refund', $order));
        $this->assertTrue(in_array($response->status(), [302, 403], true));

        Livewire::actingAs($this->cashier)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertForbidden();

        // OrderIndex hides refund link for cashier, shows it for owner
        Livewire::actingAs($this->cashier)
            ->test(OrderIndex::class)
            ->call('setTab', 'all')
            ->assertDontSee(route('orders.refund', $order));

        Livewire::actingAs($this->owner)
            ->test(OrderIndex::class)
            ->call('setTab', 'all')
            ->assertSee(route('orders.refund', $order));
    }

    public function test_raw_exception_text_is_never_exposed_to_user(): void
    {
        [$order, $payment] = $this->createPaidOrder('cash');

        $this->mock(RefundService::class, function ($mock) {
            $mock->shouldReceive('processRefund')
                ->once()
                ->andThrow(new \RuntimeException('SECRET_INTERNAL_SQL_STACK_TRACE_XYZ'));
        });

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Masking test')
            ->call('submitRefund')
            ->assertSet('errorMessage', __('messages.refund.error_generic'))
            ->assertDontSee('SECRET_INTERNAL_SQL_STACK_TRACE_XYZ');
    }

    public function test_refund_screen_renders_in_both_indonesian_and_english_locales(): void
    {
        [$order] = $this->createPaidOrder('cash');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertSee('Proses Pengembalian Dana (Refund)')
            ->assertSee('Alasan Refund');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertSee('Process Order Refund')
            ->assertSee('Refund Reason');
    }
}
