<?php

namespace App\Services;

use App\Exceptions\Order\OrderIdempotencyConflictException;
use App\Exceptions\Order\ShiftClosedForOrderException;
use App\Models\ActivityLog;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrderService
{
    /**
     * Calculate business date according to shop business_day_start_time (default 06:00:00).
     * If timestamp is before start time (e.g. 02:00 AM), it counts toward the previous calendar day.
     */
    public function calculateBusinessDate(?Carbon $timestamp = null): string
    {
        $setting = ShopSetting::where('is_active', true)->first();
        $startTime = $setting?->business_day_start_time ?? '06:00:00';
        $tz = $setting?->timezone ?? 'Asia/Jakarta';

        $now = ($timestamp ? $timestamp->copy() : Carbon::now())->setTimezone($tz);
        [$cutoffHour, $cutoffMinute] = explode(':', $startTime);

        if ($now->hour < (int) $cutoffHour || ($now->hour === (int) $cutoffHour && $now->minute < (int) $cutoffMinute)) {
            return $now->subDay()->toDateString();
        }

        return $now->toDateString();
    }

    /**
     * Concurrency-safe daily sequence generator.
     * Serializes concurrent requests by locking the active ShopSetting parent row.
     * Returns ['sequence' => int, 'call_number' => 'TP-001', 'order_number' => 'ORD-20261114-001']
     *
     * @return array{sequence: int, call_number: string, order_number: string}
     */
    public function getNextDailySequence(string $businessDate, ?int $branchId = null): array
    {
        // Enforce lock on active ShopSetting to serialize counter calculation within active transaction
        ShopSetting::where('is_active', true)->lockForUpdate()->first();

        $query = Order::query()->whereDate('business_date', $businessDate);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $lastSeq = (int) $query->max('daily_sequence');

        $nextSeq = $lastSeq + 1;
        $callNumber = sprintf('TP-%03d', $nextSeq);
        $orderNumber = sprintf('ORD-%s-%03d', str_replace('-', '', $businessDate), $nextSeq);

        return [
            'sequence' => $nextSeq,
            'call_number' => $callNumber,
            'order_number' => $orderNumber,
        ];
    }

    /**
     * Create a new Order in DB with frozen line items & modifier snapshots.
     *
     * @param  array{
     *     order_type: string,
     *     customer_name?: ?string,
     *     notes?: ?string,
     *     idempotency_key?: ?string,
     *     items: array<int, array{
     *         product_id: int,
     *         variant_id?: ?int,
     *         quantity: int,
     *         notes?: ?string,
     *         modifier_ids?: array<int, int>
     *     }>
     * }  $orderPayload
     *
     * @throws Exception
     */
    public function createOrder(User $cashier, Shift $shift, array $orderPayload): Order
    {
        // 1. Pre-flight shift check (fast fail before acquiring any lock)
        if (! $shift->isOpen()) {
            throw new ShiftClosedForOrderException('Shift kasir sudah ditutup. Tidak dapat memproses transaksi baru.');
        }

        // 2. Idempotency Guard — return existing order if the same key is submitted again with matching payload
        $idempotencyKey = $orderPayload['idempotency_key'] ?? (string) Str::uuid();
        $existingOrder = Order::with(['items.modifiers'])->where('idempotency_key', $idempotencyKey)->first();
        if ($existingOrder) {
            $this->assertPayloadMatchesExistingOrder($existingOrder, $orderPayload);

            return $existingOrder;
        }

        if (empty($orderPayload['items'])) {
            throw new InvalidArgumentException('Pesanan tidak boleh kosong.');
        }

        try {
            return DB::transaction(function () use ($cashier, $shift, $orderPayload, $idempotencyKey) {
                // 3. Re-validate shift INSIDE the transaction using a pessimistic write lock.
                //    This closes the TOCTOU window: if the shift was closed between the pre-flight
                //    check above and this point, we abort cleanly before writing any order rows.
                $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->first();
                if (! $lockedShift || ! $lockedShift->isOpen()) {
                    throw new ShiftClosedForOrderException('Shift kasir sudah ditutup. Tidak dapat memproses transaksi baru.');
                }

                $businessDate = $this->calculateBusinessDate();
                $seqData = $this->getNextDailySequence($businessDate, $shift->branch_id);

                $order = new Order;
                $order->ulid = (string) Str::ulid();
                $order->branch_id = $shift->branch_id;
                $order->shift_id = $shift->id;
                $order->user_id = $cashier->id;
                $order->order_number = $seqData['order_number'];
                $order->daily_order_number = $seqData['call_number'];
                $order->daily_sequence = $seqData['sequence'];
                $order->business_date = $businessDate;
                $order->order_type = $orderPayload['order_type'] ?? Order::TYPE_DINE_IN;
                $order->status = Order::STATUS_AWAITING_PAYMENT;
                $order->customer_name = ! empty($orderPayload['customer_name']) ? trim($orderPayload['customer_name']) : null;
                $order->notes = ! empty($orderPayload['notes']) ? trim($orderPayload['notes']) : null;
                $order->idempotency_key = $idempotencyKey;

                // Financial accumulators (all integer Rupiah)
                $subtotal = 0;

                // First save order shell to get order ID
                $order->subtotal = 0;
                $order->tax_amount = 0;
                $order->service_charge_amount = 0;
                $order->discount_amount = 0;
                $order->total_amount = 0;
                $order->save();

                // Process Items
                foreach ($orderPayload['items'] as $itemData) {
                    $qty = (int) ($itemData['quantity'] ?? 1);
                    if ($qty <= 0) {
                        continue;
                    }

                    $product = Product::with(['translations', 'variants.translations'])->findOrFail($itemData['product_id']);

                    if ($product->is_sold_out) {
                        throw new InvalidArgumentException("Produk {$product->name} saat ini sedang habis (sold out).");
                    }

                    $variant = null;
                    $unitPrice = (int) $product->base_price;
                    $sku = $product->sku;

                    if (! empty($itemData['variant_id'])) {
                        $variant = $product->variants->firstWhere('id', $itemData['variant_id']);
                        if (! $variant) {
                            throw new InvalidArgumentException("Varian tidak ditemukan untuk produk {$product->name}.");
                        }
                        if ($variant->is_sold_out) {
                            throw new InvalidArgumentException("Varian {$variant->name} untuk {$product->name} sedang habis (sold out).");
                        }
                        $unitPrice = (int) $variant->price;
                        $sku = $variant->sku ?: $sku;
                    }

                    // Process modifier options
                    $modifiersUnitPrice = 0;
                    $validModifiers = [];

                    if (! empty($itemData['modifier_ids']) && is_array($itemData['modifier_ids'])) {
                        $modifiers = Modifier::with(['translations', 'group.translations'])
                            ->whereIn('id', $itemData['modifier_ids'])
                            ->get();

                        foreach ($modifiers as $mod) {
                            if ($mod->is_sold_out) {
                                throw new InvalidArgumentException("Modifier {$mod->name} saat ini sedang habis (sold out).");
                            }
                            $modifiersUnitPrice += (int) $mod->price;
                            $validModifiers[] = $mod;
                        }
                    }

                    $finalUnitPrice = $unitPrice + $modifiersUnitPrice;
                    $lineSubtotal = $finalUnitPrice * $qty;
                    $subtotal += $lineSubtotal;

                    // Create OrderItem snapshot
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'product_name_id' => $product->translations->firstWhere('locale', 'id')?->name ?? $product->name,
                        'product_name_en' => $product->translations->firstWhere('locale', 'en')?->name,
                        'variant_name_id' => $variant ? ($variant->translations->firstWhere('locale', 'id')?->name ?? $variant->name) : null,
                        'variant_name_en' => $variant ? $variant->translations->firstWhere('locale', 'en')?->name : null,
                        'sku' => $sku,
                        'unit_price' => $unitPrice,
                        'modifiers_unit_price' => $modifiersUnitPrice,
                        'final_unit_price' => $finalUnitPrice,
                        'quantity' => $qty,
                        'subtotal' => $lineSubtotal,
                        'special_instructions' => ! empty($itemData['notes']) ? trim($itemData['notes']) : null,
                    ]);

                    // Create OrderItemModifier snapshots
                    foreach ($validModifiers as $mod) {
                        OrderItemModifier::create([
                            'order_item_id' => $orderItem->id,
                            'modifier_id' => $mod->id,
                            'modifier_group_id' => $mod->modifier_group_id,
                            'group_name_id' => $mod->group?->translations->firstWhere('locale', 'id')?->name ?? $mod->group?->name ?? 'Modifier',
                            'group_name_en' => $mod->group?->translations->firstWhere('locale', 'en')?->name,
                            'modifier_name_id' => $mod->translations->firstWhere('locale', 'id')?->name ?? $mod->name,
                            'modifier_name_en' => $mod->translations->firstWhere('locale', 'en')?->name,
                            'unit_price' => (int) $mod->price,
                        ]);
                    }
                }

                // Calculate tax & service charge from ShopSetting
                $shopSetting = ShopSetting::where('is_active', true)->first();
                $taxBasisPoints = (int) ($shopSetting?->tax_rate ?? 0);
                $serviceBasisPoints = (int) ($shopSetting?->service_charge_rate ?? 0);

                $serviceChargeAmount = (int) floor(($subtotal * $serviceBasisPoints) / 10000);
                $taxAmount = (int) floor((($subtotal + $serviceChargeAmount) * $taxBasisPoints) / 10000);
                $discountAmount = 0;
                $totalAmount = $subtotal + $serviceChargeAmount + $taxAmount - $discountAmount;

                // Direct DB update to bypass booted() immutable check on newly created order
                DB::table('orders')->where('id', $order->id)->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'service_charge_amount' => $serviceChargeAmount,
                    'discount_amount' => $discountAmount,
                    'total_amount' => $totalAmount,
                    'updated_at' => now(),
                ]);

                // Refresh model instance
                $order = Order::with(['items.modifiers', 'statusHistories'])->findOrFail($order->id);

                // Record initial state history
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => null,
                    'to_status' => Order::STATUS_AWAITING_PAYMENT,
                    'user_id' => $cashier->id,
                    'notes' => 'Pesanan baru dibuat melalui kasir.',
                ]);

                // Re-load relation for return
                $order->load('statusHistories');

                ActivityLog::record(
                    action: 'order.created',
                    description: "Pesanan {$order->order_number} ({$order->call_number}) dibuat oleh {$cashier->name}.",
                    properties: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'total_amount' => $order->total_amount,
                    ]
                );

                return $order;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Safe handling of concurrent unique-constraint race on idempotency_key
            $existingOrder = Order::with(['items.modifiers'])->where('idempotency_key', $idempotencyKey)->first();
            if ($existingOrder) {
                $this->assertPayloadMatchesExistingOrder($existingOrder, $orderPayload);

                return $existingOrder;
            }

            throw $e;
        }
    }

    /**
     * Canonical item signature incorporating product, variant, quantity, notes, and modifier IDs.
     *
     * @param  array<int>  $modifierIds
     */
    protected function buildItemSignature(int $productId, ?int $variantId, int $qty, ?string $notes, array $modifierIds): string
    {
        $cleanModIds = array_values(array_unique(array_filter(array_map('intval', $modifierIds))));
        sort($cleanModIds);

        return sprintf(
            '%d:%s:%d:%s:[%s]',
            $productId,
            $variantId ? (string) $variantId : '0',
            $qty,
            $notes ? strtolower(trim($notes)) : '',
            implode(',', $cleanModIds)
        );
    }

    /**
     * Verify that an existing order with the same idempotency key matches the incoming order payload.
     * Throws ConflictHttpException if there are any discrepancies in order type, customer, notes, or items.
     *
     * @param  array<string, mixed>  $orderPayload
     *
     * @throws ConflictHttpException
     */
    public function assertPayloadMatchesExistingOrder(Order $existingOrder, array $orderPayload): void
    {
        // 1. Order-level attributes
        $expectedType = $orderPayload['order_type'] ?? Order::TYPE_DINE_IN;
        if ($existingOrder->order_type !== $expectedType) {
            throw new OrderIdempotencyConflictException('Kunci idempotensi sudah digunakan untuk tipe pesanan berbeda.');
        }

        $expectedCustomer = ! empty($orderPayload['customer_name']) ? strtolower(trim($orderPayload['customer_name'])) : null;
        $actualCustomer = ! empty($existingOrder->customer_name) ? strtolower(trim($existingOrder->customer_name)) : null;
        if ($actualCustomer !== $expectedCustomer) {
            throw new OrderIdempotencyConflictException('Kunci idempotensi sudah digunakan untuk nama pelanggan berbeda.');
        }

        $expectedNotes = ! empty($orderPayload['notes']) ? strtolower(trim($orderPayload['notes'])) : null;
        $actualNotes = ! empty($existingOrder->notes) ? strtolower(trim($existingOrder->notes)) : null;
        if ($actualNotes !== $expectedNotes) {
            throw new OrderIdempotencyConflictException('Kunci idempotensi sudah digunakan untuk catatan pesanan berbeda.');
        }

        // 2. Line items comparison (order-independent)
        $existingItems = $existingOrder->items()->with('modifiers')->get();
        $payloadItems = $orderPayload['items'] ?? [];

        if ($existingItems->count() !== count($payloadItems)) {
            throw new OrderIdempotencyConflictException('Kunci idempotensi sudah digunakan untuk pesanan dengan jumlah item berbeda.');
        }

        // Canonical signatures from payload
        $payloadSignatures = [];
        foreach ($payloadItems as $item) {
            $payloadSignatures[] = $this->buildItemSignature(
                productId: (int) $item['product_id'],
                variantId: ! empty($item['variant_id']) ? (int) $item['variant_id'] : null,
                qty: (int) ($item['quantity'] ?? 1),
                notes: $item['notes'] ?? null,
                modifierIds: $item['modifier_ids'] ?? []
            );
        }
        sort($payloadSignatures);

        // Canonical signatures from database
        $existingSignatures = [];
        foreach ($existingItems as $item) {
            $modIds = $item->modifiers->pluck('modifier_id')->filter()->map(fn ($id) => (int) $id)->toArray();
            $existingSignatures[] = $this->buildItemSignature(
                productId: (int) $item->product_id,
                variantId: $item->product_variant_id ? (int) $item->product_variant_id : null,
                qty: (int) $item->quantity,
                notes: $item->special_instructions,
                modifierIds: $modIds
            );
        }
        sort($existingSignatures);

        if ($payloadSignatures !== $existingSignatures) {
            throw new OrderIdempotencyConflictException('Kunci idempotensi sudah digunakan untuk pesanan dengan rincian item, varian, atau modifier berbeda.');
        }
    }

    /**
     * Transition order status through kitchen & service lifecycle.
     *
     * Permitted transitions (Phase 4):
     *   draft            -> awaiting_payment
     *   awaiting_payment -> paid | cancelled          (cancelled only via cancelOrder() with Owner auth)
     *   paid             -> queued | preparing        (NO cancellation — requires Phase 5 refund workflow)
     *   queued           -> preparing
     *   preparing        -> ready
     *   ready            -> completed
     *   completed        -> (terminal)
     *   cancelled        -> (terminal)
     */
    public function advanceStatus(Order $order, string $newStatus, User $user, ?string $notes = null): Order
    {
        $allowedTransitions = [
            Order::STATUS_DRAFT => [Order::STATUS_AWAITING_PAYMENT],
            Order::STATUS_AWAITING_PAYMENT => [Order::STATUS_PAID, Order::STATUS_CANCELLED],
            Order::STATUS_PAID => [Order::STATUS_QUEUED, Order::STATUS_PREPARING],
            Order::STATUS_QUEUED => [Order::STATUS_PREPARING],
            Order::STATUS_PREPARING => [Order::STATUS_READY],
            Order::STATUS_READY => [Order::STATUS_COMPLETED],
            Order::STATUS_COMPLETED => [],
            Order::STATUS_CANCELLED => [],
        ];

        $currentStatus = $order->status;
        $validTargets = $allowedTransitions[$currentStatus] ?? [];

        if (! in_array($newStatus, $validTargets, true)) {
            throw new InvalidArgumentException("Transisi status tidak diizinkan dari {$currentStatus} ke {$newStatus}.");
        }

        return DB::transaction(function () use ($order, $newStatus, $user, $notes, $currentStatus) {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $currentStatus,
                'to_status' => $newStatus,
                'user_id' => $user->id,
                'notes' => $notes ?: "Status diperbarui menjadi {$newStatus}.",
            ]);

            ActivityLog::record(
                action: 'order.status_changed',
                description: "Status pesanan {$order->order_number} diubah dari {$currentStatus} ke {$newStatus}.",
                properties: [
                    'order_id' => $order->id,
                    'from_status' => $currentStatus,
                    'to_status' => $newStatus,
                ]
            );

            return $order->fresh(['items.modifiers', 'statusHistories']);
        });
    }

    /**
     * Cancel an order. Owner-only authorisation required.
     *
     * Only orders in draft or awaiting_payment status may be cancelled.
     * Orders that have been paid, queued, preparing, ready, or completed cannot be
     * cancelled through this method. Paid orders require a formal refund workflow
     * (to be implemented in Phase 5) before cancellation is permitted.
     */
    public function cancelOrder(Order $order, User $user, string $reason): Order
    {
        if (! $user->isOwner()) {
            throw new InvalidArgumentException('Hanya Pemilik (Owner) yang memiliki wewenang untuk membatalkan pesanan.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan pembatalan pesanan wajib diisi.');
        }

        if ($order->isCancelled()) {
            throw new InvalidArgumentException('Pesanan ini sudah dalam status dibatalkan.');
        }

        if ($order->status === Order::STATUS_COMPLETED) {
            throw new InvalidArgumentException('Pesanan yang sudah selesai (completed) tidak dapat dibatalkan.');
        }

        // Block cancellation of paid and post-payment orders (Phase 5 will add a refund workflow).
        $paidPlusStatuses = [
            Order::STATUS_PAID,
            Order::STATUS_QUEUED,
            Order::STATUS_PREPARING,
            Order::STATUS_READY,
        ];
        if (in_array($order->status, $paidPlusStatuses, true)) {
            throw new InvalidArgumentException(
                'Pesanan yang sudah dibayar tidak dapat dibatalkan tanpa alur refund resmi. '.
                'Fitur pembatalan pesanan berbayar akan tersedia di Fase 5.'
            );
        }

        return DB::transaction(function () use ($order, $user, $reason) {
            $fromStatus = $order->status;

            DB::table('orders')->where('id', $order->id)->update([
                'status' => Order::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $user->id,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => Order::STATUS_CANCELLED,
                'user_id' => $user->id,
                'notes' => "Dibatalkan oleh Owner. Alasan: {$reason}",
            ]);

            ActivityLog::record(
                action: 'order.cancelled',
                description: "Pesanan {$order->order_number} dibatalkan oleh {$user->name}. Alasan: {$reason}",
                properties: [
                    'order_id' => $order->id,
                    'reason' => $reason,
                ]
            );

            return $order->fresh(['items.modifiers', 'statusHistories']);
        });
    }
}
