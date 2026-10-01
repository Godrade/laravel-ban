<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Feature;

use Godrade\LaravelBan\Enums\BanStatus;
use Godrade\LaravelBan\Events\ModelBanned;
use Godrade\LaravelBan\Events\ModelUnbanned;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Models\BannedIp;
use Godrade\LaravelBan\Tests\Support\RelationalBanUser;
use Godrade\LaravelBan\Tests\Support\RelationalDatabase;
use Godrade\LaravelBan\Tests\Support\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Run against a dedicated database with BAN_TEST_DB_DRIVER=mysql or pgsql. */
final class RelationalDatabaseTest extends TestCase
{
    private bool $databasePrepared = false;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (RelationalDatabase::enabled()) {
            $app['config']->set(RelationalDatabase::settings());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! RelationalDatabase::enabled()) {
            $this->markTestSkipped('Set BAN_TEST_DB_DRIVER to mysql or pgsql to run database integration tests.');
        }

        $this->databasePrepared = true;
        $this->dropTables();
        $this->migrateTables();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->databasePrepared) {
                while (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                $this->dropTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_ban_sync_and_unban_with_real_migrations_and_cache(): void
    {
        $user = RelationalBanUser::create();
        $cause = RelationalBanUser::create();
        $expiry = now()->addHour()->startOfSecond();

        $this->assertFalse($user->isBannedFrom('comments'));
        $ban = $user->ban(['reason' => 'initial', 'expired_at' => $expiry, 'cause' => $cause]);
        $this->assertTrue($ban->isActive());
        $this->assertTrue($user->isBannedFrom('comments'));
        $this->assertTrue($ban->cause->is($cause));

        $updated = $user->syncBan(['reason' => 'updated']);
        $this->assertSame($ban->id, $updated->id);
        $this->assertSame('updated', $updated->reason);
        $this->assertTrue($updated->expired_at->equalTo($expiry));
        $this->assertSame(1, Ban::count());

        $user->ban(['feature' => 'chat']);
        $user->unban();
        $this->assertFalse($user->isBannedFrom('comments'));
        $this->assertTrue($user->isBannedFrom('chat'));
        $this->assertSame(BanStatus::CANCELLED, $ban->fresh()->status);

        $user->unban('chat');
        $this->assertFalse($user->isBannedFrom('chat'));
        $this->assertSame(2, Ban::cancelled()->count());
    }

    public function test_rollback_discards_changes_cache_and_domain_events(): void
    {
        $user = RelationalBanUser::create();
        Event::fake([ModelBanned::class]);
        $this->assertFalse($user->isBanned());

        DB::beginTransaction();
        $user->ban();
        $this->assertTrue($user->isBanned());
        Event::assertNotDispatched(ModelBanned::class);
        DB::rollBack();

        $this->assertFalse($user->isBanned());
        $this->assertSame(0, Ban::count());
        Event::assertNotDispatched(ModelBanned::class);
    }

    public function test_events_are_dispatched_after_outer_commit_with_current_state(): void
    {
        $user = RelationalBanUser::create();
        $observed = [];
        Event::listen(ModelBanned::class, function (ModelBanned $event) use (&$observed): void {
            $observed[] = [DB::transactionLevel(), $event->bannable->isBanned()];
        });
        Event::listen(ModelUnbanned::class, function (ModelUnbanned $event) use (&$observed): void {
            $observed[] = [DB::transactionLevel(), $event->bannable->isBanned()];
        });

        DB::beginTransaction();
        $user->ban();
        $this->assertSame([], $observed);
        DB::commit();
        $this->assertSame([[0, true]], $observed);

        DB::beginTransaction();
        $user->unban();
        $this->assertSame([[0, true]], $observed);
        DB::commit();
        $this->assertSame([[0, true], [0, false]], $observed);
    }

    public function test_models_work_without_soft_delete_columns(): void
    {
        $this->dropTables();
        config(['ban.soft_delete' => false]);
        $this->migrateTables();
        $this->assertFalse(Schema::hasColumn('integration_bans', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('integration_banned_ips', 'deleted_at'));

        $user = RelationalBanUser::create();
        $ban = $user->ban();
        $this->assertTrue($user->isBanned());
        $ban->delete();
        $this->assertFalse($user->isBanned());
        $this->assertSame(0, Ban::withTrashed()->count());

        $ip = BannedIp::create(['ip_address' => '203.0.113.41', 'created_by' => $user]);
        $this->assertTrue($ip->createdBy->is($user));
        $this->assertSame(1, BannedIp::active()->count());
        $ip->delete();
        $this->assertSame(0, BannedIp::withTrashed()->count());
    }

    public function test_ip_upgrade_preserves_history_and_removes_legacy_uniqueness(): void
    {
        // Recreate the legacy index on the table produced by the real migration.
        Schema::table('integration_banned_ips', function (Blueprint $table): void {
            $table->dropIndex(['ip_address']);
            $table->unique('ip_address', 'integration_legacy_ip_unique');
        });
        DB::table('integration_banned_ips')->insert([
            'ip_address' => '2001:0db8:0000:0000:0000:0000:0000:0001',
            'feature' => 'comments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require __DIR__.'/../../database/migrations/2026_10_01_000003_allow_multiple_bans_per_ip.php';
        $migration->up();
        $migration->up(); // Safe if the upgrade was already applied.

        $old = BannedIp::firstOrFail();
        $this->assertSame('2001:db8::1', $old->ip_address);
        $old->delete();
        BannedIp::create(['ip_address' => '2001:db8::1', 'feature' => 'comments']);
        BannedIp::create(['ip_address' => '2001:db8::1', 'feature' => 'chat']);

        $this->assertSame(3, BannedIp::withTrashed()->count());
        $this->assertSame(2, BannedIp::active()->forIp('2001:db8::1')->count());
        $this->assertTrue(Schema::hasIndex('integration_banned_ips', ['ip_address']));
        foreach (Schema::getIndexes('integration_banned_ips') as $index) {
            if ($index['columns'] === ['ip_address']) {
                $this->assertFalse($index['unique']);
            }
        }
    }

    public function test_concurrent_ban_calls_create_one_active_record(): void
    {
        $outcomes = $this->runConcurrentChanges('ban');

        $this->assertSame(['already banned', 'saved'], $outcomes);
        $this->assertSame(1, Ban::active()->count());
    }

    public function test_concurrent_sync_calls_update_one_active_record(): void
    {
        $outcomes = $this->runConcurrentChanges('syncBan');

        $this->assertSame(['saved', 'saved'], $outcomes);
        $this->assertSame(1, Ban::active()->count());
    }

    private function runConcurrentChanges(string $operation): array
    {
        $user = RelationalBanUser::create();
        $processes = [];
        $inputs = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $input = new InputStream;
                $process = new Process([
                    PHP_BINARY, __DIR__.'/../Support/run-relational-ban.php', $operation, (string) $user->id,
                ]);
                $process->setTimeout(20)->setInput($input);
                $process->start();
                $processes[] = $process;
                $inputs[] = $input;
            }

            foreach ($processes as $process) {
                $this->assertTrue(str_contains($process->getOutput(), 'ready')
                    || $process->waitUntil(fn (string $type, string $output) => str_contains($output, 'ready')));
            }

            foreach ($inputs as $index => $input) {
                $input->write("go\n");
                $input->close();
                $processes[$index]->isRunning(); // Pump both inputs before waiting.
            }

            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame('', $process->getErrorOutput());
                $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $outcomes[] = trim(str_replace("ready\n", '', $process->getOutput()));
            }
            sort($outcomes);

            return $outcomes;
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
        }
    }

    private function migrateTables(): void
    {
        Schema::create('integration_users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        foreach ([
            '2024_01_01_000001_create_bans_table.php',
            '2024_01_01_000002_create_banned_ips_table.php',
            '2026_10_01_000003_allow_multiple_bans_per_ip.php',
        ] as $file) {
            (require __DIR__.'/../../database/migrations/'.$file)->up();
        }
    }

    private function dropTables(): void
    {
        foreach (['integration_banned_ips', 'integration_bans', 'integration_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
