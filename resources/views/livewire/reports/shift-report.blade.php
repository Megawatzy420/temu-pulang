<div class="p-4 sm:p-6 lg:p-8 max-w-5xl mx-auto space-y-6 print-report-container">
    <style>
        @media print {
            aside,
            header,
            .no-print {
                display: none !important;
            }
            body,
            main,
            .print-report-container {
                background: #ffffff !important;
                color: #000000 !important;
                overflow: visible !important;
                height: auto !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-card {
                box-shadow: none !important;
                border: 1px solid #d6d3d1 !important;
                break-inside: avoid;
            }
        }
    </style>

    <!-- Action Bar (Hidden in Print) -->
    <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <a
            href="{{ route('shifts') }}"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-xs font-bold text-coffee-800 hover:bg-cream-100 transition"
        >
            {{ __('messages.reports.back_to_shifts') }}
        </a>

        <div class="flex items-center gap-2">
            @if ($report['is_open'])
                <button
                    type="button"
                    wire:click="refreshReport"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-xs font-bold text-coffee-800 hover:bg-cream-100 transition cursor-pointer"
                >
                    <svg class="w-4 h-4 text-coffee-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span>{{ __('messages.reports.refresh_btn') }}</span>
                </button>
            @endif

            <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-coffee-900 hover:bg-coffee-800 text-cream-50 text-xs font-bold shadow-xs transition cursor-pointer"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                <span>{{ __('messages.reports.print_btn') }}</span>
            </button>
        </div>
    </div>

    <!-- Report Header Card -->
    <div class="print-card bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-coffee-100">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-coffee-500">
                    {{ $report['header']['shop_name'] }}
                </p>
                <h1 class="text-2xl font-extrabold text-coffee-950 tracking-tight mt-0.5">
                    {{ $report['is_open'] ? __('messages.reports.x_report_title') : __('messages.reports.z_report_title') }}
                    — {{ $report['header']['shift_number'] }}
                </h1>
                <p class="text-xs text-coffee-600 mt-0.5">
                    {{ $report['is_open'] ? __('messages.reports.x_report_subtitle') : __('messages.reports.z_report_subtitle') }}
                </p>
            </div>

            <div>
                @if ($report['is_open'])
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        {{ __('messages.reports.live_badge') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-coffee-900 text-cream-50">
                        {{ __('messages.reports.final_badge') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-xs">
            <div>
                <span class="text-coffee-500 block">{{ __('messages.reports.header_shift_number') }}</span>
                <strong class="text-coffee-950 font-mono text-sm">{{ $report['header']['shift_number'] }}</strong>
            </div>
            <div>
                <span class="text-coffee-500 block">{{ __('messages.reports.header_cashier') }}</span>
                <strong class="text-coffee-950">{{ $report['header']['cashier_name'] }}</strong>
            </div>
            <div>
                <span class="text-coffee-500 block">{{ __('messages.reports.header_status') }}</span>
                <strong class="text-coffee-950">
                    {{ $report['is_open'] ? __('messages.reports.status_open') : __('messages.reports.status_closed') }}
                </strong>
            </div>
            <div>
                <span class="text-coffee-500 block">{{ __('messages.reports.header_opened_at') }}</span>
                <strong class="text-coffee-950">{{ $report['header']['opened_at'] }} WIB</strong>
            </div>
            <div>
                <span class="text-coffee-500 block">{{ __('messages.reports.header_closed_at') }}</span>
                <strong class="text-coffee-950">
                    {{ $report['header']['closed_at'] ? $report['header']['closed_at'].' WIB' : __('messages.reports.header_still_open') }}
                </strong>
            </div>
            <div>
                <span class="text-coffee-500 block">{{ __('messages.reports.header_generated_at') }}</span>
                <strong class="text-coffee-950">{{ $report['header']['generated_at'] }} WIB</strong>
            </div>
        </div>
    </div>

    <!-- Main Report Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- 1. Sales Summary & Excluded Orders -->
        <div class="print-card bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-bold uppercase tracking-wider text-coffee-900 border-b border-coffee-100 pb-3">
                {{ __('messages.reports.section_sales') }}
            </h2>

            <div class="space-y-2.5 text-xs">
                <div class="flex items-center justify-between text-coffee-700">
                    <span>{{ __('messages.reports.subtotal_sales') }}</span>
                    <span class="font-semibold text-coffee-900">Rp {{ number_format($report['sales']['subtotal_sales'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-coffee-700">
                    <span>{{ __('messages.reports.tax_total') }}</span>
                    <span class="font-semibold text-coffee-900">Rp {{ number_format($report['sales']['tax_total'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-coffee-700">
                    <span>{{ __('messages.reports.service_charge_total') }}</span>
                    <span class="font-semibold text-coffee-900">Rp {{ number_format($report['sales']['service_charge_total'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-coffee-100 font-bold text-coffee-950 text-sm">
                    <span>{{ __('messages.reports.gross_sales') }}</span>
                    <span>Rp {{ number_format($report['sales']['gross_sales'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-rose-700">
                    <span>{{ __('messages.reports.refunds_in_shift') }}</span>
                    <span class="font-semibold">-Rp {{ number_format($report['sales']['refunds_total'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between pt-2.5 border-t border-coffee-200 font-extrabold text-coffee-950 text-base">
                    <span>{{ __('messages.reports.net_sales') }}</span>
                    <span>Rp {{ number_format($report['sales']['net_sales'], 0, ',', '.') }}</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 pt-3 border-t border-coffee-100 text-xs">
                <div class="p-3 rounded-2xl bg-cream-50 border border-coffee-200/60">
                    <span class="text-coffee-500 block">{{ __('messages.reports.paid_orders_count') }}</span>
                    <strong class="text-lg font-extrabold text-coffee-950">{{ $report['sales']['paid_orders_count'] }}</strong>
                </div>
                <div class="p-3 rounded-2xl bg-cream-50 border border-coffee-200/60">
                    <span class="text-coffee-500 block">{{ __('messages.reports.avg_ticket') }}</span>
                    <strong class="text-lg font-extrabold text-coffee-950">Rp {{ number_format($report['sales']['avg_ticket'], 0, ',', '.') }}</strong>
                </div>
            </div>

            <!-- Excluded Orders (Unpaid / Cancelled) -->
            <div class="pt-3 border-t border-coffee-100 space-y-2 text-xs">
                <p class="font-bold text-coffee-700">{{ __('messages.reports.excluded_orders_heading') }}</p>
                <div class="flex items-center justify-between text-coffee-600">
                    <span>{{ __('messages.reports.unpaid_orders_count') }}</span>
                    <span class="font-semibold text-coffee-900">{{ $report['excluded_orders']['unpaid_count'] }}</span>
                </div>
                <div class="flex items-center justify-between text-coffee-600">
                    <span>{{ __('messages.reports.cancelled_orders_count') }}</span>
                    <span class="font-semibold text-coffee-900">{{ $report['excluded_orders']['cancelled_count'] }}</span>
                </div>
            </div>
        </div>

        <!-- 2. Payments by Method & Refunds Breakdown -->
        <div class="space-y-6">
            <!-- Payments by Method -->
            <div class="print-card bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wider text-coffee-900 border-b border-coffee-100 pb-3">
                    {{ __('messages.reports.section_payments') }}
                </h2>

                <div class="space-y-2.5 text-xs">
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.payment_cash') }}</span>
                        <span class="font-semibold text-coffee-950">Rp {{ number_format($report['payments_by_method']['cash'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.payment_qris') }}</span>
                        <span class="font-semibold text-coffee-950">Rp {{ number_format($report['payments_by_method']['qris'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.payment_debit') }}</span>
                        <span class="font-semibold text-coffee-950">Rp {{ number_format($report['payments_by_method']['debit'], 0, ',', '.') }}</span>
                    </div>
                    @if ($report['payments_by_method']['other'] > 0)
                        <div class="flex items-center justify-between text-coffee-700">
                            <span>{{ __('messages.reports.payment_other') }}</span>
                            <span class="font-semibold text-coffee-950">Rp {{ number_format($report['payments_by_method']['other'], 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between pt-2.5 border-t border-coffee-200 font-extrabold text-coffee-950 text-sm">
                        <span>{{ __('messages.reports.payments_total') }}</span>
                        <span>Rp {{ number_format($report['payments_by_method']['total'], 0, ',', '.') }}</span>
                    </div>
                </div>
            </div>

            <!-- Refunds Recorded in This Shift -->
            <div class="print-card bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wider text-coffee-900 border-b border-coffee-100 pb-3">
                    {{ __('messages.reports.section_refunds') }}
                </h2>

                <div class="space-y-2.5 text-xs">
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.refund_count') }}</span>
                        <span class="font-bold text-coffee-950">{{ $report['refunds']['count'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.refund_method_cash') }}</span>
                        <span class="font-semibold text-rose-700">Rp {{ number_format($report['refunds']['by_method']['cash'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.refund_method_qris_reversal') }}</span>
                        <span class="font-semibold text-rose-700">Rp {{ number_format($report['refunds']['by_method']['qris_reversal'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-coffee-700">
                        <span>{{ __('messages.reports.refund_method_manual_transfer') }}</span>
                        <span class="font-semibold text-rose-700">Rp {{ number_format($report['refunds']['by_method']['manual_transfer'], 0, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between pt-2.5 border-t border-coffee-200 font-extrabold text-rose-800 text-sm">
                        <span>{{ __('messages.reports.refund_total') }}</span>
                        <span>Rp {{ number_format($report['refunds']['total'], 0, ',', '.') }}</span>
                    </div>
                </div>

                <p class="text-[11px] text-coffee-500 italic pt-2 border-t border-coffee-100">
                    {{ __('messages.reports.no_shift_refunds_note') }}
                </p>
            </div>
        </div>
    </div>

    <!-- 3. Cash Drawer Reconciliation Card -->
    <div class="print-card bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-5">
        <h2 class="text-sm font-bold uppercase tracking-wider text-coffee-900 border-b border-coffee-100 pb-3">
            {{ __('messages.reports.section_cash_drawer') }}
        </h2>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Drawer Formula Breakdown -->
            <div class="space-y-2.5 text-xs">
                <div class="flex items-center justify-between text-coffee-700">
                    <span>{{ __('messages.reports.opening_cash') }}</span>
                    <span class="font-semibold text-coffee-950">Rp {{ number_format($report['cash_drawer']['opening_cash'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-emerald-700">
                    <span>{{ __('messages.reports.manual_cash_in') }}</span>
                    <span class="font-semibold">+Rp {{ number_format($report['cash_drawer']['manual_cash_in'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-rose-700">
                    <span>{{ __('messages.reports.manual_cash_out') }}</span>
                    <span class="font-semibold">-Rp {{ number_format($report['cash_drawer']['manual_cash_out'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-emerald-700">
                    <div>
                        <span>{{ __('messages.reports.cash_sales_net') }}</span>
                        <span class="block text-[11px] text-coffee-500 font-normal">
                            {{ __('messages.reports.cash_tendered_meta', [
                                'tendered' => number_format($report['cash_drawer']['cash_tendered'], 0, ',', '.'),
                                'change' => number_format($report['cash_drawer']['cash_change'], 0, ',', '.'),
                            ]) }}
                        </span>
                    </div>
                    <span class="font-semibold">+Rp {{ number_format($report['cash_drawer']['cash_sales'], 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-rose-700">
                    <span>{{ __('messages.reports.cash_refunds_out') }}</span>
                    <span class="font-semibold">-Rp {{ number_format($report['cash_drawer']['cash_refunds'], 0, ',', '.') }}</span>
                </div>

                <div class="flex items-center justify-between pt-3 border-t border-coffee-200 font-extrabold text-coffee-950 text-base">
                    <span>{{ __('messages.reports.expected_cash') }}</span>
                    <span>Rp {{ number_format($report['cash_drawer']['expected_cash'], 0, ',', '.') }}</span>
                </div>

                @if (! $report['is_open'])
                    <div class="pt-3 border-t border-coffee-100 space-y-2">
                        <div class="flex items-center justify-between font-bold text-coffee-900 text-sm">
                            <span>{{ __('messages.reports.counted_cash') }}</span>
                            <span>Rp {{ number_format($report['cash_drawer']['counted_cash'] ?? 0, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between font-bold text-sm">
                            <span class="text-coffee-700">{{ __('messages.reports.variance') }}</span>
                            @php
                                $variance = (int) ($report['cash_drawer']['variance'] ?? 0);
                            @endphp
                            @if ($variance === 0)
                                <span class="text-emerald-700">{{ __('messages.reports.variance_exact') }}</span>
                            @elseif ($variance < 0)
                                <span class="text-rose-700">{{ __('messages.reports.variance_shortage', ['amount' => number_format(abs($variance), 0, ',', '.')]) }}</span>
                            @else
                                <span class="text-amber-700">{{ __('messages.reports.variance_surplus', ['amount' => number_format($variance, 0, ',', '.')]) }}</span>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-2 border-t border-dashed border-coffee-200 text-[11px] text-coffee-500">
                    <span>{{ __('messages.reports.non_cash_sales_info') }}</span>
                    <span class="font-semibold text-coffee-800">Rp {{ number_format($report['cash_drawer']['non_cash_sales'], 0, ',', '.') }}</span>
                </div>
            </div>

            <!-- Manual Cash Movements Breakdown & Notes -->
            <div class="space-y-4 text-xs">
                <div class="space-y-2">
                    <h3 class="font-bold text-coffee-800">{{ __('messages.reports.movements_breakdown_heading') }}</h3>
                    @if (empty($report['cash_drawer']['movements_by_category']))
                        <p class="text-coffee-500 italic">{{ __('messages.reports.no_manual_movements') }}</p>
                    @else
                        <div class="space-y-1.5">
                            @foreach ($report['cash_drawer']['movements_by_category'] as $mov)
                                @php
                                    $catLabel = match ($mov['category']) {
                                        'capital_add' => __('messages.shifts.cat_capital_add'),
                                        'owner_deposit' => __('messages.shifts.cat_owner_deposit'),
                                        'other_in' => __('messages.shifts.cat_other_in'),
                                        'expense' => __('messages.shifts.cat_expense'),
                                        'cash_drop' => __('messages.shifts.cat_cash_drop'),
                                        'owner_withdrawal' => __('messages.shifts.cat_owner_withdrawal'),
                                        'other_out' => __('messages.shifts.cat_other_out'),
                                        'reversal' => __('messages.shifts.cat_reversal'),
                                        default => __('messages.shifts.cat_other_in'),
                                    };
                                @endphp
                                <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                                    <div>
                                        <span class="font-semibold text-coffee-900">{{ $catLabel }}</span>
                                        <span class="text-[11px] text-coffee-500 ml-1">({{ $mov['count'] }}×)</span>
                                    </div>
                                    <span class="font-bold {{ $mov['type'] === 'in' ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $mov['type'] === 'in' ? '+' : '-' }}Rp {{ number_format($mov['amount'], 0, ',', '.') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="pt-3 border-t border-coffee-100 space-y-2">
                    <div>
                        <span class="font-bold text-coffee-700 block">{{ __('messages.reports.opening_note_label') }}</span>
                        <p class="text-coffee-600 mt-0.5">{{ $report['header']['opening_note'] !== '' ? $report['header']['opening_note'] : __('messages.reports.no_note') }}</p>
                    </div>
                    @if (! $report['is_open'])
                        <div>
                            <span class="font-bold text-coffee-700 block">{{ __('messages.reports.closing_note_label') }}</span>
                            <p class="text-coffee-600 mt-0.5">{{ $report['cash_drawer']['closing_note'] !== '' ? $report['cash_drawer']['closing_note'] : __('messages.reports.no_note') }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
