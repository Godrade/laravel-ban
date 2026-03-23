<?php

declare(strict_types=1);

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

class MaintenanceUser extends Model implements AuthenticatableContract, Bannable
{
    use HasBans, Authenticatable;

    protected $table   = 'users';
    protected $guarded = [];
    public $timestamps = false;

    public function getMorphClass(): string { return 'App\\Models\\User'; }
}

// ---------------------------------------------------------------------------
// Schema helpers
// ---------------------------------------------------------------------------

function maintenanceCreateSchema(): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('bans', function (Blueprint $table) {
        $table->id();
        $table->morphs('bannable');
        $table->nullableMorphs('created_by');
        $table->nullableMorphs('cause');
        $table->string('feature')->nullable();
        $table->text('reason')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function maintenanceDropSchema(): void
{
    Schema::dropIfExists('bans');
    Schema::dropIfExists('users');
}

function maintenanceUser(): MaintenanceUser
{
    return MaintenanceUser::create(['name' => 'Bob']);
}

// ---------------------------------------------------------------------------
// ban:list
// ---------------------------------------------------------------------------

describe('ban:list command', function () {

    beforeEach(fn () => maintenanceCreateSchema());
    afterEach(fn () => maintenanceDropSchema());

    it('exits successfully when there are no bans', function () {
        $exitCode = Artisan::call('ban:list');
        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('No bans found');
    });

    it('displays active bans in a table', function () {
        $user = maintenanceUser();
        $user->ban(['reason' => 'Spam', 'feature' => 'comments']);

        Artisan::call('ban:list');
        $output = Artisan::output();

        expect($output)
            ->toContain('App\\Models\\User')
            ->toContain('comments')
            ->toContain('Spam');
    });

    it('filters by feature with --feature option', function () {
        $user = maintenanceUser();
        $user->ban(['feature' => 'forum',    'reason' => 'Forum ban']);
        $user->ban(['feature' => 'comments', 'reason' => 'Comment ban']);

        Artisan::call('ban:list', ['--feature' => 'forum']);
        $output = Artisan::output();

        expect($output)
            ->toContain('Forum ban')
            ->not->toContain('Comment ban');
    });

    it('hides expired bans by default', function () {
        $user = maintenanceUser();
        $user->ban(['reason' => 'old ban', 'expired_at' => now()->subDay()]);
        $user->ban(['reason' => 'active ban']);

        Artisan::call('ban:list');
        $output = Artisan::output();

        expect($output)
            ->toContain('active ban')
            ->not->toContain('old ban');
    });

    it('includes expired bans with --expired flag', function () {
        $user = maintenanceUser();
        $user->ban(['reason' => 'old ban', 'expired_at' => now()->subDay()]);

        Artisan::call('ban:list', ['--expired' => true]);
        $output = Artisan::output();

        expect($output)->toContain('old ban');
    });

});

// ---------------------------------------------------------------------------
// ban:remove
// ---------------------------------------------------------------------------

describe('ban:remove command', function () {

    beforeEach(fn () => maintenanceCreateSchema());
    afterEach(fn () => maintenanceDropSchema());

    it('soft-deletes a ban when confirmed', function () {
        $user = maintenanceUser();
        $ban  = $user->ban(['reason' => 'To remove']);

        Artisan::call('ban:remove', ['id' => $ban->id, '--no-confirm' => true]);

        expect(Ban::withTrashed()->find($ban->id)?->deleted_at)->not->toBeNull();
    });

    it('permanently deletes a ban with --force', function () {
        $user = maintenanceUser();
        $ban  = $user->ban(['reason' => 'Force remove']);

        Artisan::call('ban:remove', ['id' => $ban->id, '--force' => true, '--no-confirm' => true]);

        expect(Ban::withTrashed()->find($ban->id))->toBeNull();
    });

    it('returns FAILURE when ban ID does not exist', function () {
        $exitCode = Artisan::call('ban:remove', ['id' => 99999, '--no-confirm' => true]);
        expect($exitCode)->toBe(1);
    });

    it('aborts without deleting when user declines confirmation', function () {
        $user = maintenanceUser();
        $ban  = $user->ban(['reason' => 'Keep me']);

        // No --no-confirm → prompt → fake "no" by not confirming (Artisan mock returns false)
        // We simulate decline by piping 'no' via the command test helper
        $this->artisan('ban:remove', ['id' => $ban->id])
            ->expectsConfirmation("Delete ban #{$ban->id}?", 'no')
            ->assertExitCode(0);

        expect(Ban::find($ban->id))->not->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// MassPrunable — prunable() query
// ---------------------------------------------------------------------------

describe('Ban MassPrunable', function () {

    beforeEach(fn () => maintenanceCreateSchema());
    afterEach(fn () => maintenanceDropSchema());

    it('includes bans expired more than 30 days ago in the prunable query', function () {
        Ban::create([
            'bannable_type' => 'App\\Models\\User',
            'bannable_id'   => 1,
            'expired_at'    => now()->subDays(31),
        ]);

        $prunable = (new Ban())->prunable()->get();
        expect($prunable)->toHaveCount(1);
    });

    it('excludes bans expired less than 30 days ago', function () {
        Ban::create([
            'bannable_type' => 'App\\Models\\User',
            'bannable_id'   => 1,
            'expired_at'    => now()->subDays(29),
        ]);

        $prunable = (new Ban())->prunable()->get();
        expect($prunable)->toHaveCount(0);
    });

    it('excludes permanent bans (expired_at = null)', function () {
        Ban::create([
            'bannable_type' => 'App\\Models\\User',
            'bannable_id'   => 1,
            'expired_at'    => null,
        ]);

        $prunable = (new Ban())->prunable()->get();
        expect($prunable)->toHaveCount(0);
    });

    it('excludes bans that are still active', function () {
        Ban::create([
            'bannable_type' => 'App\\Models\\User',
            'bannable_id'   => 1,
            'expired_at'    => now()->addDays(7),
        ]);

        $prunable = (new Ban())->prunable()->get();
        expect($prunable)->toHaveCount(0);
    });

});

// ---------------------------------------------------------------------------
// syncBan()
// ---------------------------------------------------------------------------

describe('HasBans::syncBan()', function () {

    beforeEach(fn () => maintenanceCreateSchema());
    afterEach(fn () => maintenanceDropSchema());

    it('creates a new ban when none exists', function () {
        $user = maintenanceUser();
        $ban  = $user->syncBan(['reason' => 'first sync']);

        expect($ban)->toBeInstanceOf(Ban::class)
            ->and($ban->reason)->toBe('first sync')
            ->and(Ban::count())->toBe(1);
    });

    it('updates the existing active ban instead of creating a duplicate', function () {
        $user = maintenanceUser();
        $user->syncBan(['reason' => 'original reason']);

        $updated = $user->syncBan(['reason' => 'updated reason']);

        expect(Ban::count())->toBe(1)
            ->and($updated->reason)->toBe('updated reason');
    });

    it('updates expired_at on the existing ban', function () {
        $user     = maintenanceUser();
        $original = $user->syncBan(['expired_at' => now()->addDay()]);

        $newExpiry  = now()->addDays(7);
        $synced = $user->syncBan(['expired_at' => $newExpiry]);

        expect(Ban::count())->toBe(1)
            ->and($synced->expired_at->toDateString())->toBe($newExpiry->toDateString());
    });

    it('does NOT throw AlreadyBannedException when a ban already exists', function () {
        $user = maintenanceUser();
        $user->ban(['reason' => 'original']);

        expect(fn () => $user->syncBan(['reason' => 'sync over existing']))
            ->not->toThrow(\Godrade\LaravelBan\Exceptions\AlreadyBannedException::class);
    });

    it('handles feature-scoped syncs independently', function () {
        $user = maintenanceUser();

        $user->syncBan(['feature' => 'forum',    'reason' => 'forum ban']);
        $user->syncBan(['feature' => 'comments', 'reason' => 'comment ban']);

        expect(Ban::count())->toBe(2);

        // Update only the forum ban
        $user->syncBan(['feature' => 'forum', 'reason' => 'forum ban updated']);

        expect(Ban::count())->toBe(2)
            ->and(Ban::where('feature', 'forum')->first()?->reason)->toBe('forum ban updated')
            ->and(Ban::where('feature', 'comments')->first()?->reason)->toBe('comment ban');
    });

    it('flushes the ban cache after sync so isBanned() reflects current state', function () {
        $user = maintenanceUser();

        // No ban yet → isBanned() must be false
        expect($user->isBanned())->toBeFalse();

        // Create ban via syncBan
        $user->syncBan(['reason' => 'initial']);

        // Cache must have been invalidated: isBanned() must now return true
        expect($user->isBanned())->toBeTrue();
    });

    it('creates a new ban when the existing one has expired', function () {
        $user = maintenanceUser();

        // Force-insert an expired ban directly (bypassing AlreadyBannedException)
        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'old expired',
            'expired_at'    => now()->subHour(),
        ]);

        $ban = $user->syncBan(['reason' => 'fresh ban']);

        expect(Ban::count())->toBe(2)
            ->and($ban->reason)->toBe('fresh ban');
    });

});
