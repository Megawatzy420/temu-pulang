<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('menu.products') }}" class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    {{ $productId ? __('messages.products.form_heading_edit') : __('messages.products.form_heading_create') }}
                </h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('messages.products.form_subheading') }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('menu.products') }}" class="px-4 py-2 text-sm font-medium rounded-lg border border-zinc-300 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 transition">
                {{ __('messages.products.cancel_btn') }}
            </a>
            <button wire:click="save" wire:loading.attr="disabled" class="px-5 py-2 text-sm font-semibold rounded-lg bg-amber-600 hover:bg-amber-500 text-white shadow-sm flex items-center gap-2 transition disabled:opacity-50">
                <svg wire:loading wire:target="save" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span>{{ $productId ? __('messages.products.update_product_btn') : __('messages.products.save_product_btn') }}</span>
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-zinc-200 dark:border-zinc-800">
        <nav class="flex space-x-6 overflow-x-auto">
            <button type="button" wire:click="$set('activeTab', 'basic')" class="py-3 px-1 border-b-2 font-medium text-sm transition shrink-0 {{ $activeTab === 'basic' ? 'border-amber-600 text-amber-600 dark:text-amber-500 font-bold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                {{ __('messages.products.tab_basic') }}
            </button>
            <button type="button" wire:click="$set('activeTab', 'content')" class="py-3 px-1 border-b-2 font-medium text-sm transition shrink-0 {{ $activeTab === 'content' ? 'border-amber-600 text-amber-600 dark:text-amber-500 font-bold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                {{ __('messages.products.tab_content') }}
            </button>
            <button type="button" wire:click="$set('activeTab', 'variants')" class="py-3 px-1 border-b-2 font-medium text-sm transition shrink-0 {{ $activeTab === 'variants' ? 'border-amber-600 text-amber-600 dark:text-amber-500 font-bold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                {{ __('messages.products.tab_variants') }} @if($hasVariants)<span class="ml-1 px-2 py-0.5 text-xs bg-amber-100 text-amber-800 rounded-full font-bold">{{ count($variants) }}</span>@endif
            </button>
            <button type="button" wire:click="$set('activeTab', 'modifiers')" class="py-3 px-1 border-b-2 font-medium text-sm transition shrink-0 {{ $activeTab === 'modifiers' ? 'border-amber-600 text-amber-600 dark:text-amber-500 font-bold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                {{ __('messages.products.tab_modifiers') }} @if(count($selectedModifierGroupIds) > 0)<span class="ml-1 px-2 py-0.5 text-xs bg-emerald-100 text-emerald-800 rounded-full font-bold">{{ count($selectedModifierGroupIds) }}</span>@endif
            </button>
            <button type="button" wire:click="$set('activeTab', 'recipe')" class="py-3 px-1 border-b-2 font-medium text-sm transition shrink-0 {{ $activeTab === 'recipe' ? 'border-amber-600 text-amber-600 dark:text-amber-500 font-bold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                {{ __('messages.products.tab_recipe') }} @if(count($baseRecipeItems) > 0)<span class="ml-1 px-2 py-0.5 text-xs bg-amber-100 text-amber-800 rounded-full font-bold">{{ count($baseRecipeItems) }}</span>@endif
            </button>
        </nav>
    </div>

    <!-- Tab 1: Informasi Dasar -->
    <div class="{{ $activeTab === 'basic' ? 'block' : 'hidden' }} space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Form -->
            <div class="md:col-span-2 bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">{{ __('messages.products.category_label') }} <span class="text-rose-500">*</span></label>
                        <select wire:model="categoryId" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500">
                            <option value="">{{ __('messages.products.select_category_placeholder') }}</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">{{ __('messages.products.sku_label') }}</label>
                        <input type="text" wire:model="sku" placeholder="{{ __('messages.products.sku_placeholder') }}" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500">
                        @error('sku') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">{{ __('messages.products.base_price_label') }} <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-zinc-400 font-bold">Rp</span>
                            <input type="number" wire:model="basePrice" min="0" step="1" class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500">
                        </div>
                        <p class="text-[11px] text-zinc-400 mt-1">{{ __('messages.products.base_price_hint') }}</p>
                        @error('basePrice') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">{{ __('messages.products.sort_order_label') }}</label>
                        <input type="number" wire:model="sortOrder" min="0" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500">
                        @error('sortOrder') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Channel Toggles -->
                <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 space-y-3">
                    <span class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('messages.products.order_availability_label') }}</span>
                    <div class="flex flex-wrap gap-6">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="dineInAvailable" class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500">
                            <span class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">{{ __('messages.products.dine_in_available_label') }}</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="takeawayAvailable" class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500">
                            <span class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">{{ __('messages.products.takeaway_available_label') }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Right 1 Col: Image & Status Badges -->
            <div class="space-y-6">
                <!-- Status Box -->
                <div class="bg-white dark:bg-zinc-900 p-5 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.products.operational_status_heading') }}</h3>
                    
                    <label class="flex items-center justify-between p-3 rounded-lg border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer">
                        <div>
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 block">{{ __('messages.products.menu_status_title') }}</span>
                            <span class="text-xs text-zinc-500">{{ __('messages.products.menu_status_desc') }}</span>
                        </div>
                        <input type="checkbox" wire:model="isActive" class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500 h-5 w-5">
                    </label>

                    <label class="flex items-center justify-between p-3 rounded-lg border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer">
                        <div>
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 block">{{ __('messages.products.sold_out_title') }}</span>
                            <span class="text-xs text-zinc-500">{{ __('messages.products.sold_out_desc') }}</span>
                        </div>
                        <input type="checkbox" wire:model="isSoldOut" class="rounded border-zinc-300 text-rose-600 focus:ring-rose-500 h-5 w-5">
                    </label>

                    <label class="flex items-center justify-between p-3 rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50/50 dark:bg-amber-950/20 cursor-pointer">
                        <div>
                            <span class="text-sm font-medium text-amber-900 dark:text-amber-200 block">{{ __('messages.products.has_variants_title') }}</span>
                            <span class="text-xs text-amber-700 dark:text-amber-400">{{ __('messages.products.has_variants_desc') }}</span>
                        </div>
                        <input type="checkbox" wire:model="hasVariants" class="rounded border-amber-300 text-amber-600 focus:ring-amber-500 h-5 w-5">
                    </label>
                </div>

                <!-- Image Box -->
                <div class="bg-white dark:bg-zinc-900 p-5 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-3">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.products.product_photo_heading') }}</h3>
                    
                    @if ($image)
                        <div class="relative w-full h-40 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
                            <img src="{{ $image->temporaryUrl() }}" class="w-full h-full object-cover">
                            <span class="absolute top-2 right-2 px-2 py-0.5 text-[10px] font-bold bg-amber-600 text-white rounded">{{ __('messages.products.newly_uploaded_badge') }}</span>
                        </div>
                    @elseif ($existingImagePath)
                        <div class="relative w-full h-40 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
                            <img src="{{ asset('storage/' . $existingImagePath) }}" class="w-full h-full object-cover">
                        </div>
                    @else
                        <div class="w-full h-32 rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-700 flex flex-col items-center justify-center text-zinc-400 text-xs">
                            <svg class="w-8 h-8 mb-1 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span>{{ __('messages.products.no_photo_yet') }}</span>
                        </div>
                    @endif

                    <input type="file" wire:model="image" accept="image/*" class="text-xs text-zinc-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100 dark:file:bg-zinc-800 dark:file:text-zinc-300">
                    @error('image') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Konten & Bahasa -->
    <div class="{{ $activeTab === 'content' ? 'block' : 'hidden' }} space-y-6">
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-6">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.products.bilingual_heading') }}</h3>
                <p class="text-xs text-zinc-500 mt-0.5">{{ __('messages.products.bilingual_subheading') }}</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Indonesian -->
                <div class="space-y-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 text-xs font-bold rounded bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300">ID</span>
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">{{ __('messages.products.lang_id_heading') }}</h4>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1">{{ __('messages.products.name_id_label') }} <span class="text-rose-500">*</span></label>
                        <input type="text" wire:model="nameId" placeholder="{{ __('messages.products.name_id_placeholder') }}" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500">
                        @error('nameId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1">{{ __('messages.products.desc_id_label') }}</label>
                        <textarea wire:model="descriptionId" rows="4" placeholder="{{ __('messages.products.desc_id_placeholder') }}" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"></textarea>
                        @error('descriptionId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- English -->
                <div class="space-y-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 text-xs font-bold rounded bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300">EN</span>
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">{{ __('messages.products.lang_en_heading') }}</h4>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1">{{ __('messages.products.name_en_label') }}</label>
                        <input type="text" wire:model="nameEn" placeholder="{{ __('messages.products.name_en_placeholder') }}" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500">
                        @error('nameEn') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1">{{ __('messages.products.desc_en_label') }}</label>
                        <textarea wire:model="descriptionEn" rows="4" placeholder="{{ __('messages.products.desc_en_placeholder') }}" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"></textarea>
                        @error('descriptionEn') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 3: Varian Menu -->
    <div class="{{ $activeTab === 'variants' ? 'block' : 'hidden' }} space-y-6">
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.products.variants_heading') }}</h3>
                    <p class="text-xs text-zinc-500 mt-0.5">{{ __('messages.products.variants_subheading') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 cursor-pointer mr-4">
                        <input type="checkbox" wire:model="hasVariants" class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500">
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ __('messages.products.enable_variants_label') }}</span>
                    </label>
                    @if($hasVariants)
                        <button type="button" wire:click="addVariant" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950 dark:text-amber-300 transition">
                            {{ __('messages.products.add_variant_btn') }}
                        </button>
                    @endif
                </div>
            </div>

            @if(!$hasVariants)
                <div class="p-8 text-center border-2 border-dashed border-zinc-200 dark:border-zinc-800 rounded-lg">
                    <p class="text-sm text-zinc-500">{{ __('messages.products.no_variants_title') }}</p>
                    <p class="text-xs text-zinc-400 mt-1">{{ __('messages.products.no_variants_subtitle') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 dark:border-zinc-800 text-zinc-400 font-medium">
                            <tr>
                                <th class="pb-2 w-1/4">{{ __('messages.products.col_variant_name_id') }} <span class="text-rose-500">*</span></th>
                                <th class="pb-2 w-1/4">{{ __('messages.products.col_variant_name_en') }}</th>
                                <th class="pb-2 w-1/5">{{ __('messages.products.col_variant_price') }} <span class="text-rose-500">*</span></th>
                                <th class="pb-2 w-1/6">{{ __('messages.products.col_variant_sku') }}</th>
                                <th class="pb-2 text-center">{{ __('messages.products.col_variant_sold_out') }}</th>
                                <th class="pb-2 text-right">{{ __('messages.products.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($variants as $index => $v)
                                <tr>
                                    <td class="py-2.5 pr-2">
                                        <input type="text" wire:model="variants.{{ $index }}.name_id" placeholder="{{ __('messages.products.variant_name_id_placeholder') }}" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 text-xs">
                                        @error("variants.$index.name_id") <span class="text-[10px] text-rose-500 block">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="py-2.5 pr-2">
                                        <input type="text" wire:model="variants.{{ $index }}.name_en" placeholder="{{ __('messages.products.variant_name_en_placeholder') }}" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 text-xs">
                                    </td>
                                    <td class="py-2.5 pr-2">
                                        <input type="number" wire:model="variants.{{ $index }}.price" min="0" step="1" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 text-xs">
                                        @error("variants.$index.price") <span class="text-[10px] text-rose-500 block">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="py-2.5 pr-2">
                                        <input type="text" wire:model="variants.{{ $index }}.sku" placeholder="{{ __('messages.products.variant_sku_placeholder') }}" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 text-xs">
                                    </td>
                                    <td class="py-2.5 text-center">
                                        <input type="checkbox" wire:model="variants.{{ $index }}.is_sold_out" class="rounded border-zinc-300 text-rose-600 focus:ring-rose-500">
                                    </td>
                                    <td class="py-2.5 text-right">
                                        @if(count($variants) > 1)
                                            <button type="button" wire:click="removeVariant({{ $index }})" class="p-1 text-zinc-400 hover:text-rose-500 transition">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Tab 4: Grup Modifier -->
    <div class="{{ $activeTab === 'modifiers' ? 'block' : 'hidden' }} space-y-6">
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.products.linked_modifiers_heading') }}</h3>
                <p class="text-xs text-zinc-500 mt-0.5">{{ __('messages.products.linked_modifiers_subheading') }}</p>
            </div>

            @if($availableModifierGroups->isEmpty())
                <div class="p-6 text-center border-2 border-dashed border-zinc-200 dark:border-zinc-800 rounded-lg">
                    <p class="text-sm text-zinc-500">{{ __('messages.products.no_active_modifiers') }}</p>
                    <a href="{{ route('menu.modifiers') }}" class="mt-2 inline-block text-xs font-semibold text-amber-600 hover:underline">
                        {{ __('messages.products.create_modifier_first_link') }}
                    </a>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($availableModifierGroups as $group)
                        <label class="flex items-start gap-3 p-4 rounded-xl border {{ in_array($group->id, $selectedModifierGroupIds) ? 'border-amber-500 bg-amber-50/30 dark:bg-amber-950/20' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700' }} cursor-pointer transition">
                            <input type="checkbox" wire:model="selectedModifierGroupIds" value="{{ $group->id }}" class="mt-0.5 rounded border-zinc-300 text-amber-600 focus:ring-amber-500">
                            <div class="flex-1">
                                <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 block">{{ $group->name }}</span>
                                <span class="text-xs text-zinc-500 block mt-0.5">
                                    {{ $group->is_required ? __('messages.products.modifier_required_tag') : __('messages.products.modifier_optional_tag') }} • {{ __('messages.products.modifier_min_max_meta', ['min' => $group->min_selections, 'max' => $group->max_selections]) }}
                                </span>
                            </div>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <!-- Tab 5: Resep / Bill of Materials (BOM) -->
    <div class="{{ $activeTab === 'recipe' ? 'block' : 'hidden' }} space-y-6">
        <!-- Base Recipe Card -->
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $hasVariants ? __('messages.products.base_recipe_heading_variants') : __('messages.products.base_recipe_heading_single') }}
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        {{ __('messages.products.base_recipe_subheading') }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="addBaseRecipeItem"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    {{ __('messages.products.add_recipe_item_btn') }}
                </button>
            </div>

            @if(empty($baseRecipeItems))
                <div class="p-8 text-center border-2 border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                    <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-950/50 text-amber-600 flex items-center justify-center mx-auto mb-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('messages.products.empty_recipe_title') }}</p>
                    <p class="text-xs text-zinc-500 mt-0.5">{{ __('messages.products.empty_recipe_subtitle') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-zinc-900 dark:text-zinc-100">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-xs uppercase font-semibold text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                            <tr>
                                <th class="px-4 py-3">{{ __('messages.products.col_ingredient') }}</th>
                                <th class="px-4 py-3 w-44">{{ __('messages.products.col_portion_qty') }}</th>
                                <th class="px-4 py-3 w-36 text-right">{{ __('messages.products.col_estimated_cost') }}</th>
                                <th class="px-4 py-3 w-16 text-center">{{ __('messages.products.col_remove') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($baseRecipeItems as $bIdx => $bItem)
                                @php
                                    $selectedIng = $availableIngredients->firstWhere('id', $bItem['ingredient_id'] ?? null);
                                    $lineCost = $selectedIng ? ((float)($bItem['quantity'] ?? 0) * $selectedIng->cost_per_unit) : 0;
                                @endphp
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <select
                                            wire:model.live="baseRecipeItems.{{ $bIdx }}.ingredient_id"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 focus:ring-2 focus:ring-amber-500"
                                        >
                                            <option value="">{{ __('messages.products.select_ingredient_placeholder') }}</option>
                                            @foreach($availableIngredients as $ing)
                                                <option value="{{ $ing->id }}">
                                                    {{ $ing->name }} (Rp {{ number_format($ing->cost_per_unit, 0, ',', '.') }}/{{ $ing->unit }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-1.5">
                                            <input
                                                type="text"
                                                wire:model.live.debounce.300ms="baseRecipeItems.{{ $bIdx }}.quantity"
                                                placeholder="18.000"
                                                class="w-full px-3 py-2 text-sm font-mono rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 focus:ring-2 focus:ring-amber-500"
                                            />
                                            <span class="text-xs font-semibold text-zinc-500 shrink-0 w-8">
                                                {{ $selectedIng ? $selectedIng->unit : '-' }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        Rp {{ number_format($lineCost, 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <button
                                            type="button"
                                            wire:click="removeBaseRecipeItem({{ $bIdx }})"
                                            class="text-rose-500 hover:text-rose-700 p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/30 transition"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if($hasVariants && count($variants) > 0)
            <!-- Variant-Specific Recipe Overrides -->
            <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.products.variant_recipe_heading') }}
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        {{ __('messages.products.variant_recipe_subheading') }}
                    </p>
                </div>

                <div class="space-y-4">
                    @foreach($variants as $vIdx => $vData)
                        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                                    <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">
                                        {{ __('messages.products.variant_title_prefix', ['name' => !empty($vData['name_id']) ? $vData['name_id'] : __('messages.products.variant_fallback_name', ['number' => $vIdx + 1])]) }}
                                    </h4>
                                    <span class="text-xs text-zinc-500">
                                        ({{ !empty($variantRecipeItems[$vIdx]) ? __('messages.products.variant_custom_ingredients_count', ['count' => count($variantRecipeItems[$vIdx])]) : __('messages.products.variant_follows_base_recipe') }})
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    wire:click="addVariantRecipeItem({{ $vIdx }})"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 hover:bg-zinc-50 text-zinc-700 dark:text-zinc-300 transition"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    {{ __('messages.products.add_variant_ingredient_btn') }}
                                </button>
                            </div>

                            @if(!empty($variantRecipeItems[$vIdx]))
                                <div class="space-y-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                                    @foreach($variantRecipeItems[$vIdx] as $viIdx => $viItem)
                                        @php
                                            $vSelectedIng = $availableIngredients->firstWhere('id', $viItem['ingredient_id'] ?? null);
                                        @endphp
                                        <div class="flex items-center gap-3">
                                            <select
                                                wire:model.live="variantRecipeItems.{{ $vIdx }}.{{ $viIdx }}.ingredient_id"
                                                class="flex-1 px-3 py-1.5 text-xs rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800"
                                            >
                                                <option value="">{{ __('messages.products.select_ingredient_placeholder') }}</option>
                                                @foreach($availableIngredients as $ing)
                                                    <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                                                @endforeach
                                            </select>
                                            <div class="flex items-center gap-1 w-36">
                                                <input
                                                    type="text"
                                                    wire:model.live.debounce.300ms="variantRecipeItems.{{ $vIdx }}.{{ $viIdx }}.quantity"
                                                    placeholder="{{ __('messages.products.portion_placeholder') }}"
                                                    class="w-full px-2.5 py-1.5 text-xs font-mono rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800"
                                                />
                                                <span class="text-xs text-zinc-500 font-mono w-6 shrink-0">
                                                    {{ $vSelectedIng ? $vSelectedIng->unit : '-' }}
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                wire:click="removeVariantRecipeItem({{ $vIdx }}, {{ $viIdx }})"
                                                class="text-rose-500 hover:text-rose-700 p-1"
                                            >
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
