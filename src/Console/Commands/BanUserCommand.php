<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Console\Commands;

use Carbon\Carbon;
use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

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
        $modelClass = $this->option('model');

        if (! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->error("Model class [{$modelClass}] does not exist.");

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, Bannable::class)) {
            $this->error("Model [{$modelClass}] must implement the Bannable contract.");

            return self::FAILURE;
        }

        try {
            $model = $modelClass::query()->whereKey($this->argument('id'))->firstOrFail();
        } catch (ModelNotFoundException) {
            $this->error("No record found for [{$modelClass}] with id [{$this->argument('id')}].");

            return self::FAILURE;
        }

        try {
            $expiredAt = $this->resolveExpiration();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $reason = $this->option('reason') ?: null;
        $feature = $this->option('feature') ?: null;

        try {
            $ban = $model->ban([
                'reason' => $reason,
                'expired_at' => $expiredAt,
                'feature' => $feature,
            ]);
        } catch (AlreadyBannedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($ban === null) {
            $this->error('The ban was not created because another ban operation is already running for this model.');

            return self::FAILURE;
        }

        $this->info("Model [{$modelClass}#{$model->getKey()}] has been banned (ban #{$ban->id}).");

        $this->table(
            ['Field', 'Value'],
            [
                ['Feature',    $ban->feature ?? 'global'],
                ['Reason',     $ban->reason ?? '—'],
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

        if (! ctype_digit((string) $duration) || filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 60)]]) === false) {
            throw new InvalidArgumentException("Invalid duration [{$duration}]. Use a positive integer number of minutes.");
        }

        return now()->addMinutes((int) $duration);
    }
}
