<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Traits;

use Closure;
use DateTimeInterface;
use Godrade\LaravelBan\Enums\BanStatus;
use Godrade\LaravelBan\Events\ModelBanned;
use Godrade\LaravelBan\Events\ModelBanUpdated;
use Godrade\LaravelBan\Events\ModelUnbanned;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Support\BanCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Adds ban/unban capability to any persisted Eloquent model.
 *
 * @mixin Model
 */
trait HasBans
{
    /** Guard listeners that re-enter through another instance of the same model. */
    protected static array $executingBans = [];

    /** @return MorphMany<Ban, $this> */
    public function bans(): MorphMany
    {
        return $this->morphMany(Ban::class, 'bannable');
    }

    /**
     * Ban this model. Recursive calls for the same database identity return null.
     *
     * @param array{
     *     reason?: string|null,
     *     expired_at?: DateTimeInterface|string|null,
     *     feature?: string|null,
     *     created_by?: Model|null,
     *     cause?: Model|null,
     * } $attributes
     */
    public function ban(array $attributes = []): ?Ban
    {
        return $this->withBanLock(function () use ($attributes): Ban {
            $feature = $attributes['feature'] ?? null;

            if (! config('ban.allow_overlapping_bans', false)) {
                $existing = $this->bans()->active()->where('feature', $feature)->lockForUpdate()->first();

                if ($existing !== null) {
                    throw new AlreadyBannedException($existing);
                }
            }

            $ban = $this->bans()->create($this->banPayload($attributes) + ['feature' => $feature]);
            $this->dispatchBanEvent(new ModelBanned($this, $ban));

            return $ban;
        });
    }

    /**
     * Create or update the active ban in this exact scope. Omitted attributes
     * are preserved on update; explicitly passing null clears an attribute.
     *
     * @param array{
     *     reason?: string|null,
     *     expired_at?: DateTimeInterface|string|null,
     *     feature?: string|null,
     *     created_by?: Model|null,
     *     cause?: Model|null,
     * } $attributes
     */
    public function syncBan(array $attributes = []): ?Ban
    {
        return $this->withBanLock(function () use ($attributes): Ban {
            $feature = $attributes['feature'] ?? null;
            $payload = $this->banPayload($attributes);
            $ban = $this->bans()->active()->where('feature', $feature)->lockForUpdate()->first();

            if ($ban !== null) {
                $originalAttributes = $ban->getOriginal();
                $ban->update($payload);
                $this->dispatchBanEvent(new ModelBanUpdated($this, $ban, $originalAttributes));
            } else {
                $ban = $this->bans()->create($payload + ['feature' => $feature]);
                $this->dispatchBanEvent(new ModelBanned($this, $ban));
            }

            return $ban;
        });
    }

    /** Cancel active bans in this exact scope; null targets global bans only. */
    public function unban(?string $feature = null): void
    {
        $this->withBanLock(function () use ($feature): void {
            $this->bans()->active()->where('feature', $feature)->lockForUpdate()->get()
                ->each(function (Ban $ban): void {
                    $ban->update(['status' => BanStatus::CANCELLED]);
                });

            $this->flushBanCache($feature);
            $this->dispatchBanEvent(new ModelUnbanned($this, $feature));
        });
    }

    public function isBanned(): bool
    {
        return BanCache::check($this, null);
    }

    /** Global bans also block every feature, without caching a combined answer. */
    public function isBannedFrom(string $feature): bool
    {
        return $this->isBanned() || BanCache::check($this, $feature);
    }

    /** Call after bulk query updates/deletes, which bypass Eloquent model events. */
    public function flushBanCache(?string $feature = null): void
    {
        BanCache::forget($this, $feature);
    }

    private function banPayload(array $attributes): array
    {
        $payload = array_intersect_key($attributes, array_flip(['reason', 'expired_at']));

        foreach (['created_by', 'cause'] as $relation) {
            if (array_key_exists($relation, $attributes)) {
                $related = $attributes[$relation];
                $payload[$relation.'_type'] = $related?->getMorphClass();
                $payload[$relation.'_id'] = $related?->getKey();
            }
        }

        return $payload;
    }

    /** Serialize changes on the parent row, including when no ban row exists yet. */
    private function withBanLock(Closure $callback): mixed
    {
        $lock = $this->banLockKey();

        if (isset(self::$executingBans[$lock])) {
            return null;
        }

        if (! $this->exists || $this->getKey() === null) {
            throw new LogicException('Save the model before changing its bans.');
        }

        self::$executingBans[$lock] = true;

        try {
            return $this->getConnection()->transaction(function () use ($callback) {
                $query = $this->newQueryWithoutScopes()->whereKey($this->getKey());

                if ($this->getConnection()->getDriverName() === 'sqlite') {
                    // SQLite ignores FOR UPDATE. Acquire its write lock before
                    // checking for bans, without changing timestamps or firing events.
                    $key = $this->getKeyName();
                    $query->toBase()->update([
                        $key => $this->getConnection()->raw($this->getConnection()->getQueryGrammar()->wrap($key)),
                    ]);
                }

                $query->lockForUpdate()->firstOrFail();

                return $callback();
            }, 3);
        } finally {
            unset(self::$executingBans[$lock]);
        }
    }

    private function banLockKey(): string
    {
        return hash('sha256', serialize([
            $this->getConnection()->getName(),
            $this->getConnection()->getDatabaseName(),
            $this->getConnection()->getTablePrefix(),
            $this->getTable(),
            (string) $this->getKey(),
        ]));
    }

    /** Dispatch only committed changes, retaining recursion protection in listeners. */
    private function dispatchBanEvent(object $event): void
    {
        $lock = $this->banLockKey();

        $this->getConnection()->afterCommit(static function () use ($event, $lock): void {
            $alreadyExecuting = isset(self::$executingBans[$lock]);
            self::$executingBans[$lock] = true;

            try {
                event($event);
            } finally {
                if (! $alreadyExecuting) {
                    unset(self::$executingBans[$lock]);
                }
            }
        });
    }
}
