<?php

namespace App\Livewire\Orders;

use App\Exceptions\Refund\CashRefundRequiresOpenShiftException;
use App\Exceptions\Refund\OrderAlreadyRefundedException;
use App\Exceptions\Refund\RefundExceedsRemainingBalanceException;
use App\Exceptions\Refund\RefundIdempotencyConflictException;
use App\Exceptions\Refund\RefundReasonRequiredException;
use App\Exceptions\Refund\RefundTotalExceedsPaidAmountException;
use App\Exceptions\Refund\UnpaidOrderNotRefundableException;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Services\Inventory\InventoryService;
use App\Services\RefundService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrderRefund extends Component
{
    public Order $order;

    /**
     * Per-payment refund inputs keyed by payment ID.
     *
     * @var array<int, array{amount: int, refund_method: string}>
     */
    public array $allocations = [];

    public string $reason = '';

    public string $idempotencyKey = '';

    public ?string $errorMessage = null;

    public ?string $completedRefundNumber = null;

    public ?string $updatedPaymentStatus = null;

    public bool $returnToStock = false;

    public bool $hasUncreditedDeductions = false;

    public ?string $restockStatus = null;

    public function mount(Order $order): void
    {
        if (! Auth::user()?->isOwner()) {
            abort(403);
        }

        $this->order = $order->load(['payments.refunds', 'refundTransactions']);
        $this->idempotencyKey = (string) Str::uuid();
        $this->updatedPaymentStatus = $this->order->payment_status;
        $this->hasUncreditedDeductions = app(InventoryService::class)->hasUncreditedDeductions($this->order);
        $this->returnToStock = in_array($this->order->status, [Order::STATUS_PAID, Order::STATUS_QUEUED], true);

        foreach ($this->order->payments as $payment) {
            $defaultMethod = match ($payment->payment_method) {
                'qris' => 'qris_reversal',
                'debit' => 'manual_transfer',
                default => 'cash',
            };

            $this->allocations[$payment->id] = [
                'amount' => 0,
                'refund_method' => $defaultMethod,
            ];
        }
    }

    public function submitRefund(RefundService $refundService, ShiftService $shiftService): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $this->errorMessage = null;
        $this->restockStatus = null;

        if (trim($this->reason) === '') {
            $this->errorMessage = __('messages.refund.reason_required');
            $this->addError('reason', $this->errorMessage);

            return;
        }

        $payloadAllocations = [];
        $hasCashAllocation = false;

        foreach ($this->order->payments as $payment) {
            $entry = $this->allocations[$payment->id] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $amount = (int) ($entry['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $method = (string) ($entry['refund_method'] ?? 'cash');
            if ($method === 'cash') {
                $hasCashAllocation = true;
            }

            $payloadAllocations[] = [
                'payment_id' => $payment->id,
                'amount' => $amount,
                'refund_method' => $method,
            ];
        }

        if ($payloadAllocations === []) {
            $this->errorMessage = __('messages.refund.amount_required');
            $this->addError('allocations', $this->errorMessage);

            return;
        }

        $shift = null;
        if ($hasCashAllocation) {
            $shift = $shiftService->getActiveShift();
            if (! $shift) {
                $this->errorMessage = __('messages.refund.no_open_shift_cash');
                $this->dispatch('notify', message: $this->errorMessage, type: 'error');

                return;
            }
        }

        try {
            $transaction = $refundService->processRefund(
                authorizer: $user,
                order: $this->order,
                allocations: $payloadAllocations,
                reason: $this->reason,
                shift: $shift,
                idempotencyKey: $this->idempotencyKey,
            );

            $this->order = $this->order->fresh(['payments.refunds', 'refundTransactions']);
            $this->completedRefundNumber = $transaction->refund_transaction_number;
            $this->updatedPaymentStatus = $this->order->payment_status;

            if ($this->returnToStock && $this->order->payment_status === Order::PAYMENT_STATUS_REFUNDED) {
                try {
                    $result = app(InventoryService::class)->restockForRefund($transaction, true, $user);
                    $this->restockStatus = $result;
                    $this->hasUncreditedDeductions = app(InventoryService::class)->hasUncreditedDeductions($this->order);
                } catch (\Throwable $e) {
                    Log::error('Refund inventory restock failed: '.$e->getMessage(), [
                        'order_id' => $this->order->id,
                        'refund_transaction_id' => $transaction->id,
                        'exception_class' => $e::class,
                    ]);

                    ActivityLog::record(
                        action: 'refund_restock_failed',
                        description: "Inventory restock failed for refund {$transaction->refund_transaction_number}.",
                        subject: $transaction,
                        properties: [
                            'order_id' => $this->order->id,
                            'refund_transaction_id' => $transaction->id,
                            'exception_class' => $e::class,
                        ],
                        user: $user,
                        module: 'inventory'
                    );

                    $this->restockStatus = 'failed';
                }
            }

            $this->dispatch('notify', message: __('messages.refund.success', ['number' => $transaction->refund_transaction_number]), type: 'success');
        } catch (\Throwable $e) {
            $this->errorMessage = $this->mapRefundExceptionToMessage($e);
            $this->dispatch('notify', message: $this->errorMessage, type: 'error');
        }
    }

    protected function mapRefundExceptionToMessage(\Throwable $e): string
    {
        if ($e instanceof RefundIdempotencyConflictException || $e instanceof ConflictHttpException) {
            return __('messages.refund.error_conflict');
        }

        if (
            $e instanceof RefundExceedsRemainingBalanceException
            || $e instanceof RefundTotalExceedsPaidAmountException
        ) {
            return __('messages.refund.error_over_refund');
        }

        if ($e instanceof UnpaidOrderNotRefundableException) {
            return __('messages.refund.error_unpaid_order');
        }

        if ($e instanceof OrderAlreadyRefundedException) {
            return __('messages.refund.error_already_refunded');
        }

        if ($e instanceof CashRefundRequiresOpenShiftException) {
            return __('messages.refund.no_open_shift_cash');
        }

        if ($e instanceof RefundReasonRequiredException) {
            return __('messages.refund.reason_required');
        }

        Log::error('Order refund error: '.$e->getMessage(), [
            'exception' => $e,
            'order_id' => $this->order->id,
            'user_id' => Auth::id(),
        ]);

        return __('messages.refund.error_generic');
    }

    public function render(): View
    {
        $statusKey = 'messages.refund.status_'.$this->order->payment_status;
        $statusLabel = __($statusKey);

        $totalRemaining = (int) $this->order->payments->sum(static fn ($p): int => $p->remainingRefundableBalance());
        $enteredTotal = (int) collect($this->allocations)->sum(static fn ($a): int => max(0, (int) ($a['amount'] ?? 0)));
        $showRestockOption = $totalRemaining > 0 && $enteredTotal === $totalRemaining && $this->hasUncreditedDeductions;

        return view('livewire.orders.order-refund', [
            'statusLabel' => $statusLabel,
            'showRestockOption' => $showRestockOption,
        ])->layout('components.layouts.app', [
            'title' => __('messages.refund.title'),
            'headerTitle' => __('messages.refund.title'),
            'headerSubtitle' => __('messages.refund.subtitle', [
                'order' => $this->order->order_number,
                'status' => $statusLabel,
            ]),
        ]);
    }
}
