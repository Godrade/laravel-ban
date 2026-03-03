<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Traits;

use DateTimeInterface;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;
use Godrade\LaravelBan\Events\UserBanned;
use Godrade\LaravelBan\Events\UserUnbanned;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Godrade\LaravelBan\Models\Ban;

/**
 * Adds ban/unban capability to any Eloquent model.
 *
 * @mixin Model
 */
trait HasBans
{
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

        event(new UserBanned($this, $ban));

        return $ban;
    }

    /**
     * Synchronize the active ban for this model on the given scope.
     *
     * - If an active ban already exists on the same feature, it is **updated**
     *   in place (reason, expired_at, created_by).
     * - If no active ban exists, a new one is **created**.
     *
     * Unlike ban(), this method never throws AlreadyBannedException, making it
     * safe for idempotent operations (e.g. API upserts, scheduled tasks).
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
            $existing->update($payload);
            $existing->refresh();
            $ban = $existing;
        } else {
            /** @var Ban $ban */
            $ban = $this->bans()->create(array_merge($payload, ['feature' => $feature]));
            event(new UserBanned($this, $ban));
        }

        $this->flushBanCache($feature);

        return $ban;
    }

    /**
     * Remove active bans. Pass a feature to target only that scope,
     * or null to remove all global bans.
     */
    public function unban(?string $feature = null): void
    {
        $query = $this->bans()->active();

        if ($feature !== null) {
            $query->forFeature($feature);
        } else {
            $query->global();
        }

        $query->get()->each->delete();

        $this->flushBanCache($feature);

        event(new UserUnbanned($this, $feature));
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
