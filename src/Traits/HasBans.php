<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Traits;

use DateTimeInterface;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;
use Godrade\LaravelBan\Events\ModelBanned;
use Godrade\LaravelBan\Events\ModelBanUpdated;
use Godrade\LaravelBan\Events\ModelUnbanned;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Godrade\LaravelBan\Models\Ban;

/**
 * Adds ban/unban capability to any Eloquent model.
 *
 * @mixin Model
 */
trait HasBans
{
    /**
     * Per-instance execution lock to prevent recursive ban/unban calls.
     * Keyed by spl_object_hash($this).
     */
    protected static array $executingBans = [];

    // -------------------------------------------------------------------------
    // Relationship
    // -------------------------------------------------------------------------

    public function bans(): MorphMany
    {
        return $this->morphMany(Ban::class, 'bannable');
    }

    // -------------------------------------------------------------------------
    // Core API
    // -------------------------------------------------------------------------

    /**
     * Ban this model.
     *
     * @param array{
     *     reason?: string|null,
     *     expired_at?: DateTimeInterface|string|null,
     *     feature?: string|null,
     *     created_by?: Model|null,
     * } $attributes
     */
    public function ban(array $attributes = []): Ban
    {
        $lock = spl_object_hash($this);

        if (isset(self::$executingBans[$lock])) {
            // @phpstan-ignore-next-line — intentional guard; caller must handle null
            return new Ban();
        }

        self::$executingBans[$lock] = true;

        try {
            $feature   = $attributes['feature'] ?? null;
            $createdBy = $attributes['created_by'] ?? null;

            if (! config('ban.allow_overlapping_bans', false)) {
                $existing = $this->bans()
                    ->active()
                    ->where('feature', $feature)
                    ->first();

                if ($existing !== null) {
                    throw new AlreadyBannedException($existing);
                }
            }

            /** @var Ban $ban */
            $ban = $this->bans()->create([
                'feature'         => $feature,
                'reason'          => $attributes['reason'] ?? null,
                'expired_at'      => $attributes['expired_at'] ?? null,
                'created_by_type' => $createdBy ? $createdBy->getMorphClass() : null,
                'created_by_id'   => $createdBy?->getKey(),
            ]);

            $this->flushBanCache($feature);

            event(new ModelBanned($this, $ban));

            return $ban;
        } finally {
            unset(self::$executingBans[$lock]);
        }
    }

    /**
     * Synchronize the active ban for this model on the given scope.
     *
     * - If an active ban already exists on the same feature, it is **updated**
     *   in place (reason, expired_at, created_by) and ModelBanUpdated is dispatched.
     * - If no active ban exists, a new one is **created** and ModelBanned is dispatched.
     *
     * Unlike ban(), this method never throws AlreadyBannedException.
     *
     * @param array{
     *     reason?: string|null,
     *     expired_at?: \DateTimeInterface|string|null,
     *     feature?: string|null,
     *     created_by?: \Illuminate\Database\Eloquent\Model|null,
     * } $attributes
     */
    public function syncBan(array $attributes = []): Ban
    {
        $lock = spl_object_hash($this);

        if (isset(self::$executingBans[$lock])) {
            // @phpstan-ignore-next-line
            return new Ban();
        }

        self::$executingBans[$lock] = true;

        try {
            $feature   = $attributes['feature'] ?? null;
            $createdBy = $attributes['created_by'] ?? null;

            $payload = [
                'reason'          => $attributes['reason'] ?? null,
                'expired_at'      => $attributes['expired_at'] ?? null,
                'created_by_type' => $createdBy ? $createdBy->getMorphClass() : null,
                'created_by_id'   => $createdBy?->getKey(),
            ];

            /** @var Ban|null $existing */
            $existing = $this->bans()
                ->active()
                ->where('feature', $feature)
                ->first();

            if ($existing !== null) {
                $originalAttributes = $existing->getOriginal();
                $existing->update($payload);
                $existing->refresh();
                $ban = $existing;

                event(new ModelBanUpdated($this, $ban, $originalAttributes));
            } else {
                /** @var Ban $ban */
                $ban = $this->bans()->create(array_merge($payload, ['feature' => $feature]));

                event(new ModelBanned($this, $ban));
            }

            $this->flushBanCache($feature);

            return $ban;
        } finally {
            unset(self::$executingBans[$lock]);
        }
    }

    /**
     * Remove active bans. Pass a feature to target only that scope,
     * or null to remove all global bans.
     */
    public function unban(?string $feature = null): void
    {
        $lock = spl_object_hash($this);

        if (isset(self::$executingBans[$lock])) {
            return;
        }

        self::$executingBans[$lock] = true;

        try {
            $query = $this->bans()->active();

            if ($feature !== null) {
                $query->forFeature($feature);
            } else {
                $query->global();
            }

            $query->get()->each->delete();

            $this->flushBanCache($feature);

            event(new ModelUnbanned($this, $feature));
        } finally {
            unset(self::$executingBans[$lock]);
        }
    }

    /**
     * Check whether the model has an active global ban.
     */
    public function isBanned(): bool
    {
        return $this->cachedBanCheck(null, fn(): bool => $this->bans()
            ->active()
            ->global()
            ->exists());
    }

    /**
     * Check whether the model is banned from a specific feature.
     * A global ban also counts as a ban from any feature.
     */
    public function isBannedFrom(string $feature): bool
    {
        return $this->cachedBanCheck($feature, fn(): bool => $this->bans()
            ->active()
            ->where(function ($query) use ($feature): void {
                $query->whereNull('feature')
                    ->orWhere('feature', $feature);
            })
            ->exists());
    }

    // -------------------------------------------------------------------------
    // Cache Helpers
    // -------------------------------------------------------------------------

    private function cachedBanCheck(?string $feature, \Closure $callback): bool
    {
        $ttl = (int)config('ban.cache_ttl', 3600);

        if ($ttl <= 0) {
            return $callback();
        }

        return $this->banCacheStore()->remember(
            $this->buildCacheKey($feature),
            $ttl,
            $callback,
        );
    }

    private function flushBanCache(?string $feature): void
    {
        $ttl = (int)config('ban.cache_ttl', 3600);

        if ($ttl <= 0) {
            return;
        }

        $store = $this->banCacheStore();

        // Always flush the global key
        $store->forget($this->buildCacheKey(null));

        // Flush the feature-specific key if relevant
        if ($feature !== null) {
            $store->forget($this->buildCacheKey($feature));
        }
    }

    private function buildCacheKey(?string $feature): string
    {
        $prefix = config('ban.cache_prefix', 'laravel_ban_');
        $morphClass = str_replace('\\', '_', $this->getMorphClass());
        $id = $this->getKey();
        $scope = $feature ?? 'global';

        return "{$prefix}{$morphClass}_{$id}_{$scope}";
    }

    private function banCacheStore(): Repository
    {
        $driver = config('ban.cache_driver');

        /** @var CacheRepository */
        return $driver !== null
            ? Cache::store($driver)
            : Cache::store();
    }
}

