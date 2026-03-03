<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Middleware;

use Closure;
use Godrade\LaravelBan\Models\BannedIp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks requests originating from a banned IP address.
 *
 * The result of the database check is memoized in a static array keyed by IP
 * so that multiple middleware calls within the same request cycle (e.g. nested
 * pipelines) never hit the database more than once.
 *
 * Usage:
 *   Route::middleware('ban.ip')->group(...)
 *   Route::middleware('ban.ip:api')->group(...)   // feature scope
 */
final class BlockBannedIp
{
    /**
     * Per-request memoization cache.
     * Key   : "{ip}:{feature}"
     * Value : bool (true = banned)
     *
     * @var array<string, bool>
     */
    private static array $cache = [];

    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        if ($this->ipIsBanned($request->ip() ?? '', $feature)) {
            abort(Response::HTTP_FORBIDDEN, __('Your IP address has been banned.'));
        }

        return $next($request);
    }

    private function ipIsBanned(string $ip, ?string $feature): bool
    {
        if ($ip === '') {
            return false;
        }

        $cacheKey = $ip . ':' . ($feature ?? '*');

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $query = BannedIp::active()->forIp($ip);

        if ($feature !== null) {
            $query->forFeature($feature);
        }

        return self::$cache[$cacheKey] = $query->exists();
    }

    /**
     * Flush the static memoization cache.
     *
     * Useful in tests or long-running processes (e.g. Laravel Octane) where
     * the static state must be reset between requests.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
