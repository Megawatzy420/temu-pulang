<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Livewire\Orders\OrderIndex;
use App\Livewire\Orders\OrderRefund;
use App\Livewire\Pos\CashierPos;
use App\Livewire\Printing\PrintJobIndex;
use App\Livewire\Shifts\ShiftManager;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RawKeysAndEntitiesUiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift($this->cashier, 200000, 'UI entities test shift');
    }

    public function test_lang_files_contain_no_html_entities_and_have_identical_keys(): void
    {
        $idFlat = Arr::dot(require base_path('lang/id/messages.php'));
        $enFlat = Arr::dot(require base_path('lang/en/messages.php'));

        $idKeys = array_keys($idFlat);
        $enKeys = array_keys($enFlat);
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);

        $entityPattern = '/&(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#x[0-9A-Fa-f]+);/';

        foreach ($idFlat as $key => $value) {
            $this->assertIsString($value, "lang/id/messages.php key [{$key}] must be a string.");
            $this->assertDoesNotMatchRegularExpression(
                $entityPattern,
                $value,
                "lang/id/messages.php key [{$key}] contains an HTML entity: {$value}"
            );
        }

        foreach ($enFlat as $key => $value) {
            $this->assertIsString($value, "lang/en/messages.php key [{$key}] must be a string.");
            $this->assertDoesNotMatchRegularExpression(
                $entityPattern,
                $value,
                "lang/en/messages.php key [{$key}] contains an HTML entity: {$value}"
            );
        }
    }

    public function test_order_detail_modal_status_history_shows_translated_labels_and_real_arrow_in_id_and_en(): void
    {
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
        $orderService = app(OrderService::class);

        $order = $orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order = $orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);
        $order = $orderService->advanceStatus($order, Order::STATUS_QUEUED, $this->cashier);
        $order = $orderService->advanceStatus($order, Order::STATUS_PREPARING, $this->cashier);
        $order = $orderService->advanceStatus($order, Order::STATUS_READY, $this->cashier);
        $order = $orderService->advanceStatus($order, Order::STATUS_COMPLETED, $this->cashier);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => Order::PAYMENT_STATUS_PAID,
            'to_status' => Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
            'user_id' => $this->owner->id,
            'notes' => 'Partial refund note',
        ]);

        // 1. Indonesian locale
        App::setLocale('id');
        $idHtml = Livewire::actingAs($this->owner)
            ->test(OrderIndex::class)
            ->call('openDetailModal', $order->id)
            ->assertSet('showDetailModal', true)
            ->html();

        // Extract only the history transition header spans (<span class="font-semibold text-zinc-800 dark:text-zinc-200">...</span>)
        preg_match_all('/<span class="font-semibold text-zinc-800 dark:text-zinc-200">\s*(.*?)\s*<\/span>/s', $idHtml, $idHeaderMatches);
        $idHeaders = implode(' | ', $idHeaderMatches[1] ?? []);

        $this->assertNotEmpty($idHeaders);
        $this->assertStringContainsString('Menunggu Bayar → Terbayar', $idHeaders);
        $this->assertStringContainsString('Terbayar → Antrean Kitchen', $idHeaders);
        $this->assertStringContainsString('Antrean Kitchen → Sedang Disiapkan', $idHeaders);
        $this->assertStringContainsString('Sedang Disiapkan → Siap Disajikan', $idHeaders);
        $this->assertStringContainsString('Siap Disajikan → Selesai', $idHeaders);
        $this->assertStringContainsString('Terbayar → Refund Sebagian', $idHeaders);
        $this->assertStringNotContainsString('&rarr;', $idHeaders);
        $this->assertStringNotContainsString('&amp;', $idHeaders);
        $this->assertStringNotContainsString('awaiting_payment', $idHeaders);
        $this->assertStringNotContainsString('queued', $idHeaders);
        $this->assertStringNotContainsString('partially_refunded', $idHeaders);

        // 2. English locale
        App::setLocale('en');
        $enHtml = Livewire::actingAs($this->owner)
            ->test(OrderIndex::class)
            ->call('openDetailModal', $order->id)
            ->assertSet('showDetailModal', true)
            ->html();

        preg_match_all('/<span class="font-semibold text-zinc-800 dark:text-zinc-200">\s*(.*?)\s*<\/span>/s', $enHtml, $enHeaderMatches);
        $enHeaders = implode(' | ', $enHeaderMatches[1] ?? []);

        $this->assertNotEmpty($enHeaders);
        $this->assertStringContainsString('Awaiting Payment → Paid', $enHeaders);
        $this->assertStringContainsString('Paid → Kitchen Queue', $enHeaders);
        $this->assertStringContainsString('Kitchen Queue → Preparing', $enHeaders);
        $this->assertStringContainsString('Preparing → Ready to Serve', $enHeaders);
        $this->assertStringContainsString('Ready to Serve → Completed', $enHeaders);
        $this->assertStringContainsString('Paid → Partially Refunded', $enHeaders);
        $this->assertStringNotContainsString('&rarr;', $enHeaders);
        $this->assertStringNotContainsString('&amp;', $enHeaders);
        $this->assertStringNotContainsString('awaiting_payment', $enHeaders);
        $this->assertStringNotContainsString('queued', $enHeaders);
        $this->assertStringNotContainsString('partially_refunded', $enHeaders);
    }

    public function test_shift_manager_displays_translated_cash_movement_categories_in_id_and_en(): void
    {
        CashMovement::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'type' => 'in',
            'amount' => 50000,
            'category' => 'capital_add',
            'description' => 'Float top up',
        ]);

        CashMovement::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->owner->id,
            'type' => 'out',
            'amount' => 25000,
            'category' => 'cash_drop',
            'description' => 'Safe drop',
        ]);

        CashMovement::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->owner->id,
            'type' => 'out',
            'amount' => 15000,
            'category' => 'owner_withdrawal',
            'description' => 'Owner take',
        ]);

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->assertSee('Tambah Modal Awal')
            ->assertSee('Setor Kas ke Brankas (Cash Drop)')
            ->assertSee('Penarikan Kas oleh Pemilik')
            ->assertDontSee('Capital add')
            ->assertDontSee('Cash drop')
            ->assertDontSee('Owner withdrawal');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->assertSee('Add Starting Float')
            ->assertSee('Safe Cash Drop')
            ->assertSee('Owner Cash Withdrawal')
            ->assertDontSee('Capital add')
            ->assertDontSee('Cash drop')
            ->assertDontSee('Owner withdrawal');
    }

    public function test_pos_success_modal_and_order_refund_screen_display_translated_payment_methods_in_id_and_en(): void
    {
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // 1. POS Success Modal in Indonesian & English
        App::setLocale('id');
        $posIdHtml = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $product->base_price)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSee('Tunai')
            ->html();

        $this->assertStringNotContainsString('>cash<', $posIdHtml);

        App::setLocale('en');
        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('closeSuccessModal')
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'cash')
            ->set('cashGiven', $product->base_price)
            ->call('processPayment')
            ->assertSet('showSuccessModal', true)
            ->assertSee('Cash');

        // 2. OrderRefund screen per-payment heading in Indonesian & English
        $order = Order::latest('id')->firstOrFail();
        $payment = $order->payments()->firstOrFail();

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertSee("Pembayaran #{$payment->id} (Tunai)")
            ->assertDontSee("Pembayaran #{$payment->id} (CASH)");

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertSee("Payment #{$payment->id} (Cash)")
            ->assertDontSee("Payment #{$payment->id} (CASH)");
    }

    public function test_print_job_index_displays_translated_job_types_without_raw_underscore_keys_in_id_and_en(): void
    {
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS-80',
            'status' => 'uncertain',
            'last_error' => 'Spooler timeout',
        ]);

        PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'kitchen_ticket',
            'printer_destination' => 'Kitchen-80',
            'status' => 'failed',
            'last_error' => 'Printer offline',
        ]);

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->assertSee('Struk Pelanggan')
            ->assertSee('Tiket Dapur')
            ->assertDontSee('customer_receipt')
            ->assertDontSee('kitchen_ticket');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->assertSee('Customer Receipt')
            ->assertSee('Kitchen Ticket')
            ->assertDontSee('customer_receipt')
            ->assertDontSee('kitchen_ticket');
    }
}
