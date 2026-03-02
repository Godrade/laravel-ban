<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Console\Commands;

use Illuminate\Console\Command;

final class BanConfigCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ban:config
        {--migrations : Also publish the migration files}';

    /**
     * @var string
     */
    protected $description = 'Publish the laravel-ban configuration and (optionally) migrations.';

    public function handle(): int
    {
        $tags = ['ban-config'];

        if ($this->option('migrations')) {
            $tags[] = 'ban-migrations';
            $this->info('Publishing config and migrations…');
        } else {
            $this->info('Publishing config…');
        }

        $this->callSilently('vendor:publish', [
            '--provider' => 'Godrade\\LaravelBan\\BanServiceProvider',
            '--tag'      => $tags,
        ]);

        $this->info('Done! Review the published files in config/ban.php and database/migrations/.');

        if (! $this->option('migrations')) {
            $this->line('Tip: run <comment>php artisan ban:config --migrations</comment> to also publish the migration files.');
        }

        return self::SUCCESS;
    }
}
