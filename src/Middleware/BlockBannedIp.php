<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Middleware;

use Closure;
use Godrade\LaravelBan\Support\IpBanLookup;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks requests originating from a banned IP address.
 *
 * Repeated checks are shared with Blade and memoized for this request only.
 *
 * Usage:
 *   Route::middleware('ban.ip')->group(...)
 *   Route::middleware('ban.ip:api')->group(...)   // feature scope
 */
final class BlockBannedIp
{
    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        if (IpBanLookup::isBanned($request, $request->ip() ?? '', $feature)) {
            abort(Response::HTTP_FORBIDDEN, __('Your IP address has been banned.'));
        }

        return $next($request);
    }

    /** Explicitly invalidate request memoization, for example after bulk updates. */
    public static function flushCache(): void
    {
        IpBanLookup::flush();
    }
}
