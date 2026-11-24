<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-cream-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Temu Pulang') }} — POS & Management</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-coffee-900 bg-cream-50 flex flex-col justify-between selection:bg-coffee-200">
    <!-- Top Language Switcher Bar -->
    <header class="w-full px-6 py-4 flex justify-end">
        <div class="flex items-center gap-1.5 bg-white/80 backdrop-blur-xs px-2.5 py-1.5 rounded-full border border-coffee-200/80 shadow-2xs">
            <a
                href="{{ route('locale.set', 'id') }}"
                class="px-2.5 py-1 text-xs font-semibold rounded-full transition-all {{ app()->getLocale() === 'id' ? 'bg-coffee-900 text-cream-50' : 'text-coffee-600 hover:text-coffee-900' }}"
            >
                ID
            </a>
            <span class="text-coffee-300">|</span>
            <a
                href="{{ route('locale.set', 'en') }}"
                class="px-2.5 py-1 text-xs font-semibold rounded-full transition-all {{ app()->getLocale() === 'en' ? 'bg-coffee-900 text-cream-50' : 'text-coffee-600 hover:text-coffee-900' }}"
            >
                EN
            </a>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="flex-1 flex flex-col items-center justify-center px-4 sm:px-6 py-6">
        <div class="w-full max-w-md">
            {{ $slot }}
        </div>
    </main>

    <!-- Footer -->
    <footer class="py-5 text-center text-xs text-coffee-400">
        &copy; {{ date('Y') }} {{ config('app.name', 'Temu Pulang') }}. Local-First F&B POS Architecture.
    </footer>

    <x-notification />
</body>
</html>

