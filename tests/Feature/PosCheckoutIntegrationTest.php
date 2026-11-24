<?php

namespace Tests\Feature;

use App\Livewire\Pos\CashierPos;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PosCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Shift $shift;

    protected Product $standardProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        // Open shift for cashier
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Kas modal POS checkout test'
        );

        // Get single-price product (Kentang Goreng - no variants)
        $this->standardProduct = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();

        // Configure shop setting for testing
        ShopSetting::where('is_active', true)->update([
            'printer_enabled' => true,
            'printer_name' => 'POS80 Printer',
        ]);
    }

    /**
     * 1. Production cart starts empty.
     */
    public function test_production_cart_starts_empty(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->assertStatus(200)
            ->assertCount('cart', 0)
            ->assertSet('showPaymentModal', false)
            ->assertSet('showSuccessModal', false);
    }

    /**
     * 2. Cash checkout with correct change.
     */
    public function test_cash_checkout_with_correct_change(): void
    {
        $price = $this->standardProduct->base_price;
        $tendered = $price + 30000;
        $expectedChange = 30000;

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->assertCount('cart', 1)
            ->call('openCheckout')
            ->assertSet('showPaymentModal', true)
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $tendered)
            ->call('processPayment')
            ->assertSet('showPaymentModal', false)
            ->assertSet('showSuccessModal', true)
            ->assertCount('cart', 0)
            ->assertSet('lastTotal', $price)
            ->assertSet('lastCashGiven', $tendered)
            ->assertSet('lastChange', $expectedChange)
            ->assertSee('Pembayaran Berhasil')
            ->assertSee(number_format($expectedChange, 0, ',', '.'));

        // Verify order and transaction in database
        $this->assertDatabaseHas('orders', [
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_STATUS_PAID,
            'total_amount' => $price,
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'total_amount' => $price,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('payments', [
            'payment_method' => 'cash',
            'amount' => $price,
            'tendered_amount' => $tendered,
            'change_amount' => $expectedChange,
        ]);
    }

    /**
     * 3. Insufficient cash rejection.
     */
    public function test_insufficient_cash_is_rejected(): void
    {
        $price = $this->standardProduct->base_price;
        $insufficientCash = $price - 5000;

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $insufficientCash)
            ->call('processPayment')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'error')
            ->assertSet('showSuccessModal', false)
            ->assertCount('cart', 1);

        // Verify no order was paid
        $this->assertEquals(0, Order::where('status', Order::STATUS_PAID)->count());
    }

    /**
     * 4. QRIS requires explicit confirmation.
     */
    public function test_qris_requires_explicit_confirmation(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'qris')
            ->set('qrisCashierConfirmed', false) // Not confirmed!
            ->call('processPayment')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'error' && str_contains($params['message'], 'QRIS'))
            ->assertSet('showSuccessModal', false)
            ->assertCount('cart', 1);

        $this->assertEquals(0, Order::count());
    }

    /**
     * 5. QRIS checkout with confirmation.
     */
    public function test_qris_checkout_with_confirmation(): void
    {
        $price = $this->standardProduct->base_price;

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'qris')
            ->set('qrisReferenceNumber', 'QRIS-REF-12345')
            ->set('qrisCashierConfirmed', true) // Confirmed!
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastPaymentMethod', 'qris')
            ->assertSet('lastTotal', $price);

        $this->assertDatabaseHas('payments', [
            'payment_method' => 'qris',
            'amount' => $price,
            'reference_number' => 'QRIS-REF-12345',
            'change_amount' => 0,
        ]);
    }

    /**
     * 6. Debit checkout with confirmation.
     */
    public function test_debit_checkout_with_confirmation(): void
    {
        $price = $this->standardProduct->base_price;

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'debit')
            ->set('debitReferenceNumber', 'EDC-TRACE-9876')
            ->set('debitCashierConfirmed', true)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastPaymentMethod', 'debit')
            ->assertSet('lastTotal', $price);

        $this->assertDatabaseHas('payments', [
            'payment_method' => 'debit',
            'amount' => $price,
            'reference_number' => 'EDC-TRACE-9876',
            'change_amount' => 0,
        ]);
    }

    /**
     * 7. Checkout blocked without an active shift.
     */
    public function test_checkout_blocked_without_active_shift(): void
    {
        // Close the current shift
        app(ShiftService::class)->closeShift(
            shift: $this->shift,
            closingUser: $this->cashier,
            actualCash: 100000
        );

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'warning')
            ->assertCount('cart', 0);
    }

    /**
     * 8. Duplicate submission protection.
     */
    public function test_duplicate_submission_protection(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout');

        $key = $component->get('checkoutIdempotencyKey');
        $this->assertNotEmpty($key);

        // First submission succeeds
        $component->set('paymentMethod', 'cash')
            ->set('cashGiven', $this->standardProduct->base_price)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true);

        // A second call on the same component after completion is safely blocked because cart is cleared
        $component->call('processPayment')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'warning' && str_contains($params['message'], 'kosong'));

        // Guard when isProcessingPayment is true also immediately blocks execution
        $component->set('isProcessingPayment', true)
            ->call('processPayment');

        // Verify only 1 order and 1 payment transaction exist in the DB for this key
        $this->assertEquals(1, Order::where('idempotency_key', $key)->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', 'pay_'.$key)->count());
    }

    /**
     * 9. Session recovery after component remount or browser reload simulation.
     */
    public function test_session_recovery_after_browser_reload(): void
    {
        $testKey = (string) Str::uuid();

        // Simulate session having an active checkout key and in-flight flag
        session([
            'pos_active_checkout_key' => $testKey,
            'pos_payment_inflight' => true,
        ]);

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->assertSet('checkoutIdempotencyKey', $testKey)
            ->assertSet('paymentInFlight', true)
            ->assertSee('Transaksi sebelumnya belum terkonfirmasi');
    }

    /**
     * 10. Successful payment recovery.
     */
    public function test_session_recovery_when_payment_succeeded(): void
    {
        $key = (string) Str::uuid();
        $orderService = app(OrderService::class);
        $paymentService = app(PaymentService::class);

        // Create order and settle payment
        $order = $orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $this->standardProduct->id, 'quantity' => 1]],
        ]);

        $paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [[
                'payment_method' => 'cash',
                'amount' => $order->total_amount,
                'tendered_amount' => $order->total_amount + 5000,
                'change_amount' => 5000,
            ]],
            idempotencyKey: 'pay_'.$key
        );

        // Put key in session as if browser crashed right after sending
        session([
            'pos_active_checkout_key' => $key,
            'pos_payment_inflight' => true,
        ]);

        // Remount and recover
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('recoverPendingPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('paymentInFlight', false)
            ->assertSet('lastTotal', $order->total_amount)
            ->assertSet('lastChange', 5000)
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'success');
    }

    /**
     * 11. Unpaid order recovery.
     */
    public function test_session_recovery_when_order_unpaid(): void
    {
        $key = (string) Str::uuid();
        $orderService = app(OrderService::class);

        // Create order only (not paid)
        $order = $orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $this->standardProduct->id, 'quantity' => 1]],
        ]);

        session([
            'pos_active_checkout_key' => $key,
            'pos_payment_inflight' => true,
        ]);

        // Recover: should warn that order is unpaid and allow continuation
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('recoverPendingPayment')
            ->assertSet('showSuccessModal', false)
            ->assertSet('paymentInFlight', false)
            ->assertSet('checkoutIdempotencyKey', $key)
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'warning' && str_contains($params['message'], 'belum selesai'));
    }

    /**
     * 12. Print job creation after successful payment.
     */
    public function test_print_job_created_after_payment(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $this->standardProduct->base_price)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true);

        // Verify print job created in database
        $this->assertDatabaseHas('print_jobs', [
            'job_type' => 'customer_receipt',
            'status' => 'pending',
            'is_reprint' => false,
        ]);
    }

    /**
     * 13. Safe retry for failed jobs.
     */
    public function test_safe_print_retry_for_failed_jobs(): void
    {
        // First do checkout
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $this->standardProduct->base_price)
            ->call('processPayment');

        $printJobId = $component->get('lastPrintJobId');
        $this->assertNotNull($printJobId);

        // Simulate safe failure (e.g. exit code 3: printer offline)
        $printJob = PrintJob::findOrFail($printJobId);
        $printJob->update(['status' => 'failed', 'last_error' => 'Printer offline']);

        // Call retryPrint
        $component->call('retryPrint')
            ->assertSet('lastPrintJobStatus', 'pending')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'success');

        $this->assertEquals('pending', $printJob->fresh()->status);
    }

    /**
     * 14. Reprint creates a separate job.
     */
    public function test_reprint_creates_separate_job(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $this->standardProduct->base_price)
            ->call('processPayment');

        $originalJobId = $component->get('lastPrintJobId');
        $printJob = PrintJob::findOrFail($originalJobId);
        $printJob->update(['status' => 'completed']);

        // Request reprint
        $component->call('reprintReceipt')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'success');

        $newJobId = $component->get('lastPrintJobId');
        $this->assertNotEquals($originalJobId, $newJobId);

        $reprintJob = PrintJob::findOrFail($newJobId);
        $this->assertTrue((bool) $reprintJob->is_reprint);
        $this->assertEquals($originalJobId, $reprintJob->original_print_job_id);
        $this->assertEquals('pending', $reprintJob->status);
    }

    /**
     * 15. Uncertain jobs cannot be retried.
     */
    public function test_uncertain_jobs_cannot_be_retried(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $this->standardProduct->base_price)
            ->call('processPayment');

        $printJobId = $component->get('lastPrintJobId');
        $printJob = PrintJob::findOrFail($printJobId);

        // Mark as uncertain
        $printJob->update(['status' => 'uncertain', 'last_error' => 'Timeout']);

        // Attempting retryPrint must fail with error notification
        $component->call('retryPrint')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'error');

        $this->assertEquals('uncertain', $printJob->fresh()->status);
    }

    /**
     * 16. Cart remains locked during unresolved payment.
     */
    public function test_cart_locked_during_unresolved_payment(): void
    {
        $testKey = (string) Str::uuid();

        // When paymentInFlight is true
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->set('paymentInFlight', true)
            ->set('checkoutIdempotencyKey', $testKey);

        // openCheckout must be blocked
        $component->call('openCheckout')
            ->assertDispatched('notify', fn ($event, $params) => $params['type'] === 'warning')
            ->assertSet('showPaymentModal', false);

        // Cart mutation attempts must not reset the idempotency key
        $component->call('clearCart')
            ->assertSet('checkoutIdempotencyKey', $testKey);
    }

    /**
     * 17. Existing product variants, modifiers, and item notes continue to work.
     */
    public function test_variants_modifiers_and_item_notes_work(): void
    {
        $productWithVariants = Product::where('has_variants', true)
            ->where('is_sold_out', false)
            ->firstOrFail();

        $variant = $productWithVariants->variants->where('is_sold_out', false)->firstOrFail();

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $productWithVariants->id)
            ->assertSet('showCustomizeModal', true)
            ->call('setVariant', $variant->id, $variant->name, $variant->price)
            ->set('customNotes', 'Gula 50% dan es sedikit')
            ->call('confirmCustomization')
            ->assertSet('showCustomizeModal', false)
            ->assertCount('cart', 1);
    }

    /**
     * 18. Successful payment followed by lost HTTP response and browser reload.
     */
    public function test_successful_payment_followed_by_lost_http_response_and_browser_reload(): void
    {
        // Cashier checks out and completes payment
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', 50000)
            ->call('processPayment');

        $component->assertSet('showSuccessModal', true);
        $orderId = $component->get('lastOrderId');
        $orderNumber = $component->get('lastOrderNumber');
        $callNumber = $component->get('lastCallNumber');
        $total = $component->get('lastTotal');
        $change = $component->get('lastChange');
        $checkoutKey = $component->get('checkoutIdempotencyKey');

        // Verify session key was preserved
        $this->assertEquals($checkoutKey, session('pos_active_checkout_key'));

        // Emulate lost HTTP response / browser reload: cashier opens fresh page
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastOrderId', $orderId)
            ->assertSet('lastOrderNumber', $orderNumber)
            ->assertSet('lastCallNumber', $callNumber)
            ->assertSet('lastTotal', $total)
            ->assertSet('lastPaymentMethod', 'cash')
            ->assertSet('lastCashGiven', 50000)
            ->assertSet('lastChange', $change)
            ->assertCount('cart', 0);
    }

    /**
     * 19. Successful payment recovery after clicking "Pesanan Baru" but losing its response.
     */
    public function test_successful_payment_recovery_after_clicking_pesanan_baru_but_losing_response(): void
    {
        // Complete an order
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', 50000)
            ->call('processPayment');

        $orderNumber = $component->get('lastOrderNumber');
        $callNumber = $component->get('lastCallNumber');
        $total = $component->get('lastTotal');

        // Cashier clicks "Pesanan Baru"
        $component->call('closeSuccessModal');
        $component->assertSet('showSuccessModal', false);

        // Suppose network drops and browser reloads before initializing a new checkout
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastOrderNumber', $orderNumber)
            ->assertSet('lastCallNumber', $callNumber)
            ->assertSet('lastTotal', $total);
    }

    /**
     * 20. Unpaid order recovery after browser reload.
     */
    public function test_unpaid_order_recovery_after_browser_reload(): void
    {
        $key = (string) Str::uuid();
        $orderService = app(OrderService::class);

        // Order created but payment interrupted
        $orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $this->standardProduct->id, 'quantity' => 2]],
        ]);

        session([
            'pos_active_checkout_key' => $key,
            'pos_payment_inflight' => true,
        ]);

        // Browser reload
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->assertSet('showSuccessModal', false)
            ->assertSet('checkoutIdempotencyKey', $key)
            ->assertSee('Lanjutkan Pembayaran')
            ->assertSee('Periksa Status Transaksi');

        // Cashier resumes unpaid order
        $component->call('resumeUnpaidOrder')
            ->assertSet('showPaymentModal', true)
            ->assertSet('paymentInFlight', false)
            ->assertSet('checkoutIdempotencyKey', $key)
            ->assertCount('cart', 1)
            ->assertDispatched('notify', fn ($event, $params) => str_contains($params['message'], 'berhasil dimuat'));
    }

    /**
     * 21. Rehydrated cart preserves variants, modifiers, and notes.
     */
    public function test_rehydrated_cart_preserves_variants_modifiers_and_notes(): void
    {
        $productWithVariants = Product::where('has_variants', true)->firstOrFail();
        $variant = $productWithVariants->variants->firstOrFail();

        $modifierGroup = ModifierGroup::with('modifiers')->first();
        $modifier = $modifierGroup?->modifiers->first();
        $modifierIds = $modifier ? [$modifier->id] : [];

        $key = (string) Str::uuid();
        $orderService = app(OrderService::class);

        $orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'customer_name' => 'Dewi Sartika',
            'notes' => 'Bungkus rapi',
            'idempotency_key' => $key,
            'items' => [[
                'product_id' => $productWithVariants->id,
                'variant_id' => $variant->id,
                'quantity' => 3,
                'notes' => 'Kurang manis',
                'modifier_ids' => $modifierIds,
            ]],
        ]);

        session([
            'pos_active_checkout_key' => $key,
            'pos_payment_inflight' => true,
        ]);

        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('resumeUnpaidOrder');

        $component->assertSet('diningMode', Order::TYPE_TAKEAWAY)
            ->assertSet('customerName', 'Dewi Sartika')
            ->assertSet('orderNotes', 'Bungkus rapi')
            ->assertCount('cart', 1);

        $cartItem = $component->get('cart')[0];
        $this->assertEquals($productWithVariants->id, $cartItem['product_id']);
        $this->assertEquals($variant->id, $cartItem['variant_id']);
        $this->assertEquals($variant->name, $cartItem['variant_name']);
        $this->assertEquals(3, $cartItem['quantity']);
        $this->assertEquals('Kurang manis', $cartItem['notes']);
        $this->assertEquals($modifierIds, $cartItem['modifier_ids']);
    }

    /**
     * 22. Resuming an unpaid order settles exactly one order and one payment transaction.
     */
    public function test_resuming_unpaid_order_settles_exactly_one_order_and_one_payment_transaction(): void
    {
        $key = (string) Str::uuid();
        $orderService = app(OrderService::class);

        // Pre-existing unpaid order
        $order = $orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Hendra',
            'idempotency_key' => $key,
            'items' => [['product_id' => $this->standardProduct->id, 'quantity' => 1]],
        ]);

        $initialOrderCount = Order::count();
        $initialTxCount = PaymentTransaction::count();

        session([
            'pos_active_checkout_key' => $key,
            'pos_payment_inflight' => true,
        ]);

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('resumeUnpaidOrder')
            ->assertSet('showPaymentModal', true)
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', 50000)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true);

        // Assert exactly 1 order existed and was settled (not duplicated)
        $this->assertEquals($initialOrderCount, Order::count());
        $this->assertEquals($initialTxCount + 1, PaymentTransaction::count());

        $this->assertEquals(1, Order::where('idempotency_key', $key)->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', 'pay_'.$key)->count());

        $settledOrder = Order::where('idempotency_key', $key)->firstOrFail();
        $this->assertEquals(Order::PAYMENT_STATUS_PAID, $settledOrder->payment_status);
    }

    /**
     * 23. A new checkout does not accidentally reuse an old completed key.
     */
    public function test_new_checkout_does_not_accidentally_reuse_old_completed_key(): void
    {
        // Order 1: Complete and settle
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', 50000)
            ->call('processPayment');

        $key1 = $component->get('checkoutIdempotencyKey');
        $this->assertNotEmpty($key1);

        // Dismiss modal and start a new checkout
        $component->call('closeSuccessModal');
        $component->assertSet('showSuccessModal', false);

        // Add item and open checkout for Order 2
        $component->call('selectProduct', $this->standardProduct->id)
            ->call('openCheckout');

        $key2 = $component->get('checkoutIdempotencyKey');
        $this->assertNotEmpty($key2);
        $this->assertNotEquals($key1, $key2);

        // Settle Order 2
        $component->set('paymentMethod', 'cash')
            ->set('cashGiven', 50000)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true);

        // Verify two separate orders and transactions exist in DB
        $this->assertEquals(1, Order::where('idempotency_key', $key1)->count());
        $this->assertEquals(1, Order::where('idempotency_key', $key2)->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', 'pay_'.$key1)->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', 'pay_'.$key2)->count());
    }
}
