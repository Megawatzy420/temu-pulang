<?php

namespace Tests\Feature;

use App\Livewire\Pos\CashierPos;
use App\Livewire\Settings\ShopSettingsManager;
use App\Models\ApplicationSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class QrisImageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        Storage::fake('public');
    }

    protected function makeFakeWebp(string $filename = 'qris.webp'): UploadedFile
    {
        // Minimal valid 1x1 lossless WebP (RIFF + WEBP + VP8L)
        $binary = base64_decode('UklGRh4AAABXRUJQVlA4TBEAAAAvAAAAAAfQ//73v/+BiOh/AAA=');

        return UploadedFile::fake()->createWithContent($filename, $binary);
    }

    public function test_owner_can_upload_png_qris_image(): void
    {
        $file = UploadedFile::fake()->image('qris.png', 200, 200);

        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('activeTab', 'qris')
            ->set('qrisImageUpload', $file)
            ->call('uploadQrisImage')
            ->assertHasNoErrors();

        $storedPath = ApplicationSetting::get('payment.qris_image_path');
        $this->assertNotEmpty($storedPath);
        $this->assertStringStartsWith('qris/', $storedPath);
        Storage::disk('public')->assertExists($storedPath);

        $row = ApplicationSetting::where('key', 'payment.qris_image_path')->first();
        $this->assertSame('payment', $row->group);
        $this->assertSame('string', $row->type);
    }

    public function test_owner_can_upload_webp_qris_image(): void
    {
        $file = $this->makeFakeWebp('qris.webp');

        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('activeTab', 'qris')
            ->set('qrisImageUpload', $file)
            ->call('uploadQrisImage')
            ->assertHasNoErrors();

        $storedPath = ApplicationSetting::get('payment.qris_image_path');
        $this->assertNotEmpty($storedPath);
        $this->assertStringStartsWith('qris/', $storedPath);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_jpg_gif_svg_and_oversized_files_are_rejected(): void
    {
        $jpg = UploadedFile::fake()->createWithContent('qris.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $jpg)
            ->call('uploadQrisImage')
            ->assertHasErrors(['qrisImageUpload']);

        $gif = UploadedFile::fake()->create('qris.gif', 10, 'image/gif');
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $gif)
            ->call('uploadQrisImage')
            ->assertHasErrors(['qrisImageUpload']);

        $svg = UploadedFile::fake()->createWithContent('qris.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $svg)
            ->call('uploadQrisImage')
            ->assertHasErrors(['qrisImageUpload']);

        $oversized = UploadedFile::fake()->create('qris.png', 2049, 'image/png');
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $oversized)
            ->call('uploadQrisImage')
            ->assertHasErrors(['qrisImageUpload']);

        $this->assertNull(ApplicationSetting::where('key', 'payment.qris_image_path')->first());
    }

    public function test_spoofed_extension_png_file_is_rejected(): void
    {
        // Plain text / HTML content disguised with .png extension
        $spoofed = UploadedFile::fake()->createWithContent('fake.png', '<html><body>not a png</body></html>');

        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $spoofed)
            ->call('uploadQrisImage')
            ->assertHasErrors(['qrisImageUpload']);

        $this->assertNull(ApplicationSetting::where('key', 'payment.qris_image_path')->first());
    }

    public function test_replacing_qris_image_deletes_the_old_file(): void
    {
        $first = UploadedFile::fake()->image('first.png', 150, 150);

        $component = Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $first)
            ->call('uploadQrisImage')
            ->assertHasNoErrors();

        $firstPath = ApplicationSetting::get('payment.qris_image_path');
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->image('second.png', 150, 150);
        $component->set('qrisImageUpload', $second)
            ->call('uploadQrisImage')
            ->assertHasNoErrors();

        $secondPath = ApplicationSetting::get('payment.qris_image_path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_removing_qris_image_deletes_file_and_clears_setting(): void
    {
        $file = UploadedFile::fake()->image('qris.png', 150, 150);

        $component = Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $file)
            ->call('uploadQrisImage')
            ->assertHasNoErrors();

        $path = ApplicationSetting::get('payment.qris_image_path');
        Storage::disk('public')->assertExists($path);

        $component->call('removeQrisImage')
            ->assertSet('qrisImagePath', '');

        Storage::disk('public')->assertMissing($path);
        $this->assertSame('', ApplicationSetting::get('payment.qris_image_path'));
    }

    public function test_cashier_cannot_upload_or_remove_qris_image(): void
    {
        $file = UploadedFile::fake()->image('qris.png', 150, 150);

        Livewire::actingAs($this->cashier)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $file)
            ->call('uploadQrisImage')
            ->assertForbidden();

        Livewire::actingAs($this->cashier)
            ->test(ShopSettingsManager::class)
            ->call('removeQrisImage')
            ->assertForbidden();
    }

    public function test_pos_shows_qris_image_and_amount_when_configured_and_notice_when_missing(): void
    {
        app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Shift QRIS test'
        );

        $product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();

        // 1. When no QRIS image is configured, POS shows translated notice
        $pos = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'qris');

        $pos->assertSee(__('messages.pos.qris_no_image_notice'));

        // 2. Upload QRIS image as owner
        $file = UploadedFile::fake()->image('qris.png', 200, 200);
        Livewire::actingAs($this->owner)
            ->test(ShopSettingsManager::class)
            ->set('qrisImageUpload', $file)
            ->call('uploadQrisImage')
            ->assertHasNoErrors();

        $storedPath = ApplicationSetting::get('payment.qris_image_path');

        // 3. Re-render POS: shows the QRIS image URL and total amount as text
        $pos2 = Livewire::actingAs($this->cashier)
            ->test(CashierPos::class)
            ->call('selectProduct', $product->id)
            ->call('openCheckout')
            ->set('paymentMethod', 'qris');

        $expectedFormattedTotal = 'Rp '.number_format($product->base_price, 0, ',', '.');
        $pos2->assertSee(Storage::url($storedPath))
            ->assertSee($expectedFormattedTotal)
            ->assertDontSee(__('messages.pos.qris_no_image_notice'));
    }
}
