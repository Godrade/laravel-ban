<?php

declare(strict_types=1);

use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Models\BannedIp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function packageMigration(string $filename): Migration
{
    return require __DIR__.'/../../database/migrations/'.$filename.'.php';
}

it('runs the actual migrations with either deletion mode and custom table names', function (bool $softDeletes, string $prefix) {
    $bans = $prefix.'bans';
    $ips = $prefix.'banned_ips';
    config([
        'ban.soft_delete' => $softDeletes,
        'ban.table_names.bans' => $bans,
        'ban.table_names.banned_ips' => $ips,
    ]);

    $banMigration = packageMigration('2024_01_01_000001_create_bans_table');
    $ipMigration = packageMigration('2024_01_01_000002_create_banned_ips_table');
    $upgrade = packageMigration('2026_10_01_000003_allow_multiple_bans_per_ip');
    $banMigration->up();
    $ipMigration->up();
    $upgrade->up();
    $upgrade->up();

    expect(Schema::hasColumn($bans, 'deleted_at'))->toBe($softDeletes)
        ->and(Schema::hasColumn($ips, 'deleted_at'))->toBe($softDeletes);

    $ban = Ban::create(['bannable_type' => 'user', 'bannable_id' => 1]);
    $ip = BannedIp::create(['ip_address' => '192.0.2.1']);
    expect(Ban::active()->exists())->toBeTrue()
        ->and(BannedIp::active()->exists())->toBeTrue();

    $ban->delete();
    $ip->delete();
    expect(Ban::count())->toBe(0)
        ->and(BannedIp::count())->toBe(0)
        ->and(Ban::withTrashed()->count())->toBe($softDeletes ? 1 : 0)
        ->and(BannedIp::withTrashed()->count())->toBe($softDeletes ? 1 : 0)
        ->and(Ban::onlyTrashed()->count())->toBe($softDeletes ? 1 : 0)
        ->and(BannedIp::onlyTrashed()->count())->toBe($softDeletes ? 1 : 0)
        ->and(Ban::withoutTrashed()->count())->toBe(0)
        ->and(BannedIp::withoutTrashed()->count())->toBe(0)
        ->and($ban->trashed())->toBe($softDeletes)
        ->and($ip->trashed())->toBe($softDeletes);

    $upgrade->down();
    $ipMigration->down();
    $banMigration->down();
    expect(Schema::hasTable($ips))->toBeFalse()
        ->and(Schema::hasTable($bans))->toBeFalse();
})->with([
    'soft deletes, standard tables' => [true, ''],
    'hard deletes, standard tables' => [false, ''],
    'soft deletes, custom tables' => [true, 'custom_'],
    'hard deletes, custom tables' => [false, 'custom_'],
]);

it('supports bulk deletion and restore APIs when old tables have no deleted_at column', function () {
    config(['ban.soft_delete' => false]);
    packageMigration('2024_01_01_000001_create_bans_table')->up();
    packageMigration('2024_01_01_000002_create_banned_ips_table')->up();

    $ban = Ban::create(['bannable_type' => 'user', 'bannable_id' => 1]);
    $ip = BannedIp::create(['ip_address' => '192.0.2.1']);
    expect($ban->restore())->toBeFalse()
        ->and($ip->restore())->toBeFalse()
        ->and(Ban::query()->restore())->toBe(0)
        ->and(BannedIp::query()->restore())->toBe(0);

    expect(Ban::query()->delete())->toBe(1)
        ->and(BannedIp::query()->delete())->toBe(1)
        ->and(Ban::withTrashed()->count())->toBe(0)
        ->and(BannedIp::withTrashed()->count())->toBe(0);
});

it('preserves soft deletion restoration and permanent deletion', function () {
    packageMigration('2024_01_01_000001_create_bans_table')->up();
    packageMigration('2024_01_01_000002_create_banned_ips_table')->up();

    foreach ([
        Ban::create(['bannable_type' => 'user', 'bannable_id' => 1]),
        BannedIp::create(['ip_address' => '192.0.2.1']),
    ] as $model) {
        $model->delete();
        expect($model->isActive())->toBeFalse()
            ->and($model->restore())->toBeTrue()
            ->and($model->trashed())->toBeFalse()
            ->and($model->isActive())->toBeTrue();
        $model->forceDelete();
        expect($model->newQuery()->withTrashed()->count())->toBe(0);
    }
});

it('upgrades legacy IP uniqueness idempotently and preserves data on rollback', function (string $tableName) {
    config(['ban.table_names.banned_ips' => $tableName]);
    Schema::create($tableName, function (Blueprint $table): void {
        $table->id();
        $table->string('ip_address', 45)->unique()->index();
        $table->string('feature')->nullable();
        $table->text('reason')->nullable();
        $table->nullableMorphs('created_by');
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    DB::table($tableName)->insert([
        ['ip_address' => '2001:0db8:0:0:0:0:0:1', 'feature' => 'comments'],
        ['ip_address' => '2001:db8::1', 'feature' => 'forum'],
        ['ip_address' => 'legacy:invalid', 'feature' => null],
    ]);

    $upgrade = packageMigration('2026_10_01_000003_allow_multiple_bans_per_ip');
    $upgrade->up();
    $upgrade->up();
    expect(BannedIp::forIp('2001:db8::1')->count())->toBe(2)
        ->and(BannedIp::where('ip_address', 'legacy:invalid')->count())->toBe(1);

    BannedIp::create(['ip_address' => '2001:db8::1', 'feature' => 'chat']);
    $upgrade->down();
    expect(BannedIp::count())->toBe(4)
        ->and(Schema::hasIndex($tableName, ['ip_address']))->toBeTrue();

    $upgrade->up();
    expect(BannedIp::count())->toBe(4);
})->with(['banned_ips', 'custom_banned_ips']);

it('allows feature-specific IP bans and a new ban after soft deletion', function () {
    packageMigration('2024_01_01_000002_create_banned_ips_table')->up();
    $old = BannedIp::create(['ip_address' => '192.0.2.1', 'feature' => 'comments']);
    BannedIp::create(['ip_address' => '192.0.2.1', 'feature' => 'forum']);
    $old->delete();
    BannedIp::create(['ip_address' => '192.0.2.1', 'feature' => 'comments']);

    expect(BannedIp::active()->forIp('192.0.2.1')->count())->toBe(2)
        ->and(BannedIp::withTrashed()->forIp('192.0.2.1')->count())->toBe(3);
});
