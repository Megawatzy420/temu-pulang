<div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl mx-auto">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-4 border-b border-coffee-200/80">
        <div>
            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">{{ __('messages.printing.title') }}</h2>
            <p class="text-xs text-coffee-600">{{ __('messages.printing.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-badge variant="neutral" size="sm">
                {{ $jobs->total() }} {{ __('messages.printing.title') }}
            </x-badge>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 p-4 shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div class="w-full sm:w-72">
            <label class="block text-xs font-semibold text-coffee-700 mb-1">{{ __('messages.printing.status') }}</label>
            <select
                wire:model.live="statusFilter"
                class="w-full px-3 py-2 rounded-xl border border-coffee-200 text-xs font-semibold text-coffee-900 bg-white focus:outline-none focus:ring-2 focus:ring-coffee-400"
            >
                <option value="all">{{ __('messages.printing.filter_all') }}</option>
                <option value="failed">{{ __('messages.printing.filter_failed') }}</option>
                <option value="uncertain">{{ __('messages.printing.filter_uncertain') }}</option>
            </select>
        </div>
    </div>

    <!-- Jobs Table -->
    <div class="bg-white rounded-2xl border border-coffee-200/80 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-coffee-800">
                <thead class="bg-cream-50/80 text-coffee-900 font-bold uppercase tracking-wider text-[11px] border-b border-coffee-200/80">
                    <tr>
                        <th class="px-4 py-3.5">{{ __('messages.printing.order_number') }}</th>
                        <th class="px-4 py-3.5">{{ __('messages.printing.job_type') }}</th>
                        <th class="px-4 py-3.5">{{ __('messages.printing.status') }}</th>
                        <th class="px-4 py-3.5">{{ __('messages.printing.last_error') }}</th>
                        <th class="px-4 py-3.5">{{ __('messages.printing.created_at') }}</th>
                        <th class="px-4 py-3.5 text-right">{{ __('messages.printing.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-coffee-100">
                    @forelse ($jobs as $job)
                        <tr class="hover:bg-cream-50/40 transition-colors">
                            <td class="px-4 py-3.5 font-mono font-bold text-coffee-950">
                                @if ($job->order)
                                    #{{ $job->order->order_number }}
                                @else
                                    #{{ $job->order_id }}
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="font-medium">
                                    {{ in_array($job->job_type, ['receipt', 'customer_receipt'], true) ? __('messages.printing.job_type_receipt') : __('messages.printing.job_type_kitchen') }}
                                </span>
                                @if ($job->is_reprint)
                                    <span class="ml-1 text-[10px] text-coffee-500 font-semibold">{{ __('messages.printing.reprint_tag') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                @if ($job->status === 'uncertain')
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ __('messages.printing.status_uncertain') }}
                                    </span>
                                @elseif ($job->status === 'failed')
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        {{ __('messages.printing.status_failed') }}
                                    </span>
                                @else
                                    @php
                                        $printStatusLabel = match ($job->status) {
                                            'pending' => __('messages.printing.status_pending'),
                                            'processing' => __('messages.printing.status_processing'),
                                            'completed' => __('messages.printing.status_completed'),
                                            default => $job->status,
                                        };
                                    @endphp
                                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-gray-100 text-gray-800">
                                        {{ $printStatusLabel }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-coffee-600 max-w-xs truncate" title="{{ $job->last_error }}">
                                {{ $job->last_error ?: '-' }}
                            </td>
                            <td class="px-4 py-3.5 text-coffee-500 whitespace-nowrap">
                                {{ $job->created_at?->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                @if ($job->status === 'uncertain')
                                    <button
                                        type="button"
                                        wire:click="openResolveModal({{ $job->id }})"
                                        class="px-3 py-1.5 rounded-lg bg-purple-700 hover:bg-purple-800 text-white font-bold text-xs shadow-2xs transition-colors cursor-pointer"
                                    >
                                        {{ __('messages.printing.resolve_btn') }}
                                    </button>
                                @else
                                    <span class="text-coffee-400 text-xs">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-coffee-500">
                                <div class="max-w-sm mx-auto space-y-2">
                                    <div class="w-12 h-12 rounded-2xl bg-cream-100 text-coffee-400 flex items-center justify-center mx-auto">
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <h4 class="font-bold text-sm text-coffee-900">{{ __('messages.printing.empty_title') }}</h4>
                                    <p class="text-xs text-coffee-500">{{ __('messages.printing.empty_desc') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($jobs->hasPages())
            <div class="p-4 border-t border-coffee-200/80 bg-cream-50/50">
                {{ $jobs->links() }}
            </div>
        @endif
    </div>

    <!-- Resolution Modal -->
    @if ($showResolveModal && $resolvingJob)
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0 flex items-center justify-center">
            <div class="fixed inset-0 bg-coffee-950/60 backdrop-blur-xs" wire:click="closeResolveModal"></div>

            <div class="relative w-full max-w-lg bg-white rounded-3xl border border-coffee-200 shadow-2xl overflow-hidden z-10 flex flex-col">
                <div class="px-6 py-4.5 border-b border-coffee-100 bg-cream-50 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-coffee-950 tracking-tight">
                            {{ __('messages.printing.modal_title', ['id' => $resolvingJob->id]) }}
                        </h3>
                        <p class="text-xs text-coffee-500 mt-0.5">
                            {{ __('messages.printing.modal_desc', ['order' => $resolvingJob->order?->order_number ?? ('#'.$resolvingJob->order_id)]) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeResolveModal"
                        class="text-coffee-400 hover:text-coffee-800 p-1"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="resolveJob" class="p-6 space-y-4">
                    <!-- Target Status Selection -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-2">
                            {{ __('messages.printing.target_status') }}
                        </label>
                        <div class="space-y-2">
                            <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer {{ $resolution === 'completed' ? 'border-emerald-500 bg-emerald-50/50' : 'border-coffee-200 hover:bg-cream-50' }}">
                                <input
                                    type="radio"
                                    wire:model="resolution"
                                    value="completed"
                                    class="mt-0.5 text-emerald-600 focus:ring-emerald-500"
                                />
                                <div>
                                    <span class="block text-xs font-bold text-coffee-950">
                                        {{ __('messages.printing.status_completed_option') }}
                                    </span>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer {{ $resolution === 'failed' ? 'border-rose-500 bg-rose-50/50' : 'border-coffee-200 hover:bg-cream-50' }}">
                                <input
                                    type="radio"
                                    wire:model="resolution"
                                    value="failed"
                                    class="mt-0.5 text-rose-600 focus:ring-rose-500"
                                />
                                <div>
                                    <span class="block text-xs font-bold text-coffee-950">
                                        {{ __('messages.printing.status_failed_option') }}
                                    </span>
                                </div>
                            </label>
                        </div>
                        @error('resolution')
                            <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Required Note -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-coffee-800 mb-1">
                            {{ __('messages.printing.note_label') }}
                        </label>
                        <textarea
                            wire:model="resolutionNote"
                            rows="3"
                            placeholder="{{ __('messages.printing.note_placeholder') }}"
                            class="w-full px-3 py-2 bg-cream-50/70 border border-coffee-200 rounded-xl text-xs text-coffee-950 focus:ring-2 focus:ring-coffee-400 focus:bg-white resize-none"
                        ></textarea>
                        @error('resolutionNote')
                            <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-3 border-t border-coffee-100 flex items-center justify-end gap-2">
                        <x-button
                            type="button"
                            variant="secondary"
                            size="sm"
                            wire:click="closeResolveModal"
                        >
                            {{ __('messages.printing.cancel') }}
                        </x-button>
                        <x-button
                            type="submit"
                            variant="primary"
                            size="sm"
                            class="bg-coffee-900 hover:bg-coffee-800"
                        >
                            {{ __('messages.printing.save_resolution') }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>
