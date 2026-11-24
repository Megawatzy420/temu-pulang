<div class="h-[calc(100vh-65px)] flex flex-col lg:flex-row overflow-hidden bg-cream-50">

    <!-- LEFT SECTION: Product Catalog & Filters (65% width on desktop) -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden border-r border-coffee-200/60">

        <!-- Top Control Bar: Search & Dining Mode Toggle -->
        <div class="p-3.5 sm:p-4 bg-white/90 border-b border-coffee-200/60 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 shrink-0">
            <!-- Search Bar -->
            <div class="relative flex-1 max-w-md">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-coffee-400">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="{{ __('messages.pos.search_placeholder') }}"
                    class="w-full pl-10 pr-4 py-2 bg-cream-50/80 border border-coffee-200 rounded-xl text-sm text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white transition-all"
                />
                @if ($search !== '')
                    <button
                        wire:click="$set('search', '')"
                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-coffee-400 hover:text-coffee-700"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>

            <!-- Dining Mode Toggle & Table Selection -->
            <div class="flex items-center gap-2">
                <div class="flex bg-cream-100 p-1 rounded-xl border border-coffee-200/80 text-xs font-semibold">
                    <button
                        type="button"
                        wire:click="$set('diningMode', 'dine_in')"
                        class="px-3 py-1.5 rounded-lg transition-all flex items-center gap-1.5 cursor-pointer {{ $diningMode === 'dine_in' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:text-coffee-950' }}"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <span>{{ __('messages.pos.dine_in') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="$set('diningMode', 'takeaway')"
                        class="px-3 py-1.5 rounded-lg transition-all flex items-center gap-1.5 cursor-pointer {{ $diningMode === 'takeaway' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-700 hover:text-coffee-950' }}"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                        </svg>
                        <span>{{ __('messages.pos.takeaway') }}</span>
                    </button>
                </div>

                <div class="hidden sm:flex items-center px-3 py-2 bg-white rounded-xl border border-coffee-200 text-xs font-bold text-coffee-800 shadow-2xs">
                    {{ $diningMode === 'dine_in' ? __('messages.pos.dine_in') : __('messages.pos.takeaway') }}
                </div>
            </div>
        </div>

        <!-- Category Filter Pills Bar -->
        <div class="px-3.5 sm:px-4 py-2.5 bg-cream-50/60 border-b border-coffee-200/60 flex items-center gap-2 overflow-x-auto shrink-0 no-scrollbar">
            <button
                type="button"
                wire:click="$set('selectedCategory', 'all')"
                class="px-4 py-2 rounded-xl text-xs font-bold tracking-tight whitespace-nowrap transition-all cursor-pointer {{ $selectedCategory === 'all' ? 'bg-coffee-900 text-cream-50 shadow-xs border border-coffee-950' : 'bg-white text-coffee-800 border border-coffee-200/90 hover:bg-coffee-100/70' }}"
            >
                {{ __('messages.pos.all_categories') }}
            </button>

            @foreach ($categories as $cat)
                <button
                    type="button"
                    wire:click="$set('selectedCategory', '{{ $cat->slug }}')"
                    class="px-4 py-2 rounded-xl text-xs font-bold tracking-tight whitespace-nowrap transition-all cursor-pointer {{ $selectedCategory === $cat->slug ? 'bg-coffee-900 text-cream-50 shadow-xs border border-coffee-950' : 'bg-white text-coffee-800 border border-coffee-200/90 hover:bg-coffee-100/70' }}"
                >
                    {{ $cat->name }}
                </button>
            @endforeach
        </div>

        <!-- Product Grid Area -->
        <div class="flex-1 p-3.5 sm:p-5 overflow-y-auto">
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3.5 sm:gap-4">

                <!-- 1. Database Products (Real Models) -->
                @foreach ($realProducts as $product)
                    <div
                        wire:click="selectProduct({{ $product->id }}, false)"
                        class="group bg-white rounded-2xl border border-coffee-200/80 p-3 sm:p-4 flex flex-col justify-between hover:border-coffee-400 hover:shadow-md transition-all duration-200 cursor-pointer relative overflow-hidden select-none"
                    >
                        <!-- Top Image / Accent Placeholder -->
                        <div class="w-full h-24 sm:h-28 rounded-xl bg-gradient-to-br from-cream-100 via-coffee-100/70 to-coffee-200/50 border border-coffee-100 flex items-center justify-center mb-3 relative overflow-hidden group-hover:scale-[1.02] transition-transform">
                            <!-- Category Badge -->
                            <div class="absolute top-2 left-2">
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-white/95 text-coffee-800 border border-coffee-200/80 shadow-2xs">
                                    {{ $product->category?->name ?? 'Menu' }}
                                </span>
                            </div>

                            <!-- Icon Visual Representation -->
                            <div class="text-coffee-600/70">
                                @if (str_contains(strtolower($product->sku), 'cf') || str_contains(strtolower($product->sku), 'lt'))
                                    <!-- Coffee / Beverage Icon -->
                                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                @else
                                    <!-- Food / Snack Icon -->
                                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                    </svg>
                                @endif
                            </div>

                            <!-- Options Badge -->
                            @if ($product->has_variants)
                                <div class="absolute bottom-2 right-2">
                                    <span class="px-1.5 py-0.5 rounded-md text-[9px] font-bold bg-caramel-500 text-white shadow-2xs">
                                        {{ count($product->variants) }} Varian
                                    </span>
                                </div>
                            @elseif ($product->modifierGroups->isNotEmpty())
                                <div class="absolute bottom-2 right-2">
                                    <span class="px-1.5 py-0.5 rounded-md text-[9px] font-bold bg-coffee-800 text-white shadow-2xs">
                                        Kustom
                                    </span>
                                </div>
                            @endif
                        </div>

                        <!-- Product Information -->
                        <div>
                            <h4 class="font-bold text-sm text-coffee-950 line-clamp-1 group-hover:text-caramel-600 transition-colors">
                                {{ $product->name }}
                            </h4>
                            <p class="text-[11px] text-coffee-400 mt-0.5 font-mono">
                                {{ $product->sku }}
                            </p>
                        </div>

                        <!-- Price and Add Action -->
                        <div class="mt-3 pt-2.5 border-t border-coffee-100 flex items-center justify-between">
                            <div>
                                <span class="text-xs text-coffee-500 block text-[10px]">Harga</span>
                                <span class="text-sm font-extrabold text-coffee-950">
                                    Rp {{ number_format($product->effective_price, 0, ',', '.') }}
                                </span>
                            </div>

                            <span class="w-8 h-8 rounded-xl bg-coffee-900 group-hover:bg-caramel-500 text-white flex items-center justify-center transition-colors shadow-2xs">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                </svg>
                            </span>
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

    <!-- RIGHT SECTION: Active Order Cart (35% width on desktop) -->
    <div class="w-full lg:w-[400px] xl:w-[440px] bg-white flex flex-col h-full border-t lg:border-t-0 border-coffee-200/80 shrink-0 shadow-lg z-20">

        <!-- Cart Header -->
        <div class="p-4 border-b border-coffee-100 bg-cream-50/70 flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="font-bold text-base text-coffee-950 tracking-tight">
                        {{ __('messages.pos.current_order') }}
                    </h3>
                    @if($activeShift)
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                            Shift #{{ $activeShift->id }} Aktif
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200">
                            Shift Tutup
                        </span>
                    @endif
                </div>
                <p class="text-xs text-coffee-500 mt-0.5">
                    {{ $diningMode === 'dine_in' ? __('messages.pos.dine_in') : __('messages.pos.takeaway') }}
                </p>
            </div>

            @if (!empty($cart))
                <button
                    type="button"
                    wire:click="clearCart"
                    :disabled="$paymentInFlight"
                    class="text-xs text-rose-600 hover:text-rose-800 font-semibold p-1 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ __('messages.pos.clear_cart') }}
                </button>
            @endif
        </div>

        <!-- Unresolved payment recovery banner -->
        @if (($paymentInFlight || $hasUnresolvedOrder) && $checkoutIdempotencyKey)
        <div class="mx-4 mt-3 p-3 rounded-xl bg-amber-50 border border-amber-300 text-xs text-amber-900 space-y-2">
            <p class="font-bold flex items-center gap-1">
                ⚠️ Transaksi sebelumnya belum terkonfirmasi
            </p>
            <p>Jangan buat pesanan baru. Periksa status transaksi terlebih dahulu atau lanjutkan pembayaran.</p>
            <div class="flex flex-wrap gap-2 pt-1">
                <button
                    type="button"
                    wire:click="recoverPendingPayment"
                    class="px-3 py-1.5 rounded-lg bg-amber-700 hover:bg-amber-800 text-white text-xs font-bold transition-colors cursor-pointer"
                >
                    Periksa Status Transaksi
                </button>
                <button
                    type="button"
                    wire:click="resumeUnpaidOrder"
                    class="px-3 py-1.5 rounded-lg bg-coffee-800 hover:bg-coffee-900 text-white text-xs font-bold transition-colors cursor-pointer"
                >
                    Lanjutkan Pembayaran
                </button>
            </div>
        </div>
        @endif

        <!-- Cart Items List (Scrollable) -->
        <div class="flex-1 p-3.5 sm:p-4 overflow-y-auto space-y-3">
            @forelse ($cart as $index => $item)
                <div class="p-3 rounded-2xl border border-coffee-200/70 bg-cream-50/40 hover:bg-cream-50 transition-colors space-y-2">
                    <!-- Top Line: Item Name, Variant & Delete -->
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <h5 class="font-bold text-sm text-coffee-950 leading-tight">
                                    {{ $item['name'] }}
                                </h5>
                                @if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standar')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-coffee-200 text-coffee-800">
                                        {{ $item['variant_name'] }}
                                    </span>
                                @endif
                            </div>

                            @if (!empty($item['modifiers_text']))
                                <p class="text-[11px] text-coffee-600 mt-1 leading-snug">
                                    + {{ $item['modifiers_text'] }}
                                </p>
                            @endif

                            @if (!empty($item['notes']))
                                <p class="text-[11px] text-caramel-700 italic mt-0.5">
                                    Catatan: "{{ $item['notes'] }}"
                                </p>
                            @endif
                        </div>

                        <button
                            type="button"
                            wire:click="removeItem({{ $index }})"
                            :disabled="$paymentInFlight"
                            class="text-coffee-300 hover:text-rose-600 p-1 transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Hapus"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>

                    <!-- Bottom Line: Price and Quantity Stepper -->
                    <div class="pt-2 border-t border-coffee-200/40 flex items-center justify-between">
                        <span class="text-sm font-extrabold text-coffee-900">
                            Rp {{ number_format($item['unit_price'] * $item['quantity'], 0, ',', '.') }}
                        </span>

                        <!-- Quantity Stepper Controls -->
                        <div class="flex items-center gap-2 bg-white rounded-xl border border-coffee-200 p-0.5 shadow-2xs">
                            <button
                                type="button"
                                wire:click="decreaseQty({{ $index }})"
                                :disabled="$paymentInFlight"
                                class="w-6 h-6 rounded-lg bg-coffee-50 hover:bg-coffee-200 text-coffee-800 flex items-center justify-center font-bold text-xs transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                -
                            </button>
                            <span class="w-6 text-center text-xs font-bold text-coffee-950">
                                {{ $item['quantity'] }}
                            </span>
                            <button
                                type="button"
                                wire:click="increaseQty({{ $index }})"
                                :disabled="$paymentInFlight"
                                class="w-6 h-6 rounded-lg bg-coffee-900 hover:bg-coffee-800 text-white flex items-center justify-center font-bold text-xs transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                +
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="h-64 flex flex-col items-center justify-center text-center p-6 text-coffee-400">
                    <div class="w-16 h-16 rounded-3xl bg-cream-100 flex items-center justify-center text-coffee-400 mb-3 border border-coffee-200/60">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                        </svg>
                    </div>
                    <p class="font-bold text-coffee-800 text-sm">
                        {{ __('messages.pos.empty_cart_title') }}
                    </p>
                    <p class="text-xs text-coffee-400 mt-1 max-w-[200px]">
                        {{ __('messages.pos.empty_cart_desc') }}
                    </p>
                </div>
            @endforelse
        </div>

        <!-- Cart Calculation & Checkout Area -->
        <div class="p-4 bg-cream-50/80 border-t border-coffee-200/80 space-y-3 shrink-0">
            <!-- Price Summary -->
            <div class="space-y-1.5 text-xs">
                <div class="flex items-center justify-between text-coffee-600">
                    <span>{{ __('messages.pos.subtotal') }}</span>
                    <span class="font-semibold text-coffee-800">Rp {{ number_format($subtotal, 0, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-coffee-600">
                    <span>{{ __('messages.pos.tax') }}</span>
                    <span class="font-semibold text-coffee-800">Rp {{ number_format($tax, 0, ',', '.') }}</span>
                </div>
                @if ($serviceCharge > 0)
                    <div class="flex items-center justify-between text-coffee-600">
                        <span>Service Charge</span>
                        <span class="font-semibold text-coffee-800">Rp {{ number_format($serviceCharge, 0, ',', '.') }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between pt-2 border-t border-coffee-200/60 text-base font-extrabold text-coffee-950">
                    <span>{{ __('messages.pos.total') }}</span>
                    <span class="text-lg text-coffee-950">Rp {{ number_format($total, 0, ',', '.') }}</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="grid grid-cols-3 gap-2.5 pt-1">
                <x-button
                    type="button"
                    variant="outline"
                    size="md"
                    wire:click="holdOrder"
                    :disabled="empty($cart)"
                    class="col-span-1 text-xs font-semibold py-3"
                >
                    {{ __('messages.pos.hold_order') }}
                </x-button>

                <x-button
                    type="button"
                    variant="primary"
                    size="md"
                    wire:click="openCheckout"
                    :disabled="empty($cart)"
                    class="col-span-2 text-sm font-bold py-3 shadow-md bg-coffee-900 hover:bg-caramel-600"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    {{ __('messages.pos.checkout') }}
                </x-button>
            </div>
        </div>
    </div>

    <!-- CUSTOMIZATION MODAL (Variant, Modifiers, Notes) -->
    @if ($showCustomizeModal && $activeProduct)
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0 flex items-center justify-center">
            <!-- Backdrop -->
            <div
                class="fixed inset-0 bg-coffee-950/60 backdrop-blur-xs transition-opacity"
                wire:click="$set('showCustomizeModal', false)"
            ></div>

            <!-- Modal Content Panel -->
            <div class="relative w-full max-w-lg bg-white rounded-3xl border border-coffee-200 shadow-2xl overflow-hidden z-10 max-h-[90vh] flex flex-col">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-coffee-100 bg-cream-50 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-coffee-950 tracking-tight">
                            {{ $activeProduct['name'] }}
                        </h3>
                        <p class="text-xs text-coffee-500 font-mono mt-0.5">
                            {{ $activeProduct['sku'] }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('showCustomizeModal', false)"
                        class="text-coffee-400 hover:text-coffee-800 p-1 rounded-xl hover:bg-coffee-100 transition-colors cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body (Scrollable options) -->
                <div class="p-6 overflow-y-auto space-y-5">

                    <!-- 1. Size / Portion Variants -->
                    @if ($activeProduct['has_variants'] && !empty($activeProduct['variants']))
                        <div class="space-y-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800">
                                {{ __('messages.pos.variants') }} <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-2.5">
                                @foreach ($activeProduct['variants'] as $variant)
                                    <button
                                        type="button"
                                        wire:click="setVariant({{ $variant['id'] }}, '{{ $variant['name'] }}', {{ $variant['price'] }})"
                                        class="p-3 rounded-2xl border text-left transition-all cursor-pointer flex flex-col justify-between {{ $selectedVariantId === $variant['id'] ? 'bg-coffee-900 text-cream-50 border-coffee-950 shadow-xs' : 'bg-cream-50/70 border-coffee-200/90 text-coffee-900 hover:bg-coffee-100/60' }}"
                                    >
                                        <span class="font-bold text-sm">{{ $variant['name'] }}</span>
                                        <span class="text-xs mt-1 {{ $selectedVariantId === $variant['id'] ? 'text-caramel-300' : 'text-coffee-600' }}">
                                            Rp {{ number_format($variant['price'], 0, ',', '.') }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- 2. Modifiers & Customizations -->
                    @foreach ($activeProduct['modifier_groups'] as $group)
                        <div class="space-y-2 pt-2 border-t border-coffee-100">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800">
                                    {{ $group['name'] }}
                                </label>
                                <span class="text-[10px] font-semibold text-coffee-500">
                                    {{ $group['is_required'] ? '(Wajib Pilih 1)' : '(Opsional)' }}
                                </span>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                @foreach ($group['modifiers'] as $mod)
                                    @php
                                        $isSelected = $group['is_required']
                                            ? (isset($selectedModifiers[$group['id']]) && $selectedModifiers[$group['id']] === $mod['id'])
                                            : in_array($mod['id'], $selectedAddons, true);
                                    @endphp

                                    <button
                                        type="button"
                                        @if ($group['is_required'])
                                            wire:click="$set('selectedModifiers.{{ $group['id'] }}', {{ $mod['id'] }})"
                                        @else
                                            wire:click="toggleAddon({{ $mod['id'] }})"
                                        @endif
                                        class="p-2.5 rounded-xl border text-center transition-all cursor-pointer flex flex-col items-center justify-center gap-1 {{ $isSelected ? 'bg-caramel-500 text-white border-caramel-600 shadow-2xs' : 'bg-cream-50/70 border-coffee-200 text-coffee-800 hover:bg-coffee-100/60' }}"
                                    >
                                        <span class="text-xs font-semibold leading-tight">{{ $mod['name'] }}</span>
                                        @if (!$mod['is_free'])
                                            <span class="text-[10px] {{ $isSelected ? 'text-cream-100' : 'text-coffee-600' }}">
                                                +Rp {{ number_format($mod['price'], 0, ',', '.') }}
                                            </span>
                                        @else
                                            <span class="text-[10px] opacity-75">Gratis</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <!-- 3. Special Notes Input -->
                    <div class="space-y-1.5 pt-2 border-t border-coffee-100">
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800">
                            {{ __('messages.pos.special_notes') }}
                        </label>
                        <textarea
                            wire:model="customNotes"
                            rows="2"
                            placeholder="{{ __('messages.pos.notes_placeholder') }}"
                            class="w-full p-3 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs text-coffee-950 placeholder-coffee-400 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white resize-none"
                        ></textarea>
                    </div>

                </div>

                <!-- Modal Footer -->
                <div class="p-4 bg-cream-50 border-t border-coffee-200/80 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] text-coffee-500 block">Harga Item</span>
                        <span class="text-base font-extrabold text-coffee-950">
                            Rp {{ number_format($selectedVariantPrice, 0, ',', '.') }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-button
                            type="button"
                            variant="secondary"
                            size="sm"
                            wire:click="$set('showCustomizeModal', false)"
                        >
                            {{ __('messages.pos.cancel') }}
                        </x-button>

                        <x-button
                            type="button"
                            variant="primary"
                            size="sm"
                            wire:click="confirmCustomization"
                            class="shadow-xs font-bold"
                        >
                            {{ __('messages.pos.add_to_order') }}
                        </x-button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- PAYMENT MODAL -->
    @if ($showPaymentModal)
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0 flex items-center justify-center">
            <!-- Backdrop -->
            <div
                class="fixed inset-0 bg-coffee-950/60 backdrop-blur-xs transition-opacity"
                wire:click="$set('showPaymentModal', false)"
            ></div>

            <!-- Modal Content Panel -->
            <div class="relative w-full max-w-md bg-white rounded-3xl border border-coffee-200 shadow-2xl overflow-hidden z-10 flex flex-col">
                <!-- Modal Header -->
                <div class="px-6 py-4.5 border-b border-coffee-100 bg-cream-50 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-coffee-950 tracking-tight">
                            Pembayaran Pesanan
                        </h3>
                        <p class="text-xs text-coffee-500 mt-0.5">
                            {{ count($cart) }} Item
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('showPaymentModal', false)"
                        class="text-coffee-400 hover:text-coffee-800 p-1"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="p-6 space-y-5 overflow-y-auto max-h-[70vh]">
                    <!-- Total Bill Box -->
                    <div class="p-4 rounded-2xl bg-cream-100/80 border border-coffee-200 text-center">
                        <span class="text-xs font-semibold text-coffee-600 block uppercase tracking-wider">Total Tagihan</span>
                        <span class="text-3xl font-black text-coffee-950 mt-1 block tracking-tight">
                            Rp {{ number_format($total, 0, ',', '.') }}
                        </span>
                    </div>

                    <!-- Payment Method Selector -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800">
                            Metode Pembayaran
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                type="button"
                                wire:click="$set('paymentMethod', 'cash')"
                                class="p-3 rounded-xl border text-center transition-all cursor-pointer flex flex-col items-center gap-1 {{ $paymentMethod === 'cash' ? 'bg-coffee-900 text-cream-50 border-coffee-950 shadow-xs' : 'bg-cream-50/70 border-coffee-200 text-coffee-800 hover:bg-coffee-100/60' }}"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <span class="text-xs font-bold">Tunai</span>
                            </button>

                            <button
                                type="button"
                                wire:click="$set('paymentMethod', 'qris')"
                                class="p-3 rounded-xl border text-center transition-all cursor-pointer flex flex-col items-center gap-1 {{ $paymentMethod === 'qris' ? 'bg-coffee-900 text-cream-50 border-coffee-950 shadow-xs' : 'bg-cream-50/70 border-coffee-200 text-coffee-800 hover:bg-coffee-100/60' }}"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                </svg>
                                <span class="text-xs font-bold">QRIS</span>
                            </button>

                            <button
                                type="button"
                                wire:click="$set('paymentMethod', 'debit')"
                                class="p-3 rounded-xl border text-center transition-all cursor-pointer flex flex-col items-center gap-1 {{ $paymentMethod === 'debit' ? 'bg-coffee-900 text-cream-50 border-coffee-950 shadow-xs' : 'bg-cream-50/70 border-coffee-200 text-coffee-800 hover:bg-coffee-100/60' }}"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                </svg>
                                <span class="text-xs font-bold">Debit</span>
                            </button>
                        </div>
                    </div>

                    <!-- Cash payment details -->
                    @if ($paymentMethod === 'cash')
                        <div class="space-y-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800">
                                Uang Diterima
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <button
                                    type="button"
                                    wire:click="$set('cashGiven', {{ $total }})"
                                    class="py-2 px-2.5 rounded-xl border text-xs font-bold transition-all cursor-pointer {{ $cashGiven === $total ? 'bg-caramel-500 text-white border-caramel-600' : 'bg-cream-50 border-coffee-200 text-coffee-800' }}"
                                >
                                    Uang Pas
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('cashGiven', 50000)"
                                    class="py-2 px-2.5 rounded-xl border text-xs font-bold transition-all cursor-pointer {{ $cashGiven === 50000 ? 'bg-caramel-500 text-white border-caramel-600' : 'bg-cream-50 border-coffee-200 text-coffee-800' }}"
                                >
                                    Rp 50.000
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('cashGiven', 100000)"
                                    class="py-2 px-2.5 rounded-xl border text-xs font-bold transition-all cursor-pointer {{ $cashGiven === 100000 ? 'bg-caramel-500 text-white border-caramel-600' : 'bg-cream-50 border-coffee-200 text-coffee-800' }}"
                                >
                                    Rp 100.000
                                </button>
                            </div>

                            @if ($cashGiven >= $total)
                                <div class="flex items-center justify-between p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-xs mt-2">
                                    <span class="font-medium text-emerald-800">Kembalian:</span>
                                    <span class="font-bold text-emerald-900 text-sm">
                                        Rp {{ number_format($cashGiven - $total, 0, ',', '.') }}
                                    </span>
                                </div>
                            @elseif ($cashGiven > 0 && $cashGiven < $total)
                                <div class="flex items-center justify-between p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-xs mt-2">
                                    <span class="font-medium text-rose-800">Kurang:</span>
                                    <span class="font-bold text-rose-900 text-sm">
                                        Rp {{ number_format($total - $cashGiven, 0, ',', '.') }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- QRIS payment details -->
                    @if ($paymentMethod === 'qris')
                        <div class="space-y-3">
                            <div class="p-3 rounded-xl bg-blue-50 border border-blue-200 text-xs text-blue-900">
                                <p class="font-bold mb-1">Instruksi Pembayaran QRIS:</p>
                                <p>1. Tampilkan kode QRIS kepada pelanggan.</p>
                                <p>2. Tunggu konfirmasi notifikasi masuk dari aplikasi merchant.</p>
                                <p>3. Centang konfirmasi di bawah lalu klik Bayar.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                    Nomor Referensi QRIS (Opsional)
                                </label>
                                <input
                                    type="text"
                                    wire:model="qrisReferenceNumber"
                                    placeholder="misal: REF-QRIS-20261114"
                                    class="w-full px-3 py-2 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs text-coffee-950 focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                                />
                            </div>
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model="qrisCashierConfirmed"
                                    class="mt-0.5 rounded border-coffee-300 text-coffee-900 focus:ring-coffee-500"
                                />
                                <span class="text-xs text-coffee-800 font-semibold leading-snug">
                                    Saya konfirmasi bahwa dana QRIS sebesar <strong>Rp {{ number_format($total, 0, ',', '.') }}</strong> telah masuk ke rekening merchant.
                                </span>
                            </label>
                        </div>
                    @endif

                    <!-- Debit payment details -->
                    @if ($paymentMethod === 'debit')
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                    Nomor Referensi EDC (Opsional)
                                </label>
                                <input
                                    type="text"
                                    wire:model="debitReferenceNumber"
                                    placeholder="misal: EDC-REF-20261114"
                                    class="w-full px-3 py-2 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs text-coffee-950 focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                                />
                            </div>
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model="debitCashierConfirmed"
                                    class="mt-0.5 rounded border-coffee-300 text-coffee-900 focus:ring-coffee-500"
                                />
                                <span class="text-xs text-coffee-800 font-semibold leading-snug">
                                    Saya konfirmasi bahwa mesin EDC menampilkan transaksi berhasil (Approved) untuk pembayaran <strong>Rp {{ number_format($total, 0, ',', '.') }}</strong>.
                                </span>
                            </label>
                        </div>
                    @endif

                    <!-- Customer Name & Notes -->
                    <div class="space-y-3 pt-2 border-t border-coffee-100">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Nama Pelanggan (Panggilan Antrean)
                            </label>
                            <input
                                type="text"
                                wire:model="customerName"
                                placeholder="misal: Bpk. Budi / Kak Sarah"
                                class="w-full px-3 py-2 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs text-coffee-950 focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                                Catatan Pesanan Keseluruhan
                            </label>
                            <input
                                type="text"
                                wire:model="orderNotes"
                                placeholder="misal: Bawa pulang kantong terpisah"
                                class="w-full px-3 py-2 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs text-coffee-950 focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                            />
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="p-4 bg-cream-50 border-t border-coffee-200/80 flex items-center justify-between">
                    <x-button
                        type="button"
                        variant="secondary"
                        size="sm"
                        wire:click="$set('showPaymentModal', false)"
                    >
                        Batal
                    </x-button>

                    <x-button
                        type="button"
                        variant="primary"
                        size="md"
                        wire:click="processPayment"
                        wire:loading.attr="disabled"
                        :disabled="$isProcessingPayment || ($paymentMethod === 'qris' && !$qrisCashierConfirmed) || ($paymentMethod === 'debit' && !$debitCashierConfirmed)"
                        class="shadow-md font-bold bg-emerald-700 hover:bg-emerald-800 relative"
                    >
                        <span wire:loading.remove wire:target="processPayment">Bayar</span>
                        <span wire:loading wire:target="processPayment" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Memproses...
                        </span>
                    </x-button>
                </div>
            </div>
        </div>
    @endif

    <!-- SUCCESS MODAL -->
    @if ($showSuccessModal)
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0 flex items-center justify-center">
            <div class="fixed inset-0 bg-coffee-950/60 backdrop-blur-xs"></div>

            <div
                class="relative w-full max-w-sm bg-white rounded-3xl border border-emerald-200 shadow-2xl overflow-hidden z-10"
                @if ($lastPrintJobStatus === 'pending' || $lastPrintJobStatus === 'processing')
                    wire:poll.2000ms="refreshPrintStatus"
                @endif
            >
                <!-- Success Header -->
                <div class="bg-emerald-600 px-6 py-5 text-white text-center">
                    <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-black tracking-tight">Pembayaran Berhasil!</h3>
                    <p class="text-emerald-100 text-xs mt-1">Transaksi telah disimpan ke sistem</p>
                </div>

                <!-- Order Details -->
                <div class="p-6 space-y-4">
                    <!-- Call Number (large) -->
                    @if ($lastCallNumber)
                        <div class="text-center">
                            <span class="text-xs font-bold text-coffee-500 uppercase tracking-wider block">Nomor Panggilan</span>
                            <span class="text-5xl font-black text-coffee-950 tracking-tight">{{ $lastCallNumber }}</span>
                        </div>
                    @endif

                    <!-- Order info -->
                    <div class="space-y-2 text-sm">
                        @if ($lastOrderNumber)
                            <div class="flex items-center justify-between text-coffee-600">
                                <span class="text-xs">No. Pesanan</span>
                                <span class="font-bold text-coffee-950 font-mono text-xs">{{ $lastOrderNumber }}</span>
                            </div>
                        @endif

                        <div class="flex items-center justify-between text-coffee-600">
                            <span class="text-xs">Total Dibayar</span>
                            <span class="font-extrabold text-coffee-950">Rp {{ number_format($lastTotal ?? 0, 0, ',', '.') }}</span>
                        </div>

                        @if ($lastPaymentMethod === 'cash' && $lastCashGiven !== null)
                            <div class="flex items-center justify-between text-coffee-600">
                                <span class="text-xs">Uang Diterima</span>
                                <span class="font-semibold text-coffee-800">Rp {{ number_format($lastCashGiven, 0, ',', '.') }}</span>
                            </div>
                            <div class="flex items-center justify-between text-emerald-700 font-bold">
                                <span class="text-xs">Kembalian</span>
                                <span class="text-base">Rp {{ number_format($lastChange ?? 0, 0, ',', '.') }}</span>
                            </div>
                        @endif

                        @if ($lastPaymentMethod)
                            <div class="flex items-center justify-between text-coffee-600">
                                <span class="text-xs">Metode</span>
                                <span class="font-semibold text-coffee-800 uppercase text-xs">{{ $lastPaymentMethod }}</span>
                            </div>
                        @endif
                    </div>

                    <!-- Print status badge -->
                    <div class="rounded-xl p-3 text-xs font-semibold flex items-center gap-2
                        @if ($lastPrintJobStatus === 'pending') bg-amber-50 border border-amber-200 text-amber-800
                        @elseif ($lastPrintJobStatus === 'processing') bg-blue-50 border border-blue-200 text-blue-800
                        @elseif ($lastPrintJobStatus === 'completed') bg-emerald-50 border border-emerald-200 text-emerald-800
                        @elseif ($lastPrintJobStatus === 'failed') bg-rose-50 border border-rose-200 text-rose-800
                        @elseif ($lastPrintJobStatus === 'uncertain') bg-purple-50 border border-purple-200 text-purple-800
                        @else bg-gray-50 border border-gray-200 text-gray-600
                        @endif
                    ">
                        @if ($lastPrintJobStatus === 'pending')
                            <svg class="animate-spin w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Mengantre cetak...
                        @elseif ($lastPrintJobStatus === 'processing')
                            <svg class="animate-spin w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Sedang mencetak...
                        @elseif ($lastPrintJobStatus === 'completed')
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                            Struk berhasil dicetak ✓
                        @elseif ($lastPrintJobStatus === 'failed')
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            Gagal mencetak
                        @elseif ($lastPrintJobStatus === 'uncertain')
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Status cetak tidak pasti — periksa fisik printer
                        @else
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            Printer tidak aktif
                        @endif
                    </div>

                    <!-- Action buttons -->
                    <div class="space-y-2 pt-1">
                        <div class="grid grid-cols-2 gap-2">
                            @if ($lastPrintJobId && $lastPrintJobStatus === 'completed')
                                <x-button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    wire:click="reprintReceipt"
                                    class="text-xs"
                                >
                                    Cetak Ulang
                                </x-button>
                            @endif

                            @if ($lastPrintJobId && $lastPrintJobStatus === 'failed')
                                <x-button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    wire:click="retryPrint"
                                    class="text-xs text-rose-700 border-rose-300 hover:bg-rose-50"
                                >
                                    Coba Cetak Lagi
                                </x-button>
                            @endif

                            <x-button
                                type="button"
                                variant="primary"
                                size="sm"
                                wire:click="closeSuccessModal"
                                class="col-span-2 text-sm font-bold bg-coffee-900 hover:bg-coffee-800"
                            >
                                Pesanan Baru
                            </x-button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif


</div>
