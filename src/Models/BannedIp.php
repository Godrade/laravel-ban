<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property string      $ip_address
 * @property string|null $feature
 * @property string|null $reason
 * @property Carbon|null $expired_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static Builder active()
 * @method static Builder forIp(string $ip)
 * @method static Builder forFeature(string $feature)
 */
final class BannedIp extends Model
{
    use MassPrunable, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'expired_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ban.table_names.banned_ips', 'banned_ips');
    }

    /** The model that created the ban. */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    public function prunable(): Builder
    {
        return static::where('expired_at', '<', now()->subDays(30));
    }

    /** Whether this IP ban is currently active. */
    public function isActive(): bool
    {
        return $this->expired_at === null || $this->expired_at->isFuture();
    }

    /** Scope: only active bans. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expired_at')
              ->orWhere('expired_at', '>', now());
        });
    }

    /** Scope: filter by IP address. */
    public function scopeForIp(Builder $query, string $ip): Builder
    {
        return $query->where('ip_address', $ip);
    }

    /** Scope: filter by feature. */
    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->where(function (Builder $q) use ($feature): void {
            $q->whereNull('feature')->orWhere('feature', $feature);
        });
    }
}
