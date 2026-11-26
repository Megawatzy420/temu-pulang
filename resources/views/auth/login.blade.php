<x-layouts.guest :title="__('messages.auth.welcome_back')">
    <div class="bg-white rounded-3xl border border-coffee-200/80 shadow-md p-7 sm:p-9 relative overflow-hidden">
        <!-- Top decorative warm glow -->
        <div class="absolute -top-16 -right-16 w-32 h-32 bg-caramel-100 rounded-full blur-2xl pointer-events-none opacity-80"></div>

        <!-- Logo & Branding -->
        <div class="text-center mb-8">
            <div class="inline-flex w-14 h-14 rounded-3xl bg-gradient-to-br from-coffee-800 to-coffee-950 text-cream-50 items-center justify-center font-bold text-2xl shadow-lg border-2 border-caramel-400/40 mb-4">
                TP
            </div>
            <h1 class="text-2xl font-bold text-coffee-950 tracking-tight">
                {{ config('app.name', 'Temu Pulang') }}
            </h1>
            <p class="text-xs font-medium uppercase tracking-widest text-caramel-600 mt-0.5">
                {{ __('messages.app_tagline') }}
            </p>
            <p class="text-sm text-coffee-500 mt-2">
                {{ __('messages.auth.sign_in_instruction') }}
            </p>
        </div>

        <!-- Login Form -->
        <form method="POST" action="{{ route('login.post') }}" class="space-y-4.5" id="loginForm">
            @csrf

            <!-- Email Input -->
            <x-input
                :label="__('messages.auth.email_label')"
                name="email"
                type="email"
                id="emailInput"
                :value="old('email', ($demoLoginEnabled ?? false) ? 'owner@temupulang.local' : '')"
                :error="$errors->first('email')"
                required
                autocomplete="email"
                placeholder="nama@temupulang.local"
                icon='<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" /></svg>'
            />

            <!-- Password Input -->
            <x-input
                :label="__('messages.auth.password_label')"
                name="password"
                type="password"
                id="passwordInput"
                :value="($demoLoginEnabled ?? false) ? 'TemuPulang@2026!' : ''"
                :error="$errors->first('password')"
                required
                autocomplete="current-password"
                placeholder="••••••••••••"
                icon='<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>'
            />

            <!-- Remember Me Checkbox -->
            <div class="flex items-center justify-between pt-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input
                        type="checkbox"
                        name="remember"
                        value="1"
                        class="w-4 h-4 rounded border-coffee-300 text-coffee-800 focus:ring-coffee-500 accent-coffee-800 cursor-pointer"
                        checked
                    />
                    <span class="text-xs text-coffee-600 font-medium">
                        {{ __('messages.auth.remember_me') }}
                    </span>
                </label>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <x-button
                    type="submit"
                    variant="primary"
                    size="lg"
                    class="w-full text-base font-semibold shadow-md"
                >
                    {{ __('messages.auth.sign_in_button') }}
                </x-button>
            </div>
        </form>

        @if ($demoLoginEnabled ?? false)
            <!-- Quick Demo Switcher Section -->
            <div class="mt-7 pt-6 border-t border-coffee-100">
                <p class="text-center text-xs font-semibold uppercase tracking-wider text-coffee-500 mb-3">
                    {{ __('messages.auth.quick_demo_login') }}
                </p>

                <div class="grid grid-cols-2 gap-2.5">
                    <form method="POST" action="{{ route('login.demo', ['role' => 'owner']) }}">
                        @csrf
                        <button
                            type="submit"
                            class="w-full px-3 py-2.5 rounded-xl border border-coffee-200 bg-cream-50 hover:bg-coffee-100/70 text-coffee-800 text-xs font-medium transition-all flex flex-col items-center justify-center gap-1 cursor-pointer hover:border-coffee-300 shadow-2xs"
                        >
                            <span class="font-bold text-coffee-950 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-caramel-500"></span>
                                {{ __('messages.auth.owner_demo') }}
                            </span>
                            <span class="text-[10px] text-coffee-500">owner@temupulang.local</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('login.demo', ['role' => 'cashier']) }}">
                        @csrf
                        <button
                            type="submit"
                            class="w-full px-3 py-2.5 rounded-xl border border-coffee-200 bg-cream-50 hover:bg-coffee-100/70 text-coffee-800 text-xs font-medium transition-all flex flex-col items-center justify-center gap-1 cursor-pointer hover:border-coffee-300 shadow-2xs"
                        >
                            <span class="font-bold text-coffee-950 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                {{ __('messages.auth.cashier_demo') }}
                            </span>
                            <span class="text-[10px] text-coffee-500">cashier@temupulang.local</span>
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-layouts.guest>

