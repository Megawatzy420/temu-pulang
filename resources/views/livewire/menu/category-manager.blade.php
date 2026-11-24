<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-coffee-500 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-coffee-800">Menu & Katalog</a>
                <span>/</span>
                <span class="text-coffee-800">Kategori Menu</span>
            </div>
            <h1 class="text-2xl font-bold text-coffee-950 tracking-tight">Kelola Kategori Menu</h1>
            <p class="text-sm text-coffee-600 mt-0.5">Kelola pengelompokan menu minuman, makanan, dan snack Temu Pulang.</p>
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
                + Tambah Kategori
            </x-button>
        </div>
    </div>

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
                    placeholder="Cari kategori berdasarkan nama atau slug..."
                    class="w-full pl-10 pr-4 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                />
            </div>
        </div>
    </div>

    <!-- Categories Table Card -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 shadow-2xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-coffee-950">
                <thead class="bg-cream-100/70 border-b border-coffee-200/80 text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    <tr>
                        <th class="px-5 py-3 w-16">Urutan</th>
                        <th class="px-5 py-3">Nama Kategori (ID / EN)</th>
                        <th class="px-5 py-3">Slug / Kode</th>
                        <th class="px-5 py-3 text-center">Jumlah Produk</th>
                        <th class="px-5 py-3 text-center">Status</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-coffee-100">
                    @forelse ($categories as $category)
                        <tr class="hover:bg-cream-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-bold text-coffee-800">
                                #{{ $category->sort_order }}
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="font-bold text-coffee-950 flex items-center gap-2">
                                    {{ $category->translate('id')?->name ?? '—' }}
                                    @if ($category->translate('en')?->name)
                                        <span class="text-xs font-normal text-coffee-500 italic">({{ $category->translate('en')->name }})</span>
                                    @endif
                                </div>
                                @if ($category->translate('id')?->description)
                                    <p class="text-xs text-coffee-500 mt-0.5 line-clamp-1">{{ $category->translate('id')->description }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 font-mono text-xs text-coffee-600">
                                {{ $category->slug }}
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $category->products_count > 0 ? 'bg-coffee-100 text-coffee-800' : 'bg-cream-200 text-coffee-500' }}">
                                    {{ $category->products_count }} produk
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <button
                                    type="button"
                                    wire:click="toggleActive({{ $category->id }})"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold cursor-pointer transition {{ $category->is_active ? 'bg-emerald-50 text-emerald-800 border border-emerald-200/80 hover:bg-emerald-100' : 'bg-red-50 text-red-700 border border-red-200/80 hover:bg-red-100' }}"
                                >
                                    <span class="w-1.5 h-1.5 rounded-full {{ $category->is_active ? 'bg-emerald-600' : 'bg-red-500' }}"></span>
                                    {{ $category->is_active ? 'Aktif' : 'Nonaktif' }}
                                </button>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1.5">
                                <button
                                    type="button"
                                    wire:click="openEditModal({{ $category->id }})"
                                    class="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-coffee-200 text-xs font-medium text-coffee-800 hover:bg-coffee-100/60 transition"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $category->id }})"
                                    class="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-red-200 text-xs font-medium text-red-700 hover:bg-red-50 transition"
                                >
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-coffee-500">
                                <p class="text-sm font-semibold">Belum ada kategori menu yang ditemukan.</p>
                                <p class="text-xs text-coffee-400 mt-1">Klik tombol "+ Tambah Kategori" di atas untuk menambahkan kategori baru.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($categories->hasPages())
            <div class="p-4 border-t border-coffee-100 bg-cream-50/50">
                {{ $categories->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Category Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-lg w-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-coffee-100 bg-cream-50/80">
                    <h3 class="text-base font-bold text-coffee-950">
                        {{ $editingCategoryId ? 'Edit Kategori Menu' : 'Tambah Kategori Menu Baru' }}
                    </h3>
                    <button type="button" wire:click="$set('showModal', false)" class="text-coffee-400 hover:text-coffee-700">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="save" class="p-6 space-y-4">
                    <!-- Indonesian Name -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Nama Kategori (Bahasa Indonesia) <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live="nameId"
                            placeholder="Contoh: Kopi Espresso, Makanan Ringan"
                            class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('nameId') ? 'border-red-400' : 'border-coffee-200' }} text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        />
                        @error('nameId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- English Name -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Nama Kategori (English)
                        </label>
                        <input
                            type="text"
                            wire:model="nameEn"
                            placeholder="e.g. Espresso Coffee, Snacks"
                            class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <!-- Slug -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Slug URL <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="slug"
                                placeholder="kopi-espresso"
                                class="w-full px-3.5 py-2 rounded-xl border {{ $errors->has('slug') ? 'border-red-400' : 'border-coffee-200' }} text-sm font-mono focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                            @error('slug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <!-- Sort Order -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Nomor Urut Tampil <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                wire:model="sortOrder"
                                class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                            />
                        </div>
                    </div>

                    <!-- Description ID -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            Deskripsi / Keterangan
                        </label>
                        <textarea
                            wire:model="descriptionId"
                            rows="2"
                            placeholder="Keterangan opsional untuk kategori ini..."
                            class="w-full px-3.5 py-2 rounded-xl border border-coffee-200 text-sm focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
                        ></textarea>
                    </div>

                    <!-- Active Toggle -->
                    <div class="flex items-center gap-2 pt-2">
                        <input
                            type="checkbox"
                            id="isActiveCheckbox"
                            wire:model="isActive"
                            class="w-4 h-4 rounded border-coffee-300 text-coffee-800 focus:ring-coffee-500 accent-coffee-800 cursor-pointer"
                        />
                        <label for="isActiveCheckbox" class="text-xs font-semibold text-coffee-800 cursor-pointer select-none">
                            Kategori Aktif & Ditampilkan di Kasir POS
                        </label>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-coffee-100">
                        <x-button type="button" wire:click="$set('showModal', false)" variant="secondary" size="md">
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

    <!-- Delete Confirmation Modal -->
    @if ($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-sm w-full p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-coffee-950 mb-1">Hapus Kategori Ini?</h3>
                <p class="text-xs text-coffee-500 mb-6">
                    Kategori akan disembunyikan (soft-delete). Produk yang terhubung akan tetap tersimpan namun tidak lagi memiliki kategori ini.
                </p>
                <div class="flex items-center justify-center gap-2.5">
                    <x-button type="button" wire:click="$set('showDeleteModal', false)" variant="secondary" size="md" class="w-full">
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

