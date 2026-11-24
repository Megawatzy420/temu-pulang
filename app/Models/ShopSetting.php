<?php

namespace App\Models;

use Database\Factories\ShopSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopSetting extends Model
{
    /** @use HasFactory<ShopSettingFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'name',
        'address',
        'phone',
        'email',
        'logo_path',
        'currency',
        'timezone',
        'business_day_start_time',
        'operating_hours',
        'tax_rate',
        'is_tax_inclusive',
        'service_charge_rate',
        'receipt_header',
        'receipt_footer',
        'receipt_show_logo',
        'receipt_show_tax',
        'receipt_show_customer_info',
        'printer_type',
        'printer_paper_width',
        'printer_connection',
        'printer_name',
        'printer_enabled',
        'kitchen_printer_enabled',
        'kitchen_printer_name',
        'printer_ip',
        'printer_port',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operating_hours' => 'array',
            'tax_rate' => 'integer',
            'is_tax_inclusive' => 'boolean',
            'service_charge_rate' => 'integer',
            'receipt_show_logo' => 'boolean',
            'receipt_show_tax' => 'boolean',
            'receipt_show_customer_info' => 'boolean',
            'printer_enabled' => 'boolean',
            'kitchen_printer_enabled' => 'boolean',
            'printer_port' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the current active shop setting.
     */
    public static function current(?int $branchId = null): ?self
    {
        return static::query()
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get tax rate as percentage (e.g. 1000 basis points = 10.0%).
     */
    public function getTaxPercentageAttribute(): float
    {
        return $this->tax_rate / 100;
    }

    /**
     * Get service charge rate as percentage (e.g. 500 basis points = 5.0%).
     */
    public function getServiceChargePercentageAttribute(): float
    {
        return $this->service_charge_rate / 100;
    }
}
