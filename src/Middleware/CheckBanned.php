<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Middleware;

use Closure;
use Illuminate\Http\Request;
use Godrade\LaravelBan\Traits\HasBans;
use Symfony\Component\HttpFoundation\Response;

final class CheckBanned
{
    /**
     * Handle an incoming request.
     *
     * Redirects any authenticated user that carries an active global ban.
     * For feature-scoped checks, use the @bannedFrom Blade directive or
     * call $user->isBannedFrom($feature) directly in your controllers.
     *
     * Usage in route definition:
     *   Route::middleware('banned')->group(...)
     *   Route::middleware('banned:comments')->group(...)  // feature scope
     */
    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $user = $request->user();

        if ($user !== null && $this->userIsBanned($user, $feature)) {
            return $this->buildRedirectResponse($request);
        }

        return $next($request);
    }

    private function userIsBanned(mixed $user, ?string $feature): bool
    {
        if (! in_array(HasBans::class, class_uses_recursive($user), strict: true)) {
            return false;
        }

        return $feature !== null
            ? $user->isBannedFrom($feature)
            : $user->isBanned();
    }

    private function buildRedirectResponse(Request $request): Response
    {
        $redirect = config('ban.redirect_url', 'login');

        // Support both named routes and plain URLs
        $url = filter_var($redirect, FILTER_VALIDATE_URL)
            ? $redirect
            : route($redirect);

        return redirect($url)
            ->with('ban_error', __('Your account has been suspended.'));
    }
}
