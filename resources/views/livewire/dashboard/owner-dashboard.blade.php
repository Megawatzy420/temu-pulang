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
                <span class="text-coffee-400">vs kemarin (Rp 2.990.000)</span>
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
                {{ $metrics['today_orders'] }} <span class="text-sm font-normal text-coffee-500">pesanan</span>
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs">
                <span class="text-coffee-600 font-medium">Rata-rata:</span>
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
                {{ $activeShift?->user?->name ?? 'Belum Ada Shift Aktif' }}
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs text-coffee-500">
                @if ($activeShift)
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>{{ $activeShift->shift_number }} • Buka {{ $activeShift->opened_at->format('H:i') }} WIB</span>
                @else
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>Kasir belum membuka shift</span>
                @endif
            </div>
        </x-card>

        <!-- 4. Menu & Kategori Database -->
        <x-card padding="p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-coffee-500">
                    Menu & Katalog
                </p>
                <span class="w-8 h-8 rounded-xl bg-stone-100 text-stone-700 flex items-center justify-center">
                    <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </span>
            </div>
            <p class="text-2xl font-bold text-coffee-950 mt-2">
                {{ $productCount }} <span class="text-sm font-normal text-coffee-500">Produk</span>
            </p>
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-coffee-100 text-xs text-coffee-500">
                <span class="text-coffee-700 font-semibold">{{ $categoryCount }} Kategori Aktif</span>
                <span>• Multi-bahasa (ID/EN)</span>
            </div>
        </x-card>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left Column: Recent Orders Table (2/3 width) -->
        <div class="lg:col-span-2 space-y-6">
            <x-card
                :title="__('messages.dashboard.recent_orders')"
                subtitle="Daftar pesanan shift saat ini di kasir"
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
                        <span>Menampilkan 5 pesanan terbaru</span>
                        <a href="{{ route('pos') }}" class="font-semibold text-coffee-800 hover:text-coffee-950 underline">
                            {{ __('messages.dashboard.open_pos') }} →
                        </a>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        <!-- Right Column: Top Products & Store Hardware (1/3 width) -->
        <div class="space-y-6">

            <!-- Top Products Card -->
            <x-card
                :title="__('messages.dashboard.best_sellers')"
                subtitle="Berdasarkan jumlah porsi terjual hari ini"
            >
                <div class="space-y-4">
                    @foreach ($topProducts as $item)
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-xs font-semibold text-coffee-900">
                                <span class="truncate">{{ $item['name'] }}</span>
                                <span class="text-coffee-500 font-normal shrink-0 ml-2">{{ $item['sold_count'] }} terjual</span>
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
                title="Status Perangkat Kasir"
                subtitle="Konfigurasi jaringan & printer POS lokal"
            >
                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div>
                                <p class="font-bold text-coffee-900">Printer Thermal ESC/POS (USB)</p>
                                <p class="text-[11px] text-coffee-500">Koneksi: USB • {{ $shopSetting->printer_paper_width ?? '80mm' }} • {{ $shopSetting->printer_name ?? 'POS-80' }}</p>
                            </div>
                        </div>
                        <x-badge variant="{{ ($shopSetting->printer_enabled ?? true) ? 'success' : 'neutral' }}" size="xs">
                            {{ ($shopSetting->printer_enabled ?? true) ? 'Aktif' : 'Nonaktif' }}
                        </x-badge>
                    </div>

                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div>
                                <p class="font-bold text-coffee-900">Pajak Restoran & Layanan</p>
                                <p class="text-[11px] text-coffee-500">Pajak: {{ $shopSetting->tax_percentage ?? 0.0 }}% • Layanan: {{ $shopSetting->service_charge_percentage ?? 0.0 }}%</p>
                            </div>
                        </div>
                        <x-badge variant="neutral" size="xs">Default 0%</x-badge>
                    </div>

                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-cream-50 border border-coffee-200/60">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div>
                                <p class="font-bold text-coffee-900">Mata Uang & Timezone</p>
                                <p class="text-[11px] text-coffee-500">IDR (Rp) • Asia/Jakarta (WIB)</p>
                            </div>
                        </div>
                        <x-badge variant="neutral" size="xs">Sesuai</x-badge>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>

