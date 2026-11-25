<div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl mx-auto">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-4 border-b border-coffee-200/80">
        <div>
            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">Audit Trail & Rekam Aktivitas</h2>
            <p class="text-xs text-coffee-600">Catatan riwayat aktivitas penting tidak dapat diedit atau dihapus untuk menjaga integritas operasional.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-badge variant="neutral" size="sm">
                {{ $logs->total() }} Log Tercatat
            </x-badge>
        </div>
    </div>

    <!-- Filter & Search Controls Bar -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 p-4 shadow-sm space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <!-- Search -->
            <div class="lg:col-span-2 relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari deskripsi, aksi, IP, atau pengguna..."
                    class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-xs text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                />
            </div>

            <!-- User Filter -->
            <div>
                <select
                    wire:model.live="selectedUserId"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                >
                    <option value="all">Semua Pengguna</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Module Filter -->
            <div>
                <select
                    wire:model.live="selectedModule"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                >
                    <option value="all">Semua Modul</option>
                    @foreach ($modules as $modKey => $modName)
                        <option value="{{ $modKey }}">{{ $modName }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Date Range From/To -->
            <div class="flex items-center gap-2">
                <input
                    type="date"
                    wire:model.live="dateFrom"
                    title="Dari Tanggal"
                    class="w-full px-2 py-2 rounded-xl border border-coffee-200 text-[11px] font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                />
                <span class="text-coffee-400 text-xs">-</span>
                <input
                    type="date"
                    wire:model.live="dateTo"
                    title="Sampai Tanggal"
                    class="w-full px-2 py-2 rounded-xl border border-coffee-200 text-[11px] font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                />
            </div>
        </div>
    </div>

    <!-- Activity Log Table -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-cream-100/70 text-coffee-800 uppercase text-[10px] font-bold border-b border-coffee-200/70">
                    <tr>
                        <th class="py-3 px-4">Waktu (WIB)</th>
                        <th class="py-3 px-4">Pengguna</th>
                        <th class="py-3 px-4">Modul & Aksi</th>
                        <th class="py-3 px-4">Deskripsi Aktivitas</th>
                        <th class="py-3 px-4">Subjek / ID</th>
                        <th class="py-3 px-4">Alamat IP</th>
                        <th class="py-3 px-4 text-center">Rincian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-coffee-100">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-cream-50/60 transition-colors">
                            <td class="py-3 px-4 text-coffee-600 whitespace-nowrap text-[11px]">
                                <div class="font-bold text-coffee-900">
                                    {{ $log->created_at->timezone('Asia/Jakarta')->translatedFormat('d M Y') }}
                                </div>
                                <div class="text-coffee-400">
                                    {{ $log->created_at->timezone('Asia/Jakarta')->format('H:i:s') }} WIB
                                </div>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap">
                                @if ($log->user)
                                    <span class="font-bold text-coffee-950 block">{{ $log->user->name }}</span>
                                    <span class="text-[10px] uppercase font-semibold text-coffee-500">
                                        {{ $log->user->isOwner() ? 'Pemilik' : 'Kasir' }}
                                    </span>
                                @else
                                    <span class="text-coffee-400 italic">Sistem Otomatis</span>
                                @endif
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold font-mono uppercase {{ str_contains($log->action, 'auth') ? 'bg-blue-100 text-blue-800' : (str_contains($log->action, 'shift') ? 'bg-emerald-100 text-emerald-800' : (str_contains($log->action, 'cash') ? 'bg-amber-100 text-amber-800' : 'bg-coffee-100 text-coffee-800')) }}">
                                    {{ $log->action }}
                                </span>
                            </td>

                            <td class="py-3 px-4 text-coffee-800 max-w-sm">
                                <p class="text-xs leading-relaxed">{{ $log->description }}</p>
                            </td>

                            <td class="py-3 px-4 text-coffee-600 whitespace-nowrap text-[11px] font-mono">
                                @if ($log->subject_type)
                                    {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                @else
                                    <span class="text-coffee-300">-</span>
                                @endif
                            </td>

                            <td class="py-3 px-4 text-coffee-500 whitespace-nowrap font-mono text-[11px]">
                                {{ $log->ip_address ?: '-' }}
                            </td>

                            <td class="py-3 px-4 text-center whitespace-nowrap">
                                @if (!empty($log->properties))
                                    <button
                                        type="button"
                                        wire:click="viewDetails({{ $log->id }})"
                                        class="px-2 py-1 rounded-lg bg-cream-100 hover:bg-cream-200 text-coffee-800 text-[11px] font-semibold border border-coffee-200 cursor-pointer"
                                    >
                                        JSON
                                    </button>
                                @else
                                    <span class="text-coffee-300 text-xs">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-coffee-400">
                                Tidak ada log aktivitas yang cocok dengan kriteria pencarian atau filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="p-4 border-t border-coffee-100 bg-cream-50/40">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

    <!-- MODAL: VIEW PROPERTIES JSON -->
    @if ($viewingLog)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-coffee-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl border border-coffee-200 max-w-xl w-full p-6 shadow-2xl space-y-4 animate-in fade-in zoom-in-95 duration-150">
                <div class="flex items-center justify-between pb-3 border-b border-coffee-100">
                    <div>
                        <h3 class="font-bold text-base text-coffee-950">Detail Snapshot Properti Log #{{ $viewingLog->id }}</h3>
                        <p class="text-xs text-coffee-500 font-mono">{{ $viewingLog->action }} • {{ $viewingLog->created_at->format('d/m/Y H:i:s') }} WIB</p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeDetails"
                        class="text-coffee-400 hover:text-coffee-700 p-1 cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="bg-coffee-950 text-cream-100 rounded-2xl p-4 overflow-x-auto text-xs font-mono max-h-80">
                    <pre>{{ json_encode($viewingLog->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>

                <div class="flex justify-end pt-2">
                    <x-button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="closeDetails"
                        class="cursor-pointer"
                    >
                        Tutup
                    </x-button>
                </div>
            </div>
        </div>
    @endif

</div>

