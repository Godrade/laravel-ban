<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Console\Commands;

use Godrade\LaravelBan\Models\Ban;
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
        $id = $this->argument('id');
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

        return self::SUCCESS;
    }

    private function displayBanSummary(Ban $ban): void
    {
        $this->table(
            ['Field', 'Value'],
            [
                ['ID',           $ban->id],
                ['Bannable',     "{$ban->bannable_type} #{$ban->bannable_id}"],
                ['Feature',      $ban->feature ?? 'global'],
                ['Reason',       $ban->reason ?? '—'],
                ['Expires at',   $ban->expired_at?->toDateTimeString() ?? 'permanent'],
                ['Created at',   $ban->created_at->toDateTimeString()],
                ['Deleted at',   $ban->deleted_at?->toDateTimeString() ?? '—'],
            ],
        );
    }
}
