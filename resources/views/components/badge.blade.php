@props([
    'variant' => 'neutral',
    'size' => 'sm',
])

@php
$sizeClasses = match ($size) {
    'xs' => 'px-1.5 py-0.5 text-[10px]',
    'sm' => 'px-2.5 py-1 text-xs',
    'md' => 'px-3 py-1.5 text-sm',
    default => 'px-2.5 py-1 text-xs',
};

$variantClasses = match ($variant) {
    'primary' => 'bg-coffee-100 text-coffee-800 border border-coffee-200/80',
    'caramel' => 'bg-caramel-100 text-caramel-700 border border-caramel-200',
    'success' => 'bg-emerald-50 text-emerald-700 border border-emerald-200/80',
    'warning' => 'bg-amber-50 text-amber-700 border border-amber-200/80',
    'danger' => 'bg-rose-50 text-rose-700 border border-rose-200/80',
    'neutral' => 'bg-stone-100 text-stone-700 border border-stone-200/80',
    default => 'bg-coffee-100 text-coffee-800 border border-coffee-200/80',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center font-medium rounded-full {$sizeClasses} {$variantClasses} tracking-tight select-none"]) }}>
    {{ $slot }}
</span>

