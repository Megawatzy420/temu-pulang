<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Services\BackupService;
use App\Services\DashboardMetricsService;
use App\Services\Inventory\LowStockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class OwnerDashboard extends Component
{
    /**
     * Mount the Owner Dashboard component and verify Owner authorization.
     */
    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }
    }

    /**
     * Render the Owner Dashboard view with live operational metrics.
     */
    public function render(DashboardMetricsService $metricsService): View
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $shopSetting = ShopSetting::current() ?? new ShopSetting([
            'name' => 'Temu Pulang Coffee & Eatery',
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'business_day_start_time' => '06:00:00',
            'timezone' => 'Asia/Jakarta',
            'printer_connection' => 'usb',
            'printer_paper_width' => '80mm',
        ]);

        $productCount = Product::active()->count();
        $categoryCount = Category::active()->count();
        $activeShift = Shift::open()->with('user')->latest('opened_at')->first();

        $snapshot = $metricsService->getDashboardSnapshot(setting: $shopSetting);

        $metrics = [
            'gross_sales' => $snapshot['gross_sales'],
            'refunds' => $snapshot['refunds'],
            'net_sales' => $snapshot['net_sales'],
            'paid_orders' => $snapshot['paid_orders'],
            'avg_ticket' => $snapshot['avg_ticket'],
            'previous_net_sales' => $snapshot['previous_net_sales'],
            'change_vs_previous' => $snapshot['change_vs_previous'],
        ];

        $lowStockItems = app(LowStockService::class)->lowStockIngredients(limit: 5);
        $backupStatus = app(BackupService::class)->getDashboardStatus(
            timezone: (string) ($shopSetting->timezone ?: 'Asia/Jakarta'),
        );

        $formattedTaxRate = DashboardMetricsService::formatBasisPoints((int) ($shopSetting->tax_rate ?? 0));
        $formattedServiceRate = DashboardMetricsService::formatBasisPoints((int) ($shopSetting->service_charge_rate ?? 0));

        return view('livewire.dashboard.owner-dashboard', [
            'shopSetting' => $shopSetting,
            'productCount' => $productCount,
            'categoryCount' => $categoryCount,
            'activeShift' => $activeShift,
            'metrics' => $metrics,
            'paymentMix' => $snapshot['payment_mix'],
            'recentOrders' => $snapshot['recent_orders'],
            'topProducts' => $snapshot['best_sellers'],
            'lowStockItems' => $lowStockItems,
            'backupStatus' => $backupStatus,
            'formattedTaxRate' => $formattedTaxRate,
            'formattedServiceRate' => $formattedServiceRate,
        ])->layout('components.layouts.app', [
            'title' => __('messages.nav.dashboard'),
            'headerTitle' => __('messages.dashboard.welcome'),
            'headerSubtitle' => __('messages.dashboard.subtitle'),
        ]);
    }
}
