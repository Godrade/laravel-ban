<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Godrade\LaravelBan\Traits\HasBans;

final class BanUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ban:user
        {id                  : The primary key of the model to ban}
        {--model=App\\Models\\User : Fully-qualified model class}
        {--duration=         : Ban duration in minutes (omit for permanent)}
        {--reason=           : Human-readable reason for the ban}
        {--feature=          : Scope the ban to a specific feature (e.g. comments)}';

    /**
     * @var string
     */
    protected $description = 'Ban a model (e.g. a user) by its primary key.';

    public function handle(): int
    {
        /** @var class-string $modelClass */
        $modelClass = $this->option('model');

        if (! class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] does not exist.");
            return self::FAILURE;
        }

        if (! in_array(HasBans::class, class_uses_recursive($modelClass), strict: true)) {
            $this->error("Model [{$modelClass}] does not use the HasBans trait.");
            return self::FAILURE;
        }

        try {
            $model = $modelClass::findOrFail($this->argument('id'));
        } catch (ModelNotFoundException) {
            $this->error("No record found for [{$modelClass}] with id [{$this->argument('id')}].");
            return self::FAILURE;
        }

        $expiredAt = $this->resolveExpiration();
        $reason    = $this->option('reason') ?: null;
        $feature   = $this->option('feature') ?: null;

        $ban = $model->ban([
            'reason'     => $reason,
            'expired_at' => $expiredAt,
            'feature'    => $feature,
        ]);

        $this->info("Model [{$modelClass}#{$model->getKey()}] has been banned (ban #{$ban->id}).");

        $this->table(
            ['Field', 'Value'],
            [
                ['Feature',    $ban->feature   ?? 'global'],
                ['Reason',     $ban->reason    ?? '—'],
                ['Expires at', $ban->expired_at?->toDateTimeString() ?? 'permanent'],
            ],
        );

        return self::SUCCESS;
    }

    private function resolveExpiration(): ?Carbon
    {
        $duration = $this->option('duration');

        if ($duration === null || $duration === '') {
            return null;
        }

        if (! ctype_digit((string) $duration) || (int) $duration <= 0) {
            $this->warn("Invalid duration [{$duration}]. Defaulting to permanent ban.");
            return null;
        }

        return now()->addMinutes((int) $duration);
    }
}
