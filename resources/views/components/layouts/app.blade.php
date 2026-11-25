<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-cream-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Temu Pulang') }} — Point of Sale</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased text-coffee-950 bg-cream-50 selection:bg-coffee-200" x-data="{ sidebarOpen: false }">

    <div class="h-full flex overflow-hidden">

        <!-- Mobile Sidebar Backdrop -->
        <div
            x-show="sidebarOpen"
            x-transition:enter="transition-opacity ease-linear duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-coffee-950/60 backdrop-blur-xs md:hidden"
            x-on:click="sidebarOpen = false"
            style="display: none;"
        ></div>

        <!-- Sidebar Navigation -->
        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-50 w-64 bg-coffee-900 text-cream-50 transition-transform duration-200 ease-in-out md:static md:translate-x-0 flex flex-col justify-between border-r border-coffee-800 shrink-0"
        >
            <div>
                <!-- Brand / Shop Header -->
                <div class="px-6 py-5 border-b border-coffee-800/80 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-caramel-500 to-coffee-700 flex items-center justify-center text-cream-50 font-bold text-lg shadow-md border border-caramel-400/30">
                            TP
                        </div>
                        <div>
                            <h1 class="font-bold text-base tracking-tight text-white leading-tight">
                                {{ config('app.name', 'Temu Pulang') }}
                            </h1>
                            <p class="text-xs text-coffee-300 font-medium">Coffee & Eatery</p>
                        </div>
                    </div>

                    <!-- Close button on mobile -->
                    <button
                        type="button"
                        class="md:hidden text-coffee-300 hover:text-white p-1"
                        x-on:click="sidebarOpen = false"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Navigation Links -->
                <nav class="px-3.5 py-4 space-y-1.5 overflow-y-auto max-h-[calc(100vh-220px)]">
                    @auth
                        @if (auth()->user()->isOwner())
                            <!-- Owner Navigation -->
                            <a
                                href="{{ route('dashboard') }}"
                                class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('dashboard') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-5 h-5 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                                <span>{{ __('messages.nav.dashboard') }}</span>
                            </a>
                        @endif

                        <!-- POS Terminal (Owner & Cashier) -->
                        <a
                            href="{{ route('pos') }}"
                            class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('pos') ? 'bg-caramel-500 text-white font-semibold shadow-sm' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                        >
                            <svg class="w-5 h-5 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <span>{{ __('messages.nav.pos') }}</span>
                        </a>

                        <!-- Orders & Kitchen Queue (Owner & Cashier) -->
                        <a
                            href="{{ route('orders.index') }}"
                            class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('orders.*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                        >
                            <svg class="w-5 h-5 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>
                            <span>Antrean & Pesanan</span>
                        </a>

                        <!-- Shifts & Cash Drawer (Owner & Cashier) -->
                        <a
                            href="{{ route('shifts') }}"
                            class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('shifts') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                        >
                            <svg class="w-5 h-5 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Kelola Shift & Kas</span>
                        </a>

                        @if (auth()->user()->isOwner())
                            <!-- Owner Administration -->
                            <div class="pt-3 pb-1.5 px-3">
                                <span class="text-[10px] uppercase font-bold tracking-wider text-coffee-400">
                                    Administrasi Pemilik
                                </span>
                            </div>

                            <a
                                href="{{ route('settings') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('settings') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span>Pengaturan Toko</span>
                            </a>

                            <a
                                href="{{ route('activity-logs') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('activity-logs') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                                <span>Log Aktivitas (Audit)</span>
                            </a>

                            <a
                                href="{{ route('printing.jobs') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('printing.jobs*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                <span>{{ __('messages.nav.print_jobs') }}</span>
                            </a>

                            <!-- Owner Additional Modules -->
                            <div class="pt-3 pb-1.5 px-3">
                                <span class="text-[10px] uppercase font-bold tracking-wider text-coffee-400">
                                    Menu & Katalog
                                </span>
                            </div>

                            <a
                                href="{{ route('menu.products') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('menu.products*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                                <span>Daftar Produk</span>
                            </a>

                            <a
                                href="{{ route('menu.categories') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('menu.categories') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                <span>Kategori Menu</span>
                            </a>

                            <a
                                href="{{ route('menu.modifiers') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('menu.modifiers') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                                </svg>
                                <span>Grup Modifier</span>
                            </a>

                            <!-- Inventory & Recipes (Phase 6) -->
                            <div class="pt-3 pb-1.5 px-3">
                                <span class="text-[10px] uppercase font-bold tracking-wider text-coffee-400">
                                    Inventaris & Bahan
                                </span>
                            </div>

                            <a
                                href="{{ route('inventory.ingredients') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('inventory.ingredients*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                <span>Bahan Baku & Stok</span>
                            </a>

                            <a
                                href="{{ route('inventory.recipes') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('inventory.recipes*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                                <span>Resep Menu (BOM)</span>
                            </a>

                            @php
                                $sidebarLowStockCount = app(\App\Services\Inventory\LowStockService::class)->count();
                            @endphp
                            <a
                                href="{{ route('inventory.stock') }}"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors {{ request()->routeIs('inventory.stock*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                </svg>
                                <span>{{ __('messages.nav.stock') }}</span>
                                @if ($sidebarLowStockCount > 0)
                                    <span class="ml-auto inline-flex items-center justify-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-500 text-white">
                                        {{ $sidebarLowStockCount }}
                                    </span>
                                @endif
                            </a>

                            <a
                                href="#reports"
                                class="flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium text-coffee-300 hover:bg-coffee-800/60 hover:text-white transition-colors"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <span>{{ __('messages.nav.reports') }}</span>
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>

            <!-- Bottom User & Role Section -->
            <div class="p-3.5 border-t border-coffee-800/80 bg-coffee-950/40">
                @auth
                    <div class="px-3 py-2.5 rounded-xl bg-coffee-800/40 border border-coffee-700/50 flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2.5 overflow-hidden">
                            <div class="w-8 h-8 rounded-full bg-coffee-700 flex items-center justify-center text-xs font-semibold text-cream-100 shrink-0">
                                {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                            </div>
                            <div class="truncate">
                                <p class="text-xs font-medium text-white truncate">{{ auth()->user()->name }}</p>
                                <span class="inline-block text-[10px] font-semibold tracking-wider uppercase {{ auth()->user()->isOwner() ? 'text-caramel-300' : 'text-emerald-300' }}">
                                    {{ auth()->user()->isOwner() ? __('messages.nav.role_owner') : __('messages.nav.role_cashier') }}
                                </span>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button
                                type="submit"
                                title="{{ __('messages.nav.logout') }}"
                                class="text-coffee-400 hover:text-red-400 p-1.5 rounded-lg hover:bg-coffee-800/80 transition-colors cursor-pointer"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </aside>

        <!-- Right / Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden bg-cream-50">

            <!-- Top Header Bar -->
            <header class="bg-white/80 backdrop-blur-xs border-b border-coffee-200/60 px-4 sm:px-6 py-3 flex items-center justify-between gap-4 z-10 shrink-0">
                <div class="flex items-center gap-3">
                    <!-- Mobile Hamburger -->
                    <button
                        type="button"
                        class="md:hidden text-coffee-700 hover:text-coffee-950 p-1.5 rounded-xl hover:bg-coffee-100 transition-colors"
                        x-on:click="sidebarOpen = true"
                    >
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-coffee-950 tracking-tight leading-tight">
                            {{ $headerTitle ?? config('app.name', 'Temu Pulang') }}
                        </h2>
                        @if (isset($headerSubtitle))
                            <p class="text-xs text-coffee-500 hidden sm:block">{{ $headerSubtitle }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    @php
                        $headerShift = \App\Models\Shift::open()->with('user')->latest('opened_at')->first();
                    @endphp
                    <!-- Dynamic Shift & Branch Status Pill -->
                    <a
                        href="{{ route('shifts') }}"
                        class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full bg-cream-100/90 hover:bg-cream-200 border border-coffee-200/70 text-xs text-coffee-800 transition-colors"
                        title="Klik untuk melihat dan mengelola shift kasir"
                    >
                        @if ($headerShift)
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="font-medium">{{ $headerShift->shift_number }} • {{ $headerShift->user->name }}</span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            <span class="font-medium text-amber-900">Belum Ada Shift Aktif</span>
                        @endif
                    </a>

                    <!-- Language Switcher Pill -->
                    <div class="flex items-center gap-1 bg-cream-100/90 px-1.5 py-1 rounded-full border border-coffee-200/80 shadow-2xs">
                        <a
                            href="{{ route('locale.set', 'id') }}"
                            class="px-2 py-0.5 text-xs font-semibold rounded-full transition-all {{ app()->getLocale() === 'id' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-600 hover:text-coffee-950' }}"
                        >
                            ID
                        </a>
                        <span class="text-coffee-300 text-[10px]">|</span>
                        <a
                            href="{{ route('locale.set', 'en') }}"
                            class="px-2 py-0.5 text-xs font-semibold rounded-full transition-all {{ app()->getLocale() === 'en' ? 'bg-coffee-900 text-cream-50 shadow-xs' : 'text-coffee-600 hover:text-coffee-950' }}"
                        >
                            EN
                        </a>
                    </div>
                </div>
            </header>

            <!-- Main Scrollable Body Area -->
            <main class="flex-1 overflow-y-auto">
                {{ $slot }}
            </main>
        </div>
    </div>

    <!-- Global Toast Notification -->
    <x-notification />

    @livewireScripts
</body>
</html>

