<?php

namespace App\Livewire\Settings;

use App\Models\ActivityLog;
use App\Models\ApplicationSetting;
use App\Models\ShopSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

class ShopSettingsManager extends Component
{
    use WithFileUploads;

    // Business Profile
    public string $name = 'Temu Pulang Coffee & Eatery';

    public string $address = 'Jl. Boulevard Raya Blok A No. 12, Jakarta';

    public string $phone = '0812-3456-7890';

    public string $email = 'halo@temupulang.local';

    public string $currency = 'IDR';

    public string $timezone = 'Asia/Jakarta';

    // Business Day
    public string $businessDayStartTime = '04:00';

    // Operating Hours (Mon - Sun)
    public array $operatingHours = [];

    // Financial Defaults (Percentage displayed, converted to/from basis points)
    public float $taxPercentage = 0.0;

    public bool $isTaxInclusive = false;

    public float $serviceChargePercentage = 0.0;

    // Printer Settings (USB)
    public string $printerConnection = 'usb';

    public string $printerPaperWidth = '80mm';

    public string $printerName = 'POS-80';

    public bool $printerEnabled = true;

    public bool $kitchenPrinterEnabled = false;

    public string $kitchenPrinterName = '';

    // Receipt Configuration
    public string $receiptHeader = '';

    public string $receiptFooter = '';

    public bool $receiptShowLogo = true;

    public bool $receiptShowTax = false;

    public bool $receiptShowCustomerInfo = true;

    // Active Tab in View
    public string $activeTab = 'profile';

    // QRIS Image
    public mixed $qrisImageUpload = null;

    public string $qrisImagePath = '';

    /**
     * Mount component with current DB settings.
     */
    public function mount(): void
    {
        $setting = ShopSetting::current() ?? ShopSetting::first();

        if ($setting) {
            $this->name = $setting->name ?? 'Temu Pulang';
            $this->address = $setting->address ?? '';
            $this->phone = $setting->phone ?? '';
            $this->email = $setting->email ?? '';
            $this->currency = $setting->currency ?? 'IDR';
            $this->timezone = $setting->timezone ?? 'Asia/Jakarta';
            $this->businessDayStartTime = substr($setting->business_day_start_time ?? '04:00:00', 0, 5);

            $this->taxPercentage = $setting->tax_rate / 100;
            $this->isTaxInclusive = (bool) $setting->is_tax_inclusive;
            $this->serviceChargePercentage = $setting->service_charge_rate / 100;

            $this->printerConnection = $setting->printer_connection ?? 'usb';
            $this->printerPaperWidth = $setting->printer_paper_width ?? '80mm';
            $this->printerName = $setting->printer_name ?? 'POS-80';
            $this->printerEnabled = (bool) ($setting->printer_enabled ?? true);
            $this->kitchenPrinterEnabled = (bool) ($setting->kitchen_printer_enabled ?? false);
            $this->kitchenPrinterName = $setting->kitchen_printer_name ?? '';

            $this->receiptHeader = $setting->receipt_header ?? '';
            $this->receiptFooter = $setting->receipt_footer ?? '';
            $this->receiptShowLogo = (bool) $setting->receipt_show_logo;
            $this->receiptShowTax = (bool) $setting->receipt_show_tax;
            $this->receiptShowCustomerInfo = (bool) $setting->receipt_show_customer_info;

            $defaultHours = [
                'monday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                'tuesday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                'wednesday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                'thursday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
                'friday' => ['open' => '08:00', 'close' => '23:00', 'is_closed' => false],
                'saturday' => ['open' => '08:00', 'close' => '23:00', 'is_closed' => false],
                'sunday' => ['open' => '08:00', 'close' => '22:00', 'is_closed' => false],
            ];
            $this->operatingHours = $setting->operating_hours ?: $defaultHours;
        }

        $this->qrisImagePath = (string) ApplicationSetting::get('payment.qris_image_path', '');
    }

    /**
     * Save Shop Settings.
     */
    public function saveSettings(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403, __('messages.settings.unauthorized_update'));
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:100'],
            'businessDayStartTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'taxPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'serviceChargePercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'printerConnection' => ['required', 'in:usb'],
            'printerPaperWidth' => ['required', 'in:58mm,80mm'],
            'printerName' => ['nullable', 'string', 'max:100'],
            'kitchenPrinterName' => ['nullable', 'string', 'max:100'],
            'operatingHours' => ['required', 'array'],
        ]);

        $setting = ShopSetting::firstOrCreate(['id' => 1]);

        // Convert percentage to basis points (e.g. 10.0% -> 1000 bp, 0% -> 0 bp)
        $taxRateBp = (int) round($this->taxPercentage * 100);
        $serviceRateBp = (int) round($this->serviceChargePercentage * 100);

        // Normalize business day start time to H:i:s
        $startTime = strlen($this->businessDayStartTime) === 5
            ? $this->businessDayStartTime.':00'
            : $this->businessDayStartTime;

        $setting->update([
            'name' => $this->name,
            'address' => $this->address ?: null,
            'phone' => $this->phone ?: null,
            'email' => $this->email ?: null,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'business_day_start_time' => $startTime,
            'operating_hours' => $this->operatingHours,
            'tax_rate' => $taxRateBp,
            'is_tax_inclusive' => $this->isTaxInclusive,
            'service_charge_rate' => $serviceRateBp,
            'printer_connection' => 'usb',
            'printer_paper_width' => $this->printerPaperWidth,
            'printer_name' => $this->printerName ?: 'POS-80',
            'printer_enabled' => $this->printerEnabled,
            'kitchen_printer_enabled' => $this->kitchenPrinterEnabled,
            'kitchen_printer_name' => $this->kitchenPrinterName ?: null,
            'receipt_header' => $this->receiptHeader ?: null,
            'receipt_footer' => $this->receiptFooter ?: null,
            'receipt_show_logo' => $this->receiptShowLogo,
            'receipt_show_tax' => $this->receiptShowTax,
            'receipt_show_customer_info' => $this->receiptShowCustomerInfo,
        ]);

        ActivityLog::record(
            action: 'settings.updated',
            description: "Pengaturan toko dan konfigurasi perangkat diperbarui oleh {$user->name}.",
            subject: $setting,
            properties: [
                'tax_rate_bp' => $taxRateBp,
                'service_charge_rate_bp' => $serviceRateBp,
                'printer_connection' => 'usb',
                'printer_paper_width' => $this->printerPaperWidth,
                'printer_name' => $this->printerName,
                'business_day_start_time' => $startTime,
            ],
            user: $user,
            module: 'settings'
        );

        $this->dispatch('notify', message: __('messages.settings.saved_notification'), type: 'success');
    }

    /**
     * Upload or replace the shop QRIS image (Owner only, PNG/WebP, max 2 MB).
     */
    public function uploadQrisImage(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $this->validate([
            'qrisImageUpload' => [
                'required',
                'file',
                'max:2048',
                'mimes:png,webp',
            ],
        ]);

        // Validate real MIME type from file contents, not just extension
        $realPath = $this->qrisImageUpload->getRealPath();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = ($realPath && is_file($realPath))
            ? $finfo->file($realPath)
            : $finfo->buffer($this->qrisImageUpload->get());

        if (! in_array($realMime, ['image/png', 'image/webp'], true)) {
            $this->addError('qrisImageUpload', __('messages.settings.qris_invalid_mime'));

            return;
        }

        $oldPath = (string) ApplicationSetting::get('payment.qris_image_path', '');
        if ($oldPath !== '' && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = (string) $this->qrisImageUpload->store('qris', 'public');

        ApplicationSetting::set(
            key: 'payment.qris_image_path',
            value: $path,
            type: 'string',
            group: 'payment',
            description: 'Path to the shop QRIS image shown in POS payment modal.'
        );

        $this->qrisImagePath = $path;
        $this->qrisImageUpload = null;

        ActivityLog::record(
            action: 'settings.qris_image_updated',
            description: "QRIS image updated by {$user->name}.",
            properties: ['path' => $path],
            user: $user,
            module: 'settings'
        );

        $this->dispatch('notify', message: __('messages.settings.qris_uploaded'), type: 'success');
    }

    /**
     * Remove the current shop QRIS image (Owner only).
     */
    public function removeQrisImage(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $oldPath = (string) ApplicationSetting::get('payment.qris_image_path', '');
        if ($oldPath !== '' && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        ApplicationSetting::set(
            key: 'payment.qris_image_path',
            value: '',
            type: 'string',
            group: 'payment',
            description: 'Path to the shop QRIS image shown in POS payment modal.'
        );

        $this->qrisImagePath = '';
        $this->qrisImageUpload = null;

        ActivityLog::record(
            action: 'settings.qris_image_removed',
            description: "QRIS image removed by {$user->name}.",
            user: $user,
            module: 'settings'
        );

        $this->dispatch('notify', message: __('messages.settings.qris_removed'), type: 'info');
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.settings.shop-settings-manager')
            ->layout('components.layouts.app', [
                'title' => __('messages.settings.page_title'),
                'headerTitle' => __('messages.settings.header_title'),
                'headerSubtitle' => __('messages.settings.header_subtitle'),
            ]);
    }
}
