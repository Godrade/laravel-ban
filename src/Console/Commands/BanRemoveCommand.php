<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Console\Commands;

use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Console\Command;

final class BanRemoveCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ban:remove
        {id           : The technical ID of the ban record to delete}
        {--force      : Permanently delete even if soft-delete is enabled}
        {--no-confirm : Skip the confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Remove a ban record by its ID (with confirmation).';

    public function handle(): int
    {
        $id  = $this->argument('id');
        $ban = Ban::withTrashed()->find($id);

        if ($ban === null) {
            $this->error("No ban record found with ID [{$id}].");
            return self::FAILURE;
        }

        $this->displayBanSummary($ban);

        if (! $this->option('no-confirm')) {
            $confirmed = $this->confirm(
                $this->option('force')
                    ? "Permanently delete ban #{$ban->id}? This cannot be undone."
                    : "Delete ban #{$ban->id}?",
                default: false,
            );

            if (! $confirmed) {
                $this->line('<fg=yellow>Aborted.</>');
                return self::SUCCESS;
            }
        }

        if ($this->option('force') || ! config('ban.soft_delete', true)) {
            $ban->forceDelete();
            $this->info("Ban #{$ban->id} has been permanently deleted.");
        } else {
            $ban->delete();
            $this->info("Ban #{$ban->id} has been soft-deleted.");
        }

        // Flush the ban cache for the affected model
        $this->flushCacheForBan($ban);

        return self::SUCCESS;
    }

    private function displayBanSummary(Ban $ban): void
    {
        $this->table(
            ['Field', 'Value'],
            [
                ['ID',           $ban->id],
                ['Bannable',     "{$ban->bannable_type} #{$ban->bannable_id}"],
                ['Feature',      $ban->feature    ?? 'global'],
                ['Reason',       $ban->reason     ?? '—'],
                ['Expires at',   $ban->expired_at?->toDateTimeString() ?? 'permanent'],
                ['Created at',   $ban->created_at->toDateTimeString()],
                ['Deleted at',   $ban->deleted_at?->toDateTimeString() ?? '—'],
            ],
        );
    }

    private function flushCacheForBan(Ban $ban): void
    {
        // Attempt to load the bannable model and flush its cache if it uses HasBans
        try {
            /** @var class-string $class */
            $class = $ban->bannable_type;

            if (! class_exists($class)) {
                return;
            }

            if (! in_array(HasBans::class, class_uses_recursive($class), strict: true)) {
                return;
            }

            $bannable = $class::find($ban->bannable_id);
            $bannable?->flushBanCache($ban->feature); // flush cache without side effects
        } catch (\Throwable) {
            // Non-critical: cache will expire naturally
        }
    }
}
