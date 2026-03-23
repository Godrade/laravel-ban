<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Godrade\LaravelBan\Models\Ban;

interface Bannable
{
    /**
     * Return all ban records for this model.
     */
    public function bans(): MorphMany;

    /**
     * Ban this model, optionally scoped to a feature.
     *
     * Returns the created {@see Ban} instance, or `null` if a recursive call
     * was detected (the static lock was already held for this instance).
     *
     * @param  array{
     *     reason?: string|null,
     *     expired_at?: \DateTimeInterface|string|null,
     *     feature?: string|null,
     *     created_by?: Model|null,
     * } $attributes
     */
    public function ban(array $attributes = []): ?Ban;

    /**
     * Synchronize the active ban for this model on the given scope.
     *
     * Returns the created or updated {@see Ban} instance, or `null` if a
     * recursive call was detected (the static lock was already held for this instance).
     *
     * @param  array{
     *     reason?: string|null,
     *     expired_at?: \DateTimeInterface|string|null,
     *     feature?: string|null,
     *     created_by?: Model|null,
     * } $attributes
     */
    public function syncBan(array $attributes = []): ?Ban;

    /**
     * Remove all active bans, optionally scoped to a feature.
     */
    public function unban(?string $feature = null): void;

    /**
     * Determine whether this model has an active global ban.
     */
    public function isBanned(): bool;

    /**
     * Determine whether this model is banned from a specific feature.
     */
    public function isBannedFrom(string $feature): bool;
}
