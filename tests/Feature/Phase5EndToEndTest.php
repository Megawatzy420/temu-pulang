<?php

namespace Tests\Feature;

use App\Console\Commands\PrintWorkerCommand;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Printing\KitchenTicketRendererService;
use App\Services\Printing\ReceiptRendererService;
use App\Services\RefundService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class Phase5EndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuous_phase5_end_to_end_lifecycle(): void
    {
        $this->seed();

        $owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        ShopSetting::where('is_active', true)->update([
            'printer_enabled' => true,
            'printer_name' => 'POS80 Printer',
        ]);

        // 1. Open shift (opening cash = 100,000)
        $shiftService = app(ShiftService::class);
        $shift = $shiftService->openShift(
            user: $cashier,
            openingCash: 100000,
            openingNote: 'Opening float E2E'
        );

        $this->assertTrue($shift->isOpen());
        $this->assertSame(100000, $shift->currentExpectedCash());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'shift.opened',
            'module' => 'shift',
            'user_id' => $cashier->id,
        ]);

        // Manual cash movements: +50,000 in, -10,000 out => expected = 140,000
        $cashMovementService = app(CashMovementService::class);
        $cashMovementService->recordMovement($shift, $cashier, 'in', 50000, 'topup', 'Extra change');
        $cashMovementService->recordMovement($shift, $cashier, 'out', 10000, 'petty_cash', 'Ice');
        $this->assertSame(140000, $shift->fresh()->currentExpectedCash());

        // 2. Create order (2x product)
        $product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->where('base_price', '>=', 20000)
            ->firstOrFail();

        $order = app(OrderService::class)->createOrder($cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_UNPAID, $order->payment_status);

        $total = $order->total_amount;
        $cashPortion = 25000;
        $qrisPortion = $total - $cashPortion;
        $this->assertGreaterThan(0, $qrisPortion);

        // 3. Settle split payment (cash 25,000 net with 30,000 tendered / 5,000 change + QRIS)
        $paymentService = app(PaymentService::class);
        $qrisConf = $paymentService->createQrisConfirmation(
            order: $order,
            cashier: $cashier,
            expectedAmount: $qrisPortion,
            referenceNumber: 'E2E-QRIS-001'
        );

        $paymentTx = $paymentService->settlePayment(
            cashier: $cashier,
            shift: $shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $cashPortion,
                    'tendered_amount' => 30000,
                    'change_amount' => 5000,
                ],
                [
                    'payment_method' => 'qris',
                    'amount' => $qrisPortion,
                    'qris_confirmation_id' => $qrisConf->id,
                ],
            ],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $order->payment_status);

        // Expected cash: 100,000 + 50,000 - 10,000 + 25,000 (net cash) = 165,000
        $this->assertSame(165000, $shift->fresh()->currentExpectedCash());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'payment.settled',
            'module' => 'payments',
            'subject_id' => $paymentTx->id,
        ]);

        // Append-only immutability on PaymentTransaction & Payment
        $cashPayment = $paymentTx->payments()->where('payment_method', 'cash')->firstOrFail();
        $qrisPayment = $paymentTx->payments()->where('payment_method', 'qris')->firstOrFail();

        $this->assertThrows(fn () => $paymentTx->update(['total_amount' => 1]), DomainException::class);
        $this->assertThrows(fn () => $cashPayment->delete(), DomainException::class);

        // 4. Print job queued and processed with fake spooler (exit code 0)
        $printJob = PrintJob::where('order_id', $order->id)
            ->where('job_type', 'customer_receipt')
            ->firstOrFail();
        $this->assertSame('pending', $printJob->status);

        $worker = new PrintWorkerCommand(
            app(ReceiptRendererService::class),
            app(KitchenTicketRendererService::class)
        );
        $worker->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $reflector = new ReflectionClass(PrintWorkerCommand::class);

        $claimMethod = $reflector->getMethod('claimNextJob');
        $claimMethod->setAccessible(true);
        $claimedJob = $claimMethod->invoke($worker);
        $this->assertNotNull($claimedJob);
        $this->assertSame($printJob->id, $claimedJob->id);

        $renderMethod = $reflector->getMethod('renderPayload');
        $renderMethod->setAccessible(true);
        $escPosPayload = $renderMethod->invoke($worker, $claimedJob, $order->fresh(['items.modifiers']));
        $this->assertNotEmpty($escPosPayload);

        $applyExitMethod = $reflector->getMethod('applyExitCode');
        $applyExitMethod->setAccessible(true);
        $applyExitMethod->invoke($worker, $claimedJob, 0);

        $printJob->refresh();
        $this->assertSame('completed', $printJob->status);
        $this->assertNotNull($printJob->completed_at);

        // 5. Partial cash refund (10,000)
        $refundService = app(RefundService::class);
        $partialRefundTx = $refundService->processRefund(
            authorizer: $owner,
            order: $order,
            allocations: [
                ['payment_id' => $cashPayment->id, 'amount' => 10000, 'refund_method' => 'cash'],
            ],
            reason: 'Partial E2E refund',
            shift: $shift,
            idempotencyKey: 'rfd_partial_'.Str::uuid()
        );

        $order->refresh();
        $this->assertSame(Order::PAYMENT_STATUS_PARTIALLY_REFUNDED, $order->payment_status);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(155000, $shift->fresh()->currentExpectedCash());
        $this->assertSame(15000, $cashPayment->fresh()->remainingRefundableBalance());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'refund.processed',
            'module' => 'payments',
            'subject_id' => $partialRefundTx->id,
        ]);

        // Append-only immutability on RefundTransaction & PaymentRefund
        $partialRefundRow = PaymentRefund::where('refund_transaction_id', $partialRefundTx->id)->firstOrFail();
        $this->assertThrows(fn () => $partialRefundTx->update(['reason' => 'tampered']), DomainException::class);
        $this->assertThrows(fn () => $partialRefundRow->delete(), DomainException::class);

        // 6. Full refund (remaining cash 15,000 + QRIS via qris_reversal)
        $fullRefundTx = $refundService->processRefund(
            authorizer: $owner,
            order: $order,
            allocations: [
                ['payment_id' => $cashPayment->id, 'amount' => 15000, 'refund_method' => 'cash'],
                ['payment_id' => $qrisPayment->id, 'amount' => $qrisPortion, 'refund_method' => 'qris_reversal'],
            ],
            reason: 'Full E2E refund',
            shift: $shift,
            idempotencyKey: 'rfd_full_'.Str::uuid()
        );

        $order->refresh();
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(140000, $shift->fresh()->currentExpectedCash());
        $this->assertSame(0, $cashPayment->fresh()->remainingRefundableBalance());
        $this->assertSame(0, $qrisPayment->fresh()->remainingRefundableBalance());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'refund.processed',
            'module' => 'payments',
            'subject_id' => $fullRefundTx->id,
        ]);

        // 7. Shift close (actual cash = 140,000 -> discrepancy = 0)
        $closedShift = $shiftService->closeShift($shift->fresh(), $cashier, 140000);
        $this->assertSame('closed', $closedShift->status);
        $this->assertSame(140000, $closedShift->expected_cash);
        $this->assertSame(0, $closedShift->discrepancy);
        $this->assertThrows(fn () => $closedShift->update(['closing_note' => 'tampered']), DomainException::class);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'shift.closed',
            'module' => 'shift',
            'subject_id' => $closedShift->id,
        ]);
    }
}
