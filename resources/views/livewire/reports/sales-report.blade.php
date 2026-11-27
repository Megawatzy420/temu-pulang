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
        $tz = $report['window']['timezone'];
        $startTime = $report['window']['start_time'];
        $fromFormatted = \Carbon\CarbonImmutable::parse($report['from_date'], $tz)
            ->locale($locale)
            ->translatedFormat('d M Y');
        $toFormatted = \Carbon\CarbonImmutable::parse($report['to_date'], $tz)
            ->locale($locale)
            ->translatedFormat('d M Y');
        $generatedFormatted = $report['generated_at']
            ->copy()
            ->setTimezone($tz)
            ->locale($locale)
            ->translatedFormat('d M Y, H:i:s');
        $totals = $report['totals'];
        $mix = $report['payment_mix'];
        $uncosted = $report['uncosted_sales'];
        $tracking = $report['cogs_tracking'];
    @endphp

    <!-- Top Header & Print / Filter Toolbar -->
    <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-6 print-card space-y-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-stone-100 pb-5">
            <div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 class="text-xl font-bold text-stone-900 tracking-tight">
                        {{ __('messages.sales_report.heading') }}
                    </h2>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">
                        {{ __('messages.sales_report.period_badge', ['from' => $fromFormatted, 'to' => $toFormatted, 'days' => $report['days_count']]) }}
                    </span>
                </div>
                <p class="text-sm text-stone-500 mt-1">
                    {{ __('messages.sales_report.subheading', ['start_time' => $startTime, 'tz' => $tz]) }}
                </p>
                <p class="text-xs text-stone-400 mt-1 font-mono">
                    {{ __('messages.sales_report.generated_at_label', ['time' => $generatedFormatted]) }}
                </p>
            </div>

            <div class="no-print flex flex-col items-start lg:items-end gap-1.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <button
                        type="button"
                        wire:click="refreshReport"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-stone-700 bg-white border border-stone-300 rounded-xl hover:bg-stone-50 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4 text-stone-500" wire:loading.class="animate-spin" wire:target="refreshReport" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        {{ __('messages.sales_report.refresh_btn') }}
                    </button>

                    <a
                        href="{{ route('reports.sales.export', ['format' => 'xlsx', 'from' => $report['from_date'], 'to' => $report['to_date']]) }}"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold text-emerald-900 bg-emerald-50 border border-emerald-300 rounded-xl hover:bg-emerald-100 transition-colors"
                    >
                        {{ __('messages.export.export_excel_btn') }}
                    </a>

                    <a
                        href="{{ route('reports.sales.export', ['format' => 'csv', 'from' => $report['from_date'], 'to' => $report['to_date']]) }}"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold text-stone-800 bg-stone-100 border border-stone-300 rounded-xl hover:bg-stone-200 transition-colors"
                    >
                        {{ __('messages.export.export_csv_btn') }}
                    </a>

                    <button
                        type="button"
                        onclick="window.print()"
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-stone-900 rounded-xl hover:bg-stone-800 transition-colors shadow-xs cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        {{ __('messages.sales_report.print_btn') }}
                    </button>
                </div>
                <p class="text-[11px] text-stone-500">
                    {{ __('messages.export.pdf_print_hint') }}
                </p>
            </div>
        </div>

        <!-- Date Range Inputs & Quick Presets -->
        <div class="no-print flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="sales-report-from" class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1">
                        {{ __('messages.sales_report.filter_from_label') }}
                    </label>
                    <input
                        id="sales-report-from"
                        type="date"
                        wire:model.live="from"
                        class="w-full px-3.5 py-2 text-sm bg-stone-50 border border-stone-300 rounded-xl text-stone-900 focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-600 font-mono"
                    />
                </div>
                <div>
                    <label for="sales-report-to" class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1">
                        {{ __('messages.sales_report.filter_to_label') }}
                    </label>
                    <input
                        id="sales-report-to"
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
                    {{ __('messages.sales_report.preset_today') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('yesterday')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.sales_report.preset_yesterday') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('this_week')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.sales_report.preset_this_week') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('this_month')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.sales_report.preset_this_month') }}
                </button>
                <button
                    type="button"
                    wire:click="applyPreset('last_month')"
                    class="px-3 py-2 text-xs font-semibold rounded-xl border border-stone-200 bg-stone-50 text-stone-700 hover:bg-amber-50 hover:text-amber-900 hover:border-amber-300 transition-colors cursor-pointer"
                >
                    {{ __('messages.sales_report.preset_last_month') }}
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

    <!-- COGS Tracking Completeness Warning Banner -->
    @if ($tracking['show_warning'])
        <div class="bg-amber-50/90 border border-amber-300 text-amber-950 rounded-2xl px-5 py-4 text-sm flex items-start gap-3 print-card">
            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div>
                @if ($tracking['earliest_deduction_date'] !== null)
                    @php
                        $earliestFormatted = \Carbon\CarbonImmutable::parse($tracking['earliest_deduction_date'], $tz)
                            ->locale($locale)
                            ->translatedFormat('d M Y');
                    @endphp
                    <p class="font-semibold">
                        {{ __('messages.sales_report.cogs_tracking_warning_with_date', ['date' => $earliestFormatted]) }}
                    </p>
                @else
                    <p class="font-semibold">
                        {{ __('messages.sales_report.cogs_tracking_warning_no_deductions') }}
                    </p>
                @endif
            </div>
        </div>
    @endif

    <!-- Summary KPI Cards (8 Cards) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Net Sales -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_net_sales') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                @if ($totals['net_sales'] < 0)
                    -Rp {{ number_format(abs($totals['net_sales']), 0, ',', '.') }}
                @else
                    Rp {{ number_format($totals['net_sales'], 0, ',', '.') }}
                @endif
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_net_sales_meta') }}
            </p>
        </div>

        <!-- Gross Sales -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_gross_sales') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                Rp {{ number_format($totals['gross_sales'], 0, ',', '.') }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_gross_sales_meta', [
                    'subtotal' => number_format($totals['subtotal'], 0, ',', '.'),
                    'tax' => number_format($totals['tax'], 0, ',', '.'),
                    'service' => number_format($totals['service_charge'], 0, ',', '.'),
                ]) }}
            </p>
        </div>

        <!-- Refunds -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_refunds') }}
            </p>
            <p class="text-2xl font-bold {{ $totals['refunds'] > 0 ? 'text-red-600' : 'text-stone-900' }} font-mono mt-2">
                @if ($totals['refunds'] > 0)
                    -Rp {{ number_format($totals['refunds'], 0, ',', '.') }}
                @else
                    Rp 0
                @endif
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_refunds_meta', ['count' => number_format($totals['refunds_count'], 0, ',', '.')]) }}
            </p>
        </div>

        <!-- COGS -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_cogs') }}
            </p>
            <p class="text-2xl font-bold text-amber-800 font-mono mt-2">
                @if ($totals['cogs'] < 0)
                    -Rp {{ number_format(abs($totals['cogs']), 0, ',', '.') }}
                @else
                    Rp {{ number_format($totals['cogs'], 0, ',', '.') }}
                @endif
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_cogs_meta') }}
            </p>
        </div>

        <!-- Gross Profit -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_gross_profit') }}
            </p>
            <p class="text-2xl font-bold {{ $totals['gross_profit'] < 0 ? 'text-red-600' : 'text-emerald-700' }} font-mono mt-2">
                @if ($totals['gross_profit'] < 0)
                    -Rp {{ number_format(abs($totals['gross_profit']), 0, ',', '.') }}
                @else
                    Rp {{ number_format($totals['gross_profit'], 0, ',', '.') }}
                @endif
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_gross_profit_meta') }}
            </p>
        </div>

        <!-- Margin -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_margin') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                {{ $totals['margin']['formatted'] }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_margin_meta') }}
            </p>
        </div>

        <!-- Paid Orders Count -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_paid_orders') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                {{ number_format($totals['paid_orders_count'], 0, ',', '.') }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_paid_orders_meta') }}
            </p>
        </div>

        <!-- Average Ticket -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs p-5 print-card">
            <p class="text-xs font-bold text-stone-500 uppercase tracking-wider">
                {{ __('messages.sales_report.card_avg_ticket') }}
            </p>
            <p class="text-2xl font-bold text-stone-900 font-mono mt-2">
                Rp {{ number_format($totals['avg_ticket'], 0, ',', '.') }}
            </p>
            <p class="text-xs text-stone-500 mt-1.5">
                {{ __('messages.sales_report.card_avg_ticket_meta') }}
            </p>
        </div>
    </div>

    <!-- Uncosted Sales (Honesty Line) & Payment Mix Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Uncosted Sales (Honesty Line) -->
        <div class="bg-white rounded-2xl border {{ $uncosted['amount'] > 0 ? 'border-amber-300' : 'border-stone-200/80' }} shadow-xs overflow-hidden print-card">
            <div class="px-6 py-4 border-b border-stone-100 {{ $uncosted['amount'] > 0 ? 'bg-amber-50/70' : 'bg-stone-50/60' }} flex items-center justify-between">
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.sales_report.uncosted_heading') }}
                </h3>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $uncosted['amount'] > 0 ? 'bg-amber-200/80 text-amber-900' : 'bg-emerald-100 text-emerald-800' }}">
                    {{ __('messages.sales_report.uncosted_share_label', ['share' => $uncosted['share_formatted']]) }}
                </span>
            </div>
            <div class="p-6 space-y-3">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm text-stone-600">{{ __('messages.sales_report.uncosted_amount_label') }}</span>
                    <span class="text-xl font-bold font-mono {{ $uncosted['amount'] > 0 ? 'text-amber-800' : 'text-stone-900' }}">
                        Rp {{ number_format($uncosted['amount'], 0, ',', '.') }}
                    </span>
                </div>
                <p class="text-xs text-stone-600 leading-relaxed pt-2 border-t border-stone-100">
                    {{ __('messages.sales_report.uncosted_explanation') }}
                </p>
                @if ($uncosted['amount'] === 0 && $totals['gross_sales'] > 0)
                    <p class="text-xs font-medium text-emerald-700">
                        {{ __('messages.sales_report.uncosted_zero_note') }}
                    </p>
                @endif
            </div>
        </div>

        <!-- Payment Mix Card -->
        <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
            <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
                <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                    {{ __('messages.sales_report.payment_mix_heading') }}
                </h3>
                <p class="text-xs text-stone-500 mt-0.5">
                    {{ __('messages.sales_report.payment_mix_subtitle') }}
                </p>
            </div>
            <div class="p-6 space-y-2.5 text-sm">
                <div class="flex items-center justify-between py-1">
                    <span class="text-stone-600">{{ __('messages.sales_report.payment_cash') }}</span>
                    <span class="font-mono font-semibold text-stone-900">
                        Rp {{ number_format($mix['cash'], 0, ',', '.') }}
                    </span>
                </div>
                <div class="flex items-center justify-between py-1">
                    <span class="text-stone-600">{{ __('messages.sales_report.payment_qris') }}</span>
                    <span class="font-mono font-semibold text-stone-900">
                        Rp {{ number_format($mix['qris'], 0, ',', '.') }}
                    </span>
                </div>
                <div class="flex items-center justify-between py-1">
                    <span class="text-stone-600">{{ __('messages.sales_report.payment_debit') }}</span>
                    <span class="font-mono font-semibold text-stone-900">
                        Rp {{ number_format($mix['debit'], 0, ',', '.') }}
                    </span>
                </div>
                @if ($mix['other'] > 0)
                    <div class="flex items-center justify-between py-1">
                        <span class="text-stone-600">{{ __('messages.sales_report.payment_other') }}</span>
                        <span class="font-mono font-semibold text-stone-900">
                            Rp {{ number_format($mix['other'], 0, ',', '.') }}
                        </span>
                    </div>
                @endif
                <div class="flex items-center justify-between pt-3 border-t-2 border-stone-200 font-bold text-stone-900">
                    <span>{{ __('messages.sales_report.payment_total') }}</span>
                    <span class="font-mono">
                        Rp {{ number_format($mix['total'], 0, ',', '.') }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Per-Day Breakdown Table -->
    <div class="bg-white rounded-2xl border border-stone-200/80 shadow-xs overflow-hidden print-card">
        <div class="px-6 py-4 border-b border-stone-100 bg-stone-50/60">
            <h3 class="text-sm font-bold text-stone-800 uppercase tracking-wider">
                {{ __('messages.sales_report.daily_table_heading') }}
            </h3>
            <p class="text-xs text-stone-500 mt-0.5">
                {{ __('messages.sales_report.daily_table_subtitle', ['start_time' => $startTime]) }}
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-stone-50 text-xs font-bold text-stone-600 uppercase tracking-wider border-b border-stone-200">
                    <tr>
                        <th class="px-4 py-3 whitespace-nowrap">{{ __('messages.sales_report.col_date') }}</th>
                        <th class="px-3 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_orders') }}</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_gross_sales') }}</th>
                        <th class="px-3 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_tax') }}</th>
                        <th class="px-3 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_service_charge') }}</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_refunds') }}</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_net_sales') }}</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_cogs') }}</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_gross_profit') }}</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">{{ __('messages.sales_report.col_margin') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($report['daily_rows'] as $row)
                        @php
                            $rowDateFormatted = \Carbon\CarbonImmutable::parse($row['business_date'], $tz)
                                ->locale($locale)
                                ->translatedFormat('d M Y');
                        @endphp
                        <tr class="hover:bg-stone-50/70">
                            <td class="px-4 py-3 font-medium text-stone-900 whitespace-nowrap">
                                {{ $rowDateFormatted }}
                                <span class="text-xs text-stone-400 font-mono ml-1">({{ $row['business_date'] }})</span>
                            </td>
                            <td class="px-3 py-3 text-right font-mono text-stone-700 whitespace-nowrap">
                                {{ number_format($row['paid_orders_count'], 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-stone-900 whitespace-nowrap">
                                Rp {{ number_format($row['gross_sales'], 0, ',', '.') }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono text-stone-600 whitespace-nowrap">
                                Rp {{ number_format($row['tax'], 0, ',', '.') }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono text-stone-600 whitespace-nowrap">
                                Rp {{ number_format($row['service_charge'], 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono {{ $row['refunds'] > 0 ? 'text-red-600 font-semibold' : 'text-stone-500' }} whitespace-nowrap">
                                @if ($row['refunds'] > 0)
                                    -Rp {{ number_format($row['refunds'], 0, ',', '.') }}
                                @else
                                    Rp 0
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-stone-900 whitespace-nowrap">
                                @if ($row['net_sales'] < 0)
                                    -Rp {{ number_format(abs($row['net_sales']), 0, ',', '.') }}
                                @else
                                    Rp {{ number_format($row['net_sales'], 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-amber-800 whitespace-nowrap">
                                @if ($row['cogs'] < 0)
                                    -Rp {{ number_format(abs($row['cogs']), 0, ',', '.') }}
                                @else
                                    Rp {{ number_format($row['cogs'], 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold {{ $row['gross_profit'] < 0 ? 'text-red-600' : 'text-emerald-700' }} whitespace-nowrap">
                                @if ($row['gross_profit'] < 0)
                                    -Rp {{ number_format(abs($row['gross_profit']), 0, ',', '.') }}
                                @else
                                    Rp {{ number_format($row['gross_profit'], 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-stone-800 whitespace-nowrap">
                                {{ $row['margin']['formatted'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-stone-100/90 border-t-2 border-stone-300 font-bold text-stone-900">
                    <tr>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            {{ __('messages.sales_report.totals_row_label', ['days' => $report['days_count']]) }}
                        </td>
                        <td class="px-3 py-3.5 text-right font-mono whitespace-nowrap">
                            {{ number_format($totals['paid_orders_count'], 0, ',', '.') }}
                        </td>
                        <td class="px-4 py-3.5 text-right font-mono whitespace-nowrap">
                            Rp {{ number_format($totals['gross_sales'], 0, ',', '.') }}
                        </td>
                        <td class="px-3 py-3.5 text-right font-mono whitespace-nowrap">
                            Rp {{ number_format($totals['tax'], 0, ',', '.') }}
                        </td>
                        <td class="px-3 py-3.5 text-right font-mono whitespace-nowrap">
                            Rp {{ number_format($totals['service_charge'], 0, ',', '.') }}
                        </td>
                        <td class="px-4 py-3.5 text-right font-mono {{ $totals['refunds'] > 0 ? 'text-red-700' : 'text-stone-700' }} whitespace-nowrap">
                            @if ($totals['refunds'] > 0)
                                -Rp {{ number_format($totals['refunds'], 0, ',', '.') }}
                            @else
                                Rp 0
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right font-mono whitespace-nowrap">
                            @if ($totals['net_sales'] < 0)
                                -Rp {{ number_format(abs($totals['net_sales']), 0, ',', '.') }}
                            @else
                                Rp {{ number_format($totals['net_sales'], 0, ',', '.') }}
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right font-mono text-amber-900 whitespace-nowrap">
                            @if ($totals['cogs'] < 0)
                                -Rp {{ number_format(abs($totals['cogs']), 0, ',', '.') }}
                            @else
                                Rp {{ number_format($totals['cogs'], 0, ',', '.') }}
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right font-mono {{ $totals['gross_profit'] < 0 ? 'text-red-700' : 'text-emerald-800' }} whitespace-nowrap">
                            @if ($totals['gross_profit'] < 0)
                                -Rp {{ number_format(abs($totals['gross_profit']), 0, ',', '.') }}
                            @else
                                Rp {{ number_format($totals['gross_profit'], 0, ',', '.') }}
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right font-mono whitespace-nowrap">
                            {{ $totals['margin']['formatted'] }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if ($totals['has_cogs_rounding_note'])
            <div class="px-6 py-3 bg-stone-50 border-t border-stone-200 text-xs text-stone-600">
                {{ __('messages.sales_report.cogs_rounding_note', [
                    'total_cogs' => number_format($totals['cogs'], 0, ',', '.'),
                    'diff' => number_format(abs($totals['cogs_rounding_diff']), 0, ',', '.'),
                    'daily_sum' => number_format($totals['daily_rounded_cogs_sum'], 0, ',', '.'),
                ]) }}
            </div>
        @endif
    </div>
</div>
