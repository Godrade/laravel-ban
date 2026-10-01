<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Console\Commands;

use Godrade\LaravelBan\Enums\BanStatus;
use Godrade\LaravelBan\Models\Ban;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

final class BanListCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ban:list
        {--feature=     : Filter by feature scope (omit for all bans)}
        {--expired      : Include expired bans (default: active only)}
        {--model=       : Filter by bannable model class (e.g. App\\Models\\User)}
        {--status=      : Filter by status (active, cancelled)}';

    /**
     * @var string
     */
    protected $description = 'List active (or all) ban records in a formatted table.';

    public function handle(): int
    {
        $query = Ban::query()->orderByDesc('id');
        $status = $this->option('status');

        if ($status !== null && BanStatus::tryFrom($status) === null) {
            $this->error('Invalid status. Use active or cancelled.');

            return self::FAILURE;
        }

        // An explicit cancelled filter includes the complete cancellation history.
        if ($status === BanStatus::CANCELLED->value) {
            $query->cancelled();
        } elseif (! $this->option('expired')) {
            $query->active();
        } elseif ($status !== null) {
            $query->withStatus($status);
        }

        // Optional feature filter
        $feature = $this->option('feature') ?: null;
        if ($feature !== null) {
            $query->forFeature($feature);
        }

        // Optional model class filter
        $model = $this->option('model') ?: null;
        if ($model !== null) {
            $type = is_subclass_of($model, Model::class) ? (new $model)->getMorphClass() : $model;
            $query->where('bannable_type', $type);
        }

        $bans = $query->get();

        if ($bans->isEmpty()) {
            $this->info('No bans found matching the given criteria.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Bannable Type', 'Bannable ID', 'Feature', 'Reason', 'Status', 'Expires at', 'Created at'],
            $bans->map(fn (Ban $ban) => [
                $ban->id,
                $ban->bannable_type,
                $ban->bannable_id,
                $ban->feature ?? '—',
                $this->truncate($ban->reason ?? '—', 40),
                $ban->status->value,
                $ban->expired_at?->toDateTimeString() ?? 'permanent',
                $ban->created_at->toDateTimeString(),
            ])->all(),
        );

        $this->line(sprintf(
            '<fg=gray>%d ban(s) shown%s%s%s.</>',
            $bans->count(),
            $feature !== null ? " · feature={$feature}" : '',
            $status === BanStatus::CANCELLED->value ? ' · cancellation history' : ($this->option('expired') ? ' · including expired' : ' · active only'),
            $status !== null ? " · status={$status}" : '',
        ));

        return self::SUCCESS;
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit - 1).'…'
            : $value;
    }
}
