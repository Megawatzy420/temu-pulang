@props([
    'headers' => [],
])

<div class="overflow-x-auto w-full">
    <table {{ $attributes->merge(['class' => 'w-full text-left text-sm text-coffee-800']) }}>
        @if (!empty($headers))
            <thead class="bg-cream-100/70 border-b border-coffee-200/60 text-xs font-semibold uppercase tracking-wider text-coffee-600">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" class="px-5 py-3.5">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-coffee-100 bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>

