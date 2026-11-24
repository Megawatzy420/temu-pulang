<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-coffee-500 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-coffee-800">Menu & Katalog</a>
                <span>/</span>
                <span class="text-coffee-800">Grup Kustomisasi & Modifier</span>
            </div>
            <h1 class="text-2xl font-bold text-coffee-950 tracking-tight">Kelola Grup Kustomisasi (Modifier)</h1>
            <p class="text-sm text-coffee-600 mt-0.5">Atur opsi pilihan rasa, level gula, es, dan add-on berbayar untuk menu.</p>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="{{ route('menu.products') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-coffee-800 text-xs font-semibold hover:bg-coffee-50 transition shadow-2xs">
                <svg class="w-4 h-4 text-coffee-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                Kelola Produk
            </a>
            <x-button wire:click="openCreateModal" variant="primary" size="sm" class="flex items-center gap-1.5 shadow-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                + Tambah Grup Modifier
            </x-button>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 p-4 shadow-2xs">
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-coffee-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari grup modifier berdasarkan nama atau slug..."
                class="w-full pl-10 pr-4 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
            />
        </div>
    </div>

    <!-- Modifier Groups List -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @forelse ($groups as $group)
            <div class="bg-white rounded-2xl border border-coffee-200/80 p-5 shadow-2xs flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-bold text-coffee-950">
                                    {{ $group->translate('id')?->name ?? $group->slug }}
                                </h3>
                                @if ($group->translate('en')?->name)
                                    <span class="text-xs font-normal text-coffee-500 italic">({{ $group->translate('en')->name }})</span>
                                @endif
                            </div>
                            <p class="text-xs font-mono text-coffee-500 mt-0.5">{{ $group->slug }}</p>
                        </div>

                        <div class="flex items-center gap-1.5">
                            @if ($group->is_required)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                    Wajib
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-cream-100 text-coffee-600 border border-coffee-200/60">
                                    Opsional
                                </span>
                            @endif
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-coffee-100 text-coffee-800">
                                Min {{ $group->min_selections }} / Max {{ $group->max_selections }}
                            </span>
                        </div>
                    </div>

                    <!-- Options Tag List -->
                    <div class="mt-3 pt-3 border-t border-coffee-100/80">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-coffee-600 mb-2">
                            Pilihan Opsi ({{ $group->modifiers->count() }}):
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($group->modifiers as $mod)
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs {{ $mod->is_sold_out ? 'bg-red-50/60 border-red-200 text-red-700 line-through' : 'bg-cream-50 border-coffee-200/70 text-coffee-900' }}">
                                    <span>{{ $mod->name }}</span>
                                    <span class="font-bold {{ $mod->price > 0 ? 'text-caramel-700' : 'text-coffee-500' }}">
                                        {{ $mod->price > 0 ? '+Rp ' . number_format($mod->price, 0, ',', '.') : 'Gratis' }}
                                    </span>
                                    <button
                                        type="button"
                                        wire:click="toggleSoldOut({{ $mod->id }})"
                                        title="{{ $mod->is_sold_out ? 'Klik untuk tandai Tersedia' : 'Klik untuk tandai Habis' }}"
                                        class="ml-1 text-[10px] underline cursor-pointer {{ $mod->is_sold_out ? 'text-emerald-700 font-bold' : 'text-coffee-400 hover:text-red-600' }}"
                                    >
                                        {{ $mod->is_sold_out ? 'Buka' : 'Habis' }}
                                    </button>
                                </div>
                            @empty
                                <p class="text-xs text-coffee-400 italic">Belum ada pilihan opsi di grup ini.</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="mt-5 pt-3 border-t border-coffee-100 flex items-center justify-between">
                    <span class="text-xs text-coffee-500">Urutan: #{{ $group->sort_order }}</span>
                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            wire:click="openEditModal({{ $group->id }})"
                            class="px-2.5 py-1.5 rounded-lg border border-coffee-200 text-xs font-medium text-coffee-800 hover:bg-coffee-100/60 transition"
                        >
                            Edit Grup & Opsi
                        </button>
                        <button
                            type="button"
                            wire:click="confirmDelete({{ $group->id }})"
                            class="px-2.5 py-1.5 rounded-lg border border-red-200 text-xs font-medium text-red-700 hover:bg-red-50 transition"
                        >
                            Hapus
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-2 bg-white rounded-2xl border border-coffee-200/80 p-12 text-center text-coffee-500">
                <p class="text-sm font-semibold">Belum ada grup modifier.</p>
                <p class="text-xs text-coffee-400 mt-1">Klik "+ Tambah Grup Modifier" untuk menambahkan kustomisasi menu.</p>
            </div>
        @endforelse
    </div>

    @if ($groups->hasPages())
        <div class="p-4 bg-white rounded-2xl border border-coffee-200/80">
            {{ $groups->links() }}
        </div>
    @endif

    <!-- Create / Edit Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-coffee-100 bg-cream-50/80 shrink-0">
                    <h3 class="text-base font-bold text-coffee-950">
                        {{ $editingGroupId ? 'Edit Grup Modifier & Opsi' : 'Tambah Grup Modifier Baru' }}
                    </h3>
                    <button type="button" wire:click="$set('showModal', false)" class="text-coffee-400 hover:text-coffee-700">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="save" class="p-6 overflow-y-auto space-y-5">
                    <!-- Group Header Info -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Nama Grup (ID) <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model.live="nameId"
                                placeholder="Contoh: Level Gula, Ekstra Topping"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                            @error('nameId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Nama Grup (EN)
                            </label>
                            <input
                                type="text"
                                wire:model="nameEn"
                                placeholder="e.g. Sugar Level, Extra Toppings"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Slug <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="slug"
                                placeholder="level-gula"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                            @error('slug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Min Pilih
                            </label>
                            <input
                                type="number"
                                wire:model="minSelections"
                                min="0"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Max Pilih
                            </label>
                            <input
                                type="number"
                                wire:model="maxSelections"
                                min="1"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                        </div>
                    </div>

                    <div class="flex items-center gap-6 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                wire:model="isRequired"
                                class="w-4 h-4 rounded border-coffee-300 text-coffee-800 focus:ring-coffee-500 accent-coffee-800 cursor-pointer"
                            />
                            <span class="text-xs font-semibold text-coffee-800">Wajib Dipilih Saat Pesan</span>
                        </label>
                    </div>

                    <!-- Options Repeater Section -->
                    <div class="pt-4 border-t border-coffee-100">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h4 class="text-sm font-bold text-coffee-950">Daftar Pilihan Opsi</h4>
                                <p class="text-xs text-coffee-500">Tambahkan opsi-opsi yang dapat dipilih pelanggan (harga 0 untuk gratis).</p>
                            </div>
                            <button
                                type="button"
                                wire:click="addOption"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-coffee-200 bg-cream-50 text-coffee-800 text-xs font-semibold hover:bg-coffee-100 transition"
                            >
                                + Tambah Pilihan
                            </button>
                        </div>

                        <div class="space-y-2.5">
                            @foreach ($options as $index => $opt)
                                <div class="p-2.5 rounded-xl border border-coffee-200/70 bg-cream-50/40 space-y-2">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <input
                                                type="text"
                                                wire:model="options.{{ $index }}.nameId"
                                                placeholder="Nama Opsi (ID) *"
                                                class="w-full px-3 py-1.5 rounded-lg border border-coffee-200 text-xs bg-white focus:outline-none focus:ring-1 focus:ring-coffee-500"
                                            />
                                        </div>
                                        <div class="flex-1">
                                            <input
                                                type="text"
                                                wire:model="options.{{ $index }}.nameEn"
                                                placeholder="Option (EN)"
                                                class="w-full px-3 py-1.5 rounded-lg border border-coffee-200 text-xs bg-white focus:outline-none focus:ring-1 focus:ring-coffee-500"
                                            />
                                        </div>
                                        <div class="w-32">
                                            <div class="relative">
                                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center text-[10px] text-coffee-400 font-bold">Rp</span>
                                                <input
                                                    type="number"
                                                    wire:model="options.{{ $index }}.price"
                                                    min="0"
                                                    step="500"
                                                    placeholder="0"
                                                    class="w-full pl-7 pr-2 py-1.5 rounded-lg border border-coffee-200 text-xs font-mono bg-white focus:outline-none focus:ring-1 focus:ring-coffee-500"
                                                />
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="removeOption({{ $index }})"
                                            class="p-1.5 text-coffee-400 hover:text-red-600 transition"
                                            title="Hapus Opsi"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Recipe Impact for this Option -->
                                    <div class="flex flex-wrap items-center gap-2 pt-1.5 border-t border-coffee-200/50 text-xs">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-coffee-500 shrink-0">Resep:</span>
                                        <select
                                            wire:model="options.{{ $index }}.recipeAction"
                                            class="px-2 py-1 text-xs rounded-lg border border-coffee-200 bg-white"
                                        >
                                            <option value="add">+ Tambah Bahan</option>
                                            <option value="replace">Gantikan Bahan</option>
                                        </select>
                                        <select
                                            wire:model="options.{{ $index }}.ingredientId"
                                            class="flex-1 min-w-[140px] px-2 py-1 text-xs rounded-lg border border-coffee-200 bg-white"
                                        >
                                            <option value="">-- Tanpa Pengurangan Bahan --</option>
                                            @foreach($availableIngredients as $ing)
                                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                            @endforeach
                                        </select>
                                        @if(($opt['recipeAction'] ?? 'add') === 'replace')
                                            <select
                                                wire:model="options.{{ $index }}.replacesIngredientId"
                                                class="flex-1 min-w-[140px] px-2 py-1 text-xs rounded-lg border border-coffee-200 bg-white"
                                            >
                                                <option value="">-- Gantikan Bahan... --</option>
                                                @foreach($availableIngredients as $ing)
                                                    <option value="{{ $ing->id }}">Gantikan: {{ $ing->name }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        <input
                                            type="text"
                                            wire:model="options.{{ $index }}.quantity"
                                            placeholder="Takaran (e.g. 15.000)"
                                            class="w-28 px-2 py-1 text-xs font-mono rounded-lg border border-coffee-200 bg-white"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Modal Footer Actions -->
                    <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-coffee-100 shrink-0">
                        <x-button type="button" wire:click="$set('showModal', false)" variant="secondary" size="md">
                            Batal
                        </x-button>
                        <x-button type="submit" variant="primary" size="md">
                            {{ $editingGroupId ? 'Simpan Perubahan' : 'Simpan Grup Modifier' }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Delete Modal -->
    @if ($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-sm w-full p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-coffee-950 mb-1">Hapus Grup Modifier?</h3>
                <p class="text-xs text-coffee-500 mb-6">
                    Grup modifier ini akan disembunyikan. Produk yang terhubung tidak akan lagi menampilkan opsi ini.
                </p>
                <div class="flex items-center justify-center gap-2.5">
                    <x-button type="button" wire:click="$set('showDeleteModal', false)" variant="secondary" size="md" class="w-full">
                        Batal
                    </x-button>
                    <x-button type="button" wire:click="deleteGroup" variant="danger" size="md" class="w-full">
                        Ya, Hapus
                    </x-button>
                </div>
            </div>
        </div>
    @endif
</div>

