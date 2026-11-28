<?php

namespace Tests\Feature\Layout;

use App\Livewire\Pos\CashierPos;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PosResponsiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Product $simpleProductA;

    protected Product $simpleProductB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $category = Category::firstOrFail();

        $this->simpleProductA = Product::create([
            'category_id' => $category->id,
            'sku' => 'POS-RESP-A',
            'base_price' => 22000,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
        ]);
        ProductTranslation::create([
            'product_id' => $this->simpleProductA->id,
            'locale' => 'id',
            'name' => 'Kopi Tubruk Responsif',
        ]);
        ProductTranslation::create([
            'product_id' => $this->simpleProductA->id,
            'locale' => 'en',
            'name' => 'Responsive Tubruk Coffee',
        ]);

        $this->simpleProductB = Product::create([
            'category_id' => $category->id,
            'sku' => 'POS-RESP-B',
            'base_price' => 18000,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
        ]);
        ProductTranslation::create([
            'product_id' => $this->simpleProductB->id,
            'locale' => 'id',
            'name' => 'Teh Melati Responsif',
        ]);
        ProductTranslation::create([
            'product_id' => $this->simpleProductB->id,
            'locale' => 'en',
            'name' => 'Responsive Jasmine Tea',
        ]);
    }

    public function test_pos_switch_cart_count_badge_bottom_bar_and_back_to_menu_button_render_in_id_and_en(): void
    {
        app(ShiftService::class)->openShift($this->cashier, 200000, 'POS responsive test shift');

        foreach (['id', 'en'] as $locale) {
            app()->setLocale($locale);

            // 1. Cart with 0 items: switch present, badge = 0, bottom bar hidden, back-to-menu button present
            $component = Livewire::actingAs($this->cashier)
                ->test(CashierPos::class);

            $html0 = $component->html();
            $this->assertStringContainsString('id="pos-mobile-view-switch"', $html0);
            $this->assertStringContainsString('id="pos-tab-menu"', $html0);
            $this->assertStringContainsString('id="pos-tab-order"', $html0);
            $this->assertStringContainsString('aria-pressed="true"', $html0);
            $this->assertStringContainsString('aria-pressed="false"', $html0);
            $this->assertStringContainsString(__('messages.pos.mobile_tab_menu', [], $locale), $html0);
            $this->assertStringContainsString(__('messages.pos.mobile_tab_order', [], $locale), $html0);
            $this->assertStringContainsString('data-cart-count="0"', $html0);
            $this->assertStringNotContainsString('id="pos-mobile-bottom-bar"', $html0);
            $this->assertStringContainsString('id="pos-back-to-menu"', $html0);
            $this->assertStringContainsString(__('messages.pos.back_to_menu', [], $locale), $html0);

            // 2. Cart with 1 item: badge = 1, bottom bar visible with count=1 and total=22.000
            $component->call('selectProduct', $this->simpleProductA->id);
            $html1 = $component->html();

            $this->assertStringContainsString('data-cart-count="1"', $html1);
            $this->assertStringContainsString('id="pos-mobile-bottom-bar"', $html1);
            $this->assertStringContainsString(
                __('messages.pos.view_order_bar', ['count' => 1, 'total' => '22.000'], $locale),
                $html1
            );

            // 3. Cart with several items (1 of A + 2 of B = 3 items, total = 22000 + 36000 = 58000): badge = 3, bottom bar updated
            $component->call('selectProduct', $this->simpleProductB->id);
            $component->call('increaseQty', 1);
            $html3 = $component->html();

            $this->assertStringContainsString('data-cart-count="3"', $html3);
            $this->assertStringContainsString(
                __('messages.pos.view_order_bar', ['count' => 3, 'total' => '58.000'], $locale),
                $html3
            );

            // 4. Clearing cart hides bottom bar and resets badge to 0
            $component->call('clearCart');
            $htmlCleared = $component->html();
            $this->assertStringContainsString('data-cart-count="0"', $htmlCleared);
            $this->assertStringNotContainsString('id="pos-mobile-bottom-bar"', $htmlCleared);
        }
    }

    public function test_catalogue_and_cart_are_both_present_in_html_with_expected_responsive_and_grid_classes(): void
    {
        $response = $this->actingAs($this->owner)->get(route('pos'));
        $response->assertOk();
        $html = $response->getContent();

        // Both catalogue and cart panels exist in the rendered HTML at every width
        $this->assertStringContainsString('id="pos-catalog-panel"', $html);
        $this->assertStringContainsString('id="pos-cart-panel"', $html);

        // Two-column desktop classes and two-view mobile/tablet responsive classes
        $this->assertStringContainsString('x-data="{ activeView: \'menu\' }"', $html);
        $this->assertStringContainsString('flex-col lg:flex-row', $html);
        $this->assertStringContainsString(':class="activeView === \'menu\' ? \'flex\' : \'hidden lg:flex\'"', $html);
        $this->assertStringContainsString(':class="activeView === \'order\' ? \'flex\' : \'hidden lg:flex\'"', $html);
        $this->assertStringContainsString('w-full lg:w-[400px] xl:w-[440px]', $html);
        $this->assertStringContainsString('lg:flex lg:!flex', $html);

        // Product grid column classes: 2 cols at 320-639px, 3 cols at 640-1023px (sm:/md:), 4 cols at >=1280px (xl:)
        $this->assertStringContainsString('grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 xl:grid-cols-4', $html);

        // Compact switch and touch target classes on switch buttons, back-to-menu button, and product add buttons
        $this->assertStringContainsString('max-w-md mx-auto grid grid-cols-2', $html);
        $this->assertStringContainsString('min-h-10 h-10', $html);
        $this->assertStringContainsString('min-h-11', $html);
        $this->assertStringContainsString('min-w-11 min-h-11', $html);

        // Compact product card image height and two-line truncation
        $this->assertStringContainsString('h-20 sm:h-20 md:h-20 lg:h-24', $html);
        $this->assertStringContainsString('line-clamp-2', $html);

        // Sticky footer inside cart panel & safe-area bottom padding on catalogue
        $this->assertStringContainsString('pb-24 lg:pb-5', $html);
        $this->assertStringContainsString('sticky bottom-0', $html);
        $this->assertStringContainsString('motion-reduce:transition-none', $html);
    }

    public function test_cart_panel_has_no_conflicting_static_hidden_class_and_sticky_footer_and_single_order_type_control(): void
    {
        $response = $this->actingAs($this->owner)->get(route('pos'));
        $response->assertOk();
        $html = $response->getContent();

        // Extract opening tags for #pos-catalog-panel and #pos-cart-panel
        $this->assertMatchesRegularExpression(
            '/<div\s+id="pos-catalog-panel"[^>]*>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<div\s+id="pos-cart-panel"[^>]*>/s',
            $html
        );

        preg_match('/<div\s+id="pos-catalog-panel"([^>]*)>/s', $html, $catalogTag);
        preg_match('/<div\s+id="pos-cart-panel"([^>]*)>/s', $html, $cartTag);

        $catalogAttrs = $catalogTag[1] ?? '';
        $cartAttrs = $cartTag[1] ?? '';

        // Visibility below lg comes exclusively from the Alpine :class binding
        $this->assertStringContainsString(':class="activeView === \'menu\' ? \'flex\' : \'hidden lg:flex\'"', $catalogAttrs);
        $this->assertStringContainsString(':class="activeView === \'order\' ? \'flex\' : \'hidden lg:flex\'"', $cartAttrs);

        // Extract static class="..." attribute from #pos-cart-panel and #pos-catalog-panel
        preg_match('/(?:^|\s)class="([^"]*)"/s', $catalogAttrs, $catalogStaticClassMatch);
        preg_match('/(?:^|\s)class="([^"]*)"/s', $cartAttrs, $cartStaticClassMatch);

        $catalogStaticClasses = preg_split('/\s+/', trim($catalogStaticClassMatch[1] ?? '')) ?: [];
        $cartStaticClasses = preg_split('/\s+/', trim($cartStaticClassMatch[1] ?? '')) ?: [];

        // Assert NO static display-none class (hidden, max-lg:hidden, md:hidden, sm:hidden) exists in static class list
        foreach (['hidden', 'max-lg:hidden', 'md:hidden', 'sm:hidden'] as $forbiddenHiddenClass) {
            $this->assertNotContains($forbiddenHiddenClass, $catalogStaticClasses);
            $this->assertNotContains($forbiddenHiddenClass, $cartStaticClasses);
        }

        // Assert desktop override classes (lg:flex and lg:!flex) exist on both columns
        $this->assertContains('lg:flex', $catalogStaticClasses);
        $this->assertContains('lg:!flex', $catalogStaticClasses);
        $this->assertContains('lg:flex', $cartStaticClasses);
        $this->assertContains('lg:!flex', $cartStaticClasses);

        // Assert sticky footer inside #pos-cart-panel contains totals, Hold Order, and Checkout button
        $cartPanelStart = strpos($html, 'id="pos-cart-panel"');
        $this->assertNotFalse($cartPanelStart);
        $cartPanelHtml = substr($html, (int) $cartPanelStart);

        $this->assertStringContainsString('sticky bottom-0 z-10', $cartPanelHtml);
        $this->assertStringContainsString(__('messages.pos.subtotal'), $cartPanelHtml);
        $this->assertStringContainsString(__('messages.pos.total'), $cartPanelHtml);
        $this->assertStringContainsString(__('messages.pos.hold_order'), $cartPanelHtml);
        $this->assertStringContainsString(__('messages.pos.checkout'), $cartPanelHtml);

        // Assert compact switch has no oversized py-3/py-4 vertical padding classes and only one order-type control exists in the top bar
        preg_match('/<div\s+id="pos-mobile-view-switch"[^>]*>(.*?)<\/div>\s*<\/div>/s', $html, $switchBlock);
        $switchHtml = $switchBlock[0] ?? '';
        $this->assertNotEmpty($switchHtml);
        $this->assertStringContainsString('px-3 py-1.5', $switchHtml);
        $this->assertStringContainsString('max-w-md mx-auto', $switchHtml);
        $this->assertStringNotContainsString('py-3', $switchHtml);
        $this->assertStringNotContainsString('py-4', $switchHtml);

        // Only the single interactive dining-mode toggle exists in the catalogue header (no duplicate hidden sm:flex standalone pill)
        $this->assertStringContainsString('id="pos-dining-mode-toggle"', $html);
        $this->assertStringNotContainsString('hidden sm:flex items-center px-3 py-2 bg-white rounded-xl border border-coffee-200 text-xs font-bold', $html);
    }

    public function test_all_existing_pos_labels_appear_for_owner_and_cashier_in_id_and_en(): void
    {
        $expectedPosKeys = [
            'messages.pos.terminal_title',
            'messages.pos.all_categories',
            'messages.pos.search_placeholder',
            'messages.pos.dine_in',
            'messages.pos.takeaway',
            'messages.pos.current_order',
            'messages.pos.empty_cart_title',
            'messages.pos.empty_cart_desc',
            'messages.pos.subtotal',
            'messages.pos.tax',
            'messages.pos.total',
            'messages.pos.hold_order',
            'messages.pos.checkout',
            'messages.pos.price_label',
            'messages.pos.mobile_tab_menu',
            'messages.pos.mobile_tab_order',
            'messages.pos.back_to_menu',
        ];

        foreach (['id', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$this->owner, $this->cashier] as $user) {
                $response = $this->actingAs($user)
                    ->withSession(['locale' => $locale])
                    ->get(route('pos'));

                $response->assertOk();

                foreach ($expectedPosKeys as $key) {
                    $response->assertSee(__($key, [], $locale));
                }
            }
        }
    }

    public function test_client_only_view_switch_leaves_cart_checkout_key_and_payment_properties_unchanged(): void
    {
        app(ShiftService::class)->openShift($this->cashier, 150000, 'POS state persistence shift');

        $component = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $this->simpleProductA->id)
            ->call('selectProduct', $this->simpleProductB->id)
            ->call('openCheckout');

        $expectedCart = $component->get('cart');
        $expectedKey = $component->get('checkoutIdempotencyKey');
        $expectedMethod = $component->get('paymentMethod');
        $expectedCashGiven = $component->get('cashGiven');

        $this->assertCount(2, $expectedCart);
        $this->assertNotEmpty($expectedKey);

        // Because activeView is purely client-side Alpine state, a subsequent Livewire render
        // (such as filtering categories or refreshing) does not mutate cart, key, or payment properties
        // and preserves the stable root x-data="{ activeView: 'menu' }" wrapper.
        $component->call('$refresh');

        $this->assertSame($expectedCart, $component->get('cart'));
        $this->assertSame($expectedKey, $component->get('checkoutIdempotencyKey'));
        $this->assertSame($expectedMethod, $component->get('paymentMethod'));
        $this->assertSame($expectedCashGiven, $component->get('cashGiven'));
        $this->assertFalse($component->get('paymentInFlight'));
        $this->assertStringContainsString('x-data="{ activeView: \'menu\' }"', $component->html());
    }

    public function test_pos_view_and_layout_have_no_invalid_php_dollar_variables_and_lang_parity_holds(): void
    {
        $posViolations = ResponsiveNavTest::scanBladeFileForInvalidAlpineVariables(
            resource_path('views/livewire/pos/cashier-pos.blade.php')
        );
        $layoutViolations = ResponsiveNavTest::scanBladeFileForInvalidAlpineVariables(
            resource_path('views/components/layouts/app.blade.php')
        );

        $this->assertSame([], $posViolations);
        $this->assertSame([], $layoutViolations);

        ResponsiveNavTest::assertLanguageParityAndNoHtmlEntities($this, [
            'pos.mobile_tab_menu',
            'pos.mobile_tab_order',
            'pos.mobile_view_switcher_label',
            'pos.view_order_bar',
            'pos.back_to_menu',
            'pos.add_product_aria',
        ]);
    }
}
