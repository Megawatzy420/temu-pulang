<div class="space-y-6" wire:poll.10s>
    <!-- Header with Quick Stats -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-3">
                <span>{{ __('messages.orders.heading') }}</span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                    {{ __('messages.orders.live_polling_badge') }}
                </span>
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('messages.orders.subheading') }}
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('pos') }}" class="px-4 py-2 text-sm font-semibold rounded-lg bg-amber-600 hover:bg-amber-500 text-white shadow-sm flex items-center gap-2 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>{{ __('messages.orders.open_pos_btn') }}</span>
            </a>
        </div>
    </div>

    <!-- Quick Status Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">{{ __('messages.orders.stat_awaiting_payment') }}</span>
                <span class="text-2xl font-bold text-amber-600 dark:text-amber-500">{{ $stats['awaiting_payment'] }}</span>
            </div>
            <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-950/40 flex items-center justify-center text-amber-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>

        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">{{ __('messages.orders.stat_queued') }}</span>
                <span class="text-2xl font-bold text-blue-600 dark:text-blue-500">{{ $stats['queued'] }}</span>
            </div>
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center text-blue-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
            </div>
        </div>

        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">{{ __('messages.orders.stat_preparing') }}</span>
                <span class="text-2xl font-bold text-indigo-600 dark:text-indigo-500">{{ $stats['preparing'] }}</span>
            </div>
            <div class="w-10 h-10 rounded-full bg-indigo-50 dark:bg-indigo-950/40 flex items-center justify-center text-indigo-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
            </div>
        </div>

        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">{{ __('messages.orders.stat_ready') }}</span>
                <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-500">{{ $stats['ready'] }}</span>
            </div>
            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-950/40 flex items-center justify-center text-emerald-600">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Filters & Navigation Tabs -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-3">
        <!-- Tabs -->
        <div class="flex flex-wrap gap-2">
            <button wire:click="setTab('active')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'active' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_active', ['count' => $stats['active_total']]) }}
            </button>
            <button wire:click="setTab('awaiting_payment')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'awaiting_payment' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_awaiting_payment') }}
            </button>
            <button wire:click="setTab('queued')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'queued' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_queued') }}
            </button>
            <button wire:click="setTab('preparing')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'preparing' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_preparing') }}
            </button>
            <button wire:click="setTab('ready')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'ready' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_ready') }}
            </button>
            <button wire:click="setTab('completed')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'completed' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_completed') }}
            </button>
            <button wire:click="setTab('cancelled')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'cancelled' ? 'bg-amber-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_cancelled') }}
            </button>
            <button wire:click="setTab('all')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $currentTab === 'all' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200' }}">
                {{ __('messages.orders.tab_all') }}
            </button>
        </div>

        <!-- Search & Dining Type Filter -->
        <div class="flex items-center gap-3">
            <select wire:model.live="orderTypeFilter" class="px-3 py-1.5 text-xs rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100">
                <option value="all">{{ __('messages.orders.filter_all_types') }}</option>
                <option value="dine_in">{{ __('messages.orders.type_dine_in') }}</option>
                <option value="takeaway">{{ __('messages.orders.type_takeaway') }}</option>
            </select>

            <div class="relative w-48 sm:w-64">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('messages.orders.search_placeholder') }}" class="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100">
                <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center text-zinc-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
            </div>
        </div>
    </div>

    <!-- Orders Grid / Board -->
    @if($orders->isEmpty())
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-12 text-center">
            <svg class="w-12 h-12 mx-auto text-zinc-400 opacity-60 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
            </svg>
            <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('messages.orders.empty_title') }}</p>
            <p class="text-xs text-zinc-400 mt-1">{{ __('messages.orders.empty_subtitle') }}</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($orders as $order)
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs flex flex-col justify-between overflow-hidden">
                    <!-- Order Card Header -->
                    <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="text-base font-extrabold text-amber-600 dark:text-amber-500 font-mono">
                                {{ $order->call_number }}
                            </span>
                            <span class="text-[11px] font-mono text-zinc-400">
                                #{{ $order->order_number }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {{ $order->order_type === 'dine_in' ? 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300' : 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300' }}">
                                {{ $order->order_type === 'dine_in' ? __('messages.orders.type_dine_in') : __('messages.orders.type_takeaway') }}
                            </span>
                            
                            <!-- Status Badge -->
                            @if($order->status === 'awaiting_payment')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ __('messages.orders.badge_awaiting_payment') }}</span>
                            @elseif($order->status === 'paid')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300">{{ __('messages.orders.badge_paid') }}</span>
                            @elseif($order->status === 'queued')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300">{{ __('messages.orders.badge_queued') }}</span>
                            @elseif($order->status === 'preparing')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">{{ __('messages.orders.badge_preparing') }}</span>
                            @elseif($order->status === 'ready')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ __('messages.orders.badge_ready') }}</span>
                            @elseif($order->status === 'completed')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300">{{ __('messages.orders.badge_completed') }}</span>
                            @elseif($order->status === 'cancelled')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300">{{ __('messages.orders.badge_cancelled') }}</span>
                            @endif
                        </div>
                    </div>

                    <!-- Customer & Time Meta -->
                    <div class="px-4 py-2 border-b border-zinc-100 dark:border-zinc-800/60 bg-zinc-50/20 text-xs flex items-center justify-between text-zinc-500">
                        <span>{{ __('messages.orders.customer_label') }} <strong class="text-zinc-700 dark:text-zinc-300">{{ $order->customer_name ?: __('messages.orders.walk_in_customer') }}</strong></span>
                        <span>{{ $order->created_at->format('H:i') }} WIB</span>
                    </div>

                    <!-- Line Items Summary -->
                    <div class="p-4 flex-1 space-y-2 text-xs">
                        @foreach($order->items as $item)
                            <div class="flex items-start justify-between">
                                <div class="flex-1 pr-2">
                                    <div class="font-medium text-zinc-800 dark:text-zinc-200">
                                        <span class="font-bold text-amber-600 mr-1">{{ $item->quantity }}x</span>
                                        {{ $item->product_name }}
                                        @if($item->variant_name)
                                            <span class="text-zinc-400">({{ $item->variant_name }})</span>
                                        @endif
                                    </div>
                                    @if($item->modifiers->isNotEmpty())
                                        <div class="text-[11px] text-zinc-400 pl-4">
                                            + {{ $item->modifiers->map(fn($m) => $m->modifier_name)->implode(', ') }}
                                        </div>
                                    @endif
                                    @if($item->notes)
                                        <div class="text-[11px] italic text-amber-700 dark:text-amber-400 pl-4">
                                            {{ __('messages.orders.item_notes_prefix', ['notes' => $item->notes]) }}
                                        </div>
                                    @endif
                                </div>
                                <span class="font-semibold text-zinc-700 dark:text-zinc-300 shrink-0">
                                    Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <!-- Total & Actions Footer -->
                    <div class="p-4 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/40 dark:bg-zinc-800/20 space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-xs text-zinc-500">{{ __('messages.orders.total_bill_label') }}</span>
                            <span class="font-bold text-base text-zinc-900 dark:text-zinc-100">
                                Rp {{ number_format($order->total_amount, 0, ',', '.') }}
                            </span>
                        </div>

                        <!-- Action Buttons based on Status -->
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="openDetailModal({{ $order->id }})" class="px-2.5 py-1.5 text-xs font-medium rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                {{ __('messages.orders.detail_btn') }}
                            </button>

                            @if(auth()->user()?->isOwner() && in_array($order->payment_status, [\App\Models\Order::PAYMENT_STATUS_PAID, \App\Models\Order::PAYMENT_STATUS_PARTIALLY_REFUNDED], true))
                                <a href="{{ route('orders.refund', $order) }}" class="px-2.5 py-1.5 text-xs font-semibold rounded-lg border border-amber-300 text-amber-800 hover:bg-amber-50 transition">
                                    {{ __('messages.refund.action_btn') }}
                                </a>
                            @endif

                            <!-- Advance Status Buttons -->
                            @if($order->status === 'awaiting_payment')
                                <button type="button" wire:click="advanceStatus({{ $order->id }}, 'paid')" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white transition">
                                    {{ __('messages.orders.advance_to_paid_btn') }}
                                </button>
                            @elseif($order->status === 'paid')
                                <button type="button" wire:click="advanceStatus({{ $order->id }}, 'queued')" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-500 text-white transition">
                                    {{ __('messages.orders.advance_to_queued_btn') }}
                                </button>
                            @elseif($order->status === 'queued')
                                <button type="button" wire:click="advanceStatus({{ $order->id }}, 'preparing')" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white transition">
                                    {{ __('messages.orders.advance_to_preparing_btn') }}
                                </button>
                            @elseif($order->status === 'preparing')
                                <button type="button" wire:click="advanceStatus({{ $order->id }}, 'ready')" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white transition">
                                    {{ __('messages.orders.advance_to_ready_btn') }}
                                </button>
                            @elseif($order->status === 'ready')
                                <button type="button" wire:click="advanceStatus({{ $order->id }}, 'completed')" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-zinc-800 hover:bg-zinc-700 dark:bg-zinc-200 dark:text-zinc-900 text-white transition">
                                    {{ __('messages.orders.advance_to_completed_btn') }}
                                </button>
                            @endif

                            <!-- Owner Cancel Button -->
                            @if(auth()->user()->isOwner() && !in_array($order->status, ['completed', 'cancelled']))
                                <button type="button" wire:click="openCancelModal({{ $order->id }})" class="p-1.5 text-zinc-400 hover:text-rose-500 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/30 transition" title="{{ __('messages.orders.cancel_order_title') }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $orders->links() }}
        </div>
    @endif

    <!-- Cancel Modal (Owner Only) -->
    @if($showCancelModal)
        <div class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="bg-white dark:bg-zinc-900 w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-2xl p-6 space-y-4">
                <div class="flex items-center gap-3 text-rose-600">
                    <div class="w-10 h-10 rounded-full bg-rose-50 dark:bg-rose-950/40 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.orders.cancel_order_title') }}</h3>
                        <p class="text-xs text-zinc-500">{{ __('messages.orders.cancel_modal_subtitle') }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('messages.orders.cancel_reason_label') }} <span class="text-rose-500">*</span>
                    </label>
                    <textarea wire:model="cancellationReason" rows="3" placeholder="{{ __('messages.orders.cancel_reason_placeholder') }}" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100"></textarea>
                    <p class="text-[11px] text-zinc-400 mt-1">{{ __('messages.orders.cancel_reason_hint') }}</p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" wire:click="$set('showCancelModal', false)" class="px-4 py-2 text-sm font-medium rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100">
                        {{ __('messages.orders.cancel_btn') }}
                    </button>
                    <button type="button" wire:click="confirmCancel" class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 hover:bg-rose-500 text-white">
                        {{ __('messages.orders.confirm_cancel_btn') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Detail Modal -->
    @if($showDetailModal && $selectedOrder)
        <div class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="bg-white dark:bg-zinc-900 w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-zinc-100">{{ __('messages.orders.detail_modal_title', ['number' => $selectedOrder->call_number]) }}</h3>
                            <span class="text-xs font-mono text-zinc-400">#{{ $selectedOrder->order_number }}</span>
                        </div>
                        <p class="text-xs text-zinc-500">{{ __('messages.orders.detail_created_meta', ['datetime' => $selectedOrder->created_at->translatedFormat('d M Y, H:i:s'), 'user' => $selectedOrder->user->name ?? __('messages.orders.cashier_fallback')]) }}</p>
                    </div>
                    <button type="button" wire:click="$set('showDetailModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Line Items Breakdown -->
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('messages.orders.items_breakdown_heading') }}</h4>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($selectedOrder->items as $item)
                            <div class="py-2 flex items-start justify-between text-xs">
                                <div>
                                    <div class="font-medium text-zinc-800 dark:text-zinc-200">
                                        <span class="font-bold text-amber-600 mr-1">{{ $item->quantity }}x</span>
                                        {{ $item->product_name }}
                                        @if($item->variant_name)
                                            <span class="text-zinc-400">({{ $item->variant_name }})</span>
                                        @endif
                                    </div>
                                    @if($item->modifiers->isNotEmpty())
                                        <div class="text-[11px] text-zinc-400 pl-4">
                                            @foreach($item->modifiers as $mod)
                                                <div>+ {{ $mod->modifier_name }} (Rp {{ number_format($mod->unit_price, 0, ',', '.') }})</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                    Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Financial Calculation Summary -->
                <div class="pt-3 border-t border-zinc-100 dark:border-zinc-800 space-y-1.5 text-xs">
                    <div class="flex justify-between text-zinc-500">
                        <span>{{ __('messages.orders.subtotal_label') }}</span>
                        <span>Rp {{ number_format($selectedOrder->subtotal, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-zinc-500">
                        <span>{{ __('messages.orders.tax_label') }}</span>
                        <span>Rp {{ number_format($selectedOrder->tax_amount, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-zinc-500">
                        <span>{{ __('messages.orders.service_charge_label') }}</span>
                        <span>Rp {{ number_format($selectedOrder->service_charge_amount, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-sm font-bold text-zinc-900 dark:text-zinc-100 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <span>{{ __('messages.orders.total_label') }}</span>
                        <span>Rp {{ number_format($selectedOrder->total_amount, 0, ',', '.') }}</span>
                    </div>
                </div>

                <!-- Status Transition History -->
                <div class="pt-3 border-t border-zinc-100 dark:border-zinc-800 space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('messages.orders.status_history_heading') }}</h4>
                    <div class="space-y-2 text-xs">
                        @foreach($selectedOrder->statusHistories as $hist)
                            <div class="p-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">
                                        {{ $hist->from_status ? "{$hist->from_status} &rarr; " : '' }}{{ $hist->to_status }}
                                    </span>
                                    <span class="text-zinc-400">{{ $hist->created_at->format('H:i:s') }}</span>
                                </div>
                                <p class="text-[11px] text-zinc-500 mt-0.5">{{ $hist->notes }} {{ __('messages.orders.history_by_user', ['user' => $hist->user->name ?? __('messages.orders.system_fallback')]) }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
