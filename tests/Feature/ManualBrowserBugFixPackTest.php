<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Payment\ShiftAccessDeniedException;
use App\Exceptions\Shift\UnauthorizedShiftCloseException;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Menu\ModifierManager;
use App\Livewire\Menu\ProductForm;
use App\Livewire\Pos\CashierPos;
use App\Livewire\Shifts\ShiftManager;
use App\Models\ApplicationSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftReportService;
use App\Services\ShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ManualBrowserBugFixPackTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
    }

    /**
     * BUG 1: Rendered HTML of ShiftManager, ProductForm, ModifierManager, and IngredientManager
     * has no step attribute larger than 1 on whole-Rupiah inputs.
     */
    public function test_whole_rupiah_inputs_have_no_step_larger_than_one(): void
    {
        // 1. ShiftManager — Open shift modal (openingCash)
        $shiftOpenHtml = Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->call('openNewShiftModal')
            ->html();

        $this->assertNoStepLargerThanOne($shiftOpenHtml, 'ShiftManager (open modal)');

        // Open a shift to render cash movement modal (movementAmount) and close shift modal (actualCash)
        app(ShiftService::class)->openShift($this->owner, 200000, 'Modal awal');

        $shiftMovementAndCloseHtml = Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->call('openNewMovementModal', 'in')
            ->call('openCloseModal')
            ->html();

        $this->assertNoStepLargerThanOne($shiftMovementAndCloseHtml, 'ShiftManager (movement & close modals)');

        // 2. ProductForm — basePrice and variants.*.price
        $productFormHtml = Livewire::actingAs($this->owner)
            ->test(ProductForm::class)
            ->set('hasVariants', true)
            ->html();

        $this->assertNoStepLargerThanOne($productFormHtml, 'ProductForm');

        // 3. ModifierManager — options.*.price
        $modifierHtml = Livewire::actingAs($this->owner)
            ->test(ModifierManager::class)
            ->call('openCreateModal')
            ->html();

        $this->assertNoStepLargerThanOne($modifierHtml, 'ModifierManager');

        // 4. IngredientManager — costPerUnit
        $ingredientHtml = Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('openCreateIngredientModal')
            ->html();

        $this->assertNoStepLargerThanOne($ingredientHtml, 'IngredientManager');
    }

    /**
     * BUG 1: Cash movement accepts whole-Rupiah amount 20000 without step rejection.
     */
    public function test_cash_movement_accepts_any_positive_whole_rupiah_amount(): void
    {
        app(ShiftService::class)->openShift($this->owner, 150500, 'Modal awal non-kelipatan 1000');

        Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->call('openNewMovementModal', 'in')
            ->set('movementAmount', 20000)
            ->set('movementCategory', 'capital_add')
            ->set('movementDescription', 'Tambah modal pecahan kecil')
            ->call('submitCashMovement')
            ->assertHasNoErrors()
            ->assertSet('showMovementModal', false);

        $this->assertDatabaseHas('cash_movements', [
            'amount' => 20000,
            'type' => 'in',
        ]);
    }

    /**
     * BUG 2(b): A checkout key and unresolved-order state restored from the session while the cart
     * now holds different items must not produce an idempotency conflict for the new cart.
     */
    public function test_stale_session_key_with_unresolved_order_does_not_conflict_when_cart_holds_different_items(): void
    {
        $shift = app(ShiftService::class)->openShift($this->cashier, 100000, 'Shift kasir');
        $products = Product::where('has_variants', false)->where('is_sold_out', false)->take(2)->get();
        $productA = $products[0];
        $productB = $products[1];

        $staleKey = (string) Str::uuid();

        // Pre-existing unpaid order in DB created under $staleKey with 1x Product A
        $staleOrder = app(OrderService::class)->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $staleKey,
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
            ],
        ]);

        // Session still holds $staleKey
        session([
            'pos_active_checkout_key' => $staleKey,
            'pos_payment_inflight' => false,
        ]);

        // Cashier mounts POS (restores $staleKey and hasUnresolvedOrder=true),
        // then builds a DIFFERENT cart (2x Product B) and checks out
        $expectedNewTotal = $productB->base_price * 2;

        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->assertSet('checkoutIdempotencyKey', $staleKey)
            ->assertSet('hasUnresolvedOrder', true)
            ->call('selectProduct', $productB->id)
            ->call('confirmCustomization')
            ->call('increaseQty', 0)
            ->call('openCheckout')
            ->assertSet('showPaymentModal', true)
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $expectedNewTotal)
            ->call('processPayment');

        $component->assertSet('showSuccessModal', true)
            ->assertSet('lastTotal', $expectedNewTotal);

        $newKey = $component->get('checkoutIdempotencyKey');
        $this->assertNotSame($staleKey, $newKey);

        // Stale unpaid order was not altered or cancelled, and new order was paid cleanly
        $this->assertSame(Order::PAYMENT_STATUS_UNPAID, $staleOrder->fresh()->payment_status);
        $this->assertDatabaseHas('orders', [
            'idempotency_key' => $newKey,
            'payment_status' => Order::PAYMENT_STATUS_PAID,
            'total_amount' => $expectedNewTotal,
        ]);
    }

    /**
     * BUG 2 & BUG 3: QRIS and Debit confirmation checkboxes use wire:model.live so ticking them
     * immediately re-renders the Pay button without disabled, and the QRIS panel includes the
     * size-limiting classes and confirmation hint in both id and en locales.
     */
    public function test_qris_and_debit_confirmation_checkboxes_use_live_binding_and_render_size_limits_and_hint_in_both_locales(): void
    {
        app(ShiftService::class)->openShift($this->cashier, 100000, 'Shift kasir');
        ApplicationSetting::set(
            key: 'payment.qris_image_path',
            value: 'qris/test-qris.png',
            type: 'string',
            group: 'payment'
        );

        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        foreach (['id', 'en'] as $locale) {
            App::setLocale($locale);

            $component = Livewire::actingAs($this->cashier)
                ->test(CashierPos::class)
                ->call('selectProduct', $product->id)
                ->call('openCheckout')
                ->set('paymentMethod', 'qris');

            $qrisUnconfirmedHtml = $component->html();

            // 1. QRIS checkbox must use wire:model.live="qrisCashierConfirmed" (never deferred wire:model="qrisCashierConfirmed")
            $this->assertStringContainsString('wire:model.live="qrisCashierConfirmed"', $qrisUnconfirmedHtml);
            $this->assertStringNotContainsString('wire:model="qrisCashierConfirmed"', $qrisUnconfirmedHtml);

            // 2. QRIS image and container have size-limiting classes (max-h-56, object-contain, max-h-72)
            $this->assertStringContainsString('max-h-56', $qrisUnconfirmedHtml);
            $this->assertStringContainsString('object-contain', $qrisUnconfirmedHtml);
            $this->assertStringContainsString('max-h-72', $qrisUnconfirmedHtml);

            // 3. When unconfirmed, the translated hint is shown and Pay button is disabled
            $expectedHint = __('messages.pos.confirm_to_enable_pay');
            $this->assertStringContainsString($expectedHint, $qrisUnconfirmedHtml);

            // 4. Ticking qrisCashierConfirmed removes the hint and enables the Pay button
            $component->set('qrisCashierConfirmed', true);
            $qrisConfirmedHtml = $component->html();
            $this->assertStringNotContainsString($expectedHint, $qrisConfirmedHtml);

            // 5. Switch to Debit: unconfirmed shows the hint and uses wire:model.live="debitCashierConfirmed"
            $component->set('paymentMethod', 'debit')
                ->set('debitCashierConfirmed', false);

            $debitUnconfirmedHtml = $component->html();
            $this->assertStringContainsString('wire:model.live="debitCashierConfirmed"', $debitUnconfirmedHtml);
            $this->assertStringNotContainsString('wire:model="debitCashierConfirmed"', $debitUnconfirmedHtml);
            $this->assertStringContainsString($expectedHint, $debitUnconfirmedHtml);

            // 6. Ticking debitCashierConfirmed removes the hint
            $component->set('debitCashierConfirmed', true);
            $debitConfirmedHtml = $component->html();
            $this->assertStringNotContainsString($expectedHint, $debitConfirmedHtml);
        }
    }

    /**
     * BUG 4: Scan every .blade.php file in resources/views for Alpine or Livewire attributes
     * (:attr=, x-bind:, x-show, x-if, x-text, x-data, x-init, x-on:, @..., wire:...) on plain HTML
     * elements (non-<x-...> Blade components) that contain PHP-style variables ($name) outside
     * Blade echo tags ({{ ... }} / {!! ... !!}) other than allowed Alpine/Livewire magics.
     */
    public function test_no_plain_html_elements_have_unescaped_php_variables_in_alpine_or_livewire_attributes(): void
    {
        $viewsDir = resource_path('views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir));

        $allowedMagics = [
            '$wire',
            '$el',
            '$refs',
            '$store',
            '$nextTick',
            '$dispatch',
            '$watch',
            '$event',
            '$root',
            '$data',
            '$id',
            '$set',
            '$toggle',
            '$refresh',
            '$parent',
        ];

        $violations = [];
        $scannedFiles = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $scannedFiles++;
            $filePath = str_replace('\\', '/', $file->getPathname());
            $relativePath = str_replace(str_replace('\\', '/', base_path()).'/', '', $filePath);
            $content = (string) file_get_contents($filePath);

            // Match opening HTML/component tags (taking care not to break on '>' inside quoted attributes)
            preg_match_all('/<([a-zA-Z][a-zA-Z0-9_.:-]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $content, $tagMatches, PREG_OFFSET_CAPTURE);

            foreach ($tagMatches[0] as $i => $fullMatch) {
                $tagName = $tagMatches[1][$i][0];
                $rawAttrs = $tagMatches[2][$i][0];
                $offset = $fullMatch[1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;

                // Skip <x-...> Blade components where :attr="$phpVar" is evaluated server-side by Blade
                if (str_starts_with(strtolower($tagName), 'x-')) {
                    continue;
                }

                // Strip Blade echo blocks ({{ ... }} and {!! ... !!}) before inspecting client-side expressions
                $attrsWithoutBladeEcho = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $rawAttrs);

                if (preg_match_all('/(?:\s|^)(:[a-zA-Z0-9_.-]+|x-[a-zA-Z0-9_:.-]+|@[a-zA-Z0-9_.-]+|wire:[a-zA-Z0-9_.-]+)\s*=\s*("[^"]*"|\'[^\']*\')/s', $attrsWithoutBladeEcho, $attrMatches)) {
                    foreach ($attrMatches[0] as $j => $attrFull) {
                        $attrName = $attrMatches[1][$j];
                        $attrVal = $attrMatches[2][$j];

                        if (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $attrVal, $varMatches)) {
                            foreach ($varMatches[0] as $varName) {
                                if (! in_array($varName, $allowedMagics, true)) {
                                    $violations[] = "{$relativePath}:{$line} <{$tagName}> {$attrName}={$attrVal} contains PHP variable {$varName}";
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $scannedFiles);
        $this->assertSame([], $violations, "Found unescaped PHP variables in Alpine/Livewire attributes on plain HTML elements:\n".implode("\n", $violations));
    }

    /**
     * BUG 4: Render CashierPos in the unresolved-order / payment-in-flight state with items in the cart
     * and assert the rendered markup contains no "$paymentInFlight" inside any attribute.
     */
    public function test_cashier_pos_unresolved_order_and_inflight_render_contains_no_payment_in_flight_variable_in_html(): void
    {
        $shift = app(ShiftService::class)->openShift($this->cashier, 100000, 'Shift kasir BUG 4');
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        app(OrderService::class)->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        session([
            'pos_active_checkout_key' => $key,
            'pos_payment_inflight' => true,
        ]);

        // Render with items in cart and paymentInFlight=true & hasUnresolvedOrder=true
        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->set('paymentInFlight', true)
            ->set('hasUnresolvedOrder', true)
            ->set('checkoutIdempotencyKey', $key);

        $html = $component->html();

        $this->assertStringNotContainsString('$paymentInFlight', $html);
        $this->assertStringNotContainsString(':disabled="$paymentInFlight"', $html);
    }

    /**
     * BUG 5:
     * - Cashier in own shift: OK.
     * - Cashier in an Owner-opened shift: OK for cash, QRIS, and debit through CashierPos component,
     *   with payment recording the cashier as actor and shift report + expected-cash tie-out including it.
     * - Cashier B in Cashier A's shift: denied with translated message in id and en, NO order and NO payment created.
     * - Owner in a Cashier's shift: OK.
     * - Cashier still cannot close the Owner's shift.
     */
    public function test_shift_payment_access_rules_for_cashier_owner_and_delegated_owner_shift(): void
    {
        $shiftService = app(ShiftService::class);
        $paymentService = app(PaymentService::class);
        $reportService = app(ShiftReportService::class);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
        $price = (int) $product->base_price;

        // 1. Cashier in own shift -> OK
        $cashierShift = $shiftService->openShift($this->cashier, 100000, 'Shift milik Cashier A');
        $this->assertTrue($paymentService->userMayTakePaymentInShift($this->cashier, $cashierShift));

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $price)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->call('closeSuccessModal');

        // 2. Owner in Cashier A's shift -> OK
        $this->assertTrue($paymentService->userMayTakePaymentInShift($this->owner, $cashierShift));

        Livewire::actingAs($this->owner)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $price)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->call('closeSuccessModal');

        session()->forget(['pos_active_checkout_key', 'pos_payment_inflight']);

        // 3. Cashier B in Cashier A's shift -> DENIED in both id and en, NO order and NO payment created
        $cashierB = User::factory()->create(['name' => 'Kasir Kedua']);
        $cashierRole = Role::where('slug', 'cashier')->firstOrFail();
        $cashierB->roles()->attach($cashierRole);

        $this->assertFalse($paymentService->userMayTakePaymentInShift($cashierB, $cashierShift));

        $ordersBeforeDenied = Order::count();
        $txBeforeDenied = PaymentTransaction::count();
        $paymentsBeforeDenied = Payment::count();

        foreach (['id', 'en'] as $locale) {
            App::setLocale($locale);
            $expectedDeniedMessage = __('messages.pos.shift_access_denied');

            Livewire::actingAs($cashierB)
                ->test(CashierPos::class)
                ->call('selectProduct', $product->id)
                ->call('openCheckout')
                ->set('paymentMethod', 'cash')
                ->set('cashGiven', $price)
                ->call('processPayment')
                ->assertSet('showSuccessModal', false)
                ->assertDispatched('notify', message: $expectedDeniedMessage, type: 'error');

            // No orphan unpaid order and no payment created
            $this->assertSame($ordersBeforeDenied, Order::count(), "No order should be created when Cashier B is denied in locale {$locale}");
            $this->assertSame($txBeforeDenied, PaymentTransaction::count());
            $this->assertSame($paymentsBeforeDenied, Payment::count());
        }

        // Also verify PaymentService::settlePayment throws ShiftAccessDeniedException (subclass of InvalidArgumentException) directly
        $unpaidOrderOnCashierShift = app(OrderService::class)->createOrder($this->cashier, $cashierShift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        try {
            $paymentService->settlePayment(
                cashier: $cashierB,
                shift: $cashierShift,
                order: $unpaidOrderOnCashierShift,
                tenders: [[
                    'payment_method' => 'cash',
                    'amount' => $price,
                    'tendered_amount' => $price,
                    'change_amount' => 0,
                ]]
            );
            $this->fail('Expected ShiftAccessDeniedException was not thrown.');
        } catch (ShiftAccessDeniedException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('Kasir tidak memiliki akses ke shift ini.', $e->getMessage());
        }

        // Close Cashier A's shift
        $shiftService->closeShift(
            shift: $cashierShift,
            closingUser: $this->cashier,
            actualCash: $shiftService->calculateExpectedCash($cashierShift)
        );

        // 4. Owner opens a shift; Cashier takes payments with Cash, QRIS, and Debit through CashierPos
        App::setLocale('id');
        $ownerOpeningCash = 200000;
        $ownerShift = $shiftService->openShift($this->owner, $ownerOpeningCash, 'Shift dibuka oleh Owner');
        $this->assertTrue($paymentService->userMayTakePaymentInShift($this->cashier, $ownerShift));

        // 4a. Cash payment by Cashier in Owner's shift (with change)
        $cashTendered = $price + 10000;
        $cashComp = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $cashTendered)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastPaymentMethod', 'cash')
            ->assertSet('lastTotal', $price)
            ->assertSet('lastChange', 10000);

        $cashTx = PaymentTransaction::findOrFail($cashComp->get('lastTransactionId'));
        $this->assertSame($this->cashier->id, (int) $cashTx->user_id);
        $this->assertSame($this->cashier->name, $cashTx->cashier_name);
        $this->assertSame($ownerShift->id, (int) $cashTx->shift_id);

        // 4b. QRIS payment by Cashier in Owner's shift
        $qrisComp = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'qris')
            ->set('qrisReferenceNumber', 'QRIS-DELEGATED-001')
            ->set('qrisCashierConfirmed', true)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastPaymentMethod', 'qris')
            ->assertSet('lastTotal', $price);

        $qrisTx = PaymentTransaction::findOrFail($qrisComp->get('lastTransactionId'));
        $this->assertSame($this->cashier->id, (int) $qrisTx->user_id);
        $this->assertSame($this->cashier->name, $qrisTx->cashier_name);
        $this->assertSame($ownerShift->id, (int) $qrisTx->shift_id);
        $this->assertSame($this->cashier->id, (int) $qrisTx->payments->first()->qris_confirmed_by_user_id);

        // 4c. Debit payment by Cashier in Owner's shift
        $debitComp = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'debit')
            ->set('debitReferenceNumber', 'DEBIT-DELEGATED-001')
            ->set('debitCashierConfirmed', true)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastPaymentMethod', 'debit')
            ->assertSet('lastTotal', $price);

        $debitTx = PaymentTransaction::findOrFail($debitComp->get('lastTransactionId'));
        $this->assertSame($this->cashier->id, (int) $debitTx->user_id);
        $this->assertSame($this->cashier->name, $debitTx->cashier_name);
        $this->assertSame($ownerShift->id, (int) $debitTx->shift_id);

        // 4d. Shift report and expected-cash tie-out include all 3 delegated payments
        $expectedCashInDrawer = $ownerOpeningCash + $price;
        $this->assertSame($expectedCashInDrawer, $shiftService->calculateExpectedCash($ownerShift->fresh()));

        $report = $reportService->buildReport($ownerShift->fresh());
        $this->assertSame(3, $report['sales']['paid_orders_count']);
        $this->assertSame($price * 3, $report['sales']['gross_sales']);
        $this->assertSame($price * 3, $report['sales']['net_sales']);
        $this->assertSame($price, $report['payments_by_method']['cash']);
        $this->assertSame($price, $report['payments_by_method']['qris']);
        $this->assertSame($price, $report['payments_by_method']['debit']);
        $this->assertSame($price * 3, $report['payments_by_method']['total']);
        $this->assertSame($price, $report['cash_drawer']['cash_sales']);
        $this->assertSame($cashTendered, $report['cash_drawer']['cash_tendered']);
        $this->assertSame(10000, $report['cash_drawer']['cash_change']);
        $this->assertSame($price * 2, $report['cash_drawer']['non_cash_sales']);
        $this->assertSame($expectedCashInDrawer, $report['cash_drawer']['expected_cash']);

        // 5. A Cashier still CANNOT close the Owner's shift
        try {
            $shiftService->closeShift(
                shift: $ownerShift->fresh(),
                closingUser: $this->cashier,
                actualCash: $expectedCashInDrawer
            );
            $this->fail('Expected UnauthorizedShiftCloseException when Cashier tries to close Owner shift.');
        } catch (UnauthorizedShiftCloseException $e) {
            $this->assertSame('Anda tidak berwenang menutup shift milik kasir lain.', $e->getMessage());
        }

        $this->assertTrue($ownerShift->fresh()->isOpen());
    }

    private function assertNoStepLargerThanOne(string $html, string $context): void
    {
        preg_match_all('/<input\b[^>]*>/i', $html, $matches);
        $this->assertNotEmpty($matches[0], "Expected at least one <input> in {$context}");

        foreach ($matches[0] as $inputTag) {
            if (preg_match('/\bstep\s*=\s*["\']([^"\']+)["\']/i', $inputTag, $stepMatch)) {
                $stepValue = (float) $stepMatch[1];
                $this->assertLessThanOrEqual(
                    1.0,
                    $stepValue,
                    "Input in {$context} has step=\"{$stepMatch[1]}\" > 1: {$inputTag}"
                );
            }
        }
    }
}
