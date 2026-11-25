<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-coffee-500 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-coffee-800">Inventaris & Stok</a>
                <span>/</span>
                <span class="text-coffee-800">Bahan Baku & Kategori</span>
            </div>
            <h1 class="text-2xl font-bold text-coffee-950 tracking-tight">Kelola Bahan Baku (Raw Materials)</h1>
            <p class="text-sm text-coffee-600 mt-0.5">Pantau stok bahan mentah, batas minimum persediaan, dan harga modal per satuan unit.</p>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="{{ route('inventory.recipes') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-coffee-800 text-xs font-semibold hover:bg-coffee-50 transition shadow-2xs">
                <svg class="w-4 h-4 text-coffee-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                </svg>
                Resep Menu (BOM)
            </a>
            <x-button wire:click="openCreateCategoryModal" variant="secondary" size="sm" class="flex items-center gap-1.5 shadow-2xs">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                + Kategori
            </x-button>
            <x-button wire:click="openCreateIngredientModal" variant="primary" size="sm" class="flex items-center gap-1.5 shadow-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                + Tambah Bahan
            </x-button>
        </div>
    </div>

    <!-- Feedback Message Flash -->
    @if (session()->has('message'))
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium flex items-center justify-between shadow-2xs">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('message') }}</span>
            </div>
            <button type="button" class="text-emerald-600 hover:text-emerald-900" onclick="this.parentElement.remove()">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    @endif

    <!-- Section Navigation Tabs -->
    <div class="flex items-center gap-2 border-b border-coffee-200">
        <button
            type="button"
            wire:click="$set('activeSection', 'ingredients')"
            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 {{ $activeSection === 'ingredients' ? 'border-coffee-800 text-coffee-950 font-bold' : 'border-transparent text-coffee-500 hover:text-coffee-800' }}"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            <span>Daftar Bahan Baku ({{ $ingredients->total() }})</span>
        </button>

        <button
            type="button"
            wire:click="$set('activeSection', 'categories')"
            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 {{ $activeSection === 'categories' ? 'border-coffee-800 text-coffee-950 font-bold' : 'border-transparent text-coffee-500 hover:text-coffee-800' }}"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
            </svg>
            <span>Kategori Bahan ({{ $categories->count() }})</span>
        </button>
    </div>

    @if ($activeSection === 'ingredients')
        <!-- Search & Filter Bar -->
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
                        placeholder="Cari bahan berdasarkan nama atau SKU..."
                        class="w-full pl-10 pr-4 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                    />
                </div>

                <div class="w-full sm:w-60">
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

                <div class="w-full sm:w-52">
                    <select
                        wire:model.live="stockFilter"
                        class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                    >
                        <option value="all">{{ __('messages.stock_alert.filter_all') }}</option>
                        <option value="low_or_out">{{ __('messages.stock_alert.filter_low_or_out') }}</option>
                        <option value="out">{{ __('messages.stock_alert.filter_out') }}</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Ingredients Table Card -->
        <div class="bg-white rounded-2xl border border-coffee-200/80 shadow-2xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-coffee-950">
                    <thead class="bg-cream-100/70 border-b border-coffee-200/80 text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                        <tr>
                            <th class="px-5 py-3">SKU</th>
                            <th class="px-5 py-3">Nama Bahan Baku</th>
                            <th class="px-5 py-3">Kategori</th>
                            <th class="px-5 py-3 text-right">Biaya / Satuan</th>
                            <th class="px-5 py-3 text-right">Stok Saat Ini</th>
                            <th class="px-5 py-3 text-right">Batas Minimum</th>
                            <th class="px-5 py-3 text-center">Status</th>
                            <th class="px-5 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coffee-100">
                        @forelse ($ingredients as $ingredient)
                            @php
                                $balance = $ingredient->balances->firstWhere('branch_id', null);
                                $stockMilli = $balance ? $balance->current_stock_milli : 0;
                                $thresholdMilli = \App\Models\Ingredient::toMilli($ingredient->min_stock_alert ?? '0.000');
                                $currentStock = \App\Models\Ingredient::fromMilli($stockMilli);
                                $stockLevel = ($thresholdMilli > 0 && $stockMilli <= 0)
                                    ? 'out'
                                    : (($thresholdMilli > 0 && $stockMilli <= $thresholdMilli) ? 'low' : 'ok');
                            @endphp
                            <tr class="hover:bg-cream-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-xs text-coffee-600">
                                    {{ $ingredient->sku }}
                                </td>
                                <td class="px-5 py-3.5 font-bold text-coffee-950">
                                    {{ $ingredient->name }}
                                </td>
                                <td class="px-5 py-3.5">
                                    @if ($ingredient->category)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-coffee-100 text-coffee-800 border border-coffee-200/60">
                                            {{ $ingredient->category->name }}
                                        </span>
                                    @else
                                        <span class="text-xs text-coffee-400 italic">Tanpa Kategori</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-right font-medium text-coffee-800">
                                    Rp {{ number_format($ingredient->cost_per_unit, 0, ',', '.') }} / {{ $ingredient->unit }}
                                </td>
                                <td class="px-5 py-3.5 text-right font-mono font-bold">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <span class="{{ $stockLevel === 'out' ? 'text-rose-700' : ($stockLevel === 'low' ? 'text-amber-700' : 'text-coffee-950') }}">
                                            {{ rtrim(rtrim($currentStock, '0'), '.') ?: '0' }} {{ $ingredient->unit }}
                                        </span>
                                        @if ($stockLevel === 'out')
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                                {{ __('messages.stock_alert.badge_out') }}
                                            </span>
                                        @elseif ($stockLevel === 'low')
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                                {{ __('messages.stock_alert.badge_low') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                {{ __('messages.stock_alert.badge_ok') }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-right font-mono text-xs text-coffee-500">
                                    {{ rtrim(rtrim($ingredient->min_stock_alert, '0'), '.') ?: '0' }} {{ $ingredient->unit }}
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <button
                                        type="button"
                                        wire:click="toggleIngredientStatus({{ $ingredient->id }})"
                                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold cursor-pointer transition {{ $ingredient->is_active ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-coffee-100 text-coffee-500 hover:bg-coffee-200' }}"
                                    >
                                        {{ $ingredient->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </button>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <a
                                            href="{{ route('inventory.movements', ['ingredient' => $ingredient->id]) }}"
                                            class="px-2 py-1 rounded-lg text-xs font-semibold text-coffee-700 hover:text-coffee-950 hover:bg-coffee-100 transition"
                                            title="{{ __('messages.stock_history.row_history_link') }}"
                                        >
                                            {{ __('messages.stock_history.row_history_link') }}
                                        </a>
                                        <button
                                            type="button"
                                            wire:click="openEditIngredientModal({{ $ingredient->id }})"
                                            class="p-1.5 rounded-lg text-coffee-600 hover:text-coffee-950 hover:bg-coffee-100 transition"
                                            title="Ubah Bahan"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="confirmDeleteIngredient({{ $ingredient->id }})"
                                            class="p-1.5 rounded-lg text-red-500 hover:text-red-700 hover:bg-red-50 transition"
                                            title="Hapus Bahan"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-12 text-center">
                                    <div class="max-w-xs mx-auto text-center">
                                        <div class="w-12 h-12 rounded-2xl bg-cream-100 flex items-center justify-center mx-auto mb-3 text-coffee-400">
                                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                            </svg>
                                        </div>
                                        <h3 class="text-sm font-bold text-coffee-900 mb-1">Belum Ada Bahan Baku</h3>
                                        <p class="text-xs text-coffee-500 mb-4">Tambahkan bahan baku mentah seperti biji kopi, susu, sirup, atau kemasan.</p>
                                        <x-button wire:click="openCreateIngredientModal" variant="primary" size="sm">
                                            + Tambah Bahan Sekarang
                                        </x-button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($ingredients->hasPages())
                <div class="px-5 py-4 border-t border-coffee-100">
                    {{ $ingredients->links() }}
                </div>
            @endif
        </div>
    @else
        <!-- Categories Section View -->
        <div class="bg-white rounded-2xl border border-coffee-200/80 shadow-2xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-coffee-950">
                    <thead class="bg-cream-100/70 border-b border-coffee-200/80 text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                        <tr>
                            <th class="px-5 py-3 w-20">Urutan</th>
                            <th class="px-5 py-3">Nama Kategori</th>
                            <th class="px-5 py-3">Slug</th>
                            <th class="px-5 py-3 text-center">Jumlah Bahan</th>
                            <th class="px-5 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coffee-100">
                        @forelse ($categories as $cat)
                            <tr class="hover:bg-cream-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-bold text-coffee-800">
                                    #{{ $cat->sort_order }}
                                </td>
                                <td class="px-5 py-3.5 font-bold text-coffee-950">
                                    {{ $cat->name }}
                                </td>
                                <td class="px-5 py-3.5 font-mono text-xs text-coffee-600">
                                    {{ $cat->slug }}
                                </td>
                                <td class="px-5 py-3.5 text-center font-bold text-coffee-800">
                                    {{ $cat->ingredients_count }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="openEditCategoryModal({{ $cat->id }})"
                                            class="p-1.5 rounded-lg text-coffee-600 hover:text-coffee-950 hover:bg-coffee-100 transition"
                                            title="Ubah Kategori"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="confirmDeleteCategory({{ $cat->id }})"
                                            class="p-1.5 rounded-lg text-red-500 hover:text-red-700 hover:bg-red-50 transition"
                                            title="Hapus Kategori"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-coffee-500">
                                    Belum ada kategori bahan. Klik <strong>+ Kategori</strong> untuk menambahkan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Ingredient Form Modal -->
    @if ($showIngredientModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-3xl border border-coffee-200 shadow-2xl max-w-lg w-full p-6 text-left max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-4 border-b border-coffee-100 mb-5">
                    <div>
                        <h2 class="text-lg font-bold text-coffee-950">
                            {{ $editingIngredientId ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku Baru' }}
                        </h2>
                        <p class="text-xs text-coffee-500">Isi data spesifikasi bahan dan batas peringatan stok.</p>
                    </div>
                    <button type="button" wire:click="$set('showIngredientModal', false)" class="text-coffee-400 hover:text-coffee-700 p-1">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="saveIngredient" class="space-y-4">
                    <!-- SKU & Category -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Kode / SKU Bahan <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="sku"
                                placeholder="ING-ESP001"
                                class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('sku') ? 'border-red-400' : 'border-coffee-200' }} text-sm font-mono focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                            @error('sku') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Kategori
                            </label>
                            <select
                                wire:model="categoryId"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            >
                                <option value="">-- Pilih Kategori --</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            @error('categoryId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <!-- Name -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Nama Bahan Baku <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model="name"
                            placeholder="Contoh: Biji Kopi Espresso House Blend"
                            class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('name') ? 'border-red-400' : 'border-coffee-200' }} text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        />
                        @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Unit & Cost Per Unit -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Satuan Unit <span class="text-red-500">*</span>
                            </label>
                            <select
                                wire:model="unit"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            >
                                <option value="g">Gram (g)</option>
                                <option value="ml">Mililiter (ml)</option>
                                <option value="pcs">Pieces (pcs)</option>
                                <option value="kg">Kilogram (kg)</option>
                                <option value="L">Liter (L)</option>
                                <option value="pack">Pack / Kantong</option>
                            </select>
                            @error('unit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Harga Modal / Satuan (Rp) <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                wire:model="costPerUnit"
                                min="0"
                                placeholder="Contoh: 350"
                                class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('costPerUnit') ? 'border-red-400' : 'border-coffee-200' }} text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                            @error('costPerUnit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <!-- Min Stock Alert & Opening Stock (Only on create) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Batas Minimum Peringatan <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="minStockAlert"
                                placeholder="100.000"
                                class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('minStockAlert') ? 'border-red-400' : 'border-coffee-200' }} text-sm font-mono focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                            <p class="text-[11px] text-coffee-500 mt-0.5">Stok di bawah batas ini memicu alert menipis.</p>
                            @error('minStockAlert') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        @if (! $editingIngredientId)
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                    Stok Awal Fisik
                                </label>
                                <input
                                    type="text"
                                    wire:model="initialStock"
                                    placeholder="0.000"
                                    class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('initialStock') ? 'border-red-400' : 'border-coffee-200' }} text-sm font-mono focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                                />
                                <p class="text-[11px] text-coffee-500 mt-0.5">Saldo awal saat bahan pertama didaftarkan.</p>
                                @error('initialStock') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    <!-- Active Toggle -->
                    <div class="flex items-center gap-2 pt-2">
                        <input
                            type="checkbox"
                            id="isActiveIngredient"
                            wire:model="isActive"
                            class="w-4 h-4 rounded border-coffee-300 text-coffee-800 focus:ring-coffee-500 accent-coffee-800 cursor-pointer"
                        />
                        <label for="isActiveIngredient" class="text-xs font-semibold text-coffee-800 cursor-pointer select-none">
                            Bahan Baku Aktif & Tersedia untuk Resep Menu
                        </label>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-coffee-100">
                        <x-button type="button" wire:click="$set('showIngredientModal', false)" variant="secondary" size="md">
                            Batal
                        </x-button>
                        <x-button type="submit" variant="primary" size="md">
                            {{ $editingIngredientId ? 'Simpan Perubahan' : 'Tambahkan Bahan' }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Delete Ingredient Confirmation Modal -->
    @if ($showDeleteIngredientModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-sm w-full p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-coffee-950 mb-1">Hapus Bahan Baku?</h3>
                <p class="text-xs text-coffee-500 mb-6">
                    Bahan baku <strong>"{{ $deletingIngredientName }}"</strong> akan dihapus (soft-delete). Resep yang menggunakannya tidak akan otomatis terhapus untuk menjaga riwayat transaksi.
                </p>
                <div class="flex items-center justify-center gap-2.5">
                    <x-button type="button" wire:click="$set('showDeleteIngredientModal', false)" variant="secondary" size="md" class="w-full">
                        Batal
                    </x-button>
                    <x-button type="button" wire:click="deleteIngredient" variant="danger" size="md" class="w-full">
                        Ya, Hapus
                    </x-button>
                </div>
            </div>
        </div>
    @endif

    <!-- Category Form Modal -->
    @if ($showCategoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-3xl border border-coffee-200 shadow-2xl max-w-md w-full p-6 text-left">
                <div class="flex items-center justify-between pb-4 border-b border-coffee-100 mb-5">
                    <div>
                        <h2 class="text-lg font-bold text-coffee-950">
                            {{ $editingCategoryId ? 'Ubah Kategori Bahan' : 'Tambah Kategori Bahan' }}
                        </h2>
                        <p class="text-xs text-coffee-500">Kelompokkan bahan (contoh: Coffee Beans, Dairy, Syrups, Packaging).</p>
                    </div>
                    <button type="button" wire:click="$set('showCategoryModal', false)" class="text-coffee-400 hover:text-coffee-700 p-1">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="saveCategory" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Nama Kategori <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="categoryName"
                            placeholder="Contoh: Biji Kopi"
                            class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('categoryName') ? 'border-red-400' : 'border-coffee-200' }} text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        />
                        @error('categoryName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Slug Kategori <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model="categorySlug"
                            placeholder="biji-kopi"
                            class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('categorySlug') ? 'border-red-400' : 'border-coffee-200' }} text-sm font-mono focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        />
                        @error('categorySlug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Urutan Tampil <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="number"
                            wire:model="categorySortOrder"
                            min="0"
                            class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        />
                        @error('categorySortOrder') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-coffee-100">
                        <x-button type="button" wire:click="$set('showCategoryModal', false)" variant="secondary" size="md">
                            Batal
                        </x-button>
                        <x-button type="submit" variant="primary" size="md">
                            {{ $editingCategoryId ? 'Simpan Perubahan' : 'Tambahkan Kategori' }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Delete Category Confirmation Modal -->
    @if ($showDeleteCategoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-sm w-full p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-coffee-950 mb-1">Hapus Kategori Bahan?</h3>
                <p class="text-xs text-coffee-500 mb-6">
                    Kategori <strong>"{{ $deletingCategoryName }}"</strong> akan dihapus. Bahan-bahan di dalamnya akan tetap tersimpan tanpa kategori.
                </p>
                <div class="flex items-center justify-center gap-2.5">
                    <x-button type="button" wire:click="$set('showDeleteCategoryModal', false)" variant="secondary" size="md" class="w-full">
                        Batal
                    </x-button>
                    <x-button type="button" wire:click="deleteCategory" variant="danger" size="md" class="w-full">
                        Ya, Hapus
                    </x-button>
                </div>
            </div>
        </div>
    @endif
</div>
