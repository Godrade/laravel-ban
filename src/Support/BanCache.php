<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Support;

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Models\Ban;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Cache each exact scope separately, so global changes cannot leave feature checks stale. */
final class BanCache
{
    public static function check(Model&Bannable $bannable, ?string $feature): bool
    {
        $connection = $bannable->getConnection();
        $ttl = (int) config('ban.cache_ttl', 3600);
        $cacheable = $ttl > 0 && $connection->transactionLevel() === 0;
        $key = self::key($connection, $bannable->getMorphClass(), $bannable->getKey(), $feature);

        if ($cacheable) {
            $key = self::versionedKey($key, $ttl);
            $cached = self::store()->get($key);

            if (is_array($cached) && ($cached['valid_until'] ?? 0) > now()->getTimestamp()) {
                return $cached['banned'];
            }
        }

        // An aggregate avoids loading all overlapping bans and finds the earliest
        // point at which this answer might change without a database write.
        $state = $bannable->bans()->active()->where('feature', $feature)->useWritePdo()
            ->selectRaw('COUNT(*) AS ban_count, MIN(expired_at) AS next_expiration')
            ->toBase()->first();
        $banned = (int) $state->ban_count > 0;

        if ($cacheable) {
            $validUntil = now()->getTimestamp() + $ttl;

            if ($state->next_expiration !== null) {
                $validUntil = min($validUntil, Carbon::parse($state->next_expiration)->getTimestamp());
            }

            if ($validUntil > now()->getTimestamp()) {
                self::store()->put($key, [
                    'banned' => $banned,
                    'valid_until' => $validUntil,
                ], $validUntil - now()->getTimestamp());
            }
        }

        return $banned;
    }

    public static function forget(Model $bannable, ?string $feature): void
    {
        self::invalidate($bannable->getConnection(), [
            self::key($bannable->getConnection(), $bannable->getMorphClass(), $bannable->getKey(), $feature),
        ]);
    }

    /** Invalidate both sides when a ban moves between owners or feature scopes. */
    public static function changed(Ban $ban): void
    {
        $connection = $ban->getConnection();
        $keys = [];

        foreach ([$ban->getRawOriginal(), $ban->getAttributes()] as $attributes) {
            if (isset($attributes['bannable_type'], $attributes['bannable_id'])) {
                $keys[] = self::key(
                    $connection,
                    $attributes['bannable_type'],
                    $attributes['bannable_id'],
                    $attributes['feature'] ?? null,
                );
            }
        }

        self::invalidate($connection, array_unique($keys));
    }

    private static function invalidate(Connection $connection, array $keys): void
    {
        if ((int) config('ban.cache_ttl', 3600) <= 0) {
            return;
        }

        $store = self::store();
        $ttl = (int) config('ban.cache_ttl', 3600);
        $forget = static function () use ($store, $keys, $ttl): void {
            foreach ($keys as $key) {
                $store->put($key.':version', bin2hex(random_bytes(16)), $ttl);
            }
        };

        $forget();

        // Other connections can refill the old committed state while a write is
        // pending. Invalidate again on commit; rollback discards this callback.
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($forget);
        }
    }

    private static function versionedKey(string $key, int $ttl): string
    {
        $store = self::store();
        $versionKey = $key.':version';
        $version = $store->get($versionKey);

        if (! is_string($version)) {
            $candidate = bin2hex(random_bytes(16));
            $store->add($versionKey, $candidate, $ttl);
            $version = $store->get($versionKey, $candidate);
        }

        // A query started before invalidation may finish afterwards. Keeping its
        // result under the old version prevents it from restoring stale state.
        return $key.':'.$version;
    }

    private static function key(Connection $connection, string $type, mixed $id, ?string $feature): string
    {
        return config('ban.cache_prefix', 'laravel_ban_').'v2_'.hash('sha256', serialize([
            $connection->getName(),
            $connection->getDatabaseName(),
            $connection->getTablePrefix(),
            config('ban.table_names.bans', 'bans'),
            $type,
            (string) $id,
            $feature,
        ]));
    }

    private static function store(): Repository
    {
        return Cache::store(config('ban.cache_driver'));
    }
}
