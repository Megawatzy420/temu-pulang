<?php

namespace App\Livewire\Pos;

use App\Exceptions\Order\OrderIdempotencyConflictException;
use App\Exceptions\Order\ShiftClosedForOrderException;
use App\Exceptions\Payment\InsufficientCashTenderedException;
use App\Exceptions\Payment\OrderAlreadyPaidException;
use App\Exceptions\Payment\OrderCancelledForPaymentException;
use App\Exceptions\Payment\PaymentIdempotencyConflictException;
use App\Exceptions\Payment\QrisConfirmationExpiredOrUsedException;
use App\Exceptions\Payment\QrisConfirmationInvalidException;
use App\Exceptions\Payment\ShiftAccessDeniedException;
use App\Exceptions\Payment\ShiftClosedForPaymentException;
use App\Models\ApplicationSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PrintService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashierPos extends Component
{
    // ── Session key constants ──────────────────────────────────────────────────

    private const SESSION_CHECKOUT_KEY = 'pos_active_checkout_key';

    private const SESSION_PAYMENT_INFLIGHT = 'pos_payment_inflight';

    /**
     * Common cash denomination steps (in integer Rupiah) for quick-amount suggestions.
     *
     * @var list<int>
     */
    private const CASH_DENOMINATION_STEPS = [5000, 10000, 20000, 50000, 100000];

    // ── Catalog & order-type state ─────────────────────────────────────────────

    /**
     * Active category slug filter ('all' for all).
     */
    public string $selectedCategory = 'all';

    /**
     * Search query for filtering products.
     */
    public string $search = '';

    /**
     * Dining mode: 'dine_in' or 'takeaway'.
     */
    public string $diningMode = 'dine_in';

    /**
     * Optional customer name for order call.
     */
    public string $customerName = '';

    /**
     * Optional order-level notes.
     */
    public string $orderNotes = '';

    /**
     * Active order cart items.
     *
     * @var list<array<string, mixed>>
     */
    public array $cart = [];

    // ── Customization modal state ──────────────────────────────────────────────

    /**
     * Customization modal state.
     */
    public bool $showCustomizeModal = false;

    /**
     * Product currently being customized.
     *
     * @var array<string, mixed>|null
     */
    public ?array $activeProduct = null;

    public ?int $selectedVariantId = null;

    public string $selectedVariantName = '';

    public int $selectedVariantPrice = 0;

    /**
     * Selected modifiers: [group_id => modifier_id]
     *
     * @var array<int, int>
     */
    public array $selectedModifiers = [];

    /**
     * Selected paid add-on modifier IDs.
     *
     * @var list<int>
     */
    public array $selectedAddons = [];

    public string $customNotes = '';

    // ── Payment processing state ───────────────────────────────────────────────

    public bool $isProcessingPayment = false;

    public string $paymentMethod = 'cash';

    public int $cashGiven = 0;

    public string $qrisReferenceNumber = '';

    public bool $qrisCashierConfirmed = false;

    public string $debitReferenceNumber = '';

    public bool $debitCashierConfirmed = false;

    // ── Modal visibility ───────────────────────────────────────────────────────

    public bool $showPaymentModal = false;

    public bool $showSuccessModal = false;

    // ── Recovery / unresolved state ────────────────────────────────────────────

    /**
     * Idempotency key for the current checkout attempt.
     * Generated once when the payment modal is opened for a given cart.
     * Reused on retries of the same checkout. Cleared after a successful order
     * or when the cashier starts a genuinely new order (cart cleared).
     */
    public string $checkoutIdempotencyKey = '';

    public bool $paymentInFlight = false;

    public bool $hasUnresolvedOrder = false;

    // ── Last completed transaction data (for success modal) ───────────────────

    public ?int $lastOrderId = null;

    public ?string $lastOrderNumber = null;

    public ?string $lastCallNumber = null;

    public ?int $lastTransactionId = null;

    public ?string $lastPaymentMethod = null;

    public ?int $lastTotal = null;

    public ?int $lastCashGiven = null;

    public ?int $lastChange = null;

    public ?int $lastPrintJobId = null;

    public ?string $lastPrintJobStatus = null;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Check active shift on mount. Cart starts empty.
     * Restore unresolved or completed checkout from session on browser reload.
     */
    public function mount(ShiftService $shiftService): void
    {
        $this->cart = [];

        // Restore unresolved or completed checkout from session on browser reload
        $storedKey = session(self::SESSION_CHECKOUT_KEY);
        $storedInFlight = session(self::SESSION_PAYMENT_INFLIGHT, false);

        if ($storedKey) {
            $this->checkoutIdempotencyKey = (string) $storedKey;
            $this->paymentInFlight = (bool) $storedInFlight;

            // Check if this key already has a completed payment in DB
            if ($this->restoreCompletedPaymentState((string) $storedKey)) {
                session([self::SESSION_PAYMENT_INFLIGHT => false]);
                $this->paymentInFlight = false;
                $this->hasUnresolvedOrder = false;
            } else {
                $existingOrder = Order::where('idempotency_key', (string) $storedKey)->first();
                if ($existingOrder && $existingOrder->payment_status === Order::PAYMENT_STATUS_UNPAID) {
                    $this->hasUnresolvedOrder = true;
                }
            }
        }
    }

    // ── Computed properties ────────────────────────────────────────────────────

    /**
     * Get active shift for current cashier/branch.
     */
    public function getActiveShiftProperty(): ?Shift
    {
        return app(ShiftService::class)->getActiveShift();
    }

    /**
     * Calculate Subtotal.
     */
    public function getSubtotalProperty(): int
    {
        return (int) collect($this->cart)->sum(fn ($item) => $item['unit_price'] * $item['quantity']);
    }

    /**
     * Calculate Restaurant Tax based on configured basis points in ShopSetting (default 0%).
     */
    public function getTaxProperty(): int
    {
        $taxRateBp = ShopSetting::where('is_active', true)->value('tax_rate') ?? 0;

        return (int) round(($this->getSubtotalProperty() * $taxRateBp) / 10000);
    }

    /**
     * Calculate Service Charge based on configured basis points in ShopSetting (default 0%).
     */
    public function getServiceChargeProperty(): int
    {
        $serviceRateBp = ShopSetting::where('is_active', true)->value('service_charge_rate') ?? 0;

        return (int) round(($this->getSubtotalProperty() * $serviceRateBp) / 10000);
    }

    /**
     * Calculate Final Total.
     */
    public function getTotalProperty(): int
    {
        return $this->getSubtotalProperty() + $this->getTaxProperty() + $this->getServiceChargeProperty();
    }

    // ── Product catalog actions ────────────────────────────────────────────────

    /**
     * Add product directly to cart or open customization modal if has options.
     */
    public function selectProduct(int $productId): void
    {
        if (! $this->getActiveShiftProperty()) {
            $this->dispatch('notify', message: __('messages.pos.shift_required_order'), type: 'warning');

            return;
        }

        $product = Product::with(['category', 'translations', 'variants.translations', 'modifierGroups.modifiers.translations'])
            ->find($productId);

        if (! $product) {
            return;
        }

        if ($product->is_sold_out) {
            $this->dispatch('notify', message: __('messages.pos.product_sold_out', ['name' => $product->name]), type: 'error');

            return;
        }

        // If product has variants or modifiers, open customization modal
        if ($product->has_variants || $product->modifierGroups->isNotEmpty()) {
            $this->openCustomizer($product);

            return;
        }

        // Otherwise add single-price product directly to cart
        $this->addItemToCart([
            'id' => 'item_'.uniqid(),
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'variant_name' => 'Standar',
            'variant_id' => null,
            'base_price' => $product->base_price,
            'unit_price' => $product->base_price,
            'quantity' => 1,
            'modifiers_text' => '',
            'modifier_ids' => [],
            'notes' => '',
        ]);
    }

    /**
     * Open product customizer modal.
     */
    protected function openCustomizer(Product $product): void
    {
        $this->activeProduct = [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'has_variants' => $product->has_variants,
            'base_price' => $product->base_price,
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'price' => $v->price,
                'is_sold_out' => $v->is_sold_out,
            ])->toArray(),
            'modifier_groups' => $product->modifierGroups->map(fn ($g) => [
                'id' => $g->id,
                'slug' => $g->slug,
                'name' => $g->name,
                'is_required' => $g->is_required,
                'modifiers' => $g->modifiers->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price' => $m->price,
                    'is_free' => $m->is_free,
                    'is_sold_out' => $m->is_sold_out,
                ])->toArray(),
            ])->toArray(),
        ];

        // Select default first available variant
        if ($product->has_variants && $product->variants->isNotEmpty()) {
            $firstVariant = $product->variants->firstWhere('is_sold_out', false) ?? $product->variants->first();
            $this->selectedVariantId = $firstVariant->id;
            $this->selectedVariantName = $firstVariant->name;
            $this->selectedVariantPrice = $firstVariant->price;
        } else {
            $this->selectedVariantId = null;
            $this->selectedVariantName = 'Standar';
            $this->selectedVariantPrice = $product->base_price;
        }

        // Initialize default required modifier selections
        $this->selectedModifiers = [];
        $this->selectedAddons = [];
        $this->customNotes = '';

        foreach ($product->modifierGroups as $group) {
            if ($group->is_required && $group->modifiers->isNotEmpty()) {
                $availableMod = $group->modifiers->firstWhere('is_sold_out', false) ?? $group->modifiers->first();
                $this->selectedModifiers[$group->id] = $availableMod->id;
            }
        }

        $this->showCustomizeModal = true;
    }

    /**
     * Choose variant in customizer.
     */
    public function setVariant(int $variantId, string $variantName, int $price, bool $isSoldOut = false): void
    {
        if ($isSoldOut) {
            $this->dispatch('notify', message: __('messages.pos.variant_sold_out'), type: 'warning');

            return;
        }

        $this->selectedVariantId = $variantId;
        $this->selectedVariantName = $variantName;
        $this->selectedVariantPrice = $price;
    }

    /**
     * Toggle paid add-on modifier.
     */
    public function toggleAddon(int $modifierId, bool $isSoldOut = false): void
    {
        if ($isSoldOut) {
            $this->dispatch('notify', message: __('messages.pos.modifier_sold_out'), type: 'warning');

            return;
        }

        if (in_array($modifierId, $this->selectedAddons, true)) {
            $this->selectedAddons = array_values(array_diff($this->selectedAddons, [$modifierId]));
        } else {
            $this->selectedAddons[] = $modifierId;
        }
    }

    /**
     * Confirm customized product and add to cart.
     */
    public function confirmCustomization(): void
    {
        if (! $this->activeProduct) {
            return;
        }

        $modifierLabels = [];
        $modifierIds = [];
        $addonTotal = 0;

        // Collect names and prices of selected modifiers
        foreach ($this->activeProduct['modifier_groups'] as $group) {
            foreach ($group['modifiers'] as $mod) {
                if (isset($this->selectedModifiers[$group['id']]) && $this->selectedModifiers[$group['id']] === $mod['id']) {
                    $modifierLabels[] = $mod['name'];
                    $modifierIds[] = $mod['id'];
                }
                if (in_array($mod['id'], $this->selectedAddons, true)) {
                    $modifierLabels[] = $mod['name'].' (+Rp '.number_format($mod['price'], 0, ',', '.').')';
                    $modifierIds[] = $mod['id'];
                    $addonTotal += $mod['price'];
                }
            }
        }

        $unitPrice = $this->selectedVariantPrice + $addonTotal;

        $this->resetDraftIdempotencyKey();

        $this->addItemToCart([
            'id' => 'item_'.uniqid(),
            'product_id' => $this->activeProduct['id'],
            'name' => $this->activeProduct['name'],
            'sku' => $this->activeProduct['sku'],
            'variant_name' => $this->selectedVariantName,
            'variant_id' => $this->selectedVariantId,
            'base_price' => $this->selectedVariantPrice,
            'unit_price' => $unitPrice,
            'quantity' => 1,
            'modifiers_text' => implode(', ', $modifierLabels),
            'modifier_ids' => $modifierIds,
            'notes' => trim($this->customNotes),
        ]);

        $this->showCustomizeModal = false;
        $this->activeProduct = null;
    }

    /**
     * Add item array to cart with notification.
     *
     * @param  array<string, mixed>  $item
     */
    protected function addItemToCart(array $item): void
    {
        $this->cart[] = $item;
        $this->resetDraftIdempotencyKey();
        $this->dispatch('notify', message: __('messages.pos.item_added', ['name' => $item['name']]), type: 'success');
    }

    // ── Cart mutation methods ──────────────────────────────────────────────────

    /**
     * Reset draft checkout idempotency key when cart changes,
     * unless payment is in-flight, an unpaid order is pending for the exact current cart,
     * or the key belongs to an already completed checkout.
     */
    protected function resetDraftIdempotencyKey(): void
    {
        if ($this->paymentInFlight) {
            return;
        }

        if ($this->hasUnresolvedOrder && $this->checkoutIdempotencyKey !== '' && $this->currentCartMatchesExistingOrder($this->checkoutIdempotencyKey)) {
            return;
        }

        $this->hasUnresolvedOrder = false;
        $this->checkoutIdempotencyKey = '';
        $currentSessionKey = (string) session(self::SESSION_CHECKOUT_KEY, '');
        if (! $this->isCheckoutCompleted($currentSessionKey)) {
            session()->forget(self::SESSION_CHECKOUT_KEY);
        }
    }

    /**
     * Increase item quantity.
     */
    public function increaseQty(int $index): void
    {
        if (isset($this->cart[$index])) {
            $this->cart[$index]['quantity']++;
            $this->resetDraftIdempotencyKey();
        }
    }

    /**
     * Decrease item quantity or remove if reaching 0.
     */
    public function decreaseQty(int $index): void
    {
        if (isset($this->cart[$index])) {
            if ($this->cart[$index]['quantity'] > 1) {
                $this->cart[$index]['quantity']--;
                $this->resetDraftIdempotencyKey();
            } else {
                $this->removeItem($index);
            }
        }
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(int $index): void
    {
        if (isset($this->cart[$index])) {
            array_splice($this->cart, $index, 1);
            $this->resetDraftIdempotencyKey();

            $this->dispatch('notify', message: __('messages.pos.item_removed'), type: 'info');
        }
    }

    /**
     * Clear all items in cart and reset the checkout idempotency key
     * so the next checkout generates a fresh key for a genuinely new order.
     */
    public function clearCart(): void
    {
        $this->cart = [];
        $this->resetDraftIdempotencyKey();

        $this->dispatch('notify', message: __('messages.pos.cart_cleared'), type: 'info');
    }

    /**
     * Hold current order.
     */
    public function holdOrder(): void
    {
        if (empty($this->cart)) {
            return;
        }

        $this->cart = [];
        $this->resetDraftIdempotencyKey();

        $this->dispatch('notify', message: __('messages.pos.order_held'), type: 'warning');
    }

    // ── Checkout ──────────────────────────────────────────────────────────────

    /**
     * Open checkout confirmation modal.
     * Generates a stable idempotency key for this cart if one has not been assigned yet.
     * Reopening the modal for the same cart reuses the existing key so retried submissions
     * are safely deduplicated by OrderService.
     */
    public function openCheckout(): void
    {
        // Block if payment is unresolved
        if ($this->paymentInFlight) {
            $this->dispatch('notify', message: __('messages.pos.payment_in_flight_warning'), type: 'warning');

            return;
        }

        $shift = $this->getActiveShiftProperty();
        if (! $shift) {
            $this->dispatch('notify', message: __('messages.pos.shift_not_active'), type: 'error');

            return;
        }

        if (empty($this->cart)) {
            $this->dispatch('notify', message: __('messages.pos.cart_empty_checkout'), type: 'warning');

            return;
        }

        // Generate a fresh idempotency key only when this cart does not have one yet,
        // if the current key has already been settled in a previous completed checkout,
        // or if the current key belongs to an existing order whose payload differs from the current cart.
        if (
            $this->checkoutIdempotencyKey === ''
            || $this->isCheckoutCompleted($this->checkoutIdempotencyKey)
            || $this->hasConflictingExistingOrder($this->checkoutIdempotencyKey)
        ) {
            $this->checkoutIdempotencyKey = (string) Str::uuid();
            $this->hasUnresolvedOrder = false;
            session([self::SESSION_CHECKOUT_KEY => $this->checkoutIdempotencyKey]);
        }

        // Set sensible default cash given
        $this->cashGiven = (int) ceil($this->getTotalProperty() / 50000) * 50000;
        if ($this->cashGiven < $this->getTotalProperty()) {
            $this->cashGiven = $this->getTotalProperty();
        }

        // Reset payment-method-specific fields
        $this->qrisReferenceNumber = '';
        $this->qrisCashierConfirmed = false;
        $this->debitReferenceNumber = '';
        $this->debitCashierConfirmed = false;

        $this->showPaymentModal = true;
    }

    /**
     * Process real payment using OrderService + PaymentService.
     */
    public function processPayment(OrderService $orderService, PaymentService $paymentService): void
    {
        // Guard: prevent double submission
        if ($this->isProcessingPayment) {
            return;
        }

        $shift = $this->getActiveShiftProperty();
        if (! $shift) {
            $this->dispatch('notify', message: __('messages.pos.shift_not_opened'), type: 'error');

            return;
        }

        if (empty($this->cart)) {
            $this->dispatch('notify', message: __('messages.pos.cart_empty_payment'), type: 'warning');

            return;
        }

        if (! $paymentService->userMayTakePaymentInShift(Auth::user(), $shift)) {
            $this->dispatch('notify', message: __('messages.pos.shift_access_denied'), type: 'error');

            return;
        }

        // Validate payment method inputs
        if ($this->paymentMethod === 'cash' && $this->cashGiven < $this->getTotalProperty()) {
            $this->dispatch('notify', message: __('messages.pos.insufficient_cash'), type: 'error');

            return;
        }

        if ($this->paymentMethod === 'qris' && ! $this->qrisCashierConfirmed) {
            $this->dispatch('notify', message: __('messages.pos.qris_not_confirmed'), type: 'error');

            return;
        }

        if ($this->paymentMethod === 'debit' && ! $this->debitCashierConfirmed) {
            $this->dispatch('notify', message: __('messages.pos.debit_not_confirmed'), type: 'error');

            return;
        }

        $this->isProcessingPayment = true;
        $this->paymentInFlight = true;
        session([
            self::SESSION_CHECKOUT_KEY => $this->checkoutIdempotencyKey,
            self::SESSION_PAYMENT_INFLIGHT => true,
        ]);

        try {
            // Ensure idempotency key exists, is not already completed, and does not conflict with a stale order
            if (
                $this->checkoutIdempotencyKey === ''
                || $this->isCheckoutCompleted($this->checkoutIdempotencyKey)
                || $this->hasConflictingExistingOrder($this->checkoutIdempotencyKey)
            ) {
                $this->checkoutIdempotencyKey = (string) Str::uuid();
                $this->hasUnresolvedOrder = false;
                session([self::SESSION_CHECKOUT_KEY => $this->checkoutIdempotencyKey]);
            }

            // Build order payload
            $orderPayload = $this->buildOrderPayload($this->checkoutIdempotencyKey);

            // Create order
            $order = $orderService->createOrder(Auth::user(), $shift, $orderPayload);

            // Build tenders
            $tenders = $this->buildTenders($order, $paymentService);

            // Settle payment using pay_-prefixed key
            $paymentIdempotencyKey = 'pay_'.$this->checkoutIdempotencyKey;
            $transaction = $paymentService->settlePayment(
                cashier: Auth::user(),
                shift: $shift,
                order: $order,
                tenders: $tenders,
                idempotencyKey: $paymentIdempotencyKey
            );

            // Grab the print job for status display
            $printJob = $order->printJobs()->latest()->first();

            // Store result for success modal
            $this->lastOrderId = $order->id;
            $this->lastOrderNumber = $order->order_number;
            $this->lastCallNumber = $order->call_number;
            $this->lastTransactionId = $transaction->id;
            $this->lastPaymentMethod = $this->paymentMethod;
            $this->lastTotal = $transaction->total_amount;
            $this->lastCashGiven = $this->paymentMethod === 'cash' ? $this->cashGiven : null;
            $this->lastChange = $this->paymentMethod === 'cash' ? ($this->cashGiven - $transaction->total_amount) : null;
            $this->lastPrintJobId = $printJob?->id;
            $this->lastPrintJobStatus = $printJob?->status;

            // Clear in-flight status, but PRESERVE the checkout key in session so it remains recoverable on reload
            session([self::SESSION_PAYMENT_INFLIGHT => false]);
            $this->paymentInFlight = false;
            $this->hasUnresolvedOrder = false;

            // Reset cart and checkout state
            $this->cart = [];
            $this->customerName = '';
            $this->orderNotes = '';
            $this->showPaymentModal = false;
            $this->showSuccessModal = true;

        } catch (\Throwable $e) {
            $errorMessage = $this->mapPaymentExceptionToMessage($e);
            $this->dispatch('notify', message: $errorMessage, type: 'error');

            // Safe validation failure — payment was rejected before any mutation
            if ($e instanceof \InvalidArgumentException || $e instanceof \DomainException) {
                $this->paymentInFlight = false;
                session()->forget(self::SESSION_PAYMENT_INFLIGHT);
            }
            // For other exceptions: keep paymentInFlight=true so cashier must investigate
        } finally {
            $this->isProcessingPayment = false;
        }
    }

    /**
     * Build payment tenders array for the given order and payment method.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTenders(Order $order, PaymentService $paymentService): array
    {
        $total = $order->total_amount;

        return match ($this->paymentMethod) {
            'cash' => [[
                'payment_method' => 'cash',
                'amount' => $total,
                'tendered_amount' => $this->cashGiven,
                'change_amount' => $this->cashGiven - $total,
                'reference_number' => null,
                'notes' => null,
            ]],
            'qris' => (function () use ($order, $total, $paymentService) {
                $qris = $paymentService->createQrisConfirmation(
                    cashier: Auth::user(),
                    order: $order,
                    expectedAmount: $total,
                    referenceNumber: $this->qrisReferenceNumber ?: null
                );

                return [[
                    'payment_method' => 'qris',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                    'qris_confirmation_id' => $qris->id,
                    'reference_number' => $this->qrisReferenceNumber ?: null,
                    'notes' => null,
                ]];
            })(),
            'debit' => [[
                'payment_method' => 'debit',
                'amount' => $total,
                'tendered_amount' => $total,
                'change_amount' => 0,
                'reference_number' => $this->debitReferenceNumber ?: null,
                'notes' => null,
            ]],
            default => throw new \InvalidArgumentException(__('messages.pos.unsupported_payment_method', ['method' => $this->paymentMethod])),
        };
    }

    // ── Recovery actions ──────────────────────────────────────────────────────

    /**
     * Check if a previously in-flight payment succeeded.
     */
    public function recoverPendingPayment(): void
    {
        if (empty($this->checkoutIdempotencyKey)) {
            $this->checkoutIdempotencyKey = (string) session(self::SESSION_CHECKOUT_KEY, '');
        }

        if (empty($this->checkoutIdempotencyKey)) {
            return;
        }

        // Check if payment already succeeded
        if ($this->restoreCompletedPaymentState($this->checkoutIdempotencyKey)) {
            $this->dispatch('notify', message: __('messages.pos.payment_recovered_success'), type: 'success');

            return;
        }

        // Check if order was created but not paid
        $existingOrder = Order::where('idempotency_key', $this->checkoutIdempotencyKey)->first();

        if ($existingOrder && $existingOrder->payment_status === Order::PAYMENT_STATUS_UNPAID) {
            if (! empty($this->cart) && ! $this->currentCartMatchesExistingOrder($this->checkoutIdempotencyKey)) {
                $this->paymentInFlight = false;
                $this->hasUnresolvedOrder = false;
                $this->checkoutIdempotencyKey = '';
                session()->forget([self::SESSION_CHECKOUT_KEY, self::SESSION_PAYMENT_INFLIGHT]);
                $this->dispatch('notify', message: __('messages.pos.unpaid_order_recovered_warning'), type: 'warning');

                return;
            }

            $this->paymentInFlight = false;
            $this->hasUnresolvedOrder = true;
            session([self::SESSION_PAYMENT_INFLIGHT => false]);
            $this->dispatch('notify', message: __('messages.pos.unpaid_order_recovered_warning'), type: 'warning');

            return;
        }

        // No order found — still keep the key; original request might still be in-flight
        $this->dispatch('notify', message: __('messages.pos.no_transaction_found_warning'), type: 'warning');
    }

    /**
     * Resume an unpaid order by reconstructing the cart and restoring the payment modal.
     */
    public function resumeUnpaidOrder(): void
    {
        if (empty($this->checkoutIdempotencyKey)) {
            $this->checkoutIdempotencyKey = (string) session(self::SESSION_CHECKOUT_KEY, '');
        }

        if (empty($this->checkoutIdempotencyKey)) {
            $this->dispatch('notify', message: __('messages.pos.no_active_key_to_recover'), type: 'warning');

            return;
        }

        $existingOrder = Order::with(['items.modifiers'])
            ->where('idempotency_key', $this->checkoutIdempotencyKey)
            ->first();

        if (! $existingOrder) {
            $this->dispatch('notify', message: __('messages.pos.order_not_found_in_system'), type: 'warning');

            return;
        }

        if ($existingOrder->payment_status !== Order::PAYMENT_STATUS_UNPAID) {
            if ($this->restoreCompletedPaymentState($this->checkoutIdempotencyKey)) {
                $this->dispatch('notify', message: __('messages.pos.order_already_paid'), type: 'success');
            }

            return;
        }

        // Reconstruct cart from persisted order and line item snapshots
        $this->cart = $existingOrder->items->map(function (OrderItem $item) {
            $modifierLabels = [];
            $modifierIds = [];

            foreach ($item->modifiers as $mod) {
                $modifierIds[] = (int) $mod->modifier_id;
                if ($mod->unit_price > 0) {
                    $modifierLabels[] = $mod->modifier_name.' (+Rp '.number_format($mod->unit_price, 0, ',', '.').')';
                } else {
                    $modifierLabels[] = $mod->modifier_name;
                }
            }

            return [
                'id' => 'item_'.uniqid(),
                'product_id' => (int) $item->product_id,
                'name' => (string) $item->product_name,
                'sku' => (string) ($item->sku ?? ''),
                'variant_name' => (string) ($item->variant_name ?? 'Standar'),
                'variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                'base_price' => (int) $item->unit_price,
                'unit_price' => (int) $item->final_unit_price,
                'quantity' => (int) $item->quantity,
                'modifiers_text' => implode(', ', $modifierLabels),
                'modifier_ids' => $modifierIds,
                'notes' => (string) ($item->special_instructions ?? ''),
            ];
        })->values()->all();

        // Restore order-level metadata
        $this->diningMode = $existingOrder->order_type;
        $this->customerName = (string) ($existingOrder->customer_name ?? '');
        $this->orderNotes = (string) ($existingOrder->notes ?? '');

        // Preserve checkout idempotency key
        $this->checkoutIdempotencyKey = $existingOrder->idempotency_key;
        session([self::SESSION_CHECKOUT_KEY => $this->checkoutIdempotencyKey]);

        // Clear in-flight lock so cashier can complete payment
        $this->paymentInFlight = false;
        $this->hasUnresolvedOrder = false;
        session([self::SESSION_PAYMENT_INFLIGHT => false]);

        // Restore payment modal with initial cash selection matching order total
        $this->paymentMethod = 'cash';
        $this->cashGiven = (int) $existingOrder->total_amount;
        $this->qrisReferenceNumber = '';
        $this->qrisCashierConfirmed = false;
        $this->debitReferenceNumber = '';
        $this->debitCashierConfirmed = false;
        $this->showPaymentModal = true;

        $this->dispatch('notify', message: __('messages.pos.unpaid_order_resumed'), type: 'info');
    }

    /**
     * Build the current cart order payload for OrderService.
     *
     * @return array{
     *     order_type: string,
     *     customer_name: string,
     *     notes: string,
     *     idempotency_key: string,
     *     items: array<int, array{
     *         product_id: mixed,
     *         variant_id: mixed,
     *         quantity: mixed,
     *         notes: mixed,
     *         modifier_ids: mixed
     *     }>
     * }
     */
    protected function buildOrderPayload(string $idempotencyKey): array
    {
        return [
            'order_type' => $this->diningMode,
            'customer_name' => $this->customerName,
            'notes' => $this->orderNotes,
            'idempotency_key' => $idempotencyKey,
            'items' => collect($this->cart)->map(fn ($item) => [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'quantity' => $item['quantity'],
                'notes' => $item['notes'],
                'modifier_ids' => $item['modifier_ids'] ?? [],
            ])->toArray(),
        ];
    }

    /**
     * Check whether an existing order with the given key matches the current cart payload.
     */
    protected function currentCartMatchesExistingOrder(string $key): bool
    {
        if ($key === '' || empty($this->cart)) {
            return false;
        }

        $existingOrder = Order::with(['items.modifiers'])->where('idempotency_key', $key)->first();
        if (! $existingOrder) {
            return true;
        }

        try {
            app(OrderService::class)->assertPayloadMatchesExistingOrder($existingOrder, $this->buildOrderPayload($key));

            return true;
        } catch (OrderIdempotencyConflictException|ConflictHttpException) {
            return false;
        }
    }

    /**
     * Determine whether the given key belongs to an existing order in DB whose payload conflicts with the current cart.
     */
    protected function hasConflictingExistingOrder(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $existingOrder = Order::with(['items.modifiers'])->where('idempotency_key', $key)->first();
        if (! $existingOrder) {
            return false;
        }

        try {
            app(OrderService::class)->assertPayloadMatchesExistingOrder($existingOrder, $this->buildOrderPayload($key));

            return false;
        } catch (OrderIdempotencyConflictException|ConflictHttpException) {
            return true;
        }
    }

    /**
     * Check if a given checkout idempotency key has already been settled.
     */
    protected function isCheckoutCompleted(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        if (PaymentTransaction::where('idempotency_key', 'pay_'.$key)->exists()) {
            return true;
        }

        return Order::where('idempotency_key', $key)
            ->where('payment_status', Order::PAYMENT_STATUS_PAID)
            ->exists();
    }

    /**
     * Restore completed payment state into component properties from persisted DB records.
     */
    protected function restoreCompletedPaymentState(string $checkoutKey): bool
    {
        if ($checkoutKey === '') {
            return false;
        }

        $paymentKey = 'pay_'.$checkoutKey;
        $transaction = PaymentTransaction::with(['order.printJobs', 'payments'])
            ->where('idempotency_key', $paymentKey)
            ->first();

        $order = $transaction?->order;
        if (! $order) {
            $order = Order::with(['paymentTransaction.payments', 'printJobs'])
                ->where('idempotency_key', $checkoutKey)
                ->where('payment_status', Order::PAYMENT_STATUS_PAID)
                ->first();
            $transaction = $order?->paymentTransaction;
        }

        if (! $order || ! $transaction) {
            return false;
        }

        $printJob = $order->printJobs()->latest()->first();
        $cashPayment = $transaction->payments->firstWhere('payment_method', 'cash');

        $this->lastOrderId = $order->id;
        $this->lastOrderNumber = $order->order_number;
        $this->lastCallNumber = $order->call_number;
        $this->lastTransactionId = $transaction->id;
        $this->lastTotal = (int) $transaction->total_amount;
        $this->lastPaymentMethod = $transaction->payments->first()?->payment_method;
        $this->lastCashGiven = $cashPayment?->tendered_amount ? (int) $cashPayment->tendered_amount : null;
        $this->lastChange = $cashPayment?->change_amount !== null ? (int) $cashPayment->change_amount : null;
        $this->lastPrintJobId = $printJob?->id;
        $this->lastPrintJobStatus = $printJob?->status;

        $this->paymentInFlight = false;
        $this->hasUnresolvedOrder = false;
        $this->showSuccessModal = true;
        $this->showPaymentModal = false;
        $this->cart = [];

        return true;
    }

    // ── Print status & reprint ────────────────────────────────────────────────

    /**
     * Poll for print job status update.
     */
    public function refreshPrintStatus(): void
    {
        if (! $this->showSuccessModal || ! $this->lastPrintJobId) {
            return;
        }

        $printJob = PrintJob::find($this->lastPrintJobId);
        if ($printJob) {
            $this->lastPrintJobStatus = $printJob->status;
        }
    }

    /**
     * Request a reprint of the last receipt.
     */
    public function reprintReceipt(PrintService $printService): void
    {
        if (! $this->lastPrintJobId) {
            return;
        }

        $printJob = PrintJob::findOrFail($this->lastPrintJobId);
        $newJob = $printService->queueReprint($printJob, Auth::user());
        $this->lastPrintJobId = $newJob->id;
        $this->lastPrintJobStatus = $newJob->status;
        $this->dispatch('notify', message: __('messages.pos.reprint_queued_success'), type: 'success');
    }

    /**
     * Retry a failed print job.
     */
    public function retryPrint(PrintService $printService): void
    {
        if (! $this->lastPrintJobId) {
            return;
        }

        $printJob = PrintJob::findOrFail($this->lastPrintJobId);

        try {
            $printService->retryFailedJob($printJob);
            $this->lastPrintJobStatus = 'pending';
            $this->dispatch('notify', message: __('messages.pos.retry_print_queued_success'), type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: $this->mapPaymentExceptionToMessage($e), type: 'error');
        }
    }

    // ── Success modal ─────────────────────────────────────────────────────────

    /**
     * Close the success modal and reset last-transaction data for a new order.
     */
    public function closeSuccessModal(): void
    {
        $this->showSuccessModal = false;
        $this->lastOrderId = null;
        $this->lastOrderNumber = null;
        $this->lastCallNumber = null;
        $this->lastTransactionId = null;
        $this->lastPaymentMethod = null;
        $this->lastTotal = null;
        $this->lastCashGiven = null;
        $this->lastChange = null;
        $this->lastPrintJobId = null;
        $this->lastPrintJobStatus = null;
        $this->hasUnresolvedOrder = false;
        $this->checkoutIdempotencyKey = '';
    }

    // ── Render ────────────────────────────────────────────────────────────────

    /**
     * Render the Livewire component view.
     */
    public function render(): View
    {
        $activeShift = $this->getActiveShiftProperty();

        // 1. Fetch real categories from database
        $categories = Category::active()->ordered()->with('translations')->get();

        // 2. Fetch real products from database
        $productsQuery = Product::active()
            ->with(['category', 'translations', 'variants.translations', 'modifierGroups'])
            ->ordered();

        if ($this->selectedCategory !== 'all') {
            $productsQuery->whereHas('category', fn ($q) => $q->where('slug', $this->selectedCategory));
        }

        if (trim($this->search) !== '') {
            $keyword = '%'.trim($this->search).'%';
            $productsQuery->where(function ($q) use ($keyword) {
                $q->where('sku', 'like', $keyword)
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $keyword));
            });
        }

        $realProducts = $productsQuery->get();

        $qrisImagePath = (string) ApplicationSetting::get('payment.qris_image_path', '');
        $total = $this->getTotalProperty();

        return view('livewire.pos.cashier-pos', [
            'activeShift' => $activeShift,
            'categories' => $categories,
            'realProducts' => $realProducts,
            'subtotal' => $this->getSubtotalProperty(),
            'tax' => $this->getTaxProperty(),
            'serviceCharge' => $this->getServiceChargeProperty(),
            'total' => $total,
            'cashSuggestions' => self::suggestCashAmounts($total),
            'paymentInFlight' => $this->paymentInFlight,
            'qrisImagePath' => $qrisImagePath,
        ])->layout('components.layouts.app', [
            'title' => __('messages.nav.pos'),
            'headerTitle' => __('messages.pos.terminal_title'),
            'headerSubtitle' => $activeShift
                ? __('messages.pos.header_active_cashier', ['user' => $activeShift->user->name, 'id' => $activeShift->id])
                : __('messages.pos.header_no_active_shift'),
        ]);
    }

    /**
     * Map a payment or print exception to a user-friendly localized message by exception class.
     */
    protected function mapPaymentExceptionToMessage(\Throwable $e): string
    {
        if (
            $e instanceof OrderIdempotencyConflictException
            || $e instanceof PaymentIdempotencyConflictException
            || $e instanceof ConflictHttpException
        ) {
            return __('messages.pos.error_conflict');
        }

        if ($e instanceof ShiftClosedForOrderException || $e instanceof ShiftClosedForPaymentException) {
            return __('messages.pos.shift_closed_error');
        }

        if ($e instanceof ShiftAccessDeniedException) {
            return __('messages.pos.shift_access_denied');
        }

        if ($e instanceof InsufficientCashTenderedException) {
            return __('messages.pos.insufficient_cash');
        }

        if ($e instanceof QrisConfirmationExpiredOrUsedException) {
            return __('messages.pos.qris_expired_or_used');
        }

        if ($e instanceof QrisConfirmationInvalidException) {
            return __('messages.pos.qris_confirmation_invalid');
        }

        if ($e instanceof OrderAlreadyPaidException) {
            return __('messages.pos.order_already_paid');
        }

        if ($e instanceof OrderCancelledForPaymentException) {
            return __('messages.pos.order_cancelled_error');
        }

        Log::error('POS exception: '.$e->getMessage(), [
            'exception' => $e,
            'checkout_key' => $this->checkoutIdempotencyKey,
            'user_id' => Auth::id(),
        ]);

        return __('messages.pos.payment_failed_generic');
    }

    /**
     * Compute up to 4 unique, ascending quick-cash suggestion amounts
     * strictly greater than the given order total using pure integer arithmetic.
     *
     * @return list<int>
     */
    public static function suggestCashAmounts(int $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $suggestions = [];

        foreach (self::CASH_DENOMINATION_STEPS as $step) {
            $rounded = intdiv($total + $step - 1, $step) * $step;

            if ($rounded > $total) {
                $suggestions[] = $rounded;
            }
        }

        $suggestions = array_values(array_unique($suggestions));
        sort($suggestions);

        return array_slice($suggestions, 0, 4);
    }
}
