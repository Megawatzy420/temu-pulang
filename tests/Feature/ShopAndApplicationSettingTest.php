<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopAndApplicationSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_settings_can_store_business_profile_and_hardware_config(): void
    {
        $shop = ShopSetting::create([
            'name' => 'Temu Pulang Coffee & Eatery',
            'address' => 'Jl. Boulevard Raya Blok A No. 12, Jakarta',
            'phone' => '0812-3456-7890',
            'email' => 'halo@temupulang.local',
            'logo_path' => 'images/logo.png',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'business_day_start_time' => '06:00:00',
            'operating_hours' => [
                'monday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
            ],
            'tax_rate' => 1000, // 10.00%
            'is_tax_inclusive' => false,
            'service_charge_rate' => 500, // 5.00%
            'receipt_header' => "TEMU PULANG\nCoffee & Eatery",
            'receipt_footer' => 'Terima Kasih!',
            'receipt_show_logo' => true,
            'receipt_show_tax' => true,
            'receipt_show_customer_info' => true,
            'printer_type' => 'thermal',
            'printer_paper_width' => '80mm',
            'printer_connection' => 'network',
            'printer_ip' => '192.168.1.200',
            'printer_port' => 9100,
            'is_active' => true,
        ]);

        $this->assertEquals('Temu Pulang Coffee & Eatery', $shop->name);
        $this->assertEquals('06:00:00', $shop->business_day_start_time);
        $this->assertEquals(10.0, $shop->tax_percentage);
        $this->assertEquals(5.0, $shop->service_charge_percentage);
        $this->assertIsArray($shop->operating_hours);
        $this->assertEquals('08:00', $shop->operating_hours['monday']['open']);
        $this->assertEquals('80mm', $shop->printer_paper_width);
        $this->assertEquals('192.168.1.200', $shop->printer_ip);
        $this->assertEquals(9100, $shop->printer_port);

        // Current helper returns active shop
        $current = ShopSetting::current();
        $this->assertNotNull($current);
        $this->assertEquals($shop->id, $current->id);
    }

    public function test_application_setting_typed_get_and_set(): void
    {
        // 1. String setting
        ApplicationSetting::set('app.name', 'Temu Pulang POS', 'string', 'general');
        $this->assertEquals('Temu Pulang POS', ApplicationSetting::get('app.name'));

        // 2. Boolean setting
        ApplicationSetting::set('pos.auto_print', true, 'boolean', 'pos');
        $this->assertTrue(ApplicationSetting::get('pos.auto_print'));

        ApplicationSetting::set('pos.allow_discount', false, 'boolean', 'pos');
        $this->assertFalse(ApplicationSetting::get('pos.allow_discount'));

        // 3. Integer setting
        ApplicationSetting::set('pos.tax_rate_bp', 1100, 'integer', 'pos');
        $this->assertSame(1100, ApplicationSetting::get('pos.tax_rate_bp'));

        // 4. JSON setting
        $locales = ['id', 'en', 'jv'];
        ApplicationSetting::set('app.available_locales', $locales, 'json', 'localization');
        $this->assertEquals($locales, ApplicationSetting::get('app.available_locales'));

        // 5. Fallback for non-existent key
        $this->assertEquals('fallback_val', ApplicationSetting::get('non.existent.key', 'fallback_val'));
    }

    public function test_application_settings_can_be_scoped_by_group(): void
    {
        ApplicationSetting::set('general.1', 'val1', 'string', 'general');
        ApplicationSetting::set('general.2', 'val2', 'string', 'general');
        ApplicationSetting::set('pos.1', 'val3', 'string', 'pos');

        $generalSettings = ApplicationSetting::byGroup('general')->get();
        $this->assertCount(2, $generalSettings);

        $posSettings = ApplicationSetting::byGroup('pos')->get();
        $this->assertCount(1, $posSettings);
    }
}
