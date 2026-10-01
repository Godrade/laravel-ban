<?php

declare(strict_types=1);

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Enums\BanStatus;
use Godrade\LaravelBan\Events\ModelBanned;
use Godrade\LaravelBan\Events\ModelBanUpdated;
use Godrade\LaravelBan\Events\ModelUnbanned;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Tests\Support\ConcurrentBanUser;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class CachedBanUser extends Model implements Bannable
{
    use HasBans;

    protected $table = 'cached_ban_users';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('cached_ban_users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    (require __DIR__.'/../../database/migrations/2024_01_01_000001_create_bans_table.php')->up();

    config([
        'ban.cache_ttl' => 3600,
        'ban.cache_driver' => 'array',
        'cache.stores.file.path' => sys_get_temp_dir().'/laravel-ban-cache-'.bin2hex(random_bytes(8)),
    ]);
});

afterEach(function () {
    File::deleteDirectory(config('cache.stores.file.path'));
    Relation::morphMap([], false);
});

it('applies and removes global bans after feature checks have been cached', function (string $driver) {
    config(['ban.cache_driver' => $driver]);
    $user = CachedBanUser::create();

    expect($user->isBannedFrom('comments'))->toBeFalse()
        ->and($user->isBannedFrom('chat'))->toBeFalse();

    $user->ban();

    expect($user->isBannedFrom('comments'))->toBeTrue()
        ->and($user->isBannedFrom('chat'))->toBeTrue();

    $user->unban();

    expect($user->isBannedFrom('comments'))->toBeFalse()
        ->and($user->isBannedFrom('chat'))->toBeFalse();
})->with(['array', 'file']);

it('keeps a feature literally named global separate from the global scope', function (string $driver) {
    config(['ban.cache_driver' => $driver]);
    $user = CachedBanUser::create();
    $user->ban(['feature' => 'global']);

    expect($user->isBannedFrom('global'))->toBeTrue()
        ->and($user->isBanned())->toBeFalse()
        ->and($user->isBannedFrom('comments'))->toBeFalse();
})->with(['array', 'file']);

it('stops applying a cached ban at its exact expiration', function (string $driver) {
    config(['ban.cache_driver' => $driver]);
    $this->freezeSecond();
    $user = CachedBanUser::create();
    $user->ban(['expired_at' => now()->addMinute()]);
    $user->ban(['feature' => 'comments', 'expired_at' => now()->addMinutes(2)]);

    expect($user->isBanned())->toBeTrue();
    $this->travel(1)->minutes();
    expect($user->isBanned())->toBeFalse()
        ->and($user->isBannedFrom('comments'))->toBeTrue();
    $this->travel(1)->minutes();
    expect($user->isBannedFrom('comments'))->toBeFalse();
})->with(['array', 'file']);

it('rechecks overlapping bans as each temporary ban expires', function () {
    config(['ban.allow_overlapping_bans' => true]);
    $this->freezeSecond();
    $user = CachedBanUser::create();
    $user->ban(['expired_at' => now()->addMinute()]);
    $user->ban(['expired_at' => now()->addMinutes(2)]);

    expect($user->isBanned())->toBeTrue();
    $this->travel(1)->minutes();
    expect($user->isBanned())->toBeTrue();
    $this->travel(1)->minutes();
    expect($user->isBanned())->toBeFalse();
});

it('invalidates cache for direct create update delete restore and forceDelete', function (string $driver) {
    config(['ban.cache_driver' => $driver]);
    $user = CachedBanUser::create();
    expect($user->isBanned())->toBeFalse();

    $ban = $user->bans()->create();
    expect($user->isBanned())->toBeTrue();

    $ban->update(['status' => BanStatus::CANCELLED]);
    expect($user->isBanned())->toBeFalse();
    $ban->update(['status' => BanStatus::ACTIVE]);
    expect($user->isBanned())->toBeTrue();

    $ban->delete();
    expect($user->isBanned())->toBeFalse()
        ->and($ban->isActive())->toBeFalse();
    $ban->restore();
    expect($user->isBanned())->toBeTrue();
    $ban->forceDelete();
    expect($user->isBanned())->toBeFalse();
})->with(['array', 'file']);

it('invalidates both old and new owners and scopes when a ban is reassigned', function () {
    $first = CachedBanUser::create();
    $second = CachedBanUser::create();
    $ban = $first->ban(['feature' => 'comments']);

    expect($first->isBannedFrom('comments'))->toBeTrue()
        ->and($second->isBanned())->toBeFalse();
    $ban->update(['bannable_id' => $second->id, 'feature' => null]);

    expect($first->isBannedFrom('comments'))->toBeFalse()
        ->and($second->isBanned())->toBeTrue();
});

it('invalidates direct model changes when a morph map is used', function () {
    Relation::morphMap(['cached-user' => CachedBanUser::class]);
    $user = CachedBanUser::create();
    $ban = $user->ban();
    expect($user->isBanned())->toBeTrue();
    $ban->delete();
    expect($user->isBanned())->toBeFalse();
});

it('never caches uncommitted results and drops domain events on rollback', function (string $driver) {
    config(['ban.cache_driver' => $driver]);
    Event::fake([ModelBanned::class]);
    $user = CachedBanUser::create();
    expect($user->isBanned())->toBeFalse();

    $connection = $user->getConnection();
    $connection->beginTransaction();
    $user->ban();
    expect($user->isBanned())->toBeTrue();
    Event::assertNotDispatched(ModelBanned::class);
    $connection->rollBack();

    expect($user->isBanned())->toBeFalse()
        ->and(Ban::count())->toBe(0);
    Event::assertNotDispatched(ModelBanned::class);
})->with(['array', 'file']);

it('dispatches events after the outer commit and keeps recursion guarded across instances', function () {
    $user = CachedBanUser::create();
    $recursiveResult = 'not called';
    $transactionLevels = [];
    Event::listen(ModelBanned::class, function (ModelBanned $event) use (&$recursiveResult, &$transactionLevels) {
        $transactionLevels[] = $event->bannable->getConnection()->transactionLevel();
        $recursiveResult = $event->bannable->fresh()->ban();
    });

    $connection = $user->getConnection();
    $connection->beginTransaction();
    $user->ban();
    expect($transactionLevels)->toBe([]);
    $connection->commit();

    expect($transactionLevels)->toBe([0])
        ->and($recursiveResult)->toBeNull()
        ->and(Ban::count())->toBe(1);
});

it('does not publish cancelled state when unban is rolled back', function () {
    $user = CachedBanUser::create();
    $user->ban();
    expect($user->isBanned())->toBeTrue();
    Event::fake([ModelUnbanned::class]);

    $connection = $user->getConnection();
    $connection->beginTransaction();
    $user->unban();
    expect($user->isBanned())->toBeFalse();
    $connection->rollBack();

    expect($user->isBanned())->toBeTrue();
    Event::assertNotDispatched(ModelUnbanned::class);
});

it('invalidates before update listeners read the new state', function () {
    $user = CachedBanUser::create();
    $user->ban();
    expect($user->isBanned())->toBeTrue();
    $observed = null;
    Event::listen(ModelBanUpdated::class, function (ModelBanUpdated $event) use (&$observed) {
        $observed = $event->bannable->isBanned();
    });

    $user->syncBan(['expired_at' => now()->subMinute()]);

    expect($observed)->toBeFalse();
});

it('supports causes and preserves omitted attributes during partial synchronization', function () {
    $user = CachedBanUser::create();
    $actor = CachedBanUser::create();
    $expiry = now()->addDay()->startOfSecond();
    $ban = $user->ban(['reason' => 'original', 'expired_at' => $expiry, 'created_by' => $actor, 'cause' => $actor]);

    expect($ban->status)->toBe(BanStatus::ACTIVE)
        ->and($ban->cause->is($actor))->toBeTrue();

    $updated = $user->syncBan(['reason' => 'updated']);
    expect($updated->id)->toBe($ban->id)
        ->and($updated->expired_at->equalTo($expiry))->toBeTrue()
        ->and($updated->createdBy->is($actor))->toBeTrue()
        ->and($updated->cause->is($actor))->toBeTrue();

    $cleared = $user->syncBan(['expired_at' => null, 'created_by' => null, 'cause' => null]);
    expect($cleared->expired_at)->toBeNull()
        ->and($cleared->createdBy)->toBeNull()
        ->and($cleared->cause)->toBeNull();
});

it('guards reentrant Eloquent listeners using another instance of the same user', function () {
    $user = CachedBanUser::create();
    $recursiveResult = 'not called';
    Ban::creating(function () use ($user, &$recursiveResult) {
        $recursiveResult = $user->fresh()->ban();
    });

    $user->ban();

    expect($recursiveResult)->toBeNull()
        ->and(Ban::count())->toBe(1);
});

it('allows explicitly invalidating cache after bulk operations', function () {
    $user = CachedBanUser::create();
    $user->ban(['feature' => 'comments']);
    expect($user->isBannedFrom('comments'))->toBeTrue();

    $user->bans()->where('feature', 'comments')->update(['status' => BanStatus::CANCELLED->value]);
    $user->flushBanCache('comments');

    expect($user->isBannedFrom('comments'))->toBeFalse();
});

it('does not let a late cache fill undo invalidation', function (string $driver) {
    config(['ban.cache_driver' => $driver]);
    $user = CachedBanUser::create();
    $interleaved = false;
    $user->getConnection()->listen(function ($query) use ($user, &$interleaved) {
        if (! $interleaved && str_contains($query->sql, 'COUNT(*) AS ban_count')) {
            $interleaved = true;
            // The SELECT already finished, but its old result has not been
            // written to cache yet. Simulate a writer committing at that point.
            $user->ban();
        }
    });

    expect($user->isBanned())->toBeFalse()
        ->and($interleaved)->toBeTrue()
        ->and($user->isBanned())->toBeTrue();
})->with(['array', 'file']);

it('serializes competing processes on the same SQLite model', function (string $operation) {
    $database = tempnam(sys_get_temp_dir(), 'laravel-ban-concurrent-');
    config([
        'database.default' => 'concurrency',
        'database.connections.concurrency' => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ],
    ]);
    Schema::create('concurrent_ban_users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    (require __DIR__.'/../../database/migrations/2024_01_01_000001_create_bans_table.php')->up();
    ConcurrentBanUser::create();
    $processes = [];
    $inputs = [];

    try {
        for ($index = 0; $index < 2; $index++) {
            $input = new InputStream;
            $process = new Process([
                PHP_BINARY, __DIR__.'/../Support/run-concurrent-ban.php', $database, $operation,
            ]);
            $process->setTimeout(15)->setInput($input);
            $process->start();
            $processes[] = $process;
            $inputs[] = $input;
        }

        foreach ($processes as $process) {
            expect(str_contains($process->getOutput(), 'ready')
                || $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready')))->toBeTrue();
        }

        foreach ($inputs as $index => $input) {
            $input->write("go\n");
            $input->close();
            $processes[$index]->isRunning(); // Pump stdin for both before waiting.
        }

        foreach ($processes as $process) {
            $process->wait();
            expect($process->getErrorOutput())->toBe('')
                ->and($process->getExitCode())->toBe(0);
        }

        $output = implode('', array_map(fn ($process) => $process->getOutput(), $processes));
        expect(Ban::count())->toBe(1)
            ->and(substr_count($output, 'saved'))->toBe($operation === 'ban' ? 1 : 2)
            ->and(substr_count($output, 'already banned'))->toBe($operation === 'ban' ? 1 : 0);
    } finally {
        foreach ($processes as $process) {
            $process->stop();
        }
        DB::disconnect('concurrency');
        unlink($database);
    }
})->with(['ban', 'syncBan']);

it('isolates identical model identifiers on separate database connections', function () {
    $first = CachedBanUser::create();
    $first->ban();
    expect($first->isBanned())->toBeTrue();

    config([
        'database.default' => 'other',
        'database.connections.other' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);
    Schema::create('cached_ban_users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    (require __DIR__.'/../../database/migrations/2024_01_01_000001_create_bans_table.php')->up();
    $second = CachedBanUser::create();

    expect($second->id)->toBe($first->id)
        ->and($second->isBanned())->toBeFalse()
        ->and($first->isBanned())->toBeTrue();
});

it('checks the primary database when the read replica has not received a ban yet', function () {
    $user = CachedBanUser::create();
    $connection = $user->getConnection();
    $replica = new PDO('sqlite::memory:');

    // Give the replica the real migrated schema, but leave its bans table empty.
    $schemas = $connection->getPdo()->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($schemas as $schema) {
        $replica->exec($schema);
    }
    $replica->exec('INSERT INTO cached_ban_users (id) VALUES (1)');
    $connection->setReadPdo($replica);

    expect($user->isBanned())->toBeFalse();
    $user->ban();

    expect(Ban::active()->count())->toBe(0)
        ->and(Ban::query()->useWritePdo()->active()->count())->toBe(1)
        ->and($user->isBanned())->toBeTrue()
        ->and($user->isBanned())->toBeTrue();

    $user->unban();
    expect($user->isBanned())->toBeFalse();
});

it('hydrates the configured default status before events without replacing explicit statuses', function () {
    config(['ban.statuses.default' => 'cancelled']);
    $user = CachedBanUser::create();
    $observed = null;
    Event::listen(ModelBanned::class, function (ModelBanned $event) use (&$observed) {
        $observed = $event->ban->status;
    });

    $ban = $user->ban();
    expect($ban->status)->toBe(BanStatus::CANCELLED)
        ->and($ban->fresh()->status)->toBe(BanStatus::CANCELLED)
        ->and($observed)->toBe(BanStatus::CANCELLED)
        ->and($user->isBanned())->toBeFalse();

    $explicit = $user->bans()->create(['status' => BanStatus::ACTIVE]);
    expect($explicit->status)->toBe(BanStatus::ACTIVE)
        ->and($explicit->fresh()->status)->toBe(BanStatus::ACTIVE)
        ->and($user->isBanned())->toBeTrue();
});

it('rejects unsupported default statuses before writing a ban', function () {
    config(['ban.statuses.default' => 'unsupported']);
    expect(fn () => new Ban)->toThrow(ValueError::class);
});
