<?php

namespace Tests\Feature;

use App\Livewire\Settings\ShopSettingsManager;
use App\Models\ShopSetting;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\ShopSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopSettingsOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ShopSettingSeeder::class);
    }

    /**
     * 20. Tax default is 0%.
     * 21. Service charge default is 0%.
     * 22. Printer default is USB.
     */
    public function test_default_financial_and_printer_settings_are_correct(): void
    {
        $setting = ShopSetting::first();

        $this->assertNotNull($setting);
        $this->assertEquals(0, $setting->tax_rate, 'Default tax rate should be 0 basis points (0%)');
        $this->assertEquals(0.0, $setting->tax_percentage);
        $this->assertEquals(0, $setting->service_charge_rate, 'Default service charge rate should be 0 basis points (0%)');
        $this->assertEquals(0.0, $setting->service_charge_percentage);
        $this->assertEquals('usb', $setting->printer_connection, 'Default printer connection should be USB');
        $this->assertEquals('80mm', $setting->printer_paper_width);
        $this->assertEquals('Asia/Jakarta', $setting->timezone);
        $this->assertEquals('IDR', $setting->currency);
    }

    /**
     * 18. Owner can update shop settings.
     */
    public function test_owner_can_update_shop_settings(): void
    {
        $owner = User::factory()->owner()->create();

        Livewire::actingAs($owner)
            ->test(ShopSettingsManager::class)
            ->assertStatus(200)
            ->set('name', 'Temu Pulang Kopi & Eatery Baru')
            ->set('taxPercentage', 10.0) // 10%
            ->set('serviceChargePercentage', 5.0) // 5%
            ->set('printerPaperWidth', '58mm')
            ->set('printerName', 'Thermal-58-USB')
            ->set('businessDayStartTime', '04:00')
            ->call('saveSettings')
            ->assertDispatched('notify');

        $setting = ShopSetting::first();
        $this->assertEquals('Temu Pulang Kopi & Eatery Baru', $setting->name);
        $this->assertEquals(1000, $setting->tax_rate); // 10.0% converted to 1000 bp
        $this->assertEquals(500, $setting->service_charge_rate); // 5.0% converted to 500 bp
        $this->assertEquals('58mm', $setting->printer_paper_width);
        $this->assertEquals('Thermal-58-USB', $setting->printer_name);
        $this->assertEquals('04:00:00', $setting->business_day_start_time);

        // Activity log recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.updated',
            'module' => 'settings',
        ]);
    }

    /**
     * 19. Cashier cannot update shop settings.
     */
    public function test_cashier_cannot_access_or_update_shop_settings(): void
    {
        $cashier = User::factory()->cashier()->create();

        // HTTP Route level check: Cashier blocked by role:owner middleware
        $response = $this->actingAs($cashier)->get('/settings');
        $response->assertRedirect('/pos');

        // Direct Livewire component call authorization check
        Livewire::actingAs($cashier)
            ->test(ShopSettingsManager::class)
            ->call('saveSettings')
            ->assertForbidden();
    }

    /**
     * 23. Paper width accepts 58mm and 80mm only.
     */
    public function test_paper_width_accepts_58mm_and_80mm_only(): void
    {
        $owner = User::factory()->owner()->create();

        // Invalid width rejected
        Livewire::actingAs($owner)
            ->test(ShopSettingsManager::class)
            ->set('printerPaperWidth', '100mm')
            ->call('saveSettings')
            ->assertHasErrors(['printerPaperWidth']);

        // 58mm accepted
        Livewire::actingAs($owner)
            ->test(ShopSettingsManager::class)
            ->set('printerPaperWidth', '58mm')
            ->call('saveSettings')
            ->assertHasNoErrors(['printerPaperWidth']);

        // 80mm accepted
        Livewire::actingAs($owner)
            ->test(ShopSettingsManager::class)
            ->set('printerPaperWidth', '80mm')
            ->call('saveSettings')
            ->assertHasNoErrors(['printerPaperWidth']);
    }

    /**
     * 24. Business day start time is validated.
     */
    public function test_business_day_start_time_is_validated(): void
    {
        $owner = User::factory()->owner()->create();

        // Invalid format rejected
        Livewire::actingAs($owner)
            ->test(ShopSettingsManager::class)
            ->set('businessDayStartTime', '25:99')
            ->call('saveSettings')
            ->assertHasErrors(['businessDayStartTime']);

        // Valid 04:00 accepted
        Livewire::actingAs($owner)
            ->test(ShopSettingsManager::class)
            ->set('businessDayStartTime', '04:00')
            ->call('saveSettings')
            ->assertHasNoErrors(['businessDayStartTime']);
    }
}
