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
                            <x-badge variant="success" size="sm">Shift Sedang Aktif</x-badge>
                        </div>
                        <p class="text-xs text-coffee-600 mt-0.5">
                            Dibuka oleh <strong class="text-coffee-900">{{ $activeShift->user->name }}</strong> pada {{ $activeShift->opened_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
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
                        + Kas Masuk
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
                        - Kas Keluar
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
                        Tutup Shift Kasir
                    </x-button>
                </div>
            </div>

            <!-- 5 Financial Stat Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 sm:gap-4">
                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-coffee-500 uppercase tracking-wider block">Kas Awal (Float)</span>
                    <span class="text-lg sm:text-xl font-extrabold text-coffee-950 mt-1 block">
                        Rp {{ number_format($activeShift->opening_cash, 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">Modal tunai awal laci kasir</span>
                </div>

                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider block">+ Penjualan Tunai</span>
                    <span class="text-lg sm:text-xl font-extrabold text-emerald-800 mt-1 block">
                        Rp {{ number_format($activeShift->totalCashPayments(), 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">Penjualan tunai bersih (laci)</span>
                </div>

                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider block">+ Kas Masuk Manual</span>
                    <span class="text-lg sm:text-xl font-extrabold text-emerald-800 mt-1 block">
                        Rp {{ number_format($activeShift->totalCashIn(), 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">Tambahan modal / kas masuk</span>
                </div>

                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/60">
                    <span class="text-[11px] font-bold text-rose-700 uppercase tracking-wider block">- Keluar / Refund</span>
                    <span class="text-lg sm:text-xl font-extrabold text-rose-800 mt-1 block">
                        Rp {{ number_format($activeShift->totalCashOut() + $activeShift->totalCashRefunds(), 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-400 mt-0.5 block">Pengeluaran kas kecil & refund</span>
                </div>

                <div class="p-4 rounded-2xl bg-coffee-900 text-cream-50 shadow-md col-span-2 sm:col-span-1">
                    <span class="text-[11px] font-bold text-caramel-300 uppercase tracking-wider block">= Kas Diharapkan di Laci</span>
                    <span class="text-lg sm:text-xl font-extrabold text-white mt-1 block">
                        Rp {{ number_format($expectedCash, 0, ',', '.') }}
                    </span>
                    <span class="text-[10px] text-coffee-300 mt-0.5 block">Awal + Jual + Masuk - Keluar</span>
                </div>
            </div>

            @if ($activeShift->totalNonCashPayments() > 0)
                <div class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-sky-50/70 border border-sky-200/60 text-xs text-sky-900">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-sky-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Penjualan Non-Tunai (QRIS / Debit / Transfer): <strong>Rp {{ number_format($activeShift->totalNonCashPayments(), 0, ',', '.') }}</strong></span>
                    </div>
                    <span class="text-[11px] text-sky-700 font-medium hidden sm:inline">Tercatat terpisah (tidak masuk fisik laci kasir)</span>
                </div>
            @endif

            <!-- Active Shift Cash Movements Table -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-coffee-950">
                        Buku Kas Laci Shift Ini ({{ $movements->count() }} Pergerakan)
                    </h3>
                </div>

                <div class="border border-coffee-200/70 rounded-2xl overflow-hidden bg-white">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-cream-100/70 text-coffee-800 uppercase text-[10px] font-bold border-b border-coffee-200/70">
                                <tr>
                                    <th class="py-2.5 px-3.5">Waktu</th>
                                    <th class="py-2.5 px-3.5">Tipe</th>
                                    <th class="py-2.5 px-3.5">Kategori</th>
                                    <th class="py-2.5 px-3.5">Keterangan</th>
                                    <th class="py-2.5 px-3.5">Dicatat Oleh</th>
                                    <th class="py-2.5 px-3.5 text-right">Jumlah</th>
                                    <th class="py-2.5 px-3.5 text-center">Aksi</th>
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
                                                <x-badge variant="success" size="xs">+ Masuk</x-badge>
                                            @else
                                                <x-badge variant="danger" size="xs">- Keluar</x-badge>
                                            @endif
                                            @if ($m->is_reversal)
                                                <span class="ml-1 text-[9px] px-1 py-0.5 rounded bg-amber-200 text-amber-900 font-bold">Reversal</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-3.5 font-medium text-coffee-800">
                                            {{ ucfirst(str_replace('_', ' ', $m->category)) }}
                                        </td>
                                        <td class="py-2.5 px-3.5 text-coffee-700 max-w-xs truncate" title="{{ $m->description }}">
                                            {{ $m->description }}
                                            @if ($m->isReversed())
                                                <span class="block text-[10px] text-rose-600 italic">Dibatalkan oleh Reversal</span>
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
                                                    Batalkan
                                                </button>
                                            @else
                                                <span class="text-coffee-300 text-[11px]">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-6 text-center text-coffee-400">
                                            Belum ada pergerakan kas masuk atau keluar pada shift ini.
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
            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">Tidak Ada Shift Kasir yang Sedang Aktif</h2>
            <p class="text-xs sm:text-sm text-coffee-600 mt-2 max-w-md mx-auto">
                Buka shift kasir baru dengan menghitung dan memasukkan uang kas modal awal (cash float) sebelum memulai transaksi operasional.
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
                    Buka Shift Kasir Baru
                </x-button>
            </div>
        </div>
    @endif

    <!-- ================= PAST SHIFTS HISTORY ================= -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 p-5 sm:p-6 shadow-sm space-y-4">
        <div>
            <h3 class="text-base font-bold text-coffee-950 tracking-tight">Riwayat Shift Kasir Selesai</h3>
            <p class="text-xs text-coffee-500">Daftar shift yang telah ditutup dan direkonsiliasi</p>
        </div>

        <div class="border border-coffee-200/70 rounded-2xl overflow-hidden bg-white">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-cream-100/70 text-coffee-800 uppercase text-[10px] font-bold border-b border-coffee-200/70">
                        <tr>
                            <th class="py-3 px-3.5">No. Shift</th>
                            <th class="py-3 px-3.5">Kasir Buka / Tutup</th>
                            <th class="py-3 px-3.5">Waktu Buka / Tutup</th>
                            <th class="py-3 px-3.5 text-right">Kas Awal</th>
                            <th class="py-3 px-3.5 text-right">Kas Diharapkan</th>
                            <th class="py-3 px-3.5 text-right">Kas Dihitung</th>
                            <th class="py-3 px-3.5 text-right">Selisih</th>
                            <th class="py-3 px-3.5">Catatan Penutupan</th>
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
                                        <span class="text-[10px] text-coffee-400">Ditutup: {{ $ps->closedByUser->name }}</span>
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
                                        <x-badge variant="success" size="xs">Pas (Rp 0)</x-badge>
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
                                    Belum ada catatan riwayat shift yang ditutup.
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
                        <h3 class="font-bold text-lg text-coffee-950">Buka Shift Kasir Baru</h3>
                        <p class="text-xs text-coffee-500">Hitung uang tunai modal awal laci kasir</p>
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
                            Kas Awal Modal (Rupiah) <span class="text-rose-500">*</span>
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
                        <label class="block text-xs font-bold text-coffee-800 mb-1">Catatan Buka Shift (Opsional)</label>
                        <textarea
                            wire:model="openingNote"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="Contoh: Pecahan Rp 50.000 (2 lbr), Rp 20.000 (5 lbr)..."
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
                            Batal
                        </x-button>
                        <x-button
                            type="submit"
                            variant="primary"
                            size="md"
                            class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
                        >
                            Konfirmasi Buka Shift
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
                            {{ $movementType === 'in' ? 'Catat Kas Masuk (Inflow)' : 'Catat Kas Keluar (Outflow)' }}
                        </h3>
                        <p class="text-xs text-coffee-500">Pergerakan fisik uang tunai pada laci kasir</p>
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
                        <label class="block text-xs font-bold text-coffee-800 mb-1">Tipe Pergerakan</label>
                        <div class="grid grid-cols-2 gap-2 p-1 bg-cream-100 rounded-xl border border-coffee-200">
                            <button
                                type="button"
                                wire:click="$set('movementType', 'in')"
                                class="py-2 rounded-lg text-xs font-bold cursor-pointer transition-all {{ $movementType === 'in' ? 'bg-emerald-600 text-white shadow-xs' : 'text-coffee-700 hover:text-coffee-950' }}"
                            >
                                + Kas Masuk
                            </button>
                            <button
                                type="button"
                                wire:click="$set('movementType', 'out')"
                                class="py-2 rounded-lg text-xs font-bold cursor-pointer transition-all {{ $movementType === 'out' ? 'bg-rose-600 text-white shadow-xs' : 'text-coffee-700 hover:text-coffee-950' }}"
                            >
                                - Kas Keluar
                            </button>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            Jumlah Nominal (Rp) <span class="text-rose-500">*</span>
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
                        <label class="block text-xs font-bold text-coffee-800 mb-1">Kategori</label>
                        <select
                            wire:model="movementCategory"
                            class="w-full px-3 py-2.5 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                        >
                            @if ($movementType === 'in')
                                <option value="capital_add">Tambah Modal Awal</option>
                                <option value="owner_deposit">Setoran Kas Pemilik</option>
                                <option value="other_in">Penerimaan Kas Lainnya</option>
                            @else
                                <option value="expense">Pengeluaran Operasional / Kas Kecil</option>
                                <option value="cash_drop">Setor Kas ke Brankas (Cash Drop)</option>
                                <option value="owner_withdrawal">Penarikan Kas oleh Pemilik</option>
                                <option value="other_out">Pengeluaran Kas Lainnya</option>
                            @endif
                        </select>
                        @error('movementCategory')
                            <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            Keterangan / Alasan <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            wire:model="movementDescription"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="Contoh: Beli es batu kristal 2 kantong..."
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
                            Batal
                        </x-button>
                        <x-button
                            type="submit"
                            variant="primary"
                            size="md"
                            class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
                        >
                            Simpan Pergerakan Kas
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
                    <h3 class="font-bold text-base text-coffee-950">Batalkan / Balikkan Pergerakan Kas</h3>
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
                    Tindakan ini tidak menghapus riwayat transaksi lama. Sistem akan membuat entri pembalikan (reversal) berlawanan yang tercatat lengkap dalam audit log.
                </p>

                <form wire:submit="submitReversal" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            Alasan Pembatalan <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            wire:model="reversalReason"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="Contoh: Salah input nominal uang..."
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
                            Batal
                        </x-button>
                        <x-button
                            type="submit"
                            variant="danger"
                            size="md"
                            class="cursor-pointer"
                        >
                            Konfirmasi Pembalikan
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
                        <h3 class="font-bold text-lg text-coffee-950">Rekonsiliasi & Tutup Shift Kasir</h3>
                        <p class="text-xs text-coffee-500">Hitung fisik uang tunai di laci untuk penutupan shift</p>
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
                        <span>Kas Awal Modal (Float):</span>
                        <span class="font-semibold text-coffee-800">Rp {{ number_format($activeShift->opening_cash, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-emerald-700">
                        <span>+ Penjualan Tunai Bersih:</span>
                        <span class="font-semibold">Rp {{ number_format($activeShift->totalCashPayments(), 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-emerald-700">
                        <span>+ Total Kas Masuk Manual:</span>
                        <span class="font-semibold">Rp {{ number_format($activeShift->totalCashIn(), 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-rose-700">
                        <span>- Total Kas Keluar Manual:</span>
                        <span class="font-semibold">Rp {{ number_format($activeShift->totalCashOut(), 0, ',', '.') }}</span>
                    </div>
                    @if ($activeShift->totalCashRefunds() > 0)
                        <div class="flex justify-between text-rose-700">
                            <span>- Total Refund Tunai:</span>
                            <span class="font-semibold">Rp {{ number_format($activeShift->totalCashRefunds(), 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-2 border-t border-coffee-200 text-sm font-bold text-coffee-950">
                        <span>Kas Diharapkan di Laci:</span>
                        <span>Rp {{ number_format($expectedCash, 0, ',', '.') }}</span>
                    </div>
                    @if ($activeShift->totalNonCashPayments() > 0)
                        <div class="flex justify-between text-[11px] text-coffee-500 pt-1.5 border-t border-dashed border-coffee-200/70">
                            <span>Penjualan Non-Tunai (QRIS / EDC / Transfer):</span>
                            <span class="font-semibold text-coffee-700">Rp {{ number_format($activeShift->totalNonCashPayments(), 0, ',', '.') }}</span>
                        </div>
                    @endif
                </div>

                <form wire:submit="submitCloseShift" class="space-y-4">
                    <!-- Physical Count Input -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            Kas Fisik Hasil Hitung (Rupiah) <span class="text-rose-500">*</span>
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
                            <span>Status Selisih Kas:</span>
                            <span class="text-sm font-extrabold">
                                @if ($discrepancy === 0)
                                    Seimbang / Pas (Rp 0)
                                @elseif ($discrepancy < 0)
                                    Minus Rp {{ number_format(abs($discrepancy), 0, ',', '.') }} (Kurang)
                                @else
                                    Surplus +Rp {{ number_format($discrepancy, 0, ',', '.') }} (Lebih)
                                @endif
                            </span>
                        </div>
                    </div>

                    <!-- Closing Note -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            Catatan Penutupan Shift
                            @if ($discrepancy !== 0)
                                <span class="text-rose-500 font-bold">* (Wajib diisi karena terdapat selisih)</span>
                            @else
                                <span class="text-coffee-400 font-normal">(Opsional)</span>
                            @endif
                        </label>
                        <textarea
                            wire:model="closingNote"
                            rows="2"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs text-coffee-900 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            placeholder="{{ $discrepancy !== 0 ? 'Jelaskan alasan selisih kas fisik...' : 'Catatan kondisi kasir saat tutup...' }}"
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
                            Batal
                        </x-button>
                        <x-button
                            type="submit"
                            variant="danger"
                            size="md"
                            class="bg-rose-700 hover:bg-rose-800 text-white cursor-pointer"
                        >
                            Konfirmasi & Tutup Shift
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>

