<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Determine whether quick demo login is enabled in the current environment.
     */
    public static function isDemoLoginEnabled(): bool
    {
        $override = config('app.demo_login_enabled');
        if ($override !== null) {
            return (bool) $override;
        }

        return app()->environment(['local', 'testing']);
    }

    /**
     * Show the login page.
     */
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            /** @var User $user */
            $user = Auth::user();

            return $user->isOwner()
                ? redirect()->route('dashboard')
                : redirect()->route('pos');
        }

        return view('auth.login', [
            'demoLoginEnabled' => self::isDemoLoginEnabled(),
        ]);
    }

    /**
     * Handle a quick demo authentication attempt (local and testing environments only).
     */
    public function demoLogin(Request $request, ?string $role = null): RedirectResponse
    {
        if (! self::isDemoLoginEnabled()) {
            abort(404);
        }

        $targetRole = strtolower(trim((string) ($role ?? $request->input('role', 'owner'))));

        $demoCredentials = match ($targetRole) {
            'owner' => [
                'email' => (string) env('SEED_OWNER_EMAIL', 'owner@temupulang.local'),
                'password' => (string) env('SEED_OWNER_PASSWORD', 'TemuPulang@2026!'),
            ],
            'cashier' => [
                'email' => (string) env('SEED_CASHIER_EMAIL', 'cashier@temupulang.local'),
                'password' => (string) env('SEED_CASHIER_PASSWORD', 'Cashier@2026!'),
            ],
            default => abort(404),
        };

        $request->merge([
            'email' => $demoCredentials['email'],
            'password' => $demoCredentials['password'],
            'remember' => true,
        ]);

        return $this->login($request);
    }

    /**
     * Handle an authentication attempt.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            return back()->withInput($request->only('email', 'remember'))->withErrors([
                'email' => __('messages.auth.invalid_credentials'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withInput($request->only('email'))->withErrors([
                'email' => __('messages.auth.account_inactive'),
            ]);
        }

        ActivityLog::record(
            action: 'auth.login',
            description: "User {$user->name} signed in.",
            user: $user
        );

        if ($user->isOwner()) {
            return redirect()->intended(route('dashboard'));
        }

        return redirect()->intended(route('pos'));
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($user instanceof User) {
            ActivityLog::record(
                action: 'auth.logout',
                description: "User {$user->name} signed out.",
                user: $user
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('messages.auth.logout_success'));
    }

    /**
     * Switch application language.
     */
    public function setLocale(string $locale): RedirectResponse
    {
        if (in_array($locale, ['id', 'en'], true)) {
            session(['locale' => $locale]);
            app()->setLocale($locale);
        }

        return back();
    }
}
