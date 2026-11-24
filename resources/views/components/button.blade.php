@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'icon' => null,
])

@php
$baseClasses = 'inline-flex items-center justify-center font-medium transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed select-none cursor-pointer';

$sizeClasses = match ($size) {
    'xs' => 'px-2.5 py-1.5 text-xs rounded-lg gap-1.5',
    'sm' => 'px-3 py-1.5 text-sm rounded-xl gap-2',
    'md' => 'px-4 py-2.5 text-sm rounded-xl gap-2',
    'lg' => 'px-5 py-3 text-base rounded-2xl gap-2.5',
    default => 'px-4 py-2.5 text-sm rounded-xl gap-2',
};

$variantClasses = match ($variant) {
    'primary' => 'bg-coffee-900 text-cream-50 hover:bg-coffee-800 active:bg-coffee-950 focus:ring-coffee-700 shadow-sm shadow-coffee-950/10',
    'secondary' => 'bg-coffee-100 text-coffee-800 hover:bg-coffee-200 active:bg-coffee-300 focus:ring-coffee-400 border border-coffee-200/80',
    'outline' => 'bg-transparent text-coffee-800 hover:bg-coffee-50 active:bg-coffee-100 border border-coffee-300 focus:ring-coffee-400',
    'caramel' => 'bg-caramel-500 text-white hover:bg-caramel-600 active:bg-caramel-700 focus:ring-caramel-400 shadow-sm',
    'danger' => 'bg-red-600 text-white hover:bg-red-700 active:bg-red-800 focus:ring-red-400 shadow-sm',
    'ghost' => 'bg-transparent text-coffee-700 hover:bg-coffee-100/70 hover:text-coffee-900 focus:ring-coffee-300',
    default => 'bg-coffee-900 text-cream-50 hover:bg-coffee-800 active:bg-coffee-950 focus:ring-coffee-700',
};
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge(['class' => "{$baseClasses} {$sizeClasses} {$variantClasses}"]) }}
>
    @if ($icon)
        <span class="shrink-0">{!! $icon !!}</span>
    @endif
    {{ $slot }}
</button>

