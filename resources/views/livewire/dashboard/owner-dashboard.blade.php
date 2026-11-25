<div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6">

    <!-- Demo Simulation Notice Banner -->
    <div class="rounded-2xl bg-amber-50/90 border border-amber-200/80 p-4 flex items-center justify-between gap-4 text-xs text-amber-900 shadow-2xs">
        <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shrink-0"></span>
            <div>
                <span class="font-bold uppercase tracking-wider text-[11px] text-amber-800 mr-2 bg-amber-200/60 px-2 py-0.5 rounded-md">
                    {{ __('messages.pos.placeholder_badge') }}
                </span>
                <span>{{ __('messages.pos.demo_mode_banner') }}</span>
            </div>
        </div>

        <a
            href="{{ route('pos') }}"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-coffee-900 text-cream-50 font-semibold text-xs hover:bg-coffee-800 transition-colors shrink-0 shadow-xs"
        >
            <span>{{ __('messages.dashboard.open_pos') }}</span>
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
        </a>
    </div>

    <!-- Top KPI Metrics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. Total Penjualan Hari Ini -->
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
                Rp {{ number_format($metrics['today_sales'], 0, ',', '.') }}
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs">
                <span class="text-emerald-600 font-semibold flex items-center gap-0.5">
                    ↑ 14.2%
                </span>
                <span class="text-coffee-400">{{ __('messages.owner_dashboard.vs_yesterday', ['amount' => 'Rp 2.990.000']) }}</span>
            </div>
        </x-card>

        <!-- 2. Total Transaksi -->
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
                {{ $metrics['today_orders'] }} <span class="text-sm font-normal text-coffee-500">{{ __('messages.owner_dashboard.orders_unit') }}</span>
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

        <!-- Left Column: Recent Orders Table (2/3 width) -->
        <div class="lg:col-span-2 space-y-6">
            <x-card
                :title="__('messages.dashboard.recent_orders')"
                :subtitle="__('messages.owner_dashboard.recent_orders_subtitle')"
                padding="p-0"
            >
                <x-table :headers="[
                    __('messages.dashboard.order_id'),
                    __('messages.dashboard.customer_table'),
                    __('messages.dashboard.items'),
                    __('messages.dashboard.payment_method'),
                    __('messages.dashboard.total'),
                    __('messages.dashboard.status')
                ]">
                    @foreach ($recentOrders as $order)
                        <tr class="hover:bg-cream-50/60 transition-colors">
                            <td class="px-5 py-3.5 font-bold text-coffee-950">
                                #{{ $order['id'] }}
                                <span class="block text-[11px] font-normal text-coffee-400">{{ $order['time'] }}</span>
                            </td>
                            <td class="px-5 py-3.5 font-medium text-coffee-800">
                                {{ $order['customer'] }}
                            </td>
                            <td class="px-5 py-3.5 text-xs text-coffee-600 max-w-[200px] truncate" title="{{ $order['items_summary'] }}">
                                {{ $order['items_summary'] }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-semibold bg-coffee-100 text-coffee-800 border border-coffee-200">
                                    {{ $order['payment'] }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-bold text-coffee-900">
                                Rp {{ number_format($order['total'], 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-3.5">
                                <x-badge variant="success" size="xs">
                                    {{ __('messages.dashboard.status_completed') }}
                                </x-badge>
                            </td>
                        </tr>
                    @endforeach
                </x-table>

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

        <!-- Right Column: Top Products & Store Hardware (1/3 width) -->
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
                            {{ __('messages.stock_alert.view_all') }} &rarr;
                        </a>
                    </x-slot:footer>
                </x-card>
            @endif

            <!-- Top Products Card -->
            <x-card
                :title="__('messages.dashboard.best_sellers')"
                :subtitle="__('messages.owner_dashboard.best_sellers_subtitle')"
            >
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
                                <p class="text-[11px] text-coffee-500">{{ __('messages.owner_dashboard.tax_service_meta', ['tax' => $shopSetting->tax_percentage ?? 0.0, 'service' => $shopSetting->service_charge_percentage ?? 0.0]) }}</p>
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
