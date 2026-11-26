<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-coffee-500 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-coffee-800">{{ __('messages.products.breadcrumb_parent') }}</a>
                <span>/</span>
                <span class="text-coffee-800">{{ __('messages.products.breadcrumb_current') }}</span>
            </div>
            <h1 class="text-2xl font-bold text-coffee-950 tracking-tight">{{ __('messages.products.heading') }}</h1>
            <p class="text-sm text-coffee-600 mt-0.5">{{ __('messages.products.subheading') }}</p>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="{{ route('menu.categories') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-coffee-800 text-xs font-semibold hover:bg-coffee-50 transition shadow-2xs">
                {{ __('messages.products.categories_btn') }}
            </a>
            <a href="{{ route('menu.modifiers') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-coffee-200 bg-white text-coffee-800 text-xs font-semibold hover:bg-coffee-50 transition shadow-2xs">
                {{ __('messages.products.modifiers_btn') }}
            </a>
            <a href="{{ route('menu.products.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-coffee-800 text-cream-50 text-xs font-semibold hover:bg-coffee-900 transition shadow-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ __('messages.products.add_product_btn') }}
            </a>
        </div>
    </div>

    <!-- Filters & Search Bar -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 p-4 shadow-2xs space-y-3">
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
                    placeholder="{{ __('messages.products.search_placeholder') }}"
                    class="w-full pl-10 pr-4 py-2 rounded-xl border border-coffee-200 text-sm text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-500/20 focus:border-coffee-700 bg-white"
                />
            </div>

            <!-- Availability Filter -->
            <select
                wire:model.live="filterAvailability"
                class="px-3.5 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-800 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-500/20"
            >
                <option value="all">{{ __('messages.products.filter_availability_all') }}</option>
                <option value="available">{{ __('messages.products.filter_availability_available') }}</option>
                <option value="sold_out">{{ __('messages.products.filter_availability_sold_out') }}</option>
            </select>
        </div>

        <!-- Category Pills -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
            <button
                type="button"
                wire:click="$set('selectedCategory', 'all')"
                class="px-3 py-1.5 rounded-full font-semibold whitespace-nowrap transition {{ $selectedCategory === 'all' ? 'bg-coffee-800 text-cream-50 shadow-2xs' : 'bg-cream-100 text-coffee-700 hover:bg-coffee-100' }}"
            >
                {{ __('messages.products.filter_all_categories') }}
            </button>
            @foreach ($categories as $cat)
                <button
                    type="button"
                    wire:click="$set('selectedCategory', '{{ $cat->slug }}')"
                    class="px-3 py-1.5 rounded-full font-semibold whitespace-nowrap transition {{ $selectedCategory === $cat->slug ? 'bg-coffee-800 text-cream-50 shadow-2xs' : 'bg-cream-100 text-coffee-700 hover:bg-coffee-100' }}"
                >
                    {{ $cat->name }}
                </button>
            @endforeach
        </div>
    </div>

    <!-- Products Table -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 shadow-2xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-coffee-950">
                <thead class="bg-cream-100/70 border-b border-coffee-200/80 text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    <tr>
                        <th class="px-5 py-3">{{ __('messages.products.col_product') }}</th>
                        <th class="px-5 py-3">{{ __('messages.products.col_category') }}</th>
                        <th class="px-5 py-3">{{ __('messages.products.col_price') }}</th>
                        <th class="px-5 py-3 text-center">{{ __('messages.products.col_variants_options') }}</th>
                        <th class="px-5 py-3 text-center">{{ __('messages.products.col_stock_status') }}</th>
                        <th class="px-5 py-3 text-right">{{ __('messages.products.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-coffee-100">
                    @forelse ($products as $product)
                        <tr class="hover:bg-cream-50/50 transition-colors {{ $product->is_sold_out ? 'bg-amber-50/30' : '' }}">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-cream-200 border border-coffee-200 flex items-center justify-center overflow-hidden shrink-0">
                                        @if ($product->image_path)
                                            <img src="{{ asset('storage/' . $product->image_path) }}" alt="{{ $product->name }}" class="w-full h-full object-cover" />
                                        @else
                                            <svg class="w-5 h-5 text-coffee-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                            </svg>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-bold text-coffee-950 flex items-center gap-2">
                                            {{ $product->translate('id')?->name ?? '—' }}
                                            @if ($product->translate('en')?->name)
                                                <span class="text-xs font-normal text-coffee-500 italic">({{ $product->translate('en')->name }})</span>
                                            @endif
                                        </div>
                                        <p class="text-xs font-mono text-coffee-500 mt-0.5">{{ $product->sku }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-coffee-100/70 text-coffee-800">
                                    {{ $product->category?->name ?? __('messages.products.uncategorized') }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-bold text-coffee-950">
                                @if ($product->has_variants)
                                    <span class="text-xs font-normal text-coffee-500 block">{{ __('messages.products.starting_from') }}</span>
                                    Rp {{ number_format($product->effective_price, 0, ',', '.') }}
                                @else
                                    Rp {{ number_format($product->base_price, 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                @if ($product->has_variants)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-caramel-100 text-caramel-800">
                                        {{ __('messages.products.variant_count_badge', ['count' => $product->variants->count()]) }}
                                    </span>
                                @else
                                    <span class="text-xs text-coffee-400">{{ __('messages.products.standard_variant') }}</span>
                                @endif
                                @if ($product->modifierGroups->isNotEmpty())
                                    <span class="block text-[10px] text-coffee-500 mt-0.5">
                                        {{ __('messages.products.modifier_count_tag', ['count' => $product->modifierGroups->count()]) }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <button
                                    type="button"
                                    wire:click="toggleSoldOut({{ $product->id }})"
                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold cursor-pointer transition {{ $product->is_sold_out ? 'bg-red-100 text-red-800 border border-red-300 hover:bg-red-200' : 'bg-emerald-50 text-emerald-800 border border-emerald-200/80 hover:bg-emerald-100' }}"
                                >
                                    <span class="w-1.5 h-1.5 rounded-full {{ $product->is_sold_out ? 'bg-red-600' : 'bg-emerald-600' }}"></span>
                                    {{ $product->is_sold_out ? __('messages.products.status_sold_out') : __('messages.products.status_available') }}
                                </button>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1.5">
                                <a
                                    href="{{ route('menu.products.edit', $product->id) }}"
                                    class="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-coffee-200 text-xs font-medium text-coffee-800 hover:bg-coffee-100/60 transition"
                                >
                                    {{ __('messages.products.edit_btn') }}
                                </a>
                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $product->id }})"
                                    class="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-red-200 text-xs font-medium text-red-700 hover:bg-red-50 transition"
                                >
                                    {{ __('messages.products.delete_btn') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-coffee-500">
                                <p class="text-sm font-semibold">{{ __('messages.products.empty_title') }}</p>
                                <p class="text-xs text-coffee-400 mt-1">{{ __('messages.products.empty_subtitle') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="p-4 border-t border-coffee-100 bg-cream-50/50">
                {{ $products->links() }}
            </div>
        @endif
    </div>

    <!-- Delete Modal -->
    @if ($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-coffee-950/60 backdrop-blur-xs">
            <div class="bg-white rounded-2xl border border-coffee-200 shadow-xl max-w-sm w-full p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-coffee-950 mb-1">{{ __('messages.products.delete_modal_title') }}</h3>
                <p class="text-xs text-coffee-500 mb-6">
                    {{ __('messages.products.delete_modal_desc') }}
                </p>
                <div class="flex items-center justify-center gap-2.5">
                    <x-button type="button" wire:click="$set('showDeleteModal', false)" variant="secondary" size="md" class="w-full">
                        {{ __('messages.products.cancel_btn') }}
                    </x-button>
                    <x-button type="button" wire:click="deleteProduct" variant="danger" size="md" class="w-full">
                        {{ __('messages.products.confirm_delete_btn') }}
                    </x-button>
                </div>
            </div>
        </div>
    @endif
</div>

