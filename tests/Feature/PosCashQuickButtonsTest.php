<?php

namespace Tests\Feature;

use App\Livewire\Pos\CashierPos;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PosCashQuickButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_renders_exact_cash_first_then_computed_suggestions_and_clicking_fills_cash_given(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        app(ShiftService::class)->openShift(
            user: $cashier,
            openingCash: 100000,
            openingNote: 'Quick buttons test'
        );

        $product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();

        $total = (int) $product->base_price;
        $suggestions = CashierPos::suggestCashAmounts($total);

        $component = Livewire::actingAs($cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash');

        $html = $component->html();
        $exactPos = strpos($html, __('messages.pos.exact_cash'));
        $this->assertNotFalse($exactPos);

        // Cash input rendered with Rp prefix, numeric inputmode, and Alpine digit-stripping formatter
        $component->assertSee(__('messages.pos.cash_received'))
            ->assertSeeHtml('inputmode="numeric"')
            ->assertSeeHtml('x-ref="cashInput"');

        if (! empty($suggestions)) {
            $firstSuggestionFormatted = 'Rp '.number_format($suggestions[0], 0, ',', '.');
            $firstPos = strpos($html, $firstSuggestionFormatted);
            $this->assertNotFalse($firstPos);
            $this->assertLessThan($firstPos, $exactPos);

            $cartBefore = $component->get('cart');
            $keyBefore = $component->get('checkoutIdempotencyKey');
            $inFlightBefore = $component->get('paymentInFlight');

            $component->set('cashGiven', $suggestions[0])
                ->assertSet('cashGiven', $suggestions[0])
                ->assertSet('cart', $cartBefore)
                ->assertSet('checkoutIdempotencyKey', $keyBefore)
                ->assertSet('paymentInFlight', $inFlightBefore);
        }

        // Custom amount entry still works
        $customAmount = $total + 3500;
        $component->set('cashGiven', $customAmount)
            ->assertSet('cashGiven', $customAmount);
    }

    public function test_typed_cash_shows_change_or_shortage_rejects_insufficient_cash_and_settles_valid_amount(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        app(ShiftService::class)->openShift(
            user: $cashier,
            openingCash: 100000,
            openingNote: 'Typed cash test'
        );

        $product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();

        $total = (int) $product->base_price;

        $component = Livewire::actingAs($cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash');

        $cartBefore = $component->get('cart');
        $keyBefore = $component->get('checkoutIdempotencyKey');
        $inFlightBefore = $component->get('paymentInFlight');

        // 1. Typing 100000 sets cashGiven to 100000 and displays exact change
        $component->set('cashGiven', 100000)
            ->assertSet('cashGiven', 100000)
            ->assertSee(__('messages.pos.change_label'))
            ->assertSee('Rp '.number_format(100000 - $total, 0, ',', '.'))
            ->assertSet('cart', $cartBefore)
            ->assertSet('checkoutIdempotencyKey', $keyBefore)
            ->assertSet('paymentInFlight', $inFlightBefore);

        // 2. Empty (0) sets cashGiven to 0 and does not show change
        $component->set('cashGiven', 0)
            ->assertSet('cashGiven', 0)
            ->assertDontSee(__('messages.pos.change_label'));

        // 3. Value below total shows shortage and rejects submit with insufficient_cash
        $belowTotal = max(1, $total - 5000);
        $component->set('cashGiven', $belowTotal)
            ->assertSee(__('messages.pos.shortage_label'))
            ->assertSee('Rp '.number_format($total - $belowTotal, 0, ',', '.'))
            ->call('processPayment')
            ->assertDispatched('notify', message: __('messages.pos.insufficient_cash'), type: 'error');

        $this->assertSame(0, Order::count());

        // 4. Typing a valid amount above total settles order with right change
        $typedTender = $total + 12500;
        $component->set('cashGiven', $typedTender)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSet('lastCashGiven', $typedTender)
            ->assertSet('lastChange', 12500);

        $this->assertSame(1, Order::count());
    }
}
