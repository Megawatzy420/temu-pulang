<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-coffee-500 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-coffee-800">Inventaris & Stok</a>
                <span>/</span>
                <span class="text-coffee-800">Resep Menu (BOM)</span>
            </div>
            <h1 class="text-2xl font-bold text-coffee-950 tracking-tight">Resep Menu & Estimasi HPP (Bill of Materials)</h1>
            <p class="text-sm text-coffee-600 mt-0.5">Pantau komposisi bahan mentah per porsi, estimasi biaya bahan baku (COGS), dan margin kotor produk.</p>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="{{ route('inventory.ingredients') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-coffee-800 text-xs font-semibold hover:bg-coffee-50 transition shadow-2xs">
                <svg class="w-4 h-4 text-coffee-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
                Kelola Bahan Baku
            </a>
            <a href="{{ route('menu.products') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-coffee-800 text-white text-xs font-semibold hover:bg-coffee-900 transition shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                Kelola Katalog Menu
            </a>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-coffee-200/80 p-5 shadow-2xs">
            <div class="text-xs font-bold uppercase tracking-wider text-coffee-500">Total Menu Katalog</div>
            <div class="text-2xl font-extrabold text-coffee-950 mt-1">{{ $totalProducts }} <span class="text-xs font-medium text-coffee-500">Item</span></div>
        </div>

        <div class="bg-white rounded-2xl border border-coffee-200/80 p-5 shadow-2xs">
            <div class="text-xs font-bold uppercase tracking-wider text-emerald-600">Resep Terkonfigurasi</div>
            <div class="text-2xl font-extrabold text-emerald-700 mt-1">{{ $configuredCount }} <span class="text-xs font-medium text-emerald-600">Menu</span></div>
        </div>

        <div class="bg-white rounded-2xl border border-coffee-200/80 p-5 shadow-2xs">
            <div class="text-xs font-bold uppercase tracking-wider text-amber-600">Belum Memiliki Resep</div>
            <div class="text-2xl font-extrabold text-amber-700 mt-1">{{ $missingCount }} <span class="text-xs font-medium text-amber-600">Menu</span></div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 p-4 shadow-2xs">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-coffee-400">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari menu berdasarkan nama atau SKU..."
                    class="w-full pl-10 pr-4 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                />
            </div>

            <div class="w-full sm:w-56">
                <select
                    wire:model.live="selectedCategoryId"
                    class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                >
                    <option value="">Semua Kategori</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-full sm:w-56">
                <select
                    wire:model.live="recipeStatusFilter"
                    class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                >
                    <option value="all">Semua Status Resep</option>
                    <option value="configured">Hanya yang Ber-resep</option>
                    <option value="missing">Belum Memiliki Resep</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Products & Recipe Table -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 shadow-2xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-coffee-950">
                <thead class="bg-cream-100/70 border-b border-coffee-200/80 text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    <tr>
                        <th class="px-5 py-3">Menu & Kategori</th>
                        <th class="px-5 py-3">Komposisi Resep (BOM)</th>
                        <th class="px-5 py-3 text-right">Harga Jual</th>
                        <th class="px-5 py-3 text-right">Estimasi HPP</th>
                        <th class="px-5 py-3 text-right">Gross Margin</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-coffee-100">
                    @forelse ($products as $product)
                        @php
                            $baseRecipes = $product->recipeItems->whereNull('product_variant_id');
                            $hasRecipe = $product->recipeItems->isNotEmpty();

                            // Calculate base recipe cost
                            $baseCogs = 0;
                            foreach ($baseRecipes as $rItem) {
                                if ($rItem->ingredient) {
                                    $baseCogs += (float)$rItem->quantity * $rItem->ingredient->cost_per_unit;
                                }
                            }

                            $price = $product->effective_price;
                            $marginAmount = $price - $baseCogs;
                            $marginPercent = $price > 0 ? round(($marginAmount / $price) * 100, 1) : 0;
                        @endphp
                        <tr class="hover:bg-cream-50/50 transition-colors">
                            <td class="px-5 py-4">
                                <div class="font-bold text-coffee-950 text-base">
                                    {{ $product->name }}
                                </div>
                                <div class="flex items-center gap-2 mt-1">
                                    @if ($product->category)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-coffee-100 text-coffee-800">
                                            {{ $product->category->name }}
                                        </span>
                                    @endif
                                    @if ($product->has_variants)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">
                                            {{ $product->variants->count() }} Varian
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-5 py-4">
                                @if ($hasRecipe)
                                    <div class="space-y-1">
                                        @foreach ($baseRecipes as $bItem)
                                            <div class="text-xs text-coffee-800 flex items-center gap-1.5 font-medium">
                                                <span class="w-1.5 h-1.5 rounded-full bg-caramel-500"></span>
                                                <span>{{ $bItem->ingredient?->name ?? 'Bahan dihapus' }}:</span>
                                                <span class="font-mono text-coffee-950 font-bold">
                                                    {{ rtrim(rtrim($bItem->quantity, '0'), '.') }} {{ $bItem->ingredient?->unit }}
                                                </span>
                                            </div>
                                        @endforeach
                                        @if ($product->has_variants)
                                            @php
                                                $variantCustomCount = $product->recipeItems->whereNotNull('product_variant_id')->count();
                                            @endphp
                                            @if ($variantCustomCount > 0)
                                                <div class="text-[11px] text-coffee-500 italic mt-1">
                                                    + {{ $variantCustomCount }} takaran khusus varian
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200/80">
                                        Belum Ada Resep
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-4 text-right font-bold text-coffee-950">
                                Rp {{ number_format($price, 0, ',', '.') }}
                            </td>

                            <td class="px-5 py-4 text-right font-mono font-medium">
                                @if ($hasRecipe)
                                    <span class="text-coffee-800 font-bold">
                                        Rp {{ number_format($baseCogs, 0, ',', '.') }}
                                    </span>
                                @else
                                    <span class="text-coffee-400 italic text-xs">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-4 text-right font-mono text-xs">
                                @if ($hasRecipe && $price > 0)
                                    <div class="font-bold {{ $marginPercent >= 60 ? 'text-emerald-700' : ($marginPercent >= 40 ? 'text-coffee-800' : 'text-amber-700') }}">
                                        {{ $marginPercent }}%
                                    </div>
                                    <div class="text-[11px] text-coffee-500">
                                        Rp {{ number_format($marginAmount, 0, ',', '.') }}
                                    </div>
                                @else
                                    <span class="text-coffee-400 italic">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-4 text-right">
                                <a
                                    href="{{ route('menu.products.edit', ['id' => $product->id]) }}"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-coffee-200 bg-white hover:bg-cream-100 text-xs font-bold text-coffee-800 transition shadow-2xs"
                                >
                                    <svg class="w-3.5 h-3.5 text-coffee-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Atur Resep
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-sm text-coffee-500">
                                Tidak ada produk menu yang sesuai dengan filter pencarian.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="px-5 py-4 border-t border-coffee-100">
                {{ $products->links() }}
            </div>
        @endif
    </div>
</div>
