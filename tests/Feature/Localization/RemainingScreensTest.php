<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Livewire\Dashboard\OwnerDashboard;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Orders\OrderIndex;
use App\Livewire\Settings\ShopSettingsManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class RemainingScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
    }

    public function test_shop_settings_manager_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->assertStatus(200)
            ->assertSee('Shop & POS Settings')
            ->assertSee('Business Profile')
            ->assertSee('Operating Hours & Business Day')
            ->assertSee('Business Identity & Contact')
            ->assertSee('Save Changes')
            ->assertDontSee('Pengaturan Toko & POS')
            ->assertDontSee('Profil Usaha')
            ->assertDontSee('Jam Operasional & Hari Bisnis')
            ->assertDontSee('Identitas & Kontak Usaha')
            ->assertDontSee('Simpan Perubahan');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->assertStatus(200)
            ->assertSee('Pengaturan Toko & POS')
            ->assertSee('Profil Usaha')
            ->assertSee('Jam Operasional & Hari Bisnis')
            ->assertSee('Identitas & Kontak Usaha')
            ->assertSee('Simpan Perubahan');
    }

    public function test_order_index_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->cashier)
            ->test(OrderIndex::class)
            ->assertStatus(200)
            ->assertSee('Customer Queue & Orders')
            ->assertSee('Open POS Cashier')
            ->assertSee('Awaiting Payment')
            ->assertSee('Kitchen Queue')
            ->assertSee('No orders found.')
            ->assertDontSee('Antrean & Pesanan Pelanggan')
            ->assertDontSee('Buka Kasir POS')
            ->assertDontSee('Menunggu Bayar')
            ->assertDontSee('Antrean Dapur')
            ->assertDontSee('Tidak ada pesanan ditemukan.');

        App::setLocale('id');
        Livewire::actingAs($this->cashier)
            ->test(OrderIndex::class)
            ->assertStatus(200)
            ->assertSee('Antrean & Pesanan Pelanggan')
            ->assertSee('Buka Kasir POS')
            ->assertSee('Menunggu Bayar')
            ->assertSee('Antrean Dapur')
            ->assertSee('Tidak ada pesanan ditemukan.');
    }

    public function test_ingredient_manager_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->assertStatus(200)
            ->assertSee('Manage Raw Materials (Ingredients)')
            ->assertSee('+ Add Ingredient')
            ->assertSee('+ Category')
            ->assertSee('Ingredient Name')
            ->assertSee('Min Threshold')
            ->assertDontSee('Kelola Bahan Baku (Raw Materials)')
            ->assertDontSee('+ Tambah Bahan')
            ->assertDontSee('+ Kategori')
            ->assertDontSee('Nama Bahan Baku')
            ->assertDontSee('Batas Minimum');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->assertStatus(200)
            ->assertSee('Kelola Bahan Baku (Raw Materials)')
            ->assertSee('+ Tambah Bahan')
            ->assertSee('+ Kategori')
            ->assertSee('Nama Bahan Baku')
            ->assertSee('Batas Minimum');
    }

    public function test_owner_dashboard_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('POS Device Status')
            ->assertSee('Restaurant Tax & Service Charge')
            ->assertSee('Cashier has not opened a shift')
            ->assertSee('Showing 5 most recent orders')
            ->assertSee('Compliant')
            ->assertDontSee('Status Perangkat Kasir')
            ->assertDontSee('Pajak Restoran & Layanan')
            ->assertDontSee('Kasir belum membuka shift')
            ->assertDontSee('Menampilkan 5 pesanan terbaru')
            ->assertDontSee('Sesuai');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Status Perangkat Kasir')
            ->assertSee('Pajak Restoran & Layanan')
            ->assertSee('Kasir belum membuka shift')
            ->assertSee('Menampilkan 5 pesanan terbaru')
            ->assertSee('Sesuai');
    }

    public function test_sidebar_layout_renders_in_english_and_indonesian(): void
    {
        $this->actingAs($this->owner)
            ->withSession(['locale' => 'en'])
            ->get(route('dashboard'))
            ->assertStatus(200)
            ->assertSee('Queue & Orders')
            ->assertSee('Shift & Cash Drawer')
            ->assertSee('Owner Administration')
            ->assertSee('Activity Logs (Audit)')
            ->assertSee('Ingredients & Stock')
            ->assertDontSee('Antrean & Pesanan')
            ->assertDontSee('Kelola Shift & Kas')
            ->assertDontSee('Administrasi Pemilik')
            ->assertDontSee('Log Aktivitas (Audit)')
            ->assertDontSee('Bahan Baku & Stok');

        $this->actingAs($this->owner)
            ->withSession(['locale' => 'id'])
            ->get(route('dashboard'))
            ->assertStatus(200)
            ->assertSee('Antrean & Pesanan')
            ->assertSee('Kelola Shift & Kas')
            ->assertSee('Administrasi Pemilik')
            ->assertSee('Log Aktivitas (Audit)')
            ->assertSee('Bahan Baku & Stok');
    }
}
