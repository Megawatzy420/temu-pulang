<div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6">
    <div>
        <h2 class="text-xl font-bold text-coffee-950 tracking-tight">{{ __('messages.stock_history.title') }}</h2>
        <p class="text-xs text-coffee-600 mt-0.5">{{ __('messages.stock_history.subtitle') }}</p>
    </div>

    <!-- Filters Card -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 p-5 shadow-sm space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <!-- Ingredient Filter + Search -->
            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    {{ __('messages.stock_history.filter_ingredient') }}
                </label>
                <input
                    type="text"
                    wire:model.live.debounce.250ms="ingredientSearch"
                    placeholder="{{ __('messages.stock_history.filter_ingredient_search') }}"
                    class="w-full px-3 py-1.5 rounded-xl border border-coffee-200 text-xs text-coffee-950 bg-cream-50/50 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                />
                <select
                    wire:model.live="ingredientId"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                >
                    <option value="">{{ __('messages.stock_history.filter_ingredient_all') }}</option>
                    @foreach ($ingredientsList as $ing)
                        <option value="{{ $ing->id }}">
                            {{ $ing->name }} ({{ $ing->unit }})@if ($ing->deleted_at) — {{ __('messages.stock_history.deleted_tag') }}@endif
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Movement Type Filter -->
            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    {{ __('messages.stock_history.filter_type') }}
                </label>
                <select
                    wire:model.live="movementType"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                >
                    <option value="all">{{ __('messages.stock_history.filter_type_all') }}</option>
                    @foreach ($movementTypes as $type)
                        <option value="{{ $type }}">{{ __('messages.stock_history.type_'.$type) }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Date From -->
            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    {{ __('messages.stock_history.filter_date_from') }}
                </label>
                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                />
            </div>

            <!-- Date To -->
            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    {{ __('messages.stock_history.filter_date_to') }}
                </label>
                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                />
            </div>

            <!-- Actor Filter + Reset -->
            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    {{ __('messages.stock_history.filter_actor') }}
                </label>
                <select
                    wire:model.live="actorId"
                    class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-950 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
                >
                    <option value="all">{{ __('messages.stock_history.filter_actor_all') }}</option>
                    @foreach ($actorsList as $actor)
                        <option value="{{ $actor->id }}">
                            {{ $actor->name }}@if ($actor->deleted_at) ({{ __('messages.stock_history.deleted_tag') }})@endif
                        </option>
                    @endforeach
                </select>
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="text-[11px] font-bold text-caramel-700 hover:text-caramel-900 underline underline-offset-2 cursor-pointer"
                >
                    {{ __('messages.stock_history.reset_filters') }}
                </button>
            </div>
        </div>
    </div>

    <!-- Single-Ingredient Summary -->
    @if ($summary !== null)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-xs">
                <p class="font-bold uppercase tracking-wider text-emerald-700">{{ __('messages.stock_history.summary_total_in') }}</p>
                <p class="text-lg font-extrabold text-emerald-900 mt-1">+{{ $summary['total_in'] }} {{ $summary['unit'] }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-xs">
                <p class="font-bold uppercase tracking-wider text-rose-700">{{ __('messages.stock_history.summary_total_out') }}</p>
                <p class="text-lg font-extrabold text-rose-900 mt-1">-{{ $summary['total_out'] }} {{ $summary['unit'] }}</p>
            </div>
            <div class="p-4 rounded-2xl bg-cream-50 border border-coffee-200 text-xs">
                <p class="font-bold uppercase tracking-wider text-coffee-700">{{ __('messages.stock_history.summary_net') }}</p>
                <p class="text-lg font-extrabold text-coffee-950 mt-1">{{ $summary['net'] }} {{ $summary['unit'] }}</p>
            </div>
        </div>
    @endif

    <!-- Movements Table -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-coffee-950">
                <thead class="bg-cream-100/70 border-b border-coffee-200/80 text-[11px] font-bold uppercase tracking-wider text-coffee-700">
                    <tr>
                        <th class="px-4 py-3">{{ __('messages.stock_history.col_datetime') }}</th>
                        <th class="px-4 py-3">{{ __('messages.stock_history.col_ingredient') }}</th>
                        <th class="px-4 py-3">{{ __('messages.stock_history.col_type') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('messages.stock_history.col_quantity') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('messages.stock_history.col_balance') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('messages.stock_history.col_unit_cost') }}</th>
                        <th class="px-4 py-3">{{ __('messages.stock_history.col_actor') }}</th>
                        <th class="px-4 py-3">{{ __('messages.stock_history.col_reference') }}</th>
                        <th class="px-4 py-3">{{ __('messages.stock_history.col_notes') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-coffee-100">
                    @forelse ($movements as $mov)
                        @php
                            $qtyMilli = $mov->quantity_milli;
                            $qtyFormatted = \App\Models\Ingredient::fromMilli($qtyMilli);
                            $qtyDisplay = ($qtyMilli > 0 ? '+' : '').$qtyFormatted;
                            $beforeFormatted = \App\Models\Ingredient::fromMilli($mov->balance_before_milli);
                            $afterFormatted = \App\Models\Ingredient::fromMilli($mov->balance_after_milli);
                            $unit = $mov->ingredient?->unit ?? '';
                            $badgeClass = match ($mov->movement_type) {
                                'stock_in', 'opening_stock', 'refund_credit' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                'wastage', 'order_deduction' => 'bg-rose-100 text-rose-800 border-rose-200',
                                default => 'bg-amber-100 text-amber-800 border-amber-200',
                            };
                        @endphp
                        <tr class="hover:bg-cream-50/50 transition-colors">
                            <td class="px-4 py-3 whitespace-nowrap font-mono text-[11px] text-coffee-600">
                                {{ $mov->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') }}
                            </td>
                            <td class="px-4 py-3 font-bold text-coffee-950">
                                @if ($mov->ingredient)
                                    <span>{{ $mov->ingredient->name }}</span>
                                    <span class="text-coffee-500 font-normal">({{ $mov->ingredient->unit }})</span>
                                    @if ($mov->ingredient->deleted_at)
                                        <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-coffee-100 text-coffee-600">
                                            {{ __('messages.stock_history.deleted_tag') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-coffee-400 italic">{{ __('messages.stock_history.ref_unavailable') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border {{ $badgeClass }}">
                                    {{ __('messages.stock_history.type_'.$mov->movement_type) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-bold whitespace-nowrap {{ $qtyMilli >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $qtyDisplay }} {{ $unit }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-[11px] text-coffee-700 whitespace-nowrap">
                                {{ $beforeFormatted }} → <strong>{{ $afterFormatted }}</strong>
                            </td>
                            <td class="px-4 py-3 text-right font-mono whitespace-nowrap text-coffee-800">
                                Rp {{ number_format($mov->unit_cost, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($mov->user)
                                    <span>{{ $mov->user->name }}</span>
                                    @if ($mov->user->deleted_at)
                                        <span class="ml-1 text-[10px] text-coffee-500">({{ __('messages.stock_history.deleted_tag') }})</span>
                                    @endif
                                @else
                                    <span class="text-coffee-400 italic">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($mov->reference_type === 'orders' && $mov->reference_id)
                                    @if ($ordersById->has($mov->reference_id))
                                        <span class="font-mono font-semibold text-coffee-900">
                                            {{ __('messages.stock_history.ref_order', ['number' => $ordersById->get($mov->reference_id)]) }}
                                        </span>
                                    @else
                                        <span class="text-coffee-400 italic">{{ __('messages.stock_history.ref_unavailable') }}</span>
                                    @endif
                                @elseif ($mov->reference_type)
                                    <span class="text-coffee-400 italic">{{ __('messages.stock_history.ref_unavailable') }}</span>
                                @else
                                    <span class="text-coffee-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-coffee-700 max-w-xs truncate">
                                {{ $mov->notes ?: '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center">
                                <p class="text-sm font-bold text-coffee-900">{{ __('messages.stock_history.empty_title') }}</p>
                                <p class="text-xs text-coffee-500 mt-1">{{ __('messages.stock_history.empty_desc') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($movements->hasPages())
            <div class="p-4 border-t border-coffee-100">
                {{ $movements->links() }}
            </div>
        @endif
    </div>
</div>
