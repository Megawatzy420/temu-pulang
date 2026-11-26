<?php

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use App\Models\ShopSetting;
use Illuminate\Database\Seeder;

class ShopSettingSeeder extends Seeder
{
    public const SAMPLE_ADDRESS = 'Jl. Boulevard Raya Blok A No. 12, Jakarta';

    public const SAMPLE_PHONE = '0812-3456-7890';

    public const SAMPLE_EMAIL = 'halo@temupulang.local';

    public const SAMPLE_RECEIPT_HEADER = "TEMU PULANG\nCoffee & Eatery\nJl. Boulevard Raya Blok A No. 12, Jakarta\nTelp: 0812-3456-7890";

    public const SAMPLE_RECEIPT_FOOTER = "Terima Kasih Atas Kunjungan Anda!\nFollow Instagram: @temupulang\nWiFi: TemuPulang | Pass: kopienak";

    public const GENERIC_RECEIPT_FOOTER = 'Terima kasih atas kunjungan Anda';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $useSampleValues = app()->environment(['local', 'testing']);

        // 1. Physical Shop / Branch Profile & Hardware Configuration
        ShopSetting::updateOrCreate(
            ['id' => 1],
            [
                'branch_id' => null,
                'name' => 'Temu Pulang Coffee & Eatery',
                'address' => $useSampleValues ? self::SAMPLE_ADDRESS : '',
                'phone' => $useSampleValues ? self::SAMPLE_PHONE : '',
                'email' => $useSampleValues ? self::SAMPLE_EMAIL : '',
                'logo_path' => 'images/logo.png',
                'currency' => 'IDR',
                'timezone' => 'Asia/Jakarta',
                'business_day_start_time' => '06:00:00',
                'operating_hours' => [
                    'monday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                    'tuesday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                    'wednesday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                    'thursday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                    'friday' => ['open' => '08:00', 'close' => '23:00', 'is_closed' => false],
                    'saturday' => ['open' => '08:00', 'close' => '23:00', 'is_closed' => false],
                    'sunday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                ],
                'tax_rate' => 0, // 0.00% default
                'is_tax_inclusive' => false,
                'service_charge_rate' => 0, // 0.00% default
                'receipt_header' => $useSampleValues ? self::SAMPLE_RECEIPT_HEADER : null,
                'receipt_footer' => $useSampleValues ? self::SAMPLE_RECEIPT_FOOTER : self::GENERIC_RECEIPT_FOOTER,
                'receipt_show_logo' => true,
                'receipt_show_tax' => false,
                'receipt_show_customer_info' => true,
                'printer_type' => 'thermal',
                'printer_paper_width' => '80mm',
                'printer_connection' => 'usb',
                'printer_name' => 'POS-80',
                'printer_enabled' => true,
                'kitchen_printer_enabled' => false,
                'kitchen_printer_name' => null,
                'printer_ip' => null,
                'printer_port' => 9100,
                'is_active' => true,
            ]
        );

        // 2. Global Application Settings
        $settings = [
            [
                'key' => 'app.name',
                'value' => 'Temu Pulang',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Business display name',
            ],
            [
                'key' => 'app.default_locale',
                'value' => 'id',
                'type' => 'string',
                'group' => 'localization',
                'description' => 'Default system language (id = Bahasa Indonesia)',
            ],
            [
                'key' => 'app.available_locales',
                'value' => json_encode(['id', 'en']),
                'type' => 'json',
                'group' => 'localization',
                'description' => 'List of supported languages',
            ],
            [
                'key' => 'app.timezone',
                'value' => 'Asia/Jakarta',
                'type' => 'string',
                'group' => 'localization',
                'description' => 'Operational timezone (WIB)',
            ],
            [
                'key' => 'app.currency',
                'value' => 'IDR',
                'type' => 'string',
                'group' => 'finance',
                'description' => 'Base currency code',
            ],
            [
                'key' => 'app.currency_symbol',
                'value' => 'Rp',
                'type' => 'string',
                'group' => 'finance',
                'description' => 'Currency symbol prefix',
            ],
            [
                'key' => 'app.date_format',
                'value' => 'd/m/Y',
                'type' => 'string',
                'group' => 'format',
                'description' => 'Standard date format',
            ],
            [
                'key' => 'app.time_format',
                'value' => 'H:i',
                'type' => 'string',
                'group' => 'format',
                'description' => 'Standard 24-hour time format',
            ],
            [
                'key' => 'app.thousand_separator',
                'value' => '.',
                'type' => 'string',
                'group' => 'format',
                'description' => 'Indonesian standard thousand separator',
            ],
            [
                'key' => 'app.decimal_separator',
                'value' => ',',
                'type' => 'string',
                'group' => 'format',
                'description' => 'Indonesian standard decimal separator',
            ],
            [
                'key' => 'pos.business_day_start',
                'value' => '06:00:00',
                'type' => 'string',
                'group' => 'pos',
                'description' => 'Time when a new business day starts for shift accounting',
            ],
            [
                'key' => 'pos.allow_price_override',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'pos',
                'description' => 'Allow cashier to override item prices manually',
            ],
            [
                'key' => 'pos.auto_print_receipt',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'printer',
                'description' => 'Automatically print receipt upon payment completion',
            ],
        ];

        foreach ($settings as $setting) {
            ApplicationSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
