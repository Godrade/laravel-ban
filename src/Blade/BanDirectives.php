<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Blade;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\Compilers\BladeCompiler;
use Godrade\LaravelBan\Models\BannedIp;
use Godrade\LaravelBan\Traits\HasBans;

final class BanDirectives
{
    /** Per-request memoization cache for IP ban lookups. */
    private static array $ipCache = [];

    public function register(BladeCompiler $blade): void
    {
        // @banned($model = null) ... @endbanned
        $blade->if('banned', function (mixed $model = null): bool {
            return $this->resolveModel($model)?->isBanned() ?? false;
        });

        // @notBanned($model = null) ... @endnotBanned
        $blade->if('notBanned', function (mixed $model = null): bool {
            return ! ($this->resolveModel($model)?->isBanned() ?? false);
        });

        // @bannedFrom($feature, $model = null) ... @endbannedFrom
        $blade->if('bannedFrom', function (string $feature, mixed $model = null): bool {
            return $this->resolveModel($model)?->isBannedFrom($feature) ?? false;
        });

        // @bannedIp($ip = null, $feature = null) ... @endbannedIp
        $blade->if('bannedIp', function (?string $ip = null, ?string $feature = null): bool {
            return $this->resolveIpBan($ip, $feature);
        });
    }

    /** Flush the static IP cache (required for Laravel Octane). */
    public static function flushIpCache(): void
    {
        self::$ipCache = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveModel(mixed $model): mixed
    {
        $target = $model ?? Auth::user();

        if ($target === null || ! $this->usesTrait($target)) {
            return null;
        }

        return $target;
    }

    private function resolveIpBan(?string $ip, ?string $feature): bool
    {
        $ip ??= request()->ip() ?? '';
        $cacheKey = $ip . ':' . ($feature ?? '');

        if (array_key_exists($cacheKey, self::$ipCache)) {
            return self::$ipCache[$cacheKey];
        }

        $query = BannedIp::active()->forIp($ip);

        if ($feature !== null) {
            $query->forFeature($feature);
        }

        return self::$ipCache[$cacheKey] = $query->exists();
    }

    private function usesTrait(mixed $model): bool
    {
        return in_array(HasBans::class, class_uses_recursive($model), strict: true);
    }
}
