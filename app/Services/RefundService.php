<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RefundService
{
    public const ALLOWED_REFUND_METHODS = [
        'cash',
        'qris_reversal',
        'manual_transfer',
    ];

    /**
     * Process a partial or full refund for an order.
     *
     * @param  array<int, array{payment_id: int, amount: int, refund_method: string}>  $allocations
     *
     * @throws InvalidArgumentException|DomainException|ConflictHttpException
     */
    public function processRefund(
        User $authorizer,
        Order $order,
        array $allocations,
        string $reason,
        ?Shift $shift = null,
        ?string $idempotencyKey = null
    ): RefundTransaction {
        // 1. Owner-only authorization (pre-flight, no lock yet)
        if (! $authorizer->isOwner()) {
            throw new InvalidArgumentException('Hanya Pemilik (Owner) yang memiliki wewenang untuk memproses refund.');
        }

        // 2. Reason is mandatory
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan refund wajib diisi.');
        }

        // 3. Order must have a successful payment
        if ($order->payment_status === Order::PAYMENT_STATUS_UNPAID) {
            throw new DomainException('Pesanan ini belum dibayar dan tidak dapat direfund.');
        }

        if ($order->payment_status === Order::PAYMENT_STATUS_REFUNDED) {
            throw new DomainException('Pesanan ini sudah sepenuhnya direfund.');
        }

        // 4. Normalize and pre-validate allocations
        $normalizedAllocations = $this->normalizeAllocations($allocations);

        // 5. Build canonical fingerprint & handle idempotency
        $totalRefundedAmount = array_sum(array_column($normalizedAllocations, 'amount'));
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : (string) Str::uuid();
        $requestFingerprint = $this->generateRefundRequestFingerprint(
            orderId: $order->id,
            authorizerUserId: $authorizer->id,
            totalRefundedAmount: $totalRefundedAmount,
            normalizedAllocations: $normalizedAllocations,
            reason: trim($reason)
        );

        // 6. Idempotency check (outside transaction for performance)
        $existing = $this->findExistingRefundTransaction($idempotencyKey);
        if ($existing) {
            if ($existing->request_fingerprint === $requestFingerprint) {
                return $existing->load(['refunds', 'order', 'authorizedByUser']);
            }

            throw new ConflictHttpException('Kunci idempotensi sudah digunakan dengan parameter refund berbeda.');
        }

        // 7. Atomic transaction with idempotency collision recovery
        try {
            return DB::transaction(function () use (
                $authorizer,
                $order,
                $normalizedAllocations,
                $reason,
                $idempotencyKey,
                $requestFingerprint,
                $shift
            ) {
                // Determine if any allocation needs cash
                $hasCashAllocation = collect($normalizedAllocations)->contains('refund_method', 'cash');

                // Lock shift if cash refund — verify it is open
                $lockedShift = null;
                if ($hasCashAllocation) {
                    if (! $shift || ! $shift->isOpen()) {
                        throw new InvalidArgumentException('Refund tunai memerlukan shift kasir yang sedang aktif (open).');
                    }

                    $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->first();
                    if (! $lockedShift || ! $lockedShift->isOpen()) {
                        throw new InvalidArgumentException('Shift kasir sudah ditutup. Refund tunai tidak dapat diproses.');
                    }
                }

                // Lock order row
                $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
                if (! $lockedOrder) {
                    throw new DomainException('Pesanan tidak ditemukan.');
                }

                if ($lockedOrder->payment_status === Order::PAYMENT_STATUS_UNPAID) {
                    throw new DomainException('Pesanan ini belum dibayar dan tidak dapat direfund.');
                }

                if ($lockedOrder->payment_status === Order::PAYMENT_STATUS_REFUNDED) {
                    throw new DomainException('Pesanan ini sudah sepenuhnya direfund.');
                }

                // Collect unique payment IDs from allocations (already canonical and sorted ascending)
                $paymentIds = array_column($normalizedAllocations, 'payment_id');

                // Lock all target Payment rows in ascending ID order (prevents deadlocks)
                $lockedPayments = Payment::whereIn('id', $paymentIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Verify every payment belongs to the target order
                foreach ($lockedPayments as $payment) {
                    if ($payment->order_id !== $lockedOrder->id) {
                        throw new InvalidArgumentException(
                            "Pembayaran #{$payment->id} bukan milik pesanan ini."
                        );
                    }
                }

                // Verify all requested payment IDs exist
                foreach ($paymentIds as $pid) {
                    if (! $lockedPayments->has($pid)) {
                        throw new InvalidArgumentException(
                            "Pembayaran #{$pid} tidak ditemukan untuk pesanan ini."
                        );
                    }
                }

                // Validate canonical allocations against remaining refundable balances
                foreach ($normalizedAllocations as $alloc) {
                    $pid = $alloc['payment_id'];
                    $payment = $lockedPayments->get($pid);
                    $remaining = $payment->remainingRefundableBalance();

                    if ($alloc['amount'] > $remaining) {
                        throw new InvalidArgumentException(
                            "Jumlah refund untuk pembayaran #{$pid} (Rp ".number_format($alloc['amount'], 0, ',', '.').
                            ') melebihi saldo yang dapat direfund (Rp '.number_format($remaining, 0, ',', '.').').
                        ');
                    }
                }

                // Validate total doesn't exceed total paid amount for the order
                $totalPaid = (int) Payment::where('order_id', $lockedOrder->id)->sum('amount');
                $alreadyRefunded = (int) PaymentRefund::where('order_id', $lockedOrder->id)->sum('amount');
                $newRefundTotal = array_sum(array_column($normalizedAllocations, 'amount'));

                if (($alreadyRefunded + $newRefundTotal) > $totalPaid) {
                    throw new DomainException(
                        'Total refund melebihi total pembayaran untuk pesanan ini.'
                    );
                }

                // Generate concurrency-safe daily sequence for RefundTransaction
                $businessDate = $this->calculateBusinessDate();
                $seqData = $this->getNextRefundDailySequence($businessDate);

                // Create parent RefundTransaction
                $refundTransaction = RefundTransaction::create([
                    'ulid' => (string) Str::ulid(),
                    'refund_transaction_number' => $seqData['transaction_number'],
                    'daily_sequence' => $seqData['sequence'],
                    'business_date' => $businessDate,
                    'order_id' => $lockedOrder->id,
                    'authorized_by_user_id' => $authorizer->id,
                    'total_refunded_amount' => $newRefundTotal,
                    'reason' => trim($reason),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
                ]);

                // Create child PaymentRefund records using canonical allocations
                foreach ($normalizedAllocations as $alloc) {
                    $pid = $alloc['payment_id'];
                    $payment = $lockedPayments->get($pid);
                    PaymentRefund::create([
                        'refund_transaction_id' => $refundTransaction->id,
                        'payment_id' => $pid,
                        'order_id' => $lockedOrder->id,
                        'shift_id' => ($alloc['refund_method'] === 'cash' && $lockedShift) ? $lockedShift->id : null,
                        'original_payment_method' => $payment->payment_method,
                        'refund_method' => $alloc['refund_method'],
                        'amount' => $alloc['amount'],
                    ]);
                }

                // Recalculate payment_status
                $totalRefundedAfter = (int) PaymentRefund::where('order_id', $lockedOrder->id)->sum('amount');
                $prevPaymentStatus = $lockedOrder->payment_status;

                if ($totalRefundedAfter >= $totalPaid) {
                    $newPaymentStatus = Order::PAYMENT_STATUS_REFUNDED;
                } else {
                    $newPaymentStatus = Order::PAYMENT_STATUS_PARTIALLY_REFUNDED;
                }

                // Update order payment_status (not in $fillable — explicit assignment)
                $lockedOrder->payment_status = $newPaymentStatus;
                $lockedOrder->save();

                // If fully refunded AND order is not in a terminal state, cancel it
                if (
                    $newPaymentStatus === Order::PAYMENT_STATUS_REFUNDED
                    && ! in_array($lockedOrder->status, [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)
                ) {
                    $prevOrderStatus = $lockedOrder->status;

                    DB::table('orders')->where('id', $lockedOrder->id)->update([
                        'status' => Order::STATUS_CANCELLED,
                        'cancellation_reason' => 'Refund penuh telah diproses. Alasan: '.trim($reason),
                        'cancelled_by_user_id' => $authorizer->id,
                        'cancelled_at' => now('Asia/Jakarta'),
                        'updated_at' => now(),
                    ]);

                    OrderStatusHistory::create([
                        'order_id' => $lockedOrder->id,
                        'from_status' => $prevOrderStatus,
                        'to_status' => Order::STATUS_CANCELLED,
                        'user_id' => $authorizer->id,
                        'notes' => 'Pesanan dibatalkan akibat refund penuh oleh Owner. Alasan: '.trim($reason),
                    ]);

                    $lockedOrder->refresh();
                }

                // Record OrderStatusHistory for payment_status change if relevant
                if ($prevPaymentStatus !== $newPaymentStatus) {
                    OrderStatusHistory::create([
                        'order_id' => $lockedOrder->id,
                        'from_status' => $prevPaymentStatus,
                        'to_status' => $newPaymentStatus,
                        'user_id' => $authorizer->id,
                        'notes' => "Status pembayaran diperbarui menjadi {$newPaymentStatus} setelah refund {$refundTransaction->refund_transaction_number}.",
                    ]);
                }

                // Audit log
                ActivityLog::record(
                    action: 'refund.processed',
                    description: "Refund {$refundTransaction->refund_transaction_number} untuk pesanan {$lockedOrder->order_number} sebesar Rp ".
                        number_format($newRefundTotal, 0, ',', '.').' diproses oleh '.$authorizer->name.'. Alasan: '.trim($reason),
                    subject: $refundTransaction,
                    properties: [
                        'refund_transaction_id' => $refundTransaction->id,
                        'refund_transaction_number' => $refundTransaction->refund_transaction_number,
                        'order_id' => $lockedOrder->id,
                        'order_number' => $lockedOrder->order_number,
                        'authorized_by_user_id' => $authorizer->id,
                        'authorized_by_name' => $authorizer->name,
                        'total_refunded_amount' => $newRefundTotal,
                        'reason' => trim($reason),
                        'allocations' => $normalizedAllocations,
                        'shift_id' => $lockedShift?->id,
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $requestFingerprint,
                    ],
                    user: $authorizer,
                    module: 'payments'
                );

                return $refundTransaction->load(['refunds', 'order', 'authorizedByUser']);
            });
        } catch (QueryException $e) {
            // Target only unique constraint violations specifically on refund_transactions.idempotency_key
            if ($this->isIdempotencyKeyUniqueViolation($e)) {
                $winningTransaction = $this->findExistingRefundTransaction($idempotencyKey);

                if ($winningTransaction) {
                    if ($winningTransaction->request_fingerprint === $requestFingerprint) {
                        return $winningTransaction->load(['refunds', 'order', 'authorizedByUser']);
                    }

                    throw new ConflictHttpException('Kunci idempotensi sudah digunakan dengan parameter refund berbeda.');
                }
            }

            // Not an idempotency unique collision or no winning record found; re-throw original exception
            throw $e;
        }
    }

    /**
     * Find an existing refund transaction by its idempotency key.
     */
    public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
    {
        return RefundTransaction::where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * Determine if a QueryException is specifically caused by a unique constraint violation
     * on refund_transactions.idempotency_key.
     */
    protected function isIdempotencyKeyUniqueViolation(QueryException $e): bool
    {
        $errorCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        // MySQL: Duplicate entry driver code 1062 on index refund_transactions_idempotency_key_unique
        if ($errorCode === 1062 && preg_match("/for key ['`\"]?([^'`\"]*\.)?refund_transactions_idempotency_key_unique['`\"]?/i", $message)) {
            return true;
        }

        // SQLite: Error code 19 (SQLITE_CONSTRAINT) or 2067 (SQLITE_CONSTRAINT_UNIQUE) specifically on refund_transactions.idempotency_key
        $isSqliteCode = in_array($errorCode, [19, 2067], true) || ($errorCode === 0 && $e->getCode() === '23000');
        if ($isSqliteCode && preg_match('/UNIQUE constraint failed:\s*[`"\[]?refund_transactions[`"\]]?\.[`"\[]?idempotency_key[`"\]]?/i', $message)) {
            return true;
        }

        return false;
    }

    /**
     * Normalize, validate, and aggregate raw allocation input.
     * Combines duplicate allocations for the same payment_id and refund_method,
     * rejects conflicting refund methods for the same payment_id,
     * and returns canonical allocations sorted deterministically by payment_id.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, array{payment_id: int, amount: int, refund_method: string}>
     */
    public function normalizeAllocations(array $allocations): array
    {
        if (empty($allocations)) {
            throw new InvalidArgumentException('Rincian alokasi refund tidak boleh kosong.');
        }

        $aggregated = [];

        foreach ($allocations as $index => $alloc) {
            if (! is_array($alloc)) {
                throw new InvalidArgumentException("Alokasi refund #{$index} tidak valid.");
            }

            $paymentId = $alloc['payment_id'] ?? null;
            if (! is_int($paymentId) || $paymentId <= 0) {
                throw new InvalidArgumentException("Alokasi refund #{$index}: payment_id harus berupa bilangan bulat positif.");
            }

            $amount = $alloc['amount'] ?? null;
            if (! is_int($amount) || $amount <= 0) {
                throw new InvalidArgumentException("Alokasi refund #{$index}: amount harus berupa bilangan bulat Rupiah lebih besar dari 0.");
            }

            $refundMethod = strtolower(trim((string) ($alloc['refund_method'] ?? '')));
            if (! in_array($refundMethod, self::ALLOWED_REFUND_METHODS, true)) {
                throw new InvalidArgumentException(
                    "Alokasi refund #{$index}: metode refund '{$refundMethod}' tidak didukung. Metode yang valid: ".
                    implode(', ', self::ALLOWED_REFUND_METHODS).'.'
                );
            }

            if (! isset($aggregated[$paymentId])) {
                $aggregated[$paymentId] = [
                    'payment_id' => $paymentId,
                    'amount' => 0,
                    'refund_method' => $refundMethod,
                ];
            }

            if ($aggregated[$paymentId]['refund_method'] !== $refundMethod) {
                throw new InvalidArgumentException(
                    "Alokasi refund untuk pembayaran #{$paymentId} memiliki metode refund yang berbeda. Gunakan satu metode per pembayaran."
                );
            }

            $aggregated[$paymentId]['amount'] += $amount;
        }

        // Sort canonical allocations deterministically by payment_id ascending
        ksort($aggregated);

        return array_values($aggregated);
    }

    /**
     * Generate a deterministic SHA-256 request fingerprint for a refund request.
     *
     * @param  array<int, array{payment_id: int, amount: int, refund_method: string}>  $normalizedAllocations
     */
    public function generateRefundRequestFingerprint(
        int $orderId,
        int $authorizerUserId,
        int $totalRefundedAmount,
        array $normalizedAllocations,
        string $reason
    ): string {
        // Ensure deterministic ordering by payment_id
        usort($normalizedAllocations, function ($a, $b) {
            return $a['payment_id'] <=> $b['payment_id'];
        });

        $payload = [
            'order_id' => $orderId,
            'authorizer_user_id' => $authorizerUserId,
            'total_refunded_amount' => $totalRefundedAmount,
            'allocations' => $normalizedAllocations,
            'reason' => $reason,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Concurrency-safe daily sequence generator for refund transactions.
     * Serializes concurrent requests by locking the active ShopSetting parent row.
     *
     * @return array{sequence: int, transaction_number: string}
     */
    public function getNextRefundDailySequence(string $businessDate): array
    {
        ShopSetting::where('is_active', true)->lockForUpdate()->first();

        $lastSeq = (int) RefundTransaction::whereDate('business_date', $businessDate)->max('daily_sequence');
        $nextSeq = $lastSeq + 1;
        $trxNumber = sprintf('RFD-%s-%04d', str_replace('-', '', $businessDate), $nextSeq);

        return [
            'sequence' => $nextSeq,
            'transaction_number' => $trxNumber,
        ];
    }

    /**
     * Calculate business date according to ShopSetting (delegates to OrderService pattern).
     */
    public function calculateBusinessDate(): string
    {
        $setting = ShopSetting::where('is_active', true)->first();
        $startTime = $setting?->business_day_start_time ?? '06:00:00';
        $tz = $setting?->timezone ?? 'Asia/Jakarta';

        $now = now()->setTimezone($tz);
        [$cutoffHour, $cutoffMinute] = explode(':', $startTime);

        if ($now->hour < (int) $cutoffHour || ($now->hour === (int) $cutoffHour && $now->minute < (int) $cutoffMinute)) {
            return $now->subDay()->toDateString();
        }

        return $now->toDateString();
    }
}
