@props([
    'title' => null,
    'subtitle' => null,
    'action' => null,
    'footer' => null,
    'padding' => 'p-5 sm:p-6',
])

<div {{ $attributes->merge(['class' => 'bg-white rounded-2xl border border-coffee-200/70 shadow-xs transition-shadow hover:shadow-sm overflow-hidden']) }}>
    @if ($title || $subtitle || $action)
        <div class="px-5 py-4 sm:px-6 border-b border-coffee-100 flex items-center justify-between gap-4">
            <div>
                @if ($title)
                    <h3 class="text-base font-semibold text-coffee-900 tracking-tight">{{ $title }}</h3>
                @endif
                @if ($subtitle)
                    <p class="text-xs text-coffee-500 mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
            @if ($action)
                <div class="shrink-0">
                    {{ $action }}
                </div>
            @endif
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>

    @if ($footer)
        <div class="px-5 py-3.5 sm:px-6 bg-cream-50/60 border-t border-coffee-100">
            {{ $footer }}
        </div>
    @endif
</div>

