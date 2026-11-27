<div class="max-w-7xl mx-auto space-y-6 pb-12">
    <style>
        @media print {
            aside,
            header,
            .no-print {
                display: none !important;
            }
            main {
                padding: 0 !important;
                overflow: visible !important;
            }
            body,
            div {
                background: #ffffff !important;
                color: #111827 !important;
            }
            .print-card {
                box-shadow: none !important;
                border-color: #d1d5db !important;
                break-inside: avoid;
            }
        }
    </style>

    @php
        $locale = app()->getLocale();
        $tz = $analytics['window']['timezone'];
        $startTime = $analytics['window']['start_time'];
        $fromFormatted = \Carbon\CarbonImmutable::parse($analytics['from_date'], $tz)
            ->locale($locale)
            ->translatedFormat('d M Y');
        $toFormatted = \Carbon\CarbonImmutable::parse($analytics['to_date'], $tz)
            ->locale($locale)
            ->translatedFormat('d M Y');
        $generatedFormatted = $analytics['generated_at']
            ->copy()
            ->setTimezone($tz)
            ->locale($locale)
            ->translatedFormat('d M Y, H:i:s');
        $totals = $analytics['totals'];
        $hourly = $analytics['sales_by_hour'];
        $weekday = $analytics['sales_by_weekday'];
        $orderTypes = $analytics['sales_by_order_type'];
        $zeroSales = $analytics['products_without_sales'];
    @endphp

    <!-- Top Header & Print / Filter Toolbar -->
    <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-6 print-card space-y-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-stone-100 pb-5">
            <div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 class="text-xl font-bold text-stone-900 tracking-tight">
                        {{ __('messages.analytics.heading') }}
                    </h2>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">
                        {{ __('messages.analytics.period_badge', ['from' => $fromFormatted, 'to' => $toFormatted, 'days' => $analytics['days_count']]) }}
                    </span>
                </div>
                <p class="text-sm text-stone-500 mt-1">
                    {{ __('messages.analytics.subheading', ['start_time' => $startTime, 'tz' => $tz]) }}
                </p>
                <p class="text-xs text-stone-400 mt-1 font-mono">
                    {{ __('messages.analytics.generated_at_label', ['time' => $generatedFormatted]) }}
                </p>
            </div>

            <div class="no-print flex flex-wrap items-center gap-2.5">
                <a
                    href="{{ route('reports.sales', ['from' => $analytics['from_date'], 'to' => $analytics['to_date']]) }}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-amber-900 bg-amber-50 border border-amber-200 rounded-xl hover:bg-amber-100 transition-colors"
                >
                    {{ __('messages.analytics.sales_report_link') }}
                </a>

                <button
                    type="button"
                    wire:click="refreshAnalytics"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-stone-700 bg-white border border-stone-300 rounded-xl hover:bg-stone-50 transition-colors cursor-pointer"
                >
                    <svg class="w-4 h-4 text-stone-500" wire:loading.class="animate-spin" wire:target="refreshAnalytics" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ __('messages.analytics.refresh_btn') }}
                </button>

                <button
                    type="button"
                    onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-stone-900 rounded-xl hover:bg-stone-800 transition-colors shadow-xs cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    {{ __('messages.analytics.print_btn') }}
                </button>
            </div>
        </div>

        <!-- Date Range Inputs & Quick Presets -->
        <div class="no-print flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="analytics-from" class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1">
                        {{ __('messages.analytics.filter_from_label') }}
                    </label>
                    <input
                        id="analytics-from"
                        type="date"
                        wire:model.live="from"
                        class="w-full px-3.5 py-2 text-sm bg-stone-50 border border-stone-300 rounded-xl text-stone-900 focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-600 font-mono"
                    />
                </div>
                <div>
                    <label for="analytics-to" class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1">
                        {{ __('messages.analytics.filter_to_label') }}
                    </label>
                    <input
                        id="analytics-to"
                        type="date"
                        wire:model.live="to"
                        class="w-full px-3.5 py-2 text-sm bg-stone-50 border border-stone-300 rounded-xl text-stone-900 focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-600 font-mono"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="applyPreset('today')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.analytics.preset_today') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('yesterday')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.analytics.preset_yesterday') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('this_week')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.analytics.preset_this_week') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('this_month')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.analytics.preset_this_month') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('last_month')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.analytics.preset_last_month') }}
                </button>
            </div>
        </div>
    </div>

    <!-- Date Validation Reset Notice -->
    @if ($validationNotice)
        <div class="no-print bg-amber-50 border border-amber-300 text-amber-900 rounded-2xl px-5 py-3.5 text-sm font-medium flex items-center gap-3">
            <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{{ $validationNotice }}</span>
        </div>
    @endif

    <!-- Summary KPI Cards (4 Cards) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Gross Sales -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.analytics.card_gross_sales') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                Rp {{ number_format($totals['gross_sales'], 0, ',', '.') }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.analytics.card_gross_sales_meta') }}
            </p>
        </div>

        <!-- Paid Orders Count & Avg Ticket -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.analytics.card_paid_orders') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                {{ number_format($totals['paid_orders_count'], 0, ',', '.') }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.analytics.card_paid_orders_meta', ['avg' => number_format($totals['avg_ticket'], 0, ',', '.')]) }}
            </p>
        </div>

        <!-- Ranked Items Sold -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.analytics.card_ranked_items') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                {{ number_format($totals['ranked_items_quantity'], 0, ',', '.') }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.analytics.card_ranked_items_meta', [
                    'products' => number_format($totals['ranked_products_count'], 0, ',', '.'),
                    'revenue' => number_format($totals['ranked_items_revenue'], 0, ',', '.'),
                ]) }}
            </p>
        </div>

        <!-- Peak Hour -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.analytics.card_peak_hour') }}
            </p>
            @if ($hourly['peak_hour_label'] !== null)
                <p class="text-2xl font-bold text-amber-800 font-mono mt-2">
                    {{ $hourly['peak_hour_label'] }}
                </p>
                <p class="text-xs text-stone-500 mt-1.5">
                    {{ __('messages.analytics.card_peak_hour_meta', [
                        'orders' => number_format($hourly['peak_hour_orders'], 0, ',', '.'),
                        'sales' => number_format($hourly['peak_hour_sales'], 0, ',', '.'),
                    ]) }}
                </p>
            @else
                <p class="text-2xl font-bold text-stone-400 font-mono mt-2">
                    —
                </p>
                <p class="text-xs text-stone-500 mt-1.5">
                    {{ __('messages.analytics.card_peak_hour_none') }}
                </p>
            @endif
        </div>
    </div>

    <!-- Per-Product Margin Explanation Note -->
    <div class="bg-stone-50 border border-stone-200/90 text-stone-700 rounded-2xl px-5 py-3.5 text-xs leading-relaxed flex items-start gap-3 print-card">
        <svg class="w-4 h-4 text-stone-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p>{{ __('messages.analytics.no_per_product_margin_note') }}</p>
    </div>

    <!-- Top 10 by Quantity & Top 10 by Revenue -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top 10 by Quantity -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
            <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.analytics.top_quantity_heading') }}
                </h3>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ __('messages.analytics.top_quantity_subtitle') }}
                </p>
            </div>
            <div class="p-6">
                @if (count($analytics['top_by_quantity']) === 0)
                    <p class="text-sm text-stone-500 text-center py-6">
                        {{ __('messages.analytics.empty_rankings') }}
                    </p>
                @else
                    <div class="space-y-4">
                        @foreach ($analytics['top_by_quantity'] as $item)
                            <div class="space-y-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="flex items-baseline gap-2 min-w-0">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold {{ $item['rank'] <= 3 ? 'bg-amber-100 text-amber-900' : 'bg-stone-100 text-stone-700' }} shrink-0">
                                            {{ $item['rank'] }}
                                        </span>
                                        <span class="text-sm font-semibold text-stone-900 truncate">
                                            {{ $item['name'] }}
                                        </span>
                                        @if ($item['sku'] !== '')
                                            <span class="text-xs font-mono text-stone-400 shrink-0">
                                                ({{ $item['sku'] }})
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="text-sm font-bold font-mono text-stone-900">
                                            {{ __('messages.analytics.qty_unit', ['qty' => number_format($item['quantity'], 0, ',', '.')]) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 h-2.5 bg-stone-100 rounded-full overflow-hidden">
                                        <div
                                            class="h-full bg-amber-600 rounded-full"
                                            style="width: {{ $item['bar_pct'] }}%"
                                        ></div>
                                    </div>
                                    <span class="text-xs font-mono text-stone-600 shrink-0">
                                        Rp {{ number_format($item['revenue'], 0, ',', '.') }} ({{ $item['revenue_share_formatted'] }})
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Top 10 by Revenue -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
            <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.analytics.top_revenue_heading') }}
                </h3>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ __('messages.analytics.top_revenue_subtitle') }}
                </p>
            </div>
            <div class="p-6">
                @if (count($analytics['top_by_revenue']) === 0)
                    <p class="text-sm text-stone-500 text-center py-6">
                        {{ __('messages.analytics.empty_rankings') }}
                    </p>
                @else
                    <div class="space-y-4">
                        @foreach ($analytics['top_by_revenue'] as $item)
                            <div class="space-y-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="flex items-baseline gap-2 min-w-0">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold {{ $item['rank'] <= 3 ? 'bg-emerald-100 text-emerald-900' : 'bg-stone-100 text-stone-700' }} shrink-0">
                                            {{ $item['rank'] }}
                                        </span>
                                        <span class="text-sm font-semibold text-stone-900 truncate">
                                            {{ $item['name'] }}
                                        </span>
                                        @if ($item['sku'] !== '')
                                            <span class="text-xs font-mono text-stone-400 shrink-0">
                                                ({{ $item['sku'] }})
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="text-sm font-bold font-mono text-stone-900">
                                            Rp {{ number_format($item['revenue'], 0, ',', '.') }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 h-2.5 bg-stone-100 rounded-full overflow-hidden">
                                        <div
                                            class="h-full bg-emerald-600 rounded-full"
                                            style="width: {{ $item['bar_pct'] }}%"
                                        ></div>
                                    </div>
                                    <span class="text-xs font-mono text-stone-600 shrink-0">
                                        {{ $item['revenue_share_formatted'] }} • {{ __('messages.analytics.qty_unit', ['qty' => number_format($item['quantity'], 0, ',', '.')]) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Sales by Clock Hour (00:00–23:00) -->
    <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
        <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.analytics.hourly_heading') }}
                </h3>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ __('messages.analytics.hourly_subtitle', ['tz' => $tz]) }}
                </p>
            </div>
            <div class="text-xs font-mono text-stone-600">
                {{ __('messages.analytics.total_label') }}:
                <span class="font-bold text-stone-900">{{ __('messages.analytics.orders_count_label', ['count' => number_format($hourly['total_orders'], 0, ',', '.')]) }}</span>
                •
                <span class="font-bold text-stone-900">Rp {{ number_format($hourly['total_gross_sales'], 0, ',', '.') }}</span>
            </div>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2.5">
            @foreach ($hourly['hours'] as $h)
                <div class="flex items-center gap-3 py-1 {{ $h['is_peak'] ? 'bg-amber-50/80 -mx-2 px-2 rounded-xl border border-amber-200' : '' }}">
                    <div class="w-14 shrink-0 flex items-center gap-1.5">
                        <span class="text-xs font-mono font-semibold {{ $h['is_peak'] ? 'text-amber-900' : 'text-stone-700' }}">
                            {{ $h['label'] }}
                        </span>
                    </div>
                    <div class="flex-1 h-2.5 bg-stone-100 rounded-full overflow-hidden">
                        <div
                            class="h-full {{ $h['is_peak'] ? 'bg-amber-600' : 'bg-stone-700' }} rounded-full"
                            style="width: {{ $h['sales_bar_pct'] }}%"
                        ></div>
                    </div>
                    <div class="w-48 shrink-0 text-right font-mono text-xs flex items-center justify-end gap-1.5">
                        @if ($h['is_peak'])
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-200 text-amber-900">
                                {{ __('messages.analytics.peak_badge') }}
                            </span>
                        @endif
                        <span class="{{ $h['gross_sales'] > 0 ? 'font-semibold text-stone-900' : 'text-stone-400' }}">
                            Rp {{ number_format($h['gross_sales'], 0, ',', '.') }}
                        </span>
                        <span class="text-stone-400">
                            ({{ number_format($h['orders_count'], 0, ',', '.') }})
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Sales by Business Weekday & Breakdown by Order Type -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sales by Business Weekday (Monday to Sunday) -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
            <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.analytics.weekday_heading') }}
                </h3>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ __('messages.analytics.weekday_subtitle', ['start_time' => $startTime]) }}
                </p>
            </div>
            <div class="p-6 space-y-3">
                @foreach ($weekday['weekdays'] as $day)
                    <div class="flex items-center gap-3 py-1 {{ $day['is_peak'] ? 'bg-amber-50/80 -mx-2 px-2 rounded-xl border border-amber-200' : '' }}">
                        <div class="w-28 shrink-0 flex items-center gap-1.5">
                            <span class="text-sm font-semibold {{ $day['is_peak'] ? 'text-amber-900' : 'text-stone-800' }}">
                                {{ __($day['label_key']) }}
                            </span>
                            @if ($day['is_peak'])
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-200 text-amber-900">
                                    {{ __('messages.analytics.peak_badge') }}
                                </span>
                            @endif
                        </div>
                        <div class="flex-1 h-3 bg-stone-100 rounded-full overflow-hidden">
                            <div
                                class="h-full {{ $day['is_peak'] ? 'bg-amber-600' : 'bg-stone-700' }} rounded-full"
                                style="width: {{ $day['sales_bar_pct'] }}%"
                            ></div>
                        </div>
                        <div class="w-52 shrink-0 text-right font-mono text-xs">
                            <span class="font-bold text-stone-900">
                                Rp {{ number_format($day['gross_sales'], 0, ',', '.') }}
                            </span>
                            <span class="text-stone-500">
                                ({{ number_format($day['orders_count'], 0, ',', '.') }} • {{ $day['share_formatted'] }})
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Sales by Order Type (Dine-In vs Takeaway) -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
            <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.analytics.order_type_heading') }}
                </h3>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ __('messages.analytics.order_type_subtitle') }}
                </p>
            </div>
            <div class="p-6 space-y-5">
                @foreach ($orderTypes['types'] as $typeData)
                    <div class="space-y-2">
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-bold text-stone-900">
                                {{ __($typeData['label_key']) }}
                            </span>
                            <span class="text-sm font-bold font-mono text-stone-900">
                                Rp {{ number_format($typeData['gross_sales'], 0, ',', '.') }}
                            </span>
                        </div>
                        <div class="h-3 bg-stone-100 rounded-full overflow-hidden">
                            <div
                                class="h-full {{ $typeData['order_type'] === 'dine_in' ? 'bg-amber-600' : 'bg-emerald-600' }} rounded-full"
                                style="width: {{ $typeData['sales_bar_pct'] }}%"
                            ></div>
                        </div>
                        <div class="flex items-center justify-between text-xs text-stone-600 font-mono">
                            <span>
                                {{ __('messages.analytics.order_type_orders_share', [
                                    'count' => number_format($typeData['orders_count'], 0, ',', '.'),
                                    'share' => $typeData['orders_share_formatted'],
                                ]) }}
                            </span>
                            <span class="font-semibold text-stone-800">
                                {{ __('messages.analytics.order_type_sales_share', ['share' => $typeData['sales_share_formatted']]) }}
                            </span>
                        </div>
                    </div>
                @endforeach

                <div class="pt-4 border-t border-stone-200 flex items-center justify-between text-xs font-mono font-bold text-stone-900">
                    <span>{{ __('messages.analytics.total_label') }} ({{ number_format($orderTypes['total_orders'], 0, ',', '.') }})</span>
                    <span>Rp {{ number_format($orderTypes['total_gross_sales'], 0, ',', '.') }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Products Without Sales -->
    <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
        <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
            <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                {{ __('messages.analytics.zero_sales_heading', ['count' => number_format($zeroSales['total_count'], 0, ',', '.')]) }}
            </h3>
            <p class="text-xs text-stone-500 mt-0.5">
                {{ __('messages.analytics.zero_sales_subtitle') }}
            </p>
        </div>
        <div class="p-6">
            @if ($zeroSales['total_count'] === 0)
                <p class="text-sm font-medium text-emerald-700">
                    {{ __('messages.analytics.zero_sales_none') }}
                </p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ($zeroSales['items'] as $product)
                        <div class="px-3.5 py-2.5 rounded-xl border border-stone-200 bg-stone-50/60 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-stone-900 truncate">
                                    {{ $product['name'] }}
                                </p>
                                <p class="text-xs font-mono text-stone-500">
                                    {{ $product['sku'] }} • Rp {{ number_format($product['base_price'], 0, ',', '.') }}
                                </p>
                            </div>
                            @if ($product['is_sold_out'])
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 shrink-0">
                                    {{ __('messages.analytics.zero_sales_sold_out_badge') }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($zeroSales['remaining_count'] > 0)
                    <p class="text-xs font-semibold text-stone-600 mt-4">
                        {{ __('messages.analytics.zero_sales_and_more', ['count' => number_format($zeroSales['remaining_count'], 0, ',', '.')]) }}
                    </p>
                @endif
            @endif
        </div>
    </div>
</div>
