<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Livewire\Inventory\RecipeManager;
use App\Livewire\Menu\CategoryManager;
use App\Livewire\Menu\ModifierManager;
use App\Livewire\Menu\ProductForm;
use App\Livewire\Menu\ProductIndex;
use App\Models\ActivityLog;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerCatalogScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
    }

    public function test_product_form_create_and_edit_render_in_english_and_indonesian(): void
    {
        $product = Product::firstOrFail();

        // 1. Create mode in EN
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ProductForm::class)
            ->assertStatus(200)
            ->assertSee('Add New Menu Product')
            ->assertSee('Save Product')
            ->assertSee('1. Basic Information & Price')
            ->assertSee('2. Content & Language (ID / EN)')
            ->assertSee('3. Menu Variants')
            ->assertSee('4. Modifier Groups')
            ->assertSee('5. Ingredient Recipe (BOM)')
            ->assertSee('Operational Status')
            ->assertDontSee('Tambah Produk Menu Baru')
            ->assertDontSee('Simpan Produk')
            ->assertDontSee('1. Informasi Dasar & Harga')
            ->assertDontSee('Status Operasional');

        // 2. Edit mode in EN
        Livewire::actingAs($this->owner)
            ->test(ProductForm::class, ['id' => $product->id])
            ->assertStatus(200)
            ->assertSee('Edit Menu Product')
            ->assertSee('Update Product')
            ->assertDontSee('Edit Produk Menu')
            ->assertDontSee('Perbarui Produk');

        // 3. Create mode in ID
        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(ProductForm::class)
            ->assertStatus(200)
            ->assertSee('Tambah Produk Menu Baru')
            ->assertSee('Simpan Produk')
            ->assertSee('1. Informasi Dasar & Harga')
            ->assertSee('2. Konten & Bahasa (ID / EN)')
            ->assertSee('Status Operasional')
            ->assertDontSee('Add New Menu Product')
            ->assertDontSee('Save Product');

        // 4. Edit mode in ID
        Livewire::actingAs($this->owner)
            ->test(ProductForm::class, ['id' => $product->id])
            ->assertStatus(200)
            ->assertSee('Edit Produk Menu')
            ->assertSee('Perbarui Produk')
            ->assertDontSee('Edit Menu Product')
            ->assertDontSee('Update Product');
    }

    public function test_product_index_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ProductIndex::class)
            ->assertStatus(200)
            ->assertSee('Menu & Product List')
            ->assertSee('+ Add New Product')
            ->assertSee('Menu Categories')
            ->assertSee('Modifier Groups')
            ->assertSee('All Availability')
            ->assertSee('Variants & Options')
            ->assertDontSee('Daftar Menu & Produk')
            ->assertDontSee('+ Tambah Produk Baru')
            ->assertDontSee('Semua Ketersediaan')
            ->assertDontSee('Varian & Opsi');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(ProductIndex::class)
            ->assertStatus(200)
            ->assertSee('Daftar Menu & Produk')
            ->assertSee('+ Tambah Produk Baru')
            ->assertSee('Kategori Menu')
            ->assertSee('Grup Modifier')
            ->assertSee('Semua Ketersediaan')
            ->assertSee('Varian & Opsi')
            ->assertDontSee('Menu & Product List')
            ->assertDontSee('+ Add New Product');
    }

    public function test_category_manager_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(CategoryManager::class)
            ->call('openCreateModal')
            ->assertStatus(200)
            ->assertSee('Manage Menu Categories')
            ->assertSee('+ Add Category')
            ->assertSee('Category Name (ID / EN)')
            ->assertSee('Add New Menu Category')
            ->assertSee('Category Name (Indonesian)')
            ->assertSee('Active Category & Displayed on Cashier POS')
            ->assertDontSee('Kelola Kategori Menu')
            ->assertDontSee('+ Tambah Kategori')
            ->assertDontSee('Tambah Kategori Menu Baru')
            ->assertDontSee('Nama Kategori (Bahasa Indonesia)');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(CategoryManager::class)
            ->call('openCreateModal')
            ->assertStatus(200)
            ->assertSee('Kelola Kategori Menu')
            ->assertSee('+ Tambah Kategori')
            ->assertSee('Nama Kategori (ID / EN)')
            ->assertSee('Tambah Kategori Menu Baru')
            ->assertSee('Nama Kategori (Bahasa Indonesia)')
            ->assertSee('Kategori Aktif & Ditampilkan di Kasir POS')
            ->assertDontSee('Manage Menu Categories')
            ->assertDontSee('Add New Menu Category');
    }

    public function test_modifier_manager_with_open_group_renders_in_english_and_indonesian(): void
    {
        $group = ModifierGroup::firstOrFail();

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ModifierManager::class)
            ->call('openEditModal', $group->id)
            ->assertStatus(200)
            ->assertSee('Manage Customization Groups (Modifiers)')
            ->assertSee('+ Add Modifier Group')
            ->assertSee('Edit Group & Options')
            ->assertSee('Edit Modifier Group & Options')
            ->assertSee('Group Name (ID)')
            ->assertSee('Required When Ordering')
            ->assertSee('Option Choices List')
            ->assertSee('-- No Ingredient Deduction --')
            ->assertDontSee('Kelola Grup Kustomisasi (Modifier)')
            ->assertDontSee('+ Tambah Grup Modifier')
            ->assertDontSee('Edit Grup Modifier & Opsi')
            ->assertDontSee('Wajib Dipilih Saat Pesan')
            ->assertDontSee('Daftar Pilihan Opsi');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(ModifierManager::class)
            ->call('openEditModal', $group->id)
            ->assertStatus(200)
            ->assertSee('Kelola Grup Kustomisasi (Modifier)')
            ->assertSee('+ Tambah Grup Modifier')
            ->assertSee('Edit Grup & Opsi')
            ->assertSee('Edit Grup Modifier & Opsi')
            ->assertSee('Nama Grup (ID)')
            ->assertSee('Wajib Dipilih Saat Pesan')
            ->assertSee('Daftar Pilihan Opsi')
            ->assertSee('-- Tanpa Pengurangan Bahan --')
            ->assertDontSee('Manage Customization Groups (Modifiers)')
            ->assertDontSee('Edit Modifier Group & Options');
    }

    public function test_recipe_manager_renders_in_english_and_indonesian(): void
    {
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(RecipeManager::class)
            ->assertStatus(200)
            ->assertSee('Menu Recipes & Estimated COGS (Bill of Materials)')
            ->assertSee('Manage Ingredients')
            ->assertSee('Manage Menu Catalog')
            ->assertSee('Total Catalog Items')
            ->assertSee('Configured Recipes')
            ->assertSee('Missing Recipes')
            ->assertSee('Recipe Composition (BOM)')
            ->assertSee('Configure Recipe')
            ->assertDontSee('Resep Menu & Estimasi HPP (Bill of Materials)')
            ->assertDontSee('Kelola Bahan Baku')
            ->assertDontSee('Total Menu Katalog')
            ->assertDontSee('Resep Terkonfigurasi')
            ->assertDontSee('Atur Resep');

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(RecipeManager::class)
            ->assertStatus(200)
            ->assertSee('Resep Menu & Estimasi HPP (Bill of Materials)')
            ->assertSee('Kelola Bahan Baku')
            ->assertSee('Kelola Katalog Menu')
            ->assertSee('Total Menu Katalog')
            ->assertSee('Resep Terkonfigurasi')
            ->assertSee('Belum Memiliki Resep')
            ->assertSee('Komposisi Resep (BOM)')
            ->assertSee('Atur Resep')
            ->assertDontSee('Menu Recipes & Estimated COGS (Bill of Materials)')
            ->assertDontSee('Total Catalog Items')
            ->assertDontSee('Configure Recipe');
    }

    public function test_activity_log_with_entry_renders_in_english_and_indonesian(): void
    {
        $log = ActivityLog::record(
            action: 'shift.opened',
            description: 'Shift SFT-TEST dibuka.',
            properties: ['opening_cash' => 100000],
            user: $this->owner,
            module: 'shift'
        );

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ActivityLogIndex::class)
            ->call('viewDetails', $log->id)
            ->assertStatus(200)
            ->assertSee('Audit Trail & Activity Records')
            ->assertSee('Recorded Logs')
            ->assertSee('All Users')
            ->assertSee('All Modules')
            ->assertSee('Authentication & Access')
            ->assertSee('Cashier Shift')
            ->assertSee('Activity Description')
            ->assertSee('Owner')
            ->assertSee('Shift SFT-TEST dibuka.')
            ->assertSee('Log Property Snapshot Details #'.$log->id)
            ->assertDontSee('Audit Trail & Rekam Aktivitas')
            ->assertDontSee('Log Tercatat')
            ->assertDontSee('Semua Pengguna')
            ->assertDontSee('Semua Modul')
            ->assertDontSee('Deskripsi Aktivitas')
            ->assertDontSee('Detail Snapshot Properti Log #'.$log->id);

        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(ActivityLogIndex::class)
            ->call('viewDetails', $log->id)
            ->assertStatus(200)
            ->assertSee('Audit Trail & Rekam Aktivitas')
            ->assertSee('Log Tercatat')
            ->assertSee('Semua Pengguna')
            ->assertSee('Semua Modul')
            ->assertSee('Otentikasi & Akses')
            ->assertSee('Shift Kasir')
            ->assertSee('Deskripsi Aktivitas')
            ->assertSee('Pemilik')
            ->assertSee('Shift SFT-TEST dibuka.')
            ->assertSee('Detail Snapshot Properti Log #'.$log->id)
            ->assertDontSee('Audit Trail & Activity Records')
            ->assertDontSee('Recorded Logs')
            ->assertDontSee('Log Property Snapshot Details #'.$log->id);
    }
}
