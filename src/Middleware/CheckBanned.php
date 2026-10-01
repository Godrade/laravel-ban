<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Middleware;

use Closure;
use Godrade\LaravelBan\Contracts\Bannable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

final class CheckBanned
{
    /**
     * Handle an incoming request.
     *
     * Rejects banned API requests with 403 and redirects browser requests.
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
        if (! $user instanceof Bannable) {
            return false;
        }

        return $feature !== null
            ? $user->isBannedFrom($feature)
            : $user->isBanned();
    }

    private function buildRedirectResponse(Request $request): Response
    {
        $message = __('Your account has been suspended.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        $redirect = config('ban.redirect_url', 'login');

        if (is_string($redirect) && (filter_var($redirect, FILTER_VALIDATE_URL) || str_starts_with($redirect, '/'))) {
            $url = url($redirect);
        } elseif (is_string($redirect) && Route::has($redirect)) {
            $url = route($redirect);
        } else {
            abort(Response::HTTP_FORBIDDEN, $message);
        }

        if (rtrim($request->url(), '/') === rtrim($url, '/')) {
            abort(Response::HTTP_FORBIDDEN, $message);
        }

        $response = redirect($url);

        if ($request->hasSession()) {
            $request->session()->flash('ban_error', $message);
        }

        return $response;
    }
}
