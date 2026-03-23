<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Blade;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\Compilers\BladeCompiler;
use Godrade\LaravelBan\Contracts\Bannable;
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

        // @anyBan('feature1', 'feature2', ..., $model = null) ... @endanyBan
        // Returns true if the model is banned from AT LEAST ONE of the given features.
        // With no features, falls back to a global ban check.
        $blade->if('anyBan', function (mixed ...$args): bool {
            [$model, $features] = $this->resolveVariadicArgs($args);

            if ($model === null) {
                return false;
            }

            if (empty($features)) {
                return $model->isBanned();
            }

            foreach ($features as $feature) {
                if ($model->isBannedFrom((string) $feature)) {
                    return true;
                }
            }

            return false;
        });

        // @allBanned('feature1', 'feature2', ..., $model = null) ... @endallBanned
        // Returns true only if the model is banned from ALL of the given features.
        // With no features, falls back to a global ban check.
        $blade->if('allBanned', function (mixed ...$args): bool {
            [$model, $features] = $this->resolveVariadicArgs($args);

            if ($model === null) {
                return false;
            }

            if (empty($features)) {
                return $model->isBanned();
            }

            foreach ($features as $feature) {
                if (! $model->isBannedFrom((string) $feature)) {
                    return false;
                }
            }

            return true;
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

    /**
     * Resolve variadic directive arguments into [$model, $features].
     *
     * If the last argument implements Bannable it is used as the explicit model
     * and removed from the features list. Otherwise auth()->user() is used.
     *
     * @param  array<mixed>  $args
     * @return array{0: Bannable|null, 1: list<string>}
     */
    private function resolveVariadicArgs(array $args): array
    {
        $model = null;

        if (! empty($args) && end($args) instanceof Bannable) {
            $model = array_pop($args);
        } else {
            $model = $this->resolveModel(null);
        }

        return [$model, array_values($args)];
    }

    private function resolveModel(mixed $model): ?Bannable
    {
        $target = $model ?? Auth::user();

        if ($target === null || ! $this->usesTrait($target)) {
            return null;
        }

        /** @var Bannable $target */
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
