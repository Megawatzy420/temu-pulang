<div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl mx-auto">

    <!-- Top Alert Notification Component -->
    <x-notification />

    @if ($activeShift)
        <!-- ================= ACTIVE SHIFT PANEL ================= -->
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-5 sm:p-6 shadow-sm space-y-6">
            <!-- Active Shift Header -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-5 border-b border-coffee-100">
                <div class="flex items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-700 flex items-center justify-center shrink-0 border border-emerald-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">
                                {{ $activeShift->shift_number }}
                            </h2>
                            <x-badge variant="success" size="sm">{{ __('messages.shifts.active_badge') }}</x-badge>
                        </div>
                        <p class="text-xs text-coffee-600 mt-0.5">
                            {!! __('messages.shifts.opened_by_meta', ['user' => e($activeShift->user->name), 'datetime' => $activeShift->opened_at->timezone('Asia/Jakarta')->format('d M Y, H:i')]) !!}
                            @if ($activeShift->opening_note)
                                • <span class="italic text-coffee-500">"{{ $activeShift->opening_note }}"</span>
                            @endif
                        </p>
                    </div>
                </div>

                <!-- Shift Quick Action Buttons -->
                <div class="flex items-center gap-2 self-stretch sm:self-auto flex-wrap">
                    <x-button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="openNewMovementModal('in')"
                        class="cursor-pointer"
                    >
                        <svg class="w-4 h-4 mr-1 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        {{ __('messages.shifts.btn_cash_in') }}
                    </x-button>

                    <x-button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="openNewMovementModal('out')"
                        class="cursor-pointer"
                    >
                        <svg class="w-4 h-4 mr-1 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                        </svg>
                        {{ __('messages.shifts.btn_cash_out') }}
                    </x-button>

                    <x-button
                        type="button"
                        variant="danger"
                        size="sm"
                        wire:click="openCloseModal"
                        class="cursor-pointer bg-rose-700 hover:bg-rose-800 text-white"
                    >
                        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        {{ __('messages.shifts.btn_close_shift') }}
                    </x-button>
                </div>
            </div>

            <!-- 5 Financial Stat Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 sm:gap-4">
                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-coffee-500 uppercase tracking-wider block">{{ __('messages.shifts.stat_opening_cash_title') }}</span>
                    <span class="text-lg sm:text-xl font-extrabold text-coffee-950 mt-1 block">
                        Rp {{ number_format($activeShift->opening_cash, 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">{{ __('messages.shifts.stat_opening_cash_desc') }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider block">{{ __('messages.shifts.stat_cash_sales_title') }}</span>
                    <span class="text-lg sm:text-xl font-extrabold text-emerald-800 mt-1 block">
                        Rp {{ number_format($activeShift->totalCashPayments(), 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">{{ __('messages.shifts.stat_cash_sales_desc') }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider block">{{ __('messages.shifts.stat_manual_in_title') }}</span>
                    <span class="text-lg sm:text-xl font-extrabold text-emerald-800 mt-1 block">
                        Rp {{ number_format($activeShift->totalCashIn(), 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">{{ __('messages.shifts.stat_manual_in_desc') }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-rose-700 uppercase tracking-wider block">{{ __('messages.shifts.stat_out_refund_title') }}</span>
                    <span class="text-lg sm:text-xl font-extrabold text-rose-800 mt-1 block">
                        Rp {{ number_format($activeShift->totalCashOut() + $activeShift->totalCashRefunds(), 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">{{ __('messages.shifts.stat_out_refund_desc') }}</span>
                </div>

                <div class="p-4 rounded-2xl bg-coffee-900 text-cream-50 shadow-md col-span-2 sm:col-span-1">
                    <span class="text-[11px] font-bold text-caramel-300 uppercase tracking-wider block">{{ __('messages.shifts.stat_expected_cash_title') }}</span>
                    <span class="text-lg sm:text-xl font-extrabold text-white mt-1 block">
                        Rp {{ number_format($expectedCash, 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-300 mt-0.5 block">{{ __('messages.shifts.stat_expected_cash_desc') }}</span>
                </div>
            </div>

            @if ($activeShift->totalNonCashPayments() > 0)
                <div class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-sky-50/70 border border-sky-200/60 text-xs text-sky-900">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-sky-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{!! __('messages.shifts.non_cash_sales_banner', ['amount' => number_format($activeShift->totalNonCashPayments(), 0, ',', '.')]) !!}</span>
                    </div>
                    <span class="text-[11px] text-sky-700 font-medium hidden sm:inline">{{ __('messages.shifts.non_cash_sales_hint') }}</span>
                </div>
            @endif

            <!-- Active Shift Cash Movements Table -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-coffee-950">
                        {{ __('messages.shifts.drawer_ledger_heading', ['count' => $movements->count()]) }}
                    </h3>
                </div>

                <div class="border border-coffee-200/70 rounded-2xl overflow-hidden bg-white">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-cream-100/70 text-coffee-800 uppercase text-[10px] font-bold border-b border-coffee-200/70">
                                <tr>
                                    <th class="py-2.5 px-3.5">{{ __('messages.shifts.col_time') }}</th>
                                    <th class="py-2.5 px-3.5">{{ __('messages.shifts.col_type') }}</th>
                                    <th class="py-2.5 px-3.5">{{ __('messages.shifts.col_category') }}</th>
                                    <th class="py-2.5 px-3.5">{{ __('messages.shifts.col_description') }}</th>
                                    <th class="py-2.5 px-3.5">{{ __('messages.shifts.col_recorded_by') }}</th>
                                    <th class="py-2.5 px-3.5 text-right">{{ __('messages.shifts.col_amount') }}</th>
                                    <th class="py-2.5 px-3.5 text-center">{{ __('messages.shifts.col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-coffee-100">
                                @forelse ($movements as $m)
                                    <tr class="hover:bg-cream-50/60 transition-colors {{ $m->is_reversal ? 'bg-amber-50/40' : '' }}">
                                        <td class="py-2.5 px-3.5 text-coffee-500 whitespace-nowrap">
                                            {{ $m->created_at->timezone('Asia/Jakarta')->format('H:i:s') }}
                                        </td>
                                        <td class="py-2.5 px-3.5 whitespace-nowrap">
                                            @if ($m->type === 'in')
                                                <x-badge variant="success" size="xs">{{ __('messages.shifts.badge_type_in') }}</x-badge>
                                            @else
                                                <x-badge variant="danger" size="xs">{{ __('messages.shifts.badge_type_out') }}</x-badge>
                                            @endif
                                            @if ($m->is_reversal)
                                                <span class="ml-1 text-[9px] px-1 py-0.5 rounded bg-amber-200 text-amber-900 font-bold">{{ __('messages.shifts.badge_reversal') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-3.5 font-medium text-coffee-800">
                                            {{ ucfirst(str_replace('_', ' ', $m->category)) }}
                                        </td>
                                        <td class="py-2.5 px-3.5 text-coffee-700 max-w-xs truncate" title="{{ $m->description }}">
                                            {{ $m->description }}
                                            @if ($m->isReversed())
                                                <span class="block text-[10px] text-rose-600 italic">{{ __('messages.shifts.reversed_by_reversal_note') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-3.5 text-coffee-600 whitespace-nowrap">
                                            {{ $m->user->name }}
                                        </td>
                                        <td class="py-2.5 px-3.5 text-right font-bold whitespace-nowrap {{ $m->type === 'in' ? 'text-emerald-700' : 'text-rose-700' }}">
                                            {{ $m->type === 'in' ? '+' : '-' }}Rp {{ number_format($m->amount, 0, ',', '.') }}
                                        </td>
                                        <td class="py-2.5 px-3.5 text-center whitespace-nowrap">
                                            @if (!$m->is_reversal && !$m->isReversed())
                                                <button
                                                    type="button"
                                                    wire:click="promptReversal({{ $m->id }})"
                                                    class="text-[11px] text-coffee-500 hover:text-rose-600 font-medium underline cursor-pointer"
                                                >
                                                    {{ __('messages.shifts.reverse_action_btn') }}
                                                </button>
                                            @else
                                                <span class="text-coffee-300 text-[11px]">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-6 text-center text-coffee-400">
                                            {{ __('messages.shifts.empty_movements') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @else
        <!-- ================= NO ACTIVE SHIFT STATE ================= -->
        <div class="bg-white rounded-3xl border border-coffee-200/80 p-8 sm:p-12 text-center max-w-2xl mx-auto shadow-sm">
            <div class="w-16 h-16 rounded-3xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto mb-4 border border-amber-200">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">{{ __('messages.shifts.no_active_shift_heading') }}</h2>
            <p class="text-xs sm:text-sm text-coffee-600 mt-2 max-w-md mx-auto">
                {{ __('messages.shifts.no_active_shift_desc') }}
            </p>
            <div class="mt-6">
                <x-button
                    type="button"
                    variant="primary"
                    size="lg"
                    wire:click="openNewShiftModal"
                    class="bg-coffee-900 hover:bg-caramel-600 text-white shadow-md cursor-pointer px-6"
                >
                    <svg class="w-5 h-5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    {{ __('messages.shifts.open_new_shift_btn') }}
                </x-button>
            </div>
        </div>
    @endif

    <!-- ================= PAST SHIFTS HISTORY ================= -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 p-5 sm:p-6 shadow-sm space-y-4">
        <div>
            <h3 class="text-base font-bold text-coffee-950 tracking-tight">{{ __('messages.shifts.past_shifts_heading') }}</h3>
            <p class="text-xs text-coffee-500">{{ __('messages.shifts.past_shifts_subtitle') }}</p>
        </div>

        <div class="border border-coffee-200/70 rounded-2xl overflow-hidden bg-white">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-cream-100/70 text-coffee-800 uppercase text-[10px] font-bold border-b border-coffee-200/70">
                        <tr>
                            <th class="py-3 px-3.5">{{ __('messages.shifts.col_shift_number') }}</th>
                            <th class="py-3 px-3.5">{{ __('messages.shifts.col_open_close_cashier') }}</th>
                            <th class="py-3 px-3.5">{{ __('messages.shifts.col_open_close_time') }}</th>
                            <th class="py-3 px-3.5 text-right">{{ __('messages.shifts.col_opening_cash') }}</th>
                            <th class="py-3 px-3.5 text-right">{{ __('messages.shifts.col_expected_cash') }}</th>
                            <th class="py-3 px-3.5 text-right">{{ __('messages.shifts.col_counted_cash') }}</th>
                            <th class="py-3 px-3.5 text-right">{{ __('messages.shifts.col_discrepancy') }}</th>
                            <th class="py-3 px-3.5">{{ __('messages.shifts.col_closing_note') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coffee-100">
                        @forelse ($pastShifts as $ps)
                            <tr class="hover:bg-cream-50/60 transition-colors">
                                <td class="py-3 px-3.5 font-bold text-coffee-900 whitespace-nowrap">
                                    {{ $ps->shift_number }}
                                </td>
                                <td class="py-3 px-3.5 text-coffee-700 whitespace-nowrap">
                                    <span class="block font-medium">{{ $ps->user->name }}</span>
                                    @if ($ps->closedByUser && $ps->closed_by_user_id !== $ps->user_id)
                                        <span class="text-[10px] text-coffee-400">{{ __('messages.shifts.closed_by_prefix', ['user' => $ps->closedByUser->name]) }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-3.5 text-coffee-600 whitespace-nowrap text-[11px]">
                                    <div>{{ $ps->opened_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</div>
                                    <div class="text-coffee-400">{{ $ps->closed_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '-' }}</div>
                                </td>
                                <td class="py-3 px-3.5 text-right font-medium text-coffee-800 whitespace-nowrap">
                                    Rp {{ number_format($ps->opening_cash, 0, ',', '.') }}
                                </td>
                                <td class="py-3 px-3.5 text-right font-medium text-coffee-800 whitespace-nowrap">
                                    Rp {{ number_format($ps->expected_cash ?? 0, 0, ',', '.') }}
                                </td>
                                <td class="py-3 px-3.5 text-right font-bold text-coffee-950 whitespace-nowrap">
                                    Rp {{ number_format($ps->actual_cash ?? 0, 0, ',', '.') }}
                                </td>
                                <td class="py-3 px-3.5 text-right whitespace-nowrap">
                                    @if (($ps->discrepancy ?? 0) === 0)
                                        <x-badge variant="success" size="xs">{{ __('messages.shifts.discrepancy_exact_badge') }}</x-badge>
                                    @elseif (($ps->discrepancy ?? 0) < 0)
                                        <x-badge variant="danger" size="xs">
                                            -Rp {{ number_format(abs($ps->discrepancy), 0, ',', '.') }}
                                        </x-badge>
                                    @else
                                        <x-badge variant="primary" size="xs">
                                            +Rp {{ number_format($ps->discrepancy, 0, ',', '.') }}
                                        </x-badge>
                                    @endif
                                </td>
                                <td class="py-3 px-3.5 text-coffee-600 text-[11px] max-w-xs truncate" title="{{ $ps->closing_note }}">
                                    {{ $ps->closing_note ?: '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-coffee-400">
                                    {{ __('messages.shifts.empty_past_shifts') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($pastShifts->hasPages())
                <div class="p-3 border-t border-coffee-100 bg-cream-50/40">
                    {{ $pastShifts->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- ================= MODAL: BUKA SHIFT BARU ================= -->
    @if ($showOpenShiftModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-coffee-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl border border-coffee-200 max-w-md w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between pb-3 border-b border-coffee-100">
                    <div>
                        <h3 class="font-bold text-lg text-coffee-950">{{ __('messages.shifts.modal_open_title') }}</h3>
                        <p class="text-xs text-coffee-500">{{ __('messages.shifts.modal_open_subtitle') }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('showOpenShiftModal', false)"
                        class="text-coffee-400 hover:text-coffee-700 p-1 rounded-lg cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="submitOpenShift" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.shifts.opening_cash_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-xs font-bold text-coffee-500 pointer-events-none">
                                Rp
                            </span>
                            <input
                                type="number"
                                step="1000"
                                min="0"
                                wire:model="openingCash"
                                class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                                placeholder="200000"
                            />
                        </div>
                        @error('openingCash')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror

                        <!-- Quick Float Buttons -->
                        <div class="flex items-center gap-2 mt-2">
                            <button
                                type="button"
                                wire:click="$set('openingCash', 100000)"
                                class="px-2.5 py-1 rounded-lg bg-cream-100 hover:bg-cream-200 text-coffee-800 text-[11px] font-semibold border border-coffee-200 cursor-pointer"
                            >
                                Rp 100.000
                            </button>
                            <button
                                type="button"
                                wire:click="$set('openingCash', 200000)"
                                class="px-2.5 py-1 rounded-lg bg-cream-100 hover:bg-cream-200 text-coffee-800 text-[11px] font-semibold border border-coffee-200 cursor-pointer"
                            >
                                Rp 200.000
                            </button>
                            <button
                                type="button"
                                wire:click="$set('openingCash', 500000)"
                                class="px-2.5 py-1 rounded-lg bg-cream-100 hover:bg-cream-200 text-coffee-800 text-[11px] font-semibold border border-coffee-200 cursor-pointer"
                            >
                                Rp 500.000
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">{{ __('messages.shifts.opening_note_label') }}</label>
                        <textarea
                            wire:model="openingNote"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="{{ __('messages.shifts.opening_note_placeholder') }}"
                        ></textarea>
                        @error('openingNote')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-coffee-100">
                        <x-button
                            type="button"
                            variant="outline"
                            size="md"
                            wire:click="$set('showOpenShiftModal', false)"
                            class="cursor-pointer"
                        >
                            {{ __('messages.shifts.cancel_btn') }}
                        </x-button>
                        <x-button
                            type="submit"
                            variant="primary"
                            size="md"
                            class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
                        >
                            {{ __('messages.shifts.confirm_open_shift_btn') }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ================= MODAL: PERGERAKAN KAS DRAWER ================= -->
    @if ($showMovementModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-coffee-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl border border-coffee-200 max-w-md w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between pb-3 border-b border-coffee-100">
                    <div>
                        <h3 class="font-bold text-lg text-coffee-950">
                            {{ $movementType === 'in' ? __('messages.shifts.modal_cash_in_title') : __('messages.shifts.modal_cash_out_title') }}
                        </h3>
                        <p class="text-xs text-coffee-500">{{ __('messages.shifts.modal_movement_subtitle') }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('showMovementModal', false)"
                        class="text-coffee-400 hover:text-coffee-700 p-1 rounded-lg cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="submitCashMovement" class="space-y-4">
                    <!-- Type Toggle -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">{{ __('messages.shifts.movement_type_label') }}</label>
                        <div class="grid grid-cols-2 gap-2 p-1 bg-cream-100 rounded-xl border border-coffee-200">
                            <button
                                type="button"
                                wire:click="$set('movementType', 'in')"
                                class="py-2 rounded-lg text-xs font-bold cursor-pointer transition-all {{ $movementType === 'in' ? 'bg-emerald-600 text-white shadow-xs' : 'text-coffee-700 hover:text-coffee-950' }}"
                            >
                                {{ __('messages.shifts.btn_cash_in') }}
                            </button>
                            <button
                                type="button"
                                wire:click="$set('movementType', 'out')"
                                class="py-2 rounded-lg text-xs font-bold cursor-pointer transition-all {{ $movementType === 'out' ? 'bg-rose-600 text-white shadow-xs' : 'text-coffee-700 hover:text-coffee-950' }}"
                            >
                                {{ __('messages.shifts.btn_cash_out') }}
                            </button>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.shifts.movement_amount_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-xs font-bold text-coffee-500 pointer-events-none">
                                Rp
                            </span>
                            <input
                                type="number"
                                min="1"
                                step="1000"
                                wire:model="movementAmount"
                                class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                                placeholder="50000"
                            />
                        </div>
                        @error('movementAmount')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Category -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">{{ __('messages.shifts.movement_category_label') }}</label>
                        <select
                            wire:model="movementCategory"
                            class="w-full px-3 py-2.5 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                        >
                            @if ($movementType === 'in')
                                <option value="capital_add">{{ __('messages.shifts.cat_capital_add') }}</option>
                                <option value="owner_deposit">{{ __('messages.shifts.cat_owner_deposit') }}</option>
                                <option value="other_in">{{ __('messages.shifts.cat_other_in') }}</option>
                            @else
                                <option value="expense">{{ __('messages.shifts.cat_expense') }}</option>
                                <option value="cash_drop">{{ __('messages.shifts.cat_cash_drop') }}</option>
                                <option value="owner_withdrawal">{{ __('messages.shifts.cat_owner_withdrawal') }}</option>
                                <option value="other_out">{{ __('messages.shifts.cat_other_out') }}</option>
                            @endif
                        </select>
                        @error('movementCategory')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.shifts.movement_desc_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            wire:model="movementDescription"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="{{ __('messages.shifts.movement_desc_placeholder') }}"
                        ></textarea>
                        @error('movementDescription')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-coffee-100">
                        <x-button
                            type="button"
                            variant="outline"
                            size="md"
                            wire:click="$set('showMovementModal', false)"
                            class="cursor-pointer"
                        >
                            {{ __('messages.shifts.cancel_btn') }}
                        </x-button>
                        <x-button
                            type="submit"
                            variant="primary"
                            size="md"
                            class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
                        >
                            {{ __('messages.shifts.save_movement_btn') }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ================= MODAL: PEMBALIKAN (REVERSAL) ================= -->
    @if ($showReversalModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-coffee-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl border border-coffee-200 max-w-md w-full p-6 shadow-2xl space-y-4 animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between pb-3 border-b border-coffee-100">
                    <h3 class="font-bold text-base text-coffee-950">{{ __('messages.shifts.modal_reversal_title') }}</h3>
                    <button
                        type="button"
                        wire:click="$set('showReversalModal', false)"
                        class="text-coffee-400 hover:text-coffee-700 p-1 cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <p class="text-xs text-coffee-600">
                    {{ __('messages.shifts.modal_reversal_desc') }}
                </p>

                <form wire:submit="submitReversal" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.shifts.reversal_reason_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            wire:model="reversalReason"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="{{ __('messages.shifts.reversal_reason_placeholder') }}"
                        ></textarea>
                        @error('reversalReason')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-coffee-100">
                        <x-button
                            type="button"
                            variant="outline"
                            size="md"
                            wire:click="$set('showReversalModal', false)"
                            class="cursor-pointer"
                        >
                            {{ __('messages.shifts.cancel_btn') }}
                        </x-button>
                        <x-button
                            type="submit"
                            variant="danger"
                            size="md"
                            class="cursor-pointer"
                        >
                            {{ __('messages.shifts.confirm_reversal_btn') }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ================= MODAL: TUTUP SHIFT KASIR ================= -->
    @if ($showCloseShiftModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-coffee-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl border border-coffee-200 max-w-lg w-full p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between pb-3 border-b border-coffee-100">
                    <div>
                        <h3 class="font-bold text-lg text-coffee-950">{{ __('messages.shifts.modal_close_title') }}</h3>
                        <p class="text-xs text-coffee-500">{{ __('messages.shifts.modal_close_subtitle') }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('showCloseShiftModal', false)"
                        class="text-coffee-400 hover:text-coffee-700 p-1 rounded-lg cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Financial Breakdown -->
                <div class="p-3.5 rounded-2xl bg-cream-50 border border-coffee-200/70 space-y-2 text-xs">
                    <div class="flex justify-between text-coffee-600">
                        <span>{{ __('messages.shifts.summary_opening_cash') }}</span>
                        <span class="font-semibold text-coffee-800">Rp {{ number_format($activeShift->opening_cash, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-emerald-700">
                        <span>{{ __('messages.shifts.summary_net_cash_sales') }}</span>
                        <span class="font-semibold">Rp {{ number_format($activeShift->totalCashPayments(), 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-emerald-700">
                        <span>{{ __('messages.shifts.summary_manual_cash_in') }}</span>
                        <span class="font-semibold">Rp {{ number_format($activeShift->totalCashIn(), 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-rose-700">
                        <span>{{ __('messages.shifts.summary_manual_cash_out') }}</span>
                        <span class="font-semibold">Rp {{ number_format($activeShift->totalCashOut(), 0, ',', '.') }}</span>
                    </div>
                    @if ($activeShift->totalCashRefunds() > 0)
                        <div class="flex justify-between text-rose-700">
                            <span>{{ __('messages.shifts.summary_cash_refunds') }}</span>
                            <span class="font-semibold">Rp {{ number_format($activeShift->totalCashRefunds(), 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-2 border-t border-coffee-200 text-sm font-bold text-coffee-950">
                        <span>{{ __('messages.shifts.summary_expected_cash') }}</span>
                        <span>Rp {{ number_format($expectedCash, 0, ',', '.') }}</span>
                    </div>
                    @if ($activeShift->totalNonCashPayments() > 0)
                        <div class="flex justify-between text-[11px] text-coffee-500 pt-1.5 border-t border-dashed border-coffee-200/70">
                            <span>{{ __('messages.shifts.summary_non_cash_sales') }}</span>
                            <span class="font-semibold text-coffee-700">Rp {{ number_format($activeShift->totalNonCashPayments(), 0, ',', '.') }}</span>
                        </div>
                    @endif
                </div>

                <form wire:submit="submitCloseShift" class="space-y-4">
                    <!-- Physical Count Input -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.shifts.actual_cash_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-xs font-bold text-coffee-500 pointer-events-none">
                                Rp
                            </span>
                            <input
                                type="number"
                                min="0"
                                step="1000"
                                wire:model.live.debounce.150ms="actualCash"
                                class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            />
                        </div>
                        @error('actualCash')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Live Discrepancy Indicator -->
                    <div class="p-3 rounded-xl border {{ $discrepancy === 0 ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : ($discrepancy < 0 ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-blue-50 border-blue-200 text-blue-800') }}">
                        <div class="flex items-center justify-between text-xs font-bold">
                            <span>{{ __('messages.shifts.discrepancy_status_label') }}</span>
                            <span class="text-sm font-extrabold">
                                @if ($discrepancy === 0)
                                    {{ __('messages.shifts.discrepancy_balanced') }}
                                @elseif ($discrepancy < 0)
                                    {{ __('messages.shifts.discrepancy_shortage', ['amount' => number_format(abs($discrepancy), 0, ',', '.')]) }}
                                @else
                                    {{ __('messages.shifts.discrepancy_surplus', ['amount' => number_format($discrepancy, 0, ',', '.')]) }}
                                @endif
                            </span>
                        </div>
                    </div>

                    <!-- Closing Note -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.shifts.closing_note_label') }}
                            @if ($discrepancy !== 0)
                                <span class="text-rose-500 font-bold">{{ __('messages.shifts.closing_note_required_tag') }}</span>
                            @else
                                <span class="text-coffee-400 font-normal">{{ __('messages.shifts.closing_note_optional_tag') }}</span>
                            @endif
                        </label>
                        <textarea
                            wire:model="closingNote"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="{{ $discrepancy !== 0 ? __('messages.shifts.closing_note_discrepancy_placeholder') : __('messages.shifts.closing_note_normal_placeholder') }}"
                        ></textarea>
                        @error('closingNote')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-coffee-100">
                        <x-button
                            type="button"
                            variant="outline"
                            size="md"
                            wire:click="$set('showCloseShiftModal', false)"
                            class="cursor-pointer"
                        >
                            {{ __('messages.shifts.cancel_btn') }}
                        </x-button>
                        <x-button
                            type="submit"
                            variant="danger"
                            size="md"
                            class="bg-rose-700 hover:bg-rose-800 text-white cursor-pointer"
                        >
                            {{ __('messages.shifts.confirm_close_shift_btn') }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>
