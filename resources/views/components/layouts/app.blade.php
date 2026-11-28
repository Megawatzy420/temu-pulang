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
    <script>
        try {
            if (localStorage.getItem('tp.sidebar.collapsed') === '1') {
                document.documentElement.classList.add('tp-sidebar-collapsed');
            }
        } catch (e) {}
    </script>
</head>
<body
    class="h-full font-sans antialiased text-coffee-950 bg-cream-50 selection:bg-coffee-200"
    x-data="{
        sidebarOpen: false,
        sidebarCollapsed: (() => {
            try {
                return localStorage.getItem('tp.sidebar.collapsed') === '1';
            } catch (e) {
                return false;
            }
        })(),
        isDesktop: typeof window !== 'undefined' ? window.innerWidth >= 1024 : true,
        toggleDesktopSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            document.documentElement.classList.toggle('tp-sidebar-collapsed', this.sidebarCollapsed);
            try {
                localStorage.setItem('tp.sidebar.collapsed', this.sidebarCollapsed ? '1' : '0');
            } catch (e) {}
        },
        openMobileDrawer() {
            this.sidebarOpen = true;
            this.$nextTick(() => {
                if (this.$refs.drawerCloseBtn) {
                    this.$refs.drawerCloseBtn.focus();
                }
            });
        },
        closeMobileDrawer(returnFocus = true) {
            if (!this.sidebarOpen) {
                return;
            }
            this.sidebarOpen = false;
            if (returnFocus) {
                this.$nextTick(() => {
                    if (this.$refs.mobileMenuBtn) {
                        this.$refs.mobileMenuBtn.focus();
                    }
                });
            }
        },
        handleResize() {
            const nowDesktop = window.innerWidth >= 1024;
            if (nowDesktop && this.sidebarOpen) {
                this.sidebarOpen = false;
            }
            this.isDesktop = nowDesktop;
        }
    }"
    x-init="handleResize()"
    x-on:resize.window="handleResize()"
    x-on:keydown.escape.window="if (sidebarOpen) { closeMobileDrawer(true); }"
    x-on:livewire:navigated.window="closeMobileDrawer(false)"
    :class="sidebarOpen && !isDesktop ? 'overflow-hidden' : ''"
>

    <div class="h-full flex overflow-hidden">

        <!-- Mobile & Tablet Sidebar Backdrop -->
        <div
            id="mobile-sidebar-backdrop"
            x-show="sidebarOpen"
            x-transition:enter="transition-opacity ease-linear duration-200 motion-reduce:transition-none"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-150 motion-reduce:transition-none"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-coffee-950/60 backdrop-blur-xs lg:hidden no-print"
            x-on:click="closeMobileDrawer(true)"
            aria-hidden="true"
            style="display: none;"
        ></div>

        <!-- Sidebar Navigation -->
        <aside
            id="app-sidebar"
            aria-label="{{ __('messages.nav.sidebar_label') }}"
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                sidebarCollapsed ? 'lg:w-20' : 'lg:w-64'
            ]"
            class="fixed inset-y-0 left-0 z-50 lg:z-20 w-64 lg:w-64 h-full max-h-dvh pb-[env(safe-area-inset-bottom)] -translate-x-full lg:translate-x-0 lg:static bg-coffee-900 text-cream-50 transition-[transform,width] duration-200 ease-in-out motion-reduce:transition-none flex flex-col justify-between border-r border-coffee-800 shrink-0 no-print"
        >
            <div class="flex-1 min-h-0 flex flex-col">
                <!-- Brand / Shop Header -->
                <div
                    :class="sidebarCollapsed ? 'lg:px-2 lg:py-4 lg:flex-col lg:justify-center lg:gap-2' : 'px-5 py-4 justify-between'"
                    class="px-5 py-4 border-b border-coffee-800/80 flex items-center justify-between gap-2 shrink-0"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <div
                            title="{{ config('app.name', 'Temu Pulang') }} — {{ __('messages.app_tagline') }}"
                            class="w-10 h-10 rounded-2xl bg-gradient-to-br from-caramel-500 to-coffee-700 flex items-center justify-center text-cream-50 font-bold text-lg shadow-md border border-caramel-400/30 shrink-0"
                        >
                            TP
                        </div>
                        <div
                            :class="sidebarCollapsed ? 'lg:hidden' : ''"
                            class="tp-brand-text min-w-0"
                        >
                            <h1 class="font-bold text-base tracking-tight text-white leading-tight truncate">
                                {{ config('app.name', 'Temu Pulang') }}
                            </h1>
                            <p class="text-xs text-coffee-300 font-medium truncate">Coffee & Eatery</p>
                        </div>
                    </div>

                    <!-- Desktop Collapse / Expand Toggle Button (>= 1024px) -->
                    <button
                        id="desktop-sidebar-toggle"
                        type="button"
                        aria-controls="app-sidebar"
                        aria-expanded="true"
                        :aria-expanded="(!sidebarCollapsed).toString()"
                        aria-label="{{ __('messages.nav.collapse_sidebar') }}"
                        title="{{ __('messages.nav.collapse_sidebar') }}"
                        data-collapse-label="{{ __('messages.nav.collapse_sidebar') }}"
                        data-expand-label="{{ __('messages.nav.expand_sidebar') }}"
                        :aria-label="sidebarCollapsed ? $el.dataset.expandLabel : $el.dataset.collapseLabel"
                        :title="sidebarCollapsed ? $el.dataset.expandLabel : $el.dataset.collapseLabel"
                        x-on:click="toggleDesktopSidebar()"
                        class="hidden lg:inline-flex items-center justify-center w-9 h-9 rounded-xl text-coffee-300 hover:text-white hover:bg-coffee-800/80 transition-colors motion-reduce:transition-none cursor-pointer shrink-0 no-print"
                    >
                        <svg
                            :class="sidebarCollapsed ? 'rotate-180' : ''"
                            class="w-5 h-5 transition-transform duration-200 motion-reduce:transition-none"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                        </svg>
                    </button>

                    <!-- Close button on tablet & mobile (< 1024px) -->
                    <button
                        id="mobile-drawer-close"
                        x-ref="drawerCloseBtn"
                        type="button"
                        aria-controls="app-sidebar"
                        aria-label="{{ __('messages.nav.close_menu') }}"
                        title="{{ __('messages.nav.close_menu') }}"
                        class="lg:hidden min-w-11 min-h-11 inline-flex items-center justify-center text-coffee-300 hover:text-white p-2.5 rounded-xl hover:bg-coffee-800/80 transition-colors motion-reduce:transition-none cursor-pointer shrink-0 no-print"
                        x-on:click="closeMobileDrawer(true)"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Navigation Links -->
                <nav
                    :class="sidebarCollapsed ? 'lg:px-2' : 'px-3.5'"
                    class="flex-1 min-h-0 px-3.5 py-4 space-y-1.5 overflow-y-auto"
                >
                    @auth
                        @if (auth()->user()->isOwner())
                            <!-- Owner Navigation -->
                            <a
                                href="{{ route('dashboard') }}"
                                title="{{ __('messages.nav.dashboard') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('dashboard') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-5 h-5 opacity-80 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.dashboard') }}</span>
                            </a>
                        @endif

                        <!-- POS Terminal (Owner & Cashier) -->
                        <a
                            href="{{ route('pos') }}"
                            title="{{ __('messages.nav.pos') }}"
                            x-on:click="closeMobileDrawer(false)"
                            :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                            class="relative flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('pos') ? 'bg-caramel-500 text-white font-semibold shadow-sm' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                        >
                            <svg class="w-5 h-5 opacity-90 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.pos') }}</span>
                        </a>

                        <!-- Orders & Kitchen Queue (Owner & Cashier) -->
                        <a
                            href="{{ route('orders.index') }}"
                            title="{{ __('messages.nav.orders_queue') }}"
                            x-on:click="closeMobileDrawer(false)"
                            :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                            class="relative flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('orders.*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                        >
                            <svg class="w-5 h-5 opacity-80 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.orders_queue') }}</span>
                        </a>

                        <!-- Shifts & Cash Drawer (Owner & Cashier) -->
                        <a
                            href="{{ route('shifts') }}"
                            title="{{ __('messages.nav.shifts') }}"
                            x-on:click="closeMobileDrawer(false)"
                            :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                            class="relative flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('shifts') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-200 hover:bg-coffee-800/60 hover:text-white' }}"
                        >
                            <svg class="w-5 h-5 opacity-80 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.shifts') }}</span>
                        </a>

                        @if (auth()->user()->isOwner())
                            <!-- Owner Administration -->
                            <div
                                :class="sidebarCollapsed ? 'lg:hidden' : ''"
                                class="tp-nav-section pt-3 pb-1.5 px-3"
                            >
                                <span class="text-[10px] uppercase font-bold tracking-wider text-coffee-400">
                                    {{ __('messages.nav.section_owner_admin') }}
                                </span>
                            </div>

                            <a
                                href="{{ route('settings') }}"
                                title="{{ __('messages.nav.settings') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('settings') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.settings') }}</span>
                            </a>

                            <a
                                href="{{ route('activity-logs') }}"
                                title="{{ __('messages.nav.activity_logs') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('activity-logs') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.activity_logs') }}</span>
                            </a>

                            <a
                                href="{{ route('printing.jobs') }}"
                                title="{{ __('messages.nav.print_jobs') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('printing.jobs*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.print_jobs') }}</span>
                            </a>

                            <!-- Owner Additional Modules -->
                            <div
                                :class="sidebarCollapsed ? 'lg:hidden' : ''"
                                class="tp-nav-section pt-3 pb-1.5 px-3"
                            >
                                <span class="text-[10px] uppercase font-bold tracking-wider text-coffee-400">
                                    {{ __('messages.nav.section_menu_catalog') }}
                                </span>
                            </div>

                            <a
                                href="{{ route('menu.products') }}"
                                title="{{ __('messages.nav.product_list') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('menu.products*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.product_list') }}</span>
                            </a>

                            <a
                                href="{{ route('menu.categories') }}"
                                title="{{ __('messages.nav.menu_categories') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('menu.categories') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.menu_categories') }}</span>
                            </a>

                            <a
                                href="{{ route('menu.modifiers') }}"
                                title="{{ __('messages.nav.modifier_groups') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('menu.modifiers') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.modifier_groups') }}</span>
                            </a>

                            <!-- Inventory & Recipes (Phase 6) -->
                            <div
                                :class="sidebarCollapsed ? 'lg:hidden' : ''"
                                class="tp-nav-section pt-3 pb-1.5 px-3"
                            >
                                <span class="text-[10px] uppercase font-bold tracking-wider text-coffee-400">
                                    {{ __('messages.nav.section_inventory') }}
                                </span>
                            </div>

                            <a
                                href="{{ route('inventory.ingredients') }}"
                                title="{{ __('messages.nav.ingredients_stock') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('inventory.ingredients*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.ingredients_stock') }}</span>
                            </a>

                            <a
                                href="{{ route('inventory.recipes') }}"
                                title="{{ __('messages.nav.menu_recipes') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('inventory.recipes*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.menu_recipes') }}</span>
                            </a>

                            @php
                                $sidebarLowStockCount = app(\App\Services\Inventory\LowStockService::class)->count();
                            @endphp
                            <a
                                href="{{ route('inventory.stock') }}"
                                title="{{ __('messages.nav.stock') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('inventory.stock*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.stock') }}</span>
                                @if ($sidebarLowStockCount > 0)
                                    <span
                                        :class="sidebarCollapsed ? 'lg:absolute lg:top-0.5 lg:right-1 lg:px-1.5 lg:py-0 lg:text-[9px]' : 'ml-auto px-2 py-0.5 text-[10px]'"
                                        class="ml-auto inline-flex items-center justify-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-500 text-white"
                                    >
                                        {{ $sidebarLowStockCount }}
                                    </span>
                                @endif
                            </a>

                            <a
                                href="{{ route('inventory.movements') }}"
                                title="{{ __('messages.nav.stock_history') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('inventory.movements*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.stock_history') }}</span>
                            </a>

                            <a
                                href="{{ route('reports.sales') }}"
                                title="{{ __('messages.nav.reports') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('reports.sales*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.reports') }}</span>
                            </a>

                            <a
                                href="{{ route('reports.analytics') }}"
                                title="{{ __('messages.nav.analytics') }}"
                                x-on:click="closeMobileDrawer(false)"
                                :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : 'px-3.5'"
                                class="relative flex items-center gap-3 px-3.5 py-2 rounded-xl text-sm font-medium transition-colors motion-reduce:transition-none {{ request()->routeIs('reports.analytics*') ? 'bg-coffee-800 text-white font-semibold shadow-xs' : 'text-coffee-300 hover:bg-coffee-800/60 hover:text-white' }}"
                            >
                                <svg class="w-4.5 h-4.5 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                                </svg>
                                <span :class="sidebarCollapsed ? 'lg:hidden' : ''" class="tp-nav-label truncate">{{ __('messages.nav.analytics') }}</span>
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>

            <!-- Bottom User & Role Section -->
            <div
                :class="sidebarCollapsed ? 'lg:p-2' : 'p-3.5'"
                class="p-3.5 border-t border-coffee-800/80 bg-coffee-950/40 shrink-0"
            >
                @auth
                    <div
                        :class="sidebarCollapsed ? 'lg:flex-col lg:justify-center lg:gap-2 lg:px-1.5 lg:py-2' : 'px-3 py-2.5 justify-between'"
                        class="px-3 py-2.5 rounded-xl bg-coffee-800/40 border border-coffee-700/50 flex items-center justify-between"
                    >
                        <div class="flex items-center gap-2.5 overflow-hidden">
                            <div
                                title="{{ auth()->user()->name }} ({{ auth()->user()->isOwner() ? __('messages.nav.role_owner') : __('messages.nav.role_cashier') }})"
                                class="w-8 h-8 rounded-full bg-coffee-700 flex items-center justify-center text-xs font-semibold text-cream-100 shrink-0"
                            >
                                {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                            </div>
                            <div
                                :class="sidebarCollapsed ? 'lg:hidden' : ''"
                                class="tp-user-text truncate"
                            >
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
                                aria-label="{{ __('messages.nav.logout') }}"
                                class="text-coffee-400 hover:text-red-400 p-1.5 rounded-lg hover:bg-coffee-800/80 transition-colors motion-reduce:transition-none cursor-pointer"
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
            <header class="bg-white/80 backdrop-blur-xs border-b border-coffee-200/60 px-3 sm:px-6 py-2.5 sm:py-3 flex items-center justify-between gap-2 sm:gap-4 z-10 shrink-0 no-print">
                <div class="flex items-center gap-2.5 sm:gap-3 min-w-0 flex-1">
                    <!-- Tablet & Mobile Hamburger (< 1024px) -->
                    <button
                        id="mobile-menu-toggle"
                        x-ref="mobileMenuBtn"
                        type="button"
                        aria-controls="app-sidebar"
                        aria-expanded="false"
                        :aria-expanded="sidebarOpen ? 'true' : 'false'"
                        aria-label="{{ __('messages.nav.open_menu') }}"
                        title="{{ __('messages.nav.open_menu') }}"
                        class="lg:hidden min-w-11 min-h-11 inline-flex items-center justify-center text-coffee-700 hover:text-coffee-950 p-2.5 rounded-xl hover:bg-coffee-100 transition-colors motion-reduce:transition-none cursor-pointer shrink-0 no-print"
                        x-on:click="openMobileDrawer()"
                    >
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm sm:text-lg font-bold text-coffee-950 tracking-tight leading-tight truncate">
                            {{ $headerTitle ?? config('app.name', 'Temu Pulang') }}
                        </h2>
                        @if (isset($headerSubtitle))
                            <p class="text-xs text-coffee-500 hidden sm:block truncate">{{ $headerSubtitle }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                    @php
                        $headerShift = \App\Models\Shift::open()->with('user')->latest('opened_at')->first();
                    @endphp
                    <!-- Dynamic Shift & Branch Status Pill -->
                    <a
                        href="{{ route('shifts') }}"
                        class="inline-flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-3 py-1.5 rounded-full bg-cream-100/90 hover:bg-cream-200 border border-coffee-200/70 text-xs text-coffee-800 transition-colors max-w-36 sm:max-w-56 min-w-0"
                        title="{{ __('messages.nav.shift_pill_title') }}"
                    >
                        @if ($headerShift)
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                            <span class="hidden sm:inline font-medium truncate">{{ $headerShift->shift_number }} • {{ $headerShift->user->name }}</span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></span>
                            <span class="hidden sm:inline font-medium text-amber-900 truncate">{{ __('messages.nav.no_active_shift') }}</span>
                        @endif
                    </a>

                    <!-- Language Switcher Pill -->
                    <div class="flex items-center gap-1 bg-cream-100/90 px-1.5 py-1 rounded-full border border-coffee-200/80 shadow-2xs shrink-0">
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

