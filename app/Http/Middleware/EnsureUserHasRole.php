<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('messages.auth.account_inactive'),
            ]);
        }

        // Check if user has any of the accepted roles
        $hasAccess = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                $hasAccess = true;
                break;
            }
        }

        if (! $hasAccess) {
            ActivityLog::record(
                action: 'auth.unauthorized_access',
                description: "Upaya akses tidak berwenang ke [{$request->path()}] oleh {$user->name}.",
                properties: ['path' => $request->path(), 'required_roles' => $roles],
                user: $user,
                module: 'auth'
            );

            // Cashier attempting to reach owner dashboard
            if ($user->isCashier()) {
                return redirect()->route('pos')->with('warning', 'Akses dialihkan: Anda berada di mode Kasir.');
            }

            abort(403, 'Unauthorized access to this resource.');
        }

        return $next($request);
    }
}
