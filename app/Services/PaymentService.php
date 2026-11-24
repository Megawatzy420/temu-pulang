<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\QrisConfirmation;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentService
{
    public const ALLOWED_METHODS = [
        'cash',
        'qris',
        'debit',
        'credit',
        'transfer',
    ];

    public function __construct(
        protected OrderService $orderService,
        protected PrintService $printService,
    ) {}

    /**
     * Create a server-authoritative QRIS confirmation record for an order.
     * Cashier confirms after receiving merchant payment notification.
     */
    public function createQrisConfirmation(
        User $cashier,
        Order $order,
        int $expectedAmount,
        ?string $referenceNumber = null
    ): QrisConfirmation {
        if ($expectedAmount <= 0) {
            throw new InvalidArgumentException('Nominal pembayaran QRIS harus berupa bilangan bulat lebih besar dari 0.');
        }

        if ($order->payment_status === Order::PAYMENT_STATUS_PAID) {
            throw new DomainException('Pesanan ini sudah dibayar.');
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw new DomainException('Tidak dapat membuat konfirmasi QRIS untuk pesanan yang dibatalkan.');
        }

        return QrisConfirmation::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'user_id' => $cashier->id,
            'expected_amount' => $expectedAmount,
            'reference_number' => $referenceNumber ? trim($referenceNumber) : null,
            'confirmed_at' => now('Asia/Jakarta'),
            'status' => 'pending',
        ]);
    }

    /**
     * Concurrency-safe daily sequence generator for payment transactions.
     * Serializes concurrent requests by locking the active ShopSetting parent row.
     *
     * @return array{sequence: int, transaction_number: string}
     */
    public function getNextDailySequence(string $businessDate): array
    {
        ShopSetting::where('is_active', true)->lockForUpdate()->first();

        $lastSeq = (int) PaymentTransaction::whereDate('business_date', $businessDate)->max('daily_sequence');
        $nextSeq = $lastSeq + 1;
        $trxNumber = sprintf('PAY-%s-%04d', str_replace('-', '', $businessDate), $nextSeq);

        return [
            'sequence' => $nextSeq,
            'transaction_number' => $trxNumber,
        ];
    }

    /**
     * Generate a deterministic SHA-256 request fingerprint from order ID, shift ID, cashier ID, total, and normalized tenders.
     *
     * @param  array<int, array<string, mixed>>  $normalizedTenders
     */
    public function generateRequestFingerprint(
        int $orderId,
        int $shiftId,
        int $cashierUserId,
        int $totalAmount,
        array $normalizedTenders
    ): string {
        // Sort tenders deterministically so array order cannot cause false fingerprint mismatches
        usort($normalizedTenders, function ($a, $b) {
            return [$a['payment_method'], $a['amount'], $a['reference_number'] ?? '', $a['qris_confirmation_id'] ?? 0]
                <=> [$b['payment_method'], $b['amount'], $b['reference_number'] ?? '', $b['qris_confirmation_id'] ?? 0];
        });

        $payload = [
            'order_id' => $orderId,
            'shift_id' => $shiftId,
            'cashier_user_id' => $cashierUserId,
            'total_amount' => $totalAmount,
            'tenders' => $normalizedTenders,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Normalize and validate tender items against order total and strict Rupiah constraints.
     *
     * @param  array<int, array<string, mixed>>  $tenders
     * @return array<int, array{
     *     payment_method: string,
     *     amount: int,
     *     tendered_amount: int,
     *     change_amount: int,
     *     reference_number: ?string,
     *     qris_confirmation_id: ?int,
     *     notes: ?string
     * }>
     */
    public function normalizeAndValidateTenders(Order $order, array $tenders): array
    {
        if (empty($tenders)) {
            throw new InvalidArgumentException('Rincian pembayaran (tenders) tidak boleh kosong.');
        }

        $normalized = [];
        $totalAllocated = 0;

        foreach ($tenders as $index => $tender) {
            if (! is_array($tender)) {
                throw new InvalidArgumentException("Baris tender #{$index} tidak valid.");
            }

            $method = strtolower(trim((string) ($tender['payment_method'] ?? '')));
            if (! in_array($method, self::ALLOWED_METHODS, true)) {
                throw new InvalidArgumentException("Metode pembayaran '{$method}' tidak didukung.");
            }

            $amount = $tender['amount'] ?? null;
            if (! is_int($amount) || $amount <= 0) {
                throw new InvalidArgumentException("Nominal pembayaran untuk metode '{$method}' harus berupa bilangan bulat Rupiah lebih besar dari 0.");
            }

            $referenceNumber = isset($tender['reference_number']) ? trim((string) $tender['reference_number']) : null;
            $qrisConfirmationId = isset($tender['qris_confirmation_id']) ? $tender['qris_confirmation_id'] : null;
            $notes = isset($tender['notes']) ? trim((string) $tender['notes']) : null;

            if ($method === 'cash') {
                $tendered = $tender['tendered_amount'] ?? null;
                if (! is_int($tendered) || $tendered <= 0) {
                    throw new InvalidArgumentException('Nominal uang tunai yang diterima (tendered_amount) harus berupa bilangan bulat lebih besar dari 0.');
                }

                if ($tendered < $amount) {
                    throw new InvalidArgumentException('Uang tunai yang diterima (Rp '.number_format($tendered, 0, ',', '.').') kurang dari nilai tagihan (Rp '.number_format($amount, 0, ',', '.').').');
                }

                $expectedChange = $tendered - $amount;

                if (isset($tender['change_amount'])) {
                    $change = $tender['change_amount'];
                    if (! is_int($change) || $change !== $expectedChange) {
                        throw new InvalidArgumentException('Nilai kembalian tunai tidak sesuai. Seharusnya Rp '.number_format($expectedChange, 0, ',', '.').'.');
                    }
                }

                $changeAmount = $expectedChange;
                $tenderedAmount = $tendered;
            } else {
                // Non-cash: exact tender only, change must be 0
                $tendered = $tender['tendered_amount'] ?? $amount;
                if (! is_int($tendered) || $tendered !== $amount) {
                    throw new InvalidArgumentException("Nilai yang dibayarkan untuk metode non-tunai '{$method}' harus tepat sama dengan nilai tagihan.");
                }

                if (isset($tender['change_amount'])) {
                    $change = $tender['change_amount'];
                    if (! is_int($change) || $change !== 0) {
                        throw new InvalidArgumentException("Metode pembayaran non-tunai '{$method}' tidak boleh memiliki uang kembalian.");
                    }
                }

                if ($method === 'qris') {
                    if (! is_int($qrisConfirmationId) || $qrisConfirmationId <= 0) {
                        throw new InvalidArgumentException('Pembayaran QRIS memerlukan konfirmasi server yang valid (qris_confirmation_id).');
                    }
                }

                $tenderedAmount = $amount;
                $changeAmount = 0;
            }

            $normalized[] = [
                'payment_method' => $method,
                'amount' => $amount,
                'tendered_amount' => $tenderedAmount,
                'change_amount' => $changeAmount,
                'reference_number' => $referenceNumber ?: null,
                'qris_confirmation_id' => $qrisConfirmationId ? (int) $qrisConfirmationId : null,
                'notes' => $notes ?: null,
            ];

            $totalAllocated += $amount;
        }

        if ($totalAllocated !== $order->total_amount) {
            throw new InvalidArgumentException(
                'Total pembayaran (Rp '.number_format($totalAllocated, 0, ',', '.').') tidak sesuai dengan total tagihan pesanan (Rp '.number_format($order->total_amount, 0, ',', '.').').'
            );
        }

        return $normalized;
    }

    /**
     * Settle single or split payment for an order atomically.
     *
     * @param  array<int, array<string, mixed>>  $tenders
     *
     * @throws DomainException|InvalidArgumentException|ConflictHttpException
     */
    public function settlePayment(
        User $cashier,
        Shift $shift,
        Order $order,
        array $tenders,
        ?string $idempotencyKey = null
    ): PaymentTransaction {
        // 1. Pre-flight shift check
        if (! $shift->isOpen()) {
            throw new InvalidArgumentException('Shift kasir sudah ditutup. Tidak dapat memproses pembayaran.');
        }

        // 2. Pre-flight cashier permission check
        if ($cashier->id !== $shift->user_id && ! $cashier->hasRole('owner')) {
            throw new InvalidArgumentException('Kasir tidak memiliki akses ke shift ini.');
        }

        // 3. Pre-flight order status checks
        if ($order->payment_status === Order::PAYMENT_STATUS_PAID) {
            throw new DomainException('Pesanan ini sudah dibayar.');
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw new DomainException('Tidak dapat memproses pembayaran untuk pesanan yang dibatalkan.');
        }

        if (! in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_DRAFT], true)) {
            throw new DomainException("Status pesanan ({$order->status}) tidak valid untuk pembayaran.");
        }

        // 4. Normalize tenders & build request fingerprint
        $normalizedTenders = $this->normalizeAndValidateTenders($order, $tenders);
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : (string) Str::uuid();
        $requestFingerprint = $this->generateRequestFingerprint(
            orderId: $order->id,
            shiftId: $shift->id,
            cashierUserId: $cashier->id,
            totalAmount: $order->total_amount,
            normalizedTenders: $normalizedTenders
        );

        // 5. Idempotency Check — Return existing transaction on exact retry, reject on fingerprint mismatch
        $existingTransaction = PaymentTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existingTransaction) {
            if ($existingTransaction->request_fingerprint === $requestFingerprint) {
                return $existingTransaction->load(['payments', 'order']);
            }

            throw new ConflictHttpException('Kunci idempotensi sudah digunakan dengan parameter pembayaran berbeda.');
        }

        // 6. Atomic Transaction
        return DB::transaction(function () use (
            $cashier,
            $shift,
            $order,
            $normalizedTenders,
            $idempotencyKey,
            $requestFingerprint
        ) {
            // Re-validate & lock shift inside transaction
            $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->first();
            if (! $lockedShift || ! $lockedShift->isOpen()) {
                throw new InvalidArgumentException('Shift kasir sudah ditutup. Tidak dapat memproses pembayaran.');
            }
            if ($cashier->id !== $lockedShift->user_id && ! $cashier->hasRole('owner')) {
                throw new InvalidArgumentException('Kasir tidak memiliki akses ke shift ini.');
            }

            // Re-validate & lock order inside transaction
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $lockedOrder) {
                throw new InvalidArgumentException('Pesanan tidak ditemukan.');
            }

            if ($lockedOrder->payment_status === Order::PAYMENT_STATUS_PAID) {
                throw new DomainException('Pesanan ini sudah dibayar.');
            }

            if ($lockedOrder->paymentTransaction()->exists()) {
                throw new DomainException('Transaksi pembayaran untuk pesanan ini sudah ada.');
            }

            if ($lockedOrder->status === Order::STATUS_CANCELLED) {
                throw new DomainException('Tidak dapat memproses pembayaran untuk pesanan yang dibatalkan.');
            }

            if (! in_array($lockedOrder->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_DRAFT], true)) {
                throw new DomainException("Status pesanan ({$lockedOrder->status}) tidak valid untuk pembayaran.");
            }

            // Verify order total matches allocated sum
            $totalAllocated = array_sum(array_column($normalizedTenders, 'amount'));
            if ($totalAllocated !== $lockedOrder->total_amount) {
                throw new InvalidArgumentException('Total nilai pembayaran tidak sesuai dengan total tagihan pesanan.');
            }

            // Lock & validate QRIS confirmations if any
            $tenderRowsToInsert = [];
            foreach ($normalizedTenders as $tender) {
                $qrisConfirmedUserId = null;
                $qrisConfirmedAt = null;
                $referenceNumber = $tender['reference_number'];

                if ($tender['payment_method'] === 'qris') {
                    $qrisId = $tender['qris_confirmation_id'];
                    $qris = QrisConfirmation::where('id', $qrisId)->lockForUpdate()->first();

                    if (! $qris) {
                        throw new InvalidArgumentException('Data konfirmasi QRIS tidak ditemukan.');
                    }

                    if ($qris->order_id !== $lockedOrder->id) {
                        throw new InvalidArgumentException('Konfirmasi QRIS bukan milik pesanan ini.');
                    }

                    if ($qris->user_id !== $cashier->id && ! $cashier->hasRole('owner')) {
                        throw new InvalidArgumentException('Konfirmasi QRIS hanya dapat diproses oleh kasir yang mengonfirmasinya atau Owner.');
                    }

                    if (! $qris->isPending()) {
                        throw new DomainException('Konfirmasi QRIS sudah pernah digunakan atau kadaluarsa.');
                    }

                    if ($qris->expected_amount !== $tender['amount']) {
                        throw new InvalidArgumentException('Nominal konfirmasi QRIS tidak sesuai dengan nilai pembayaran.');
                    }

                    // Atomically consume QRIS confirmation
                    $qris->update([
                        'status' => 'consumed',
                        'consumed_at' => now('Asia/Jakarta'),
                    ]);

                    $qrisConfirmedUserId = $qris->user_id;
                    $qrisConfirmedAt = $qris->confirmed_at;
                    if (empty($referenceNumber)) {
                        $referenceNumber = $qris->reference_number;
                    }
                }

                $tenderRowsToInsert[] = [
                    'payment_method' => $tender['payment_method'],
                    'amount' => $tender['amount'],
                    'tendered_amount' => $tender['tendered_amount'],
                    'change_amount' => $tender['change_amount'],
                    'reference_number' => $referenceNumber,
                    'qris_confirmed_by_user_id' => $qrisConfirmedUserId,
                    'qris_confirmed_at' => $qrisConfirmedAt,
                    'notes' => $tender['notes'],
                ];
            }

            // Concurrency-safe sequence numbering
            $businessDate = $this->orderService->calculateBusinessDate();
            $seqData = $this->getNextDailySequence($businessDate);

            // Create parent PaymentTransaction
            $paymentTransaction = PaymentTransaction::create([
                'ulid' => (string) Str::ulid(),
                'payment_transaction_number' => $seqData['transaction_number'],
                'daily_sequence' => $seqData['sequence'],
                'business_date' => $businessDate,
                'order_id' => $lockedOrder->id,
                'shift_id' => $lockedShift->id,
                'user_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'total_amount' => $lockedOrder->total_amount,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $requestFingerprint,
                'status' => 'completed',
            ]);

            // Create child Payment tender rows
            foreach ($tenderRowsToInsert as $row) {
                $payment = new Payment([
                    'payment_transaction_id' => $paymentTransaction->id,
                    'shift_id' => $lockedShift->id,
                    'payment_method' => $row['payment_method'],
                    'amount' => $row['amount'],
                    'tendered_amount' => $row['tendered_amount'],
                    'change_amount' => $row['change_amount'],
                    'reference_number' => $row['reference_number'],
                    'qris_confirmed_by_user_id' => $row['qris_confirmed_by_user_id'],
                    'qris_confirmed_at' => $row['qris_confirmed_at'],
                    'notes' => $row['notes'],
                ]);
                // Explicit assignment satisfies non-nullable FK and protects against mass assignment
                $payment->order_id = $lockedOrder->id;
                $payment->save();
            }

            // Update Order status and payment_status
            $prevStatus = $lockedOrder->status;
            $lockedOrder->payment_status = Order::PAYMENT_STATUS_PAID;
            $lockedOrder->status = Order::STATUS_PAID;
            $lockedOrder->paid_at = now('Asia/Jakarta');
            $lockedOrder->save();

            // Record OrderStatusHistory
            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'from_status' => $prevStatus,
                'to_status' => Order::STATUS_PAID,
                'user_id' => $cashier->id,
                'notes' => 'Pembayaran lunas diterima ('.implode(', ', array_column($normalizedTenders, 'payment_method')).').',
            ]);

            // Audit log recording
            ActivityLog::record(
                action: 'payment.settled',
                description: "Pembayaran pesanan {$lockedOrder->order_number} ({$paymentTransaction->payment_transaction_number}) sebesar Rp ".number_format($lockedOrder->total_amount, 0, ',', '.').' berhasil diselesaikan.',
                subject: $paymentTransaction,
                properties: [
                    'payment_transaction_id' => $paymentTransaction->id,
                    'payment_transaction_number' => $paymentTransaction->payment_transaction_number,
                    'order_id' => $lockedOrder->id,
                    'order_number' => $lockedOrder->order_number,
                    'total_amount' => $lockedOrder->total_amount,
                    'tenders' => $normalizedTenders,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
                ],
                user: $cashier,
                module: 'payments'
            );

            // Queue print jobs safely: a print queuing failure must not reverse a successful payment
            try {
                $shopSetting = ShopSetting::current();
                if ($shopSetting !== null) {
                    $this->printService->queueOrderReceipt($lockedOrder, $paymentTransaction, $shopSetting);
                }
            } catch (\Throwable $e) {
                Log::error("Gagal membuat antrean cetak: {$e->getMessage()}");
            }

            return $paymentTransaction->fresh(['payments', 'order']);
        });
    }
}
