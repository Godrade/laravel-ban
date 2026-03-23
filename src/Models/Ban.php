<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Godrade\LaravelBan\Enums\BanStatus;

/**
 * @property int                  $id
 * @property string               $bannable_type
 * @property int                  $bannable_id
 * @property string|null          $created_by_type
 * @property int|null             $created_by_id
 * @property string|null          $cause_type
 * @property int|null             $cause_id
 * @property string|null          $feature
 * @property string|null          $reason
 * @property BanStatus            $status
 * @property Carbon|null          $expired_at
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 * @property Carbon|null          $deleted_at
 *
 * @method static Builder active()
 * @method static Builder withStatus(string|\UnitEnum $status)
 * @method static Builder forFeature(string $feature)
 * @method static Builder global()
 */
final class Ban extends Model
{
    use MassPrunable, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'     => BanStatus::class,
            'expired_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('ban.table_names.bans', 'bans');
    }

    /** The model that is banned. */
    public function bannable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The model that created the ban (e.g. an admin). */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /** The polymorphic cause of this ban (e.g. a Report, Ticket or ModerationRule). */
    public function cause(): MorphTo
    {
        return $this->morphTo('cause');
    }

    /** Whether this ban has not yet expired and has not been cancelled. */
    public function isActive(): bool
    {
        return $this->status === BanStatus::ACTIVE
            && ($this->expired_at === null || $this->expired_at->isFuture());
    }

    /**
     * Prunable query: permanently remove expired bans older than 30 days.
     * Run via: php artisan model:prune --model="Godrade\LaravelBan\Models\Ban"
     * Or schedule: $schedule->command('model:prune')->daily();
     */
    public function prunable(): Builder
    {
        return static::where('expired_at', '<', now()->subDays(30));
    }

    /** Scope: only bans that are currently active (status = ACTIVE and not expired). */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', BanStatus::ACTIVE->value)
            ->where(function (Builder $q): void {
                $q->whereNull('expired_at')
                  ->orWhere('expired_at', '>', now());
            });
    }

    /** Scope: filter by an arbitrary status value or BanStatus enum case. */
    public function scopeWithStatus(Builder $query, string|\UnitEnum $status): Builder
    {
        $value = $status instanceof \UnitEnum ? $status->value : $status;

        return $query->where('status', $value);
    }

    /** Scope: bans tied to a specific feature. */
    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }

    /** Scope: global bans (no feature restriction). */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('feature');
    }
}
