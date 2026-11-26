<div class="p-4 sm:p-6 lg:p-8 max-w-6xl mx-auto space-y-6">
    <div>
        <h2 class="text-xl font-bold text-coffee-950 tracking-tight">{{ __('messages.stock.title') }}</h2>
        <p class="text-xs text-coffee-600 mt-0.5">{{ __('messages.stock.subtitle') }}</p>
    </div>

    <!-- Action Tabs -->
    <div class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-coffee-200/60">
        <button
            type="button"
            wire:click="switchTab('stock_in')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'stock_in' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'bg-white text-coffee-700 border border-coffee-200 hover:bg-cream-100' }}"
        >
            {{ __('messages.stock.tab_stock_in') }}
        </button>
        <button
            type="button"
            wire:click="switchTab('adjustment')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'adjustment' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'bg-white text-coffee-700 border border-coffee-200 hover:bg-cream-100' }}"
        >
            {{ __('messages.stock.tab_adjustment') }}
        </button>
        <button
            type="button"
            wire:click="switchTab('wastage')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap {{ $activeTab === 'wastage' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'bg-white text-coffee-700 border border-coffee-200 hover:bg-cream-100' }}"
        >
            {{ __('messages.stock.tab_wastage') }}
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left: Ingredient Picker -->
        <div class="lg:col-span-5 bg-white rounded-3xl border border-coffee-200/80 p-5 shadow-sm space-y-4">
            <h3 class="text-sm font-bold text-coffee-950">{{ __('messages.stock.select_ingredient') }}</h3>

            <input
                type="text"
                wire:model.live.debounce.250ms="ingredientSearch"
                placeholder="{{ __('messages.stock.search_ingredient') }}"
                class="w-full px-3.5 py-2 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs font-medium text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white"
            />

            <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
                @foreach ($ingredients as $ing)
                    @php
                        $ingBalance = $balances[$ing->id] ?? '0.000';
                        $isSelected = $selectedIngredientId === $ing->id;
                    @endphp
                    <button
                        type="button"
                        wire:click="selectIngredient({{ $ing->id }})"
                        class="w-full text-left p-3 rounded-2xl border transition-all cursor-pointer flex items-center justify-between gap-3 {{ $isSelected ? 'bg-coffee-900 text-cream-50 border-coffee-950 shadow-xs' : 'bg-cream-50/50 border-coffee-200/70 text-coffee-900 hover:bg-cream-100' }}"
                    >
                        <div class="min-w-0">
                            <p class="text-xs font-bold truncate">{{ $ing->name }}</p>
                            <p class="text-[11px] {{ $isSelected ? 'text-coffee-200' : 'text-coffee-500' }}">{{ $ing->sku }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="text-xs font-extrabold">{{ $ingBalance }}</span>
                            <span class="text-[11px] {{ $isSelected ? 'text-coffee-200' : 'text-coffee-500' }}">{{ $ing->unit }}</span>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Right: Form & Result -->
        <div class="lg:col-span-7 space-y-4">
            @if ($lastResult)
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 space-y-1.5 text-xs text-emerald-900">
                    <p class="text-sm font-bold">{{ __('messages.stock.result_title') }}</p>
                    <p>{{ __('messages.stock.result_ingredient') }}: <strong>{{ $lastResult['ingredient'] }}</strong></p>
                    <p>{{ __('messages.stock.result_quantity') }}: <strong>{{ $lastResult['quantity'] }} {{ $lastResult['unit'] }}</strong></p>
                    <p>
                        {{ __('messages.stock.result_balance_before') }}: <strong>{{ $lastResult['balance_before'] }} {{ $lastResult['unit'] }}</strong>
                        →
                        {{ __('messages.stock.result_balance_after') }}: <strong>{{ $lastResult['balance_after'] }} {{ $lastResult['unit'] }}</strong>
                    </p>
                </div>
            @endif

            @if ($infoMessage)
                <div class="p-4 rounded-2xl bg-blue-50 border border-blue-200 text-xs font-semibold text-blue-900">
                    {{ $infoMessage }}
                </div>
            @endif

            @if ($errorMessage)
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-xs font-semibold text-rose-900">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-5">
                @if (! $selectedIngredient)
                    <p class="text-xs text-coffee-500 italic py-8 text-center">{{ __('messages.stock.no_ingredient_selected') }}</p>
                @else
                    <!-- Selected Ingredient Header -->
                    <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-coffee-100">
                        <div>
                            <h3 class="text-base font-bold text-coffee-950">{{ $selectedIngredient->name }}</h3>
                            <p class="text-xs text-coffee-500">{{ $selectedIngredient->sku }}</p>
                        </div>
                        <div class="px-3.5 py-2 rounded-xl bg-cream-50 border border-coffee-200 text-xs">
                            <span class="text-coffee-600">{{ __('messages.stock.current_stock') }}:</span>
                            <strong class="text-coffee-950 ml-1">{{ $selectedStock }} {{ $selectedIngredient->unit }}</strong>
                        </div>
                    </div>

                    @if ($activeTab === 'adjustment')
                        <div>
                            <label class="block text-xs font-bold text-coffee-800 mb-1">
                                {{ __('messages.stock.adjustment_mode_label') }}
                            </label>
                            <select
                                wire:model="adjustmentMode"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            >
                                <option value="counted">{{ __('messages.stock.mode_counted') }}</option>
                                <option value="difference">{{ __('messages.stock.mode_difference') }}</option>
                            </select>
                        </div>
                    @endif

                    <!-- Quantity Input with Base Unit -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.stock.quantity_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                inputmode="decimal"
                                wire:model="quantity"
                                placeholder="{{ __('messages.stock.quantity_placeholder') }}"
                                class="flex-1 px-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 bg-cream-50/50 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                            />
                            <span class="px-3 py-2.5 rounded-xl bg-cream-100 border border-coffee-200 text-xs font-bold text-coffee-800 shrink-0">
                                {{ $selectedIngredient->unit }}
                            </span>
                        </div>
                        <p class="text-[11px] text-coffee-500 mt-1">{{ __('messages.stock.quantity_hint') }}</p>
                    </div>

                    <!-- Unit Cost Input for Stock In -->
                    @if ($activeTab === 'stock_in')
                        <div
                            x-data="{
                                cost: $wire.entangle('unitCost').live,
                                format(val) {
                                    const n = parseInt(val || 0, 10);
                                    return n > 0 ? n.toLocaleString('id-ID') : '';
                                },
                                onInput(e) {
                                    const digits = e.target.value.replace(/\D/g, '').slice(0, 10);
                                    const num = digits === '' ? 0 : parseInt(digits, 10);
                                    this.cost = num;
                                    e.target.value = num > 0 ? num.toLocaleString('id-ID') : '';
                                }
                            }"
                        >
                            <label class="block text-xs font-bold text-coffee-800 mb-1">
                                {{ __('messages.stock.unit_cost_label') }}
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-xs font-bold text-coffee-600 pointer-events-none">
                                    Rp
                                </span>
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="13"
                                    :value="format(cost)"
                                    @input="onInput($event)"
                                    placeholder="0"
                                    class="w-full pl-10 pr-3.5 py-2.5 rounded-xl border border-coffee-200 text-sm font-bold text-coffee-950 bg-cream-50/50 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                                />
                            </div>
                        </div>
                    @endif

                    <!-- Notes -->
                    <div>
                        <label class="block text-xs font-bold text-coffee-800 mb-1">
                            {{ __('messages.stock.notes_label') }}
                            @if ($activeTab !== 'stock_in')
                                <span class="text-rose-500">*</span>
                            @endif
                        </label>
                        <textarea
                            wire:model="notes"
                            rows="2"
                            placeholder="{{ $activeTab === 'stock_in' ? __('messages.stock.notes_stock_in_placeholder') : __('messages.stock.notes_required_placeholder') }}"
                            class="w-full p-3 rounded-xl border border-coffee-200 text-xs font-medium text-coffee-950 bg-cream-50/50 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white"
                        ></textarea>
                    </div>

                    <div class="flex justify-end">
                        @if ($activeTab === 'stock_in')
                            <x-button
                                type="button"
                                variant="primary"
                                size="md"
                                wire:click="submitStockIn"
                                wire:loading.attr="disabled"
                                class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
                            >
                                {{ __('messages.stock.submit_stock_in') }}
                            </x-button>
                        @elseif ($activeTab === 'adjustment')
                            <x-button
                                type="button"
                                variant="primary"
                                size="md"
                                wire:click="submitAdjustment"
                                wire:loading.attr="disabled"
                                class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
                            >
                                {{ __('messages.stock.submit_adjustment') }}
                            </x-button>
                        @else
                            <x-button
                                type="button"
                                variant="primary"
                                size="md"
                                wire:click="submitWastage"
                                wire:loading.attr="disabled"
                                class="bg-rose-700 hover:bg-rose-800 text-white cursor-pointer"
                            >
                                {{ __('messages.stock.submit_wastage') }}
                            </x-button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
