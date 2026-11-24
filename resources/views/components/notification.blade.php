<div
    x-data="{ show: false, message: '', type: 'success' }"
    x-init="
        @if (session()->has('status') || session()->has('success'))
            message = '{{ session('status') ?? session('success') }}';
            type = 'success';
            show = true;
            setTimeout(() => show = false, 4000);
        @elseif (session()->has('error'))
            message = '{{ session('error') }}';
            type = 'error';
            show = true;
            setTimeout(() => show = false, 4000);
        @elseif (session()->has('warning'))
            message = '{{ session('warning') }}';
            type = 'warning';
            show = true;
            setTimeout(() => show = false, 4000);
        @endif
    "
    x-on:notify.window="
        message = $event.detail.message;
        type = $event.detail.type || 'success';
        show = true;
        setTimeout(() => show = false, 4000);
    "
    x-show="show"
    x-transition:enter="ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-4"
    x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
    x-transition:leave="ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0 sm:translate-x-0"
    x-transition:leave-end="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-4"
    style="display: none;"
    class="fixed bottom-5 right-5 z-50 max-w-sm w-full"
>
    <div
        :class="{
            'bg-emerald-50 border-emerald-200 text-emerald-900': type === 'success',
            'bg-rose-50 border-rose-200 text-rose-900': type === 'error',
            'bg-amber-50 border-amber-200 text-amber-900': type === 'warning',
            'bg-sky-50 border-sky-200 text-sky-900': type === 'info'
        }"
        class="rounded-2xl border p-4 shadow-lg flex items-center justify-between gap-3"
    >
        <div class="flex items-center gap-3">
            <template x-if="type === 'success'">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </template>
            <template x-if="type === 'error'">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </template>
            <template x-if="type === 'warning'">
                <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </template>
            <p class="text-sm font-medium" x-text="message"></p>
        </div>

        <button
            type="button"
            x-on:click="show = false"
            class="text-current opacity-60 hover:opacity-100 p-1"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</div>

