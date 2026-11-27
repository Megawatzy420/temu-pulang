<div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6">

    @if (auth()->user()?->isOwner() && isset($backupStatus))
        @php
            $bannerClasses = match ($backupStatus['level']) {
                'danger' => 'bg-rose-50 border-rose-300 text-rose-900',
                'warning' => 'bg-amber-50 border-amber-300 text-amber-900',
                default => 'bg-cream-50/90 border-coffee-200/80 text-coffee-800',
            };
            $dotClasses = match ($backupStatus['level']) {
                'danger' => 'bg-rose-600',
                'warning' => 'bg-amber-500',
                default => 'bg-emerald-500',
            };
        @endphp
        <div class="rounded-2xl border px-4 py-3 text-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 {{ $bannerClasses }}">
            <div class="flex items-center gap-2.5">
                <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $dotClasses }}"></span>
                <span class="font-semibold">
                    @if (! $backupStatus['has_completed_backup'])
                        {{ __('messages.owner_dashboard.backup_never_run') }}
                    @elseif ($backupStatus['level'] === 'danger')
                        {{ __('messages.owner_dashboard.backup_overdue', [
                            'datetime' => $backupStatus['last_completed_at_formatted'],
                            'size' => $backupStatus['last_size_formatted'],
                            'hours' => $backupStatus['age_hours'],
                            'max' => $backupStatus['max_age_hours'],
                        ]) }}
                    @elseif ($backupStatus['level'] === 'warning')
                        {{ __('messages.owner_dashboard.backup_aging', [
                            'datetime' => $backupStatus['last_completed_at_formatted'],
                            'size' => $backupStatus['last_size_formatted'],
                            'hours' => $backupStatus['age_hours'],
                        ]) }}
                    @else
                        {{ __('messages.owner_dashboard.backup_healthy', [
                            'datetime' => $backupStatus['last_completed_at_formatted'],
                            'size' => $backupStatus['last_size_formatted'],
                        ]) }}
                    @endif
                </span>
            </div>

            @if ($backupStatus['show_failure_notice'])
                <div class="text-rose-700 font-bold flex items-center gap-1.5">
                    <span>
                        {{ __('messages.owner_dashboard.backup_recent_failure', ['datetime' => $backupStatus['last_failed_at_formatted']]) }}
                    </span>
                </div>
            @endif
        </div>
    @endif

    <!-- Top KPI Metrics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. Total Penjualan Hari Ini (Net, Gross & Refunds) -->
        <x-card padding="p-5" class="relative">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-coffee-500">
                    {{ __('messages.dashboard.today_sales') }}
                </p>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <p class="text-2xl font-bold text-coffee-950 mt-2">
                Rp {{ number_format($metrics['net_sales'], 0, ',', '.') }}
            </p>
            <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-[11px] text-coffee-500">
                <span>{{ __('messages.owner_dashboard.gross_sales_label') }}: <strong class="text-coffee-800">Rp {{ number_format($metrics['gross_sales'], 0, ',', '.') }}</strong></span>
                <span>{{ __('messages.owner_dashboard.refunds_label') }}: <strong class="text-rose-700">Rp {{ number_format($metrics['refunds'], 0, ',', '.') }}</strong></span>
            </div>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs">
                @php
                    $change = $metrics['change_vs_previous'];
                    $changeColor = match ($change['direction']) {
                        'up' => 'text-emerald-600',
                        'down' => 'text-rose-600',
                        default => 'text-coffee-500',
                    };
                @endphp
                <span class="{{ $changeColor }} font-semibold flex items-center gap-0.5">
                    {{ $change['formatted'] }}
                </span>
                <span class="text-coffee-400">{{ __('messages.owner_dashboard.vs_yesterday', ['amount' => 'Rp '.number_format($metrics['previous_net_sales'], 0, ',', '.')]) }}</span>
            </div>
        </x-card>

        <!-- 2. Total Transaksi & Rata-rata -->
        <x-card padding="p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-coffee-500">
                    {{ __('messages.dashboard.today_orders') }}
                </p>
                <span class="w-8 h-8 rounded-xl bg-caramel-50 text-caramel-600 flex items-center justify-center">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                </span>
            </div>
            <p class="text-2xl font-bold text-coffee-950 mt-2">
                {{ $metrics['paid_orders'] }} <span class="text-sm font-normal text-coffee-500">{{ __('messages.owner_dashboard.orders_unit') }}</span>
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs">
                <span class="text-coffee-600 font-medium">{{ __('messages.owner_dashboard.average_prefix') }}</span>
                <span class="text-coffee-800 font-bold">Rp {{ number_format($metrics['avg_ticket'], 0, ',', '.') }}</span>
            </div>
        </x-card>

        <!-- 3. Shift Kasir Aktif -->
        <x-card padding="p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-coffee-500">
                    {{ __('messages.dashboard.active_shift') }}
                </p>
                <span class="w-8 h-8 rounded-xl bg-coffee-100 text-coffee-800 flex items-center justify-center">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <p class="text-lg font-bold text-coffee-950 mt-2 truncate">
                {{ $activeShift?->user?->name ?? __('messages.owner_dashboard.no_active_shift') }}
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs text-coffee-500">
                @if ($activeShift)
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>{{ __('messages.owner_dashboard.shift_opened_at', ['number' => $activeShift->shift_number, 'time' => $activeShift->opened_at->format('H:i')]) }}</span>
                @else
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>{{ __('messages.owner_dashboard.cashier_not_opened_shift') }}</span>
                @endif
            </div>
        </x-card>

        <!-- 4. Menu & Kategori Database -->
        <x-card padding="p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-coffee-500">
                    {{ __('messages.owner_dashboard.menu_catalog_title') }}
                </p>
                <span class="w-8 h-8 rounded-xl bg-stone-100 text-stone-700 flex items-center justify-center">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </span>
            </div>
            <p class="text-2xl font-bold text-coffee-950 mt-2">
                {{ $productCount }} <span class="text-sm font-normal text-coffee-500">{{ __('messages.owner_dashboard.products_unit') }}</span>
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs text-coffee-500">
                <span class="text-coffee-700 font-semibold">{{ __('messages.owner_dashboard.active_categories_count', ['count' => $categoryCount]) }}</span>
                <span>{{ __('messages.owner_dashboard.multilang_badge') }}</span>
            </div>
        </x-card>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left Column: Payment Mix & Recent Orders Table (2/3 width) -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Payment Mix (Money Received) Card -->
            <x-card
                :title="__('messages.owner_dashboard.payment_mix_title')"
                :subtitle="__('messages.owner_dashboard.payment_mix_subtitle')"
            >
                <div class="grid grid-cols-1 sm:grid-cols-{{ $paymentMix['other'] > 0 ? '4' : '3' }} gap-3">
                    <div class="p-3.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <p class="text-xs font-semibold text-coffee-500">{{ __('messages.pos.payment_cash') }}</p>
                        <p class="text-lg font-bold text-coffee-950 mt-1">Rp {{ number_format($paymentMix['cash'], 0, ',', '.') }}</p>
                    </div>
                    <div class="p-3.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <p class="text-xs font-semibold text-coffee-500">{{ __('messages.pos.payment_qris') }}</p>
                        <p class="text-lg font-bold text-coffee-950 mt-1">Rp {{ number_format($paymentMix['qris'], 0, ',', '.') }}</p>
                    </div>
                    <div class="p-3.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <p class="text-xs font-semibold text-coffee-500">{{ __('messages.pos.payment_debit') }}</p>
                        <p class="text-lg font-bold text-coffee-950 mt-1">Rp {{ number_format($paymentMix['debit'], 0, ',', '.') }}</p>
                    </div>
                    @if ($paymentMix['other'] > 0)
                        <div class="p-3.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                            <p class="text-xs font-semibold text-coffee-500">{{ __('messages.owner_dashboard.payment_other') }}</p>
                            <p class="text-lg font-bold text-coffee-950 mt-1">Rp {{ number_format($paymentMix['other'], 0, ',', '.') }}</p>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Recent Orders Card -->
            <x-card
                :title="__('messages.dashboard.recent_orders')"
                :subtitle="__('messages.owner_dashboard.recent_orders_subtitle')"
                padding="p-0"
            >
                @if ($recentOrders->isEmpty())
                    <div class="p-8 text-center space-y-1.5">
                        <p class="text-sm font-semibold text-coffee-800">{{ __('messages.owner_dashboard.no_sales_today') }}</p>
                        <p class="text-xs text-coffee-500">{{ __('messages.owner_dashboard.no_orders_today_desc') }}</p>
                    </div>
                @else
                    <x-table :headers="[
                        __('messages.dashboard.order_id'),
                        __('messages.dashboard.customer_table'),
                        __('messages.owner_dashboard.order_type_col'),
                        __('messages.dashboard.total'),
                        __('messages.dashboard.status')
                    ]">
                        @foreach ($recentOrders as $order)
                            @php
                                [$statusLabel, $statusVariant] = match ($order['status']) {
                                    'draft' => [__('messages.owner_dashboard.badge_draft'), 'neutral'],
                                    'awaiting_payment' => [__('messages.orders.badge_awaiting_payment'), 'warning'],
                                    'paid' => [__('messages.orders.badge_paid'), 'info'],
                                    'queued' => [__('messages.orders.badge_queued'), 'info'],
                                    'preparing' => [__('messages.orders.badge_preparing'), 'warning'],
                                    'ready' => [__('messages.orders.badge_ready'), 'success'],
                                    'completed' => [__('messages.orders.badge_completed'), 'success'],
                                    'cancelled' => [__('messages.orders.badge_cancelled'), 'danger'],
                                    default => [$order['status'], 'neutral'],
                                };
                            @endphp
                            <tr class="hover:bg-cream-50/60 transition-colors">
                                <td class="px-5 py-3.5 font-bold text-coffee-950">
                                    {{ $order['order_number'] }}
                                    <span class="block text-[11px] font-normal text-coffee-400">{{ $order['call_number'] }} • {{ $order['time'] }}</span>
                                </td>
                                <td class="px-5 py-3.5 font-medium text-coffee-800">
                                    {{ $order['customer_name'] ?: __('messages.orders.walk_in_customer') }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="px-2 py-0.5 rounded-md text-[11px] font-semibold bg-coffee-100 text-coffee-800 border border-coffee-200">
                                        {{ $order['order_type'] === 'takeaway' ? __('messages.orders.type_takeaway') : __('messages.orders.type_dine_in') }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 font-bold text-coffee-900">
                                    Rp {{ number_format($order['total_amount'], 0, ',', '.') }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <x-badge :variant="$statusVariant" size="xs">
                                        {{ $statusLabel }}
                                    </x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif

                <x-slot:footer>
                    <div class="flex items-center justify-between text-xs text-coffee-500">
                        <span>{{ __('messages.owner_dashboard.showing_recent_orders') }}</span>
                        <a href="{{ route('pos') }}" class="font-semibold text-coffee-800 hover:text-coffee-950 underline">
                            {{ __('messages.dashboard.open_pos') }} →
                        </a>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        <!-- Right Column: Low Stock, Top Products & Store Hardware (1/3 width) -->
        <div class="space-y-6">

            @if (auth()->user()?->isOwner())
                <!-- Low Stock Alert Card (Owner Only) -->
                <x-card
                    :title="__('messages.stock_alert.dashboard_title')"
                    :subtitle="__('messages.stock_alert.dashboard_subtitle')"
                >
                    @if ($lowStockItems->isEmpty())
                        <p class="text-xs text-coffee-500 italic py-2">{{ __('messages.stock_alert.empty_state') }}</p>
                    @else
                        <div class="space-y-2.5">
                            @foreach ($lowStockItems as $lowItem)
                                <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60 text-xs">
                                    <div class="min-w-0">
                                        <p class="font-bold text-coffee-950 truncate">{{ $lowItem['name'] }}</p>
                                        <p class="text-[11px] text-coffee-500">
                                            {{ $lowItem['current_stock'] }} {{ $lowItem['unit'] }}
                                            ({{ __('messages.stock_alert.threshold_label') }}: {{ $lowItem['min_stock_alert'] }} {{ $lowItem['unit'] }})
                                        </p>
                                    </div>
                                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $lowItem['level'] === 'out' ? 'bg-rose-100 text-rose-800 border border-rose-200' : 'bg-amber-100 text-amber-800 border border-amber-200' }}">
                                        {{ $lowItem['level'] === 'out' ? __('messages.stock_alert.badge_out') : __('messages.stock_alert.badge_low') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <x-slot:footer>
                        <a
                            href="{{ route('inventory.ingredients', ['stock' => 'low_or_out']) }}"
                            class="text-xs font-bold text-caramel-700 hover:text-caramel-900 underline underline-offset-2"
                        >
                            {{ __('messages.stock_alert.view_all') }} →
                        </a>
                    </x-slot:footer>
                </x-card>
            @endif

            <!-- Top Products Card -->
            <x-card
                :title="__('messages.dashboard.best_sellers')"
                :subtitle="__('messages.owner_dashboard.best_sellers_subtitle')"
            >
                @if ($topProducts->isEmpty())
                    <p class="text-xs text-coffee-500 italic py-2">{{ __('messages.owner_dashboard.no_best_sellers_today') }}</p>
                @else
                    <div class="space-y-4">
                        @foreach ($topProducts as $item)
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between text-xs font-semibold text-coffee-900">
                                    <span class="truncate">{{ $item['name'] }}</span>
                                    <span class="text-coffee-500 font-normal shrink-0 ml-2">{{ __('messages.owner_dashboard.sold_count', ['count' => $item['sold_count']]) }}</span>
                                </div>
                                <div class="w-full bg-cream-100 rounded-full h-2 overflow-hidden border border-coffee-200/50">
                                    <div class="bg-gradient-to-r from-coffee-700 to-caramel-500 h-2 rounded-full" style="width: {{ $item['percentage'] }}%"></div>
                                </div>
                                <div class="flex items-center justify-between text-[11px] text-coffee-400">
                                    <span>{{ $item['sku'] }}</span>
                                    <span class="font-medium text-coffee-700">Rp {{ number_format($item['revenue'], 0, ',', '.') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <!-- Store & Hardware Configuration Card -->
            <x-card
                :title="__('messages.owner_dashboard.hardware_status_title')"
                :subtitle="__('messages.owner_dashboard.hardware_status_subtitle')"
            >
                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div>
                                <p class="font-bold text-coffee-900">{{ __('messages.owner_dashboard.printer_title') }}</p>
                                <p class="text-[11px] text-coffee-500">{{ __('messages.owner_dashboard.printer_meta', ['width' => $shopSetting->printer_paper_width ?? '80mm', 'name' => $shopSetting->printer_name ?? 'POS-80']) }}</p>
                            </div>
                        </div>
                        <x-badge variant="{{ ($shopSetting->printer_enabled ?? true) ? 'success' : 'neutral' }}" size="xs">
                            {{ ($shopSetting->printer_enabled ?? true) ? __('messages.owner_dashboard.status_active') : __('messages.owner_dashboard.status_inactive') }}
                        </x-badge>
                    </div>

                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div>
                                <p class="font-bold text-coffee-900">{{ __('messages.owner_dashboard.tax_service_title') }}</p>
                                <p class="text-[11px] text-coffee-500">{{ __('messages.owner_dashboard.tax_service_meta', ['tax' => $formattedTaxRate, 'service' => $formattedServiceRate]) }}</p>
                            </div>
                        </div>
                        <x-badge variant="neutral" size="xs">{{ __('messages.owner_dashboard.default_zero_badge') }}</x-badge>
                    </div>

                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div>
                                <p class="font-bold text-coffee-900">{{ __('messages.owner_dashboard.currency_timezone_title') }}</p>
                                <p class="text-[11px] text-coffee-500">{{ __('messages.owner_dashboard.currency_timezone_meta') }}</p>
                            </div>
                        </div>
                        <x-badge variant="neutral" size="xs">{{ __('messages.owner_dashboard.status_compliant') }}</x-badge>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
