<?php

namespace Database\Factories;

use App\Models\ShopSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopSetting>
 */
class ShopSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'name' => 'Temu Pulang Coffee & Eatery',
            'address' => 'Jl. Merdeka No. 45, Jakarta',
            'phone' => '081234567890',
            'email' => 'contact@temupulang.local',
            'logo_path' => null,
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
            'tax_rate' => 1000, // 10.00%
            'is_tax_inclusive' => false,
            'service_charge_rate' => 500, // 5.00%
            'receipt_header' => "Temu Pulang\nTempat Bertemu & Pulang",
            'receipt_footer' => "Terima Kasih Atas Kunjungan Anda!\nFollow IG: @temupulang",
            'receipt_show_logo' => true,
            'receipt_show_tax' => true,
            'receipt_show_customer_info' => true,
            'printer_type' => 'thermal',
            'printer_paper_width' => '80mm',
            'printer_connection' => 'network',
            'printer_ip' => '192.168.1.200',
            'printer_port' => 9100,
            'is_active' => true,
        ];
    }
}
