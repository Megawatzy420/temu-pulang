@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'error' => null,
    'hint' => null,
    'icon' => null,
    'required' => false,
])

<div class="w-full">
    @php
        $id = $attributes->get('id', $name);
    @endphp

    @if ($label)
        <label for="{{ $id }}" class="block text-xs font-semibold uppercase tracking-wider text-coffee-800 mb-1.5">
            {{ $label }}
            @if ($required)
                <span class="text-red-500">*</span>
            @endif
        </label>
    @endif

    <div class="relative rounded-xl">
        @if ($icon)
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-coffee-400">
                {!! $icon !!}
            </div>
        @endif

        <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $id }}"
            {{ $attributes->merge([
                'class' => 'w-full rounded-xl border ' .
                    ($error ? 'border-red-400 focus:border-red-500 focus:ring-red-200 text-red-900 bg-red-50/20' : 'border-coffee-200 focus:border-coffee-700 focus:ring-coffee-200 text-coffee-950 bg-white placeholder-coffee-400') .
                    ' px-3.5 py-2.5 text-sm transition-all focus:outline-none focus:ring-2' .
                    ($icon ? ' pl-10' : '')
            ]) }}
        />
    </div>

    @if ($error)
        <p class="mt-1.5 text-xs text-red-600 font-medium">{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1 text-xs text-coffee-500">{{ $hint }}</p>
    @endif
</div>

