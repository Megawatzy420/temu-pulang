<?php

namespace Tests\Feature\Localization;

use App\Livewire\Pos\CashierPos;
use App\Livewire\Printing\PrintJobIndex;
use App\Livewire\Shifts\ShiftManager;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CashierScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        ShopSetting::create([
            'name' => 'Temu Pulang',
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'currency_symbol' => 'Rp',
            'receipt_paper_width' => '58mm',
            'auto_print_receipt' => false,
            'printer_connection_type' => 'usb',
        ]);

        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();
    }

    public function test_shift_manager_renders_in_en_and_id_for_cashier_and_owner(): void
    {
        // Closed shift in history
        Shift::factory()->create([
            'user_id' => $this->cashier->id,
            'opened_at' => now()->subHours(10),
            'closed_at' => now()->subHours(2),
            'opening_cash' => 200000,
            'expected_cash' => 250000,
            'actual_cash' => 250000,
            'discrepancy' => 0,
            'status' => 'closed',
        ]);

        // 1. No active shift (Open Shift CTA card + Completed shifts history) in EN as Cashier
        app()->setLocale('en');

        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->call('openNewShiftModal')
            ->assertSee('No Active Cashier Shift')
            ->assertSee('Open New Cashier Shift')
            ->assertSee('Opening Cash Float (Rupiah)')
            ->assertSee('Confirm Open Shift')
            ->assertSee('Completed Cashier Shifts History')
            ->assertDontSee('Belum Ada Shift Kasir Aktif')
            ->assertDontSee('Buka Shift Kasir Baru')
            ->assertDontSee('Jumlah Kas Awal (Rupiah)')
            ->assertDontSee('Riwayat Shift Kasir Selesai');

        // 2. Active shift with cash movement in EN as Cashier and Owner
        $activeShift = Shift::factory()->create([
            'user_id' => $this->cashier->id,
            'opened_at' => now()->subHour(),
            'opening_cash' => 150000,
            'status' => 'open',
        ]);

        CashMovement::create([
            'shift_id' => $activeShift->id,
            'user_id' => $this->cashier->id,
            'type' => 'in',
            'amount' => 50000,
            'category' => 'capital_add',
            'description' => 'Change float',
        ]);

        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->call('openNewMovementModal', 'in')
            ->assertSee('Shift Active')
            ->assertSee('+ Cash In')
            ->assertSee('- Cash Out')
            ->assertSee('Close Cashier Shift')
            ->assertSee('= Expected Cash in Drawer')
            ->assertSee('This Shift Cash Drawer Ledger (1 Movements)')
            ->assertSee('Record Cash In (Inflow)')
            ->assertDontSee('Shift Sedang Aktif')
            ->assertDontSee('+ Kas Masuk')
            ->assertDontSee('- Kas Keluar')
            ->assertDontSee('Tutup Shift Kasir')
            ->assertDontSee('Buku Kas Drawer Shift Ini');

        Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->call('openCloseModal')
            ->assertSee('Reconcile & Close Cashier Shift')
            ->assertSee('Counted Physical Cash (Rupiah)')
            ->assertSee('Confirm & Close Shift')
            ->assertDontSee('Rekonsiliasi & Tutup Shift Kasir')
            ->assertDontSee('Kas Fisik Hasil Hitung (Rupiah)');

        // 3. Active shift in ID as Cashier and Owner
        app()->setLocale('id');

        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->call('openNewMovementModal', 'in')
            ->assertSee('Shift Sedang Aktif')
            ->assertSee('+ Kas Masuk')
            ->assertSee('- Kas Keluar')
            ->assertSee('Tutup Shift Kasir')
            ->assertSee('= Kas Diharapkan di Laci')
            ->assertSee('Buku Kas Laci Shift Ini (1 Pergerakan)')
            ->assertSee('Catat Kas Masuk (Inflow)')
            ->assertSee('Riwayat Shift Kasir Selesai')
            ->assertDontSee('This Shift Cash Drawer Ledger')
            ->assertDontSee('Record Cash In (Inflow)');

        Livewire::actingAs($this->owner)
            ->test(ShiftManager::class)
            ->call('openCloseModal')
            ->assertSee('Rekonsiliasi & Tutup Shift Kasir')
            ->assertSee('Kas Fisik Hasil Hitung (Rupiah)')
            ->assertDontSee('Reconcile & Close Cashier Shift');
    }

    public function test_pos_payment_modal_and_success_modal_render_in_en_and_id(): void
    {
        Shift::factory()->create([
            'user_id' => $this->cashier->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'status' => 'open',
        ]);

        $category = Category::factory()->create([
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 25000,
            'is_active' => true,
            'has_variants' => false,
        ]);

        $product->translations()->createMany([
            ['locale' => 'id', 'name' => 'Kopi Susu'],
            ['locale' => 'en', 'name' => 'Milk Coffee'],
        ]);

        // English locale: payment modal -> checkout -> success modal
        app()->setLocale('en');

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->assertSee('Order Payment')
            ->assertSee('Total Bill')
            ->assertSee('Payment Method')
            ->assertSee('Cash Received')
            ->assertSee('Exact Cash')
            ->assertSee('Customer Name (Queue Calling)')
            ->assertDontSee('Pembayaran Pesanan')
            ->assertDontSee('Total Tagihan')
            ->assertDontSee('Metode Pembayaran')
            ->assertDontSee('Uang Diterima')
            ->assertDontSee('Uang Pas')
            ->call('processPayment')
            ->assertSee('Payment Successful!')
            ->assertSee('Call Number')
            ->assertSee('Total Paid')
            ->assertSee('New Order')
            ->assertDontSee('Pembayaran Berhasil!')
            ->assertDontSee('Nomor Panggilan')
            ->assertDontSee('Total Dibayar')
            ->assertDontSee('Pesanan Baru');

        // Indonesian locale: payment modal -> checkout -> success modal
        app()->setLocale('id');

        Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->assertSee('Pembayaran Pesanan')
            ->assertSee('Total Tagihan')
            ->assertSee('Metode Pembayaran')
            ->assertSee('Uang Diterima')
            ->assertSee('Uang Pas')
            ->assertSee('Nama Pelanggan (Panggilan Antrean)')
            ->assertDontSee('Order Payment')
            ->assertDontSee('Customer Name (Queue Calling)')
            ->call('processPayment')
            ->assertSee('Pembayaran Berhasil!')
            ->assertSee('Nomor Panggilan')
            ->assertSee('Total Dibayar')
            ->assertSee('Pesanan Baru')
            ->assertDontSee('Payment Successful!')
            ->assertDontSee('New Order');
    }

    public function test_print_job_index_renders_in_en_and_id_for_owner(): void
    {
        $shift = Shift::factory()->create([
            'user_id' => $this->owner->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'status' => 'open',
        ]);

        $order = Order::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->owner->id,
            'status' => 'completed',
        ]);

        $job = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'receipt',
            'printer_destination' => 'POS-80',
            'status' => 'uncertain',
            'attempts' => 1,
            'max_attempts' => 3,
            'is_reprint' => true,
            'last_error' => 'USB timeout',
            'requested_by' => $this->owner->id,
        ]);

        app()->setLocale('en');

        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->call('openResolveModal', $job->id)
            ->assertSee('Problematic Print Jobs')
            ->assertSee('(Reprint)')
            ->assertSee('Uncertain')
            ->assertSee('Resolve Status')
            ->assertSee('Resolve Print Job #'.$job->id)
            ->assertSee('Save Resolution')
            ->assertDontSee('Pekerjaan Cetak Bermasalah')
            ->assertDontSee('(Cetak Ulang)')
            ->assertDontSee('Selesaikan Status')
            ->assertDontSee('Simpan Resolusi');

        app()->setLocale('id');

        Livewire::actingAs($this->owner)
            ->test(PrintJobIndex::class)
            ->call('openResolveModal', $job->id)
            ->assertSee('Pekerjaan Cetak Bermasalah')
            ->assertSee('(Cetak Ulang)')
            ->assertSee('Uncertain')
            ->assertSee('Selesaikan Status')
            ->assertSee('Resolusi Pekerjaan Cetak #'.$job->id)
            ->assertSee('Simpan Resolusi')
            ->assertDontSee('Problematic Print Jobs')
            ->assertDontSee('(Reprint)')
            ->assertDontSee('Resolve Status');
    }
}
