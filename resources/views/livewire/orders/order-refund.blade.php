<div class="p-4 sm:p-6 lg:p-8 max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-coffee-950 tracking-tight">{{ __('messages.refund.title') }}</h2>
            <p class="text-xs text-coffee-600 mt-0.5">
                {{ __('messages.refund.subtitle', ['order' => $order->order_number, 'status' => $statusLabel]) }}
            </p>
        </div>

        <a
            href="{{ route('orders.index') }}"
            class="px-4 py-2 rounded-xl border border-coffee-200 bg-white text-xs font-bold text-coffee-800 hover:bg-cream-100 transition"
        >
            {{ __('messages.refund.back_to_orders') }}
        </a>
    </div>

    @if ($completedRefundNumber)
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 space-y-1">
            <p class="text-sm font-bold text-emerald-900">{{ __('messages.refund.result_title') }}</p>
            <p class="text-xs text-emerald-800">
                {{ __('messages.refund.result_number') }}:
                <span class="font-extrabold">{{ $completedRefundNumber }}</span>
            </p>
            <p class="text-xs text-emerald-800">
                {{ __('messages.refund.result_status') }}:
                <span class="font-extrabold">{{ __('messages.refund.status_'.$updatedPaymentStatus) }}</span>
            </p>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-xs font-semibold text-rose-900">
            {{ $errorMessage }}
        </div>
    @endif

    <!-- Order Summary -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 p-5 shadow-sm grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-coffee-500">{{ __('messages.pos.order_number') }}</p>
            <p class="text-base font-extrabold text-coffee-950 mt-0.5">{{ $order->order_number }}</p>
        </div>
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-coffee-500">{{ __('messages.refund.order_total') }}</p>
            <p class="text-base font-extrabold text-coffee-950 mt-0.5">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</p>
        </div>
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-coffee-500">{{ __('messages.refund.payment_status') }}</p>
            <p class="text-base font-extrabold text-coffee-950 mt-0.5">{{ $statusLabel }}</p>
        </div>
    </div>

    <!-- Per-Payment Allocations -->
    <div class="bg-white rounded-3xl border border-coffee-200/80 p-6 shadow-sm space-y-5">
        <h3 class="text-sm font-bold uppercase tracking-wider text-coffee-900 border-b border-coffee-100 pb-3">
            {{ __('messages.refund.payments_heading') }}
        </h3>

        <div class="space-y-4">
            @foreach ($order->payments as $payment)
                @php
                    $remaining = $payment->remainingRefundableBalance();
                @endphp
                <div class="p-4 rounded-2xl bg-cream-50/70 border border-coffee-200/70 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-bold text-coffee-950">
                            {{ __('messages.refund.payment_label', ['id' => $payment->id, 'method' => strtoupper($payment->payment_method)]) }}
                        </span>
                        <div class="flex items-center gap-4 text-xs">
                            <span class="text-coffee-700">
                                {{ __('messages.refund.paid_amount') }}:
                                <strong>Rp {{ number_format($payment->amount, 0, ',', '.') }}</strong>
                            </span>
                            <span class="text-caramel-700">
                                {{ __('messages.refund.refundable_balance') }}:
                                <strong>Rp {{ number_format($remaining, 0, ',', '.') }}</strong>
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div
                            x-data="{
                                amt: $wire.entangle('allocations.{{ $payment->id }}.amount').live,
                                format(val) {
                                    const n = parseInt(val || 0, 10);
                                    return n > 0 ? n.toLocaleString('id-ID') : '';
                                },
                                onInput(e) {
                                    const digits = e.target.value.replace(/\D/g, '').slice(0, 10);
                                    const num = digits === '' ? 0 : parseInt(digits, 10);
                                    this.amt = num;
                                    e.target.value = num > 0 ? num.toLocaleString('id-ID') : '';
                                }
                            }"
                        >
                            <label class="block text-xs font-bold text-coffee-800 mb-1">
                                {{ __('messages.refund.refund_amount_label') }}
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-xs font-bold text-coffee-600 pointer-events-none">
                                    Rp
                                </span>
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="13"
                                    :value="format(amt)"
                                    @input="onInput($event)"
                                    placeholder="0"
                                    class="w-full pl-10 pr-3.5 py-2 bg-white border border-coffee-200 rounded-xl text-sm font-bold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-coffee-800 mb-1">
                                {{ __('messages.refund.refund_method_label') }}
                            </label>
                            <select
                                wire:model="allocations.{{ $payment->id }}.refund_method"
                                class="w-full px-3.5 py-2 bg-white border border-coffee-200 rounded-xl text-sm font-semibold text-coffee-950 focus:outline-none focus:ring-2 focus:ring-coffee-400"
                            >
                                <option value="cash">{{ __('messages.refund.method_cash') }}</option>
                                <option value="qris_reversal">{{ __('messages.refund.method_qris_reversal') }}</option>
                                <option value="manual_transfer">{{ __('messages.refund.method_manual_transfer') }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Reason -->
        <div>
            <label class="block text-xs font-bold text-coffee-800 mb-1">
                {{ __('messages.refund.reason_label') }} <span class="text-rose-500">*</span>
            </label>
            <textarea
                wire:model="reason"
                rows="3"
                placeholder="{{ __('messages.refund.reason_placeholder') }}"
                class="w-full p-3 rounded-xl border border-coffee-200 text-xs font-medium text-coffee-950 bg-cream-50/50 focus:outline-none focus:ring-2 focus:ring-coffee-400 focus:bg-white"
            ></textarea>
            @error('reason')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-end">
            <x-button
                type="button"
                variant="primary"
                size="md"
                wire:click="submitRefund"
                wire:loading.attr="disabled"
                class="bg-coffee-900 hover:bg-caramel-600 text-white cursor-pointer"
            >
                <span wire:loading.remove wire:target="submitRefund">{{ __('messages.refund.submit_btn') }}</span>
                <span wire:loading wire:target="submitRefund">{{ __('messages.refund.processing') }}</span>
            </x-button>
        </div>
    </div>
</div>
