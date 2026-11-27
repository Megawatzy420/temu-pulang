<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Livewire\Reports\SalesReport;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SalesReportService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private ShiftService $shiftService;

    private OrderService $orderService;

    private PaymentService $paymentService;

    private SalesReportService $salesReportService;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->shiftService = app(ShiftService::class);
        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);
        $this->salesReportService = app(SalesReportService::class);

        $this->product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
    }

    public function test_rbac_owner_gets_200_cashier_gets_403_and_guest_redirects_to_login(): void
    {
        // 1. Guest redirects to login
        $this->get(route('reports.sales'))
            ->assertRedirect(route('login'));

        // 2. Cashier is blocked by role:owner middleware (redirects Cashier to POS) and gets 403 on direct Livewire mount
        $this->actingAs($this->cashier)
            ->get(route('reports.sales'))
            ->assertRedirect(route('pos'));

        Livewire::actingAs($this->cashier)
            ->test(SalesReport::class)
            ->assertForbidden();

        // 2b. Non-owner without cashier redirect gets 403 from HTTP route
        $noRoleUser = User::factory()->create();
        $this->actingAs($noRoleUser)
            ->get(route('reports.sales'))
            ->assertForbidden();

        // 3. Owner gets 200 and sidebar contains link to reports.sales
        $this->actingAs($this->owner)
            ->get(route('reports.sales'))
            ->assertOk()
            ->assertSee(route('reports.sales'));
    }

    public function test_date_validation_gracefully_resets_reversed_dates_over_366_days_and_garbage_dates_with_notice(): void
    {
        $fixedNow = CarbonImmutable::parse('2026-11-27 14:00:00', 'Asia/Jakarta');
        CarbonImmutable::setTestNow($fixedNow);
        App::setLocale('en');

        $expectedToday = '2026-11-27';
        $expectedNotice = __('messages.sales_report.validation_reset_notice');

        // 1. Reversed dates (from > to)
        Livewire::actingAs($this->owner)
            ->withQueryParams(['from' => '2026-11-25', 'to' => '2026-11-20'])
            ->test(SalesReport::class)
            ->assertStatus(200)
            ->assertSet('from', $expectedToday)
            ->assertSet('to', $expectedToday)
            ->assertSee($expectedNotice);

        // 2. Range > 366 days
        Livewire::actingAs($this->owner)
            ->withQueryParams(['from' => '2025-01-01', 'to' => '2026-11-27'])
            ->test(SalesReport::class)
            ->assertStatus(200)
            ->assertSet('from', $expectedToday)
            ->assertSet('to', $expectedToday)
            ->assertSee($expectedNotice);

        // 3. Garbage / non-existent calendar dates
        Livewire::actingAs($this->owner)
            ->withQueryParams(['from' => 'not-a-date', 'to' => '2026-02-30'])
            ->test(SalesReport::class)
            ->assertStatus(200)
            ->assertSet('from', $expectedToday)
            ->assertSet('to', $expectedToday)
            ->assertSee($expectedNotice);

        // 4. Presets work cleanly and clear the notice
        Livewire::actingAs($this->owner)
            ->test(SalesReport::class)
            ->call('applyPreset', 'yesterday')
            ->assertSet('from', '2026-11-26')
            ->assertSet('to', '2026-11-26')
            ->assertSet('validationNotice', null)
            ->call('applyPreset', 'this_month')
            ->assertSet('from', '2026-11-01')
            ->assertSet('to', '2026-11-30')
            ->call('applyPreset', 'last_month')
            ->assertSet('from', '2026-10-01')
            ->assertSet('to', '2026-10-31');

        CarbonImmutable::setTestNow();
    }

    public function test_locales_id_and_en_render_cleanly_with_print_stylesheet_and_payment_other_visibility(): void
    {
        $fixedNow = CarbonImmutable::parse('2026-11-27 14:00:00', 'Asia/Jakarta');
        CarbonImmutable::setTestNow($fixedNow);

        $shift = $this->shiftService->openShift($this->cashier, 150000);

        $cashOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $cashOrder, [[
            'payment_method' => 'cash',
            'amount' => $cashOrder->total_amount,
            'tendered_amount' => $cashOrder->total_amount,
            'change_amount' => 0,
        ]]);

        // 1. Indonesian locale render + print stylesheet + "other" bucket hidden when 0
        App::setLocale('id');
        $idHtml = Livewire::actingAs($this->owner)
            ->test(SalesReport::class)
            ->assertStatus(200)
            ->assertSee('Laporan Penjualan, HPP & Laba Kotor')
            ->assertSee('Penjualan Bersih (Net Sales)')
            ->assertSee('Penjualan Tanpa Resep (Uncosted Sales)')
            ->assertDontSee(__('messages.sales_report.payment_other'))
            ->assertDontSee('order_deduction')
            ->assertDontSee('refund_credit')
            ->assertDontSee('partially_refunded')
            ->assertDontSee('&rarr;')
            ->html();

        $this->assertStringContainsString('@media print', $idHtml);
        $this->assertStringContainsString('.no-print', $idHtml);
        $this->assertStringContainsString('display: none !important', $idHtml);

        // 2. Add a transfer payment so 'other' bucket > 0 and verify English locale render
        $transferOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $transferOrder, [[
            'payment_method' => 'transfer',
            'amount' => $transferOrder->total_amount,
        ]]);

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(SalesReport::class)
            ->assertStatus(200)
            ->assertSee('Sales, COGS & Gross-Profit Report')
            ->assertSee('Uncosted Sales (Items Without Recipes)')
            ->assertSee(__('messages.sales_report.payment_other'))
            ->assertDontSee('Laporan Penjualan, HPP & Laba Kotor')
            ->assertDontSee('order_deduction')
            ->assertDontSee('refund_credit')
            ->assertDontSee('partially_refunded')
            ->assertDontSee('&rarr;');

        // 3. Language key parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);

        CarbonImmutable::setTestNow();
    }
}
