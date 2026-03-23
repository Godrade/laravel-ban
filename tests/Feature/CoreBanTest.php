<?php

declare(strict_types=1);

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Enums\BanStatus;
use Godrade\LaravelBan\Events\ModelBanned;
use Godrade\LaravelBan\Events\ModelBanUpdated;
use Godrade\LaravelBan\Events\ModelUnbanned;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Test stub
// ---------------------------------------------------------------------------

class CoreBanUser extends Model implements AuthenticatableContract, Bannable
{
    use HasBans, Authenticatable;

    protected $table   = 'ban_users';
    protected $guarded = [];
}

// ---------------------------------------------------------------------------
// Schema / config helpers
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::create('ban_users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });

    Schema::create('bans', function (Blueprint $table) {
        $table->id();
        $table->nullableMorphs('bannable');
        $table->nullableMorphs('created_by');
        $table->nullableMorphs('cause');
        $table->string('feature', 50)->nullable()->index();
        $table->text('reason')->nullable();
        $table->string('status', 50)->default('active')->index();
        $table->timestamp('expired_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    config(['ban.allow_overlapping_bans' => false]);
    config(['ban.cache_ttl' => 0]);
});

afterEach(function () {
    Schema::dropIfExists('ban_users');
    Schema::dropIfExists('bans');
});

function banUser(): CoreBanUser
{
    return CoreBanUser::create([]);
}

// ---------------------------------------------------------------------------
// 1. ban() basics
// ---------------------------------------------------------------------------

describe('ban() basics', function () {

    it('creates a Ban record with correct attributes', function () {
        $user   = banUser();
        $expiry = now()->addDay();

        $ban = $user->ban([
            'reason'     => 'Spam',
            'expired_at' => $expiry,
            'feature'    => 'comments',
        ]);

        expect($ban)->toBeInstanceOf(Ban::class)
            ->and($ban->reason)->toBe('Spam')
            ->and($ban->feature)->toBe('comments')
            ->and($ban->expired_at->toDateString())->toBe($expiry->toDateString());
    });

    it('returns a Ban instance on success', function () {
        $ban = banUser()->ban(['reason' => 'test']);

        expect($ban)->toBeInstanceOf(Ban::class);
    });

    it('sets status = active', function () {
        $ban = banUser()->ban(['reason' => 'status check']);

        // Refresh from DB so the database default ('active') is cast to BanStatus::ACTIVE
        expect($ban->fresh()->status)->toBe(BanStatus::ACTIVE);
    });

    it('persists the ban to the database', function () {
        $user = banUser();
        $ban  = $user->ban(['reason' => 'db check']);

        expect(Ban::find($ban->id))->not->toBeNull()
            ->and(Ban::count())->toBe(1);
    });

    it('dispatches ModelBanned event', function () {
        Event::fake([ModelBanned::class]);

        $user = banUser();
        $ban  = $user->ban(['reason' => 'event test']);

        Event::assertDispatched(ModelBanned::class, function (ModelBanned $e) use ($user, $ban) {
            return $e->bannable->is($user) && $e->ban->is($ban);
        });
    });

    it('exposes the feature on the ModelBanned event', function () {
        Event::fake([ModelBanned::class]);

        $user = banUser();
        $user->ban(['feature' => 'chat', 'reason' => 'feature event test']);

        Event::assertDispatched(ModelBanned::class, fn (ModelBanned $e) => $e->feature === 'chat');
    });

    it('returns null on recursive call (lock guard)', function () {
        $user   = banUser();
        $result = null;

        // This listener fires synchronously inside ban(), while the lock is still held.
        Event::listen(ModelBanned::class, function (ModelBanned $event) use (&$result) {
            $result = $event->bannable->ban(['reason' => 'recursive']);
        });

        $user->ban(['reason' => 'initial']);

        expect($result)->toBeNull();
    });

    it('stores the ban check result in cache when cache_ttl > 0', function () {
        config(['ban.cache_ttl' => 3600]);

        $user = banUser();
        $user->ban(['reason' => 'cached']);

        // Warm the cache by calling isBanned()
        expect($user->isBanned())->toBeTrue();

        // Remove all ban records from the database
        Ban::withTrashed()->forceDelete();

        // Cache should still report banned (result was cached)
        expect($user->isBanned())->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// 2. AlreadyBannedException
// ---------------------------------------------------------------------------

describe('AlreadyBannedException', function () {

    it('throws when trying to ban an already-banned model', function () {
        $user = banUser();
        $user->ban(['reason' => 'first ban']);

        expect(fn () => $user->ban(['reason' => 'second ban']))
            ->toThrow(AlreadyBannedException::class);
    });

    it('exception has correct $existingBan property', function () {
        $user     = banUser();
        $firstBan = $user->ban(['reason' => 'first ban']);

        $caught = null;
        try {
            $user->ban(['reason' => 'second ban']);
        } catch (AlreadyBannedException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->existingBan->id)->toBe($firstBan->id);
    });

    it('exception message references the existing ban scope', function () {
        $user = banUser();
        $user->ban(['reason' => 'global ban']);

        $caught = null;
        try {
            $user->ban(['reason' => 'duplicate']);
        } catch (AlreadyBannedException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->getMessage())->toContain('globally');
    });

    it('exception message mentions feature when ban is feature-scoped', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum ban']);

        $caught = null;
        try {
            $user->ban(['feature' => 'forum', 'reason' => 'duplicate']);
        } catch (AlreadyBannedException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->getMessage())->toContain('forum');
    });

    it('does NOT throw when allow_overlapping_bans = true', function () {
        config(['ban.allow_overlapping_bans' => true]);

        $user = banUser();
        $user->ban(['reason' => 'first ban']);

        expect(fn () => $user->ban(['reason' => 'second ban']))
            ->not->toThrow(AlreadyBannedException::class);
    });

    it('is feature-scoped: throws only within the same feature', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum ban']);

        // Same feature → throws
        expect(fn () => $user->ban(['feature' => 'forum', 'reason' => 'duplicate forum ban']))
            ->toThrow(AlreadyBannedException::class);
    });

    it('is feature-scoped: does NOT throw for a different feature', function () {
        config(['ban.allow_overlapping_bans' => false]);

        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum ban']);

        // Different feature → does not throw
        expect(fn () => $user->ban(['feature' => 'comments', 'reason' => 'comment ban']))
            ->not->toThrow(AlreadyBannedException::class);
    });

});

// ---------------------------------------------------------------------------
// 3. unban() basics
// ---------------------------------------------------------------------------

describe('unban() basics', function () {

    it('sets status = cancelled on the active ban (does NOT delete the record)', function () {
        $user = banUser();
        $ban  = $user->ban(['reason' => 'to cancel']);

        $user->unban();

        $fresh = $ban->fresh();
        expect($fresh)->not->toBeNull()
            ->and($fresh->status)->toBe(BanStatus::CANCELLED);
    });

    it('does not hard-delete the ban record', function () {
        $user = banUser();
        $ban  = $user->ban(['reason' => 'to remove']);

        $user->unban();

        expect(Ban::withTrashed()->find($ban->id))->not->toBeNull();
    });

    it('feature-scoped unban only affects that feature', function () {
        config(['ban.allow_overlapping_bans' => true]);

        $user = banUser();
        $user->ban(['feature' => 'forum',    'reason' => 'forum ban']);
        $user->ban(['feature' => 'comments', 'reason' => 'comment ban']);

        $user->unban('forum');

        expect(Ban::where('feature', 'forum')->first()->status)->toBe(BanStatus::CANCELLED)
            ->and(Ban::where('feature', 'comments')->first()->status)->toBe(BanStatus::ACTIVE);
    });

    it('dispatches ModelUnbanned event', function () {
        Event::fake([ModelUnbanned::class]);

        $user = banUser();
        $user->ban(['reason' => 'unban event test']);
        $user->unban();

        Event::assertDispatched(ModelUnbanned::class, function (ModelUnbanned $e) use ($user) {
            return $e->bannable->is($user) && $e->feature === null;
        });
    });

    it('dispatches ModelUnbanned with feature name when feature-scoped', function () {
        Event::fake([ModelUnbanned::class]);

        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum ban']);
        $user->unban('forum');

        Event::assertDispatched(ModelUnbanned::class, fn (ModelUnbanned $e) => $e->feature === 'forum');
    });

    it('flushes cache after unban so isBanned() reflects current state', function () {
        config(['ban.cache_ttl' => 3600]);

        $user = banUser();
        $user->ban(['reason' => 'to unban']);

        // Warm the cache → true
        expect($user->isBanned())->toBeTrue();

        $user->unban();

        // Cache was flushed by unban(); fresh query returns false
        expect($user->isBanned())->toBeFalse();
    });

    it('returns null on recursive call (lock guard)', function () {
        $user = banUser();
        $user->ban(['reason' => 'initial']);

        $called = false;

        Event::listen(ModelUnbanned::class, function (ModelUnbanned $event) use (&$called) {
            $called = true;
            // Recursive unban inside the event — lock is still held, should be a no-op
            $event->bannable->unban();
        });

        // Should not throw even with recursive call
        $user->unban();

        expect($called)->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// 4. isBanned()
// ---------------------------------------------------------------------------

describe('isBanned()', function () {

    it('returns false for an unbanned model', function () {
        expect(banUser()->isBanned())->toBeFalse();
    });

    it('returns true for a banned model', function () {
        $user = banUser();
        $user->ban(['reason' => 'banned']);

        expect($user->isBanned())->toBeTrue();
    });

    it('returns false after unban()', function () {
        $user = banUser();
        $user->ban(['reason' => 'to remove']);
        $user->unban();

        expect($user->isBanned())->toBeFalse();
    });

    it('returns false for an expired ban (expired_at in the past)', function () {
        $user = banUser();
        $user->ban(['reason' => 'expired', 'expired_at' => now()->subSecond()]);

        expect($user->isBanned())->toBeFalse();
    });

    it('returns false for a cancelled ban', function () {
        $user = banUser();
        $ban  = $user->ban(['reason' => 'cancelled']);

        $ban->update(['status' => BanStatus::CANCELLED->value]);

        expect($user->isBanned())->toBeFalse();
    });

    it('returns true when ban has a future expiry', function () {
        $user = banUser();
        $user->ban(['reason' => 'still active', 'expired_at' => now()->addDay()]);

        expect($user->isBanned())->toBeTrue();
    });

    it('only checks global (null feature) bans, not feature-scoped bans', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'feature-only ban']);

        // A feature-scoped ban should NOT cause isBanned() to return true
        expect($user->isBanned())->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// 5. isBannedFrom($feature)
// ---------------------------------------------------------------------------

describe('isBannedFrom()', function () {

    it('returns false when not banned from that feature', function () {
        expect(banUser()->isBannedFrom('forum'))->toBeFalse();
    });

    it('returns true when banned from that specific feature', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum ban']);

        expect($user->isBannedFrom('forum'))->toBeTrue();
    });

    it('returns true when globally banned (null-feature ban matches any feature)', function () {
        $user = banUser();
        $user->ban(['reason' => 'global ban']);

        expect($user->isBannedFrom('forum'))->toBeTrue();
    });

    it('returns false after unban() for that specific feature', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum ban']);
        $user->unban('forum');

        expect($user->isBannedFrom('forum'))->toBeFalse();
    });

    it('returns false when banned from a different feature', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum only']);

        expect($user->isBannedFrom('comments'))->toBeFalse();
    });

    it('feature-scoped ban does NOT affect isBanned() (global check)', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'forum only']);

        expect($user->isBanned())->toBeFalse()
            ->and($user->isBannedFrom('forum'))->toBeTrue();
    });

    it('returns false for an expired feature-scoped ban', function () {
        $user = banUser();
        $user->ban(['feature' => 'forum', 'reason' => 'expired', 'expired_at' => now()->subSecond()]);

        expect($user->isBannedFrom('forum'))->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// 6. BanStatus enum
// ---------------------------------------------------------------------------

describe('BanStatus enum', function () {

    it('ACTIVE has value "active"', function () {
        expect(BanStatus::ACTIVE->value)->toBe('active');
    });

    it('CANCELLED has value "cancelled"', function () {
        expect(BanStatus::CANCELLED->value)->toBe('cancelled');
    });

    it('scopeActive() returns bans with status=active and null expired_at', function () {
        $user = banUser();

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'permanent active',
            'status'        => BanStatus::ACTIVE->value,
        ]);

        $active = Ban::active()->get();

        expect($active)->toHaveCount(1)
            ->and($active->first()->reason)->toBe('permanent active');
    });

    it('scopeActive() returns bans with future expired_at', function () {
        $user = banUser();

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'future expiry',
            'status'        => BanStatus::ACTIVE->value,
            'expired_at'    => now()->addDay(),
        ]);

        expect(Ban::active()->count())->toBe(1);
    });

    it('scopeActive() excludes bans with past expired_at', function () {
        $user = banUser();

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'past expiry',
            'status'        => BanStatus::ACTIVE->value,
            'expired_at'    => now()->subSecond(),
        ]);

        expect(Ban::active()->count())->toBe(0);
    });

    it('scopeActive() excludes cancelled bans', function () {
        $user = banUser();

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'cancelled',
            'status'        => BanStatus::CANCELLED->value,
        ]);

        expect(Ban::active()->count())->toBe(0);
    });

    it('scopeCancelled() returns only cancelled bans', function () {
        $user = banUser();

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'active ban',
            'status'        => BanStatus::ACTIVE->value,
        ]);

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'cancelled ban',
            'status'        => BanStatus::CANCELLED->value,
        ]);

        $cancelled = Ban::cancelled()->get();

        expect($cancelled)->toHaveCount(1)
            ->and($cancelled->first()->reason)->toBe('cancelled ban');
    });

    it('scopeCancelled() excludes active bans', function () {
        $user = banUser();

        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'active only',
            'status'        => BanStatus::ACTIVE->value,
        ]);

        expect(Ban::cancelled()->count())->toBe(0);
    });

});

// ---------------------------------------------------------------------------
// 7. syncBan()
// ---------------------------------------------------------------------------

describe('syncBan()', function () {

    it('creates a new ban when none exists', function () {
        $user = banUser();
        $ban  = $user->syncBan(['reason' => 'first sync']);

        expect($ban)->toBeInstanceOf(Ban::class)
            ->and($ban->reason)->toBe('first sync')
            ->and(Ban::count())->toBe(1);
    });

    it('updates the existing active ban instead of creating a duplicate', function () {
        $user = banUser();
        $user->syncBan(['reason' => 'original reason']);

        $updated = $user->syncBan(['reason' => 'updated reason']);

        expect(Ban::count())->toBe(1)
            ->and($updated->reason)->toBe('updated reason');
    });

    it('updates expired_at on the existing active ban', function () {
        $user = banUser();
        $user->syncBan(['expired_at' => now()->addDay()]);

        $newExpiry = now()->addDays(7);
        $synced    = $user->syncBan(['expired_at' => $newExpiry]);

        expect(Ban::count())->toBe(1)
            ->and($synced->expired_at->toDateString())->toBe($newExpiry->toDateString());
    });

    it('dispatches ModelBanned on create', function () {
        Event::fake([ModelBanned::class, ModelBanUpdated::class]);

        banUser()->syncBan(['reason' => 'created via sync']);

        Event::assertDispatched(ModelBanned::class);
        Event::assertNotDispatched(ModelBanUpdated::class);
    });

    it('dispatches ModelBanUpdated on update', function () {
        $user = banUser();
        $user->syncBan(['reason' => 'initial']);

        // Start fresh fakes so only the update is captured
        Event::fake([ModelBanned::class, ModelBanUpdated::class]);
        $user->syncBan(['reason' => 'updated']);

        Event::assertDispatched(ModelBanUpdated::class);
        Event::assertNotDispatched(ModelBanned::class);
    });

    it('ModelBanUpdated contains original attributes', function () {
        $user = banUser();
        $user->syncBan(['reason' => 'initial reason']);

        Event::fake([ModelBanUpdated::class]);
        $user->syncBan(['reason' => 'new reason']);

        Event::assertDispatched(ModelBanUpdated::class, function (ModelBanUpdated $e) {
            return $e->originalAttributes['reason'] === 'initial reason'
                && $e->ban->reason === 'new reason';
        });
    });

    it('does NOT throw AlreadyBannedException when a ban already exists', function () {
        $user = banUser();
        $user->ban(['reason' => 'original']);

        expect(fn () => $user->syncBan(['reason' => 'sync over existing']))
            ->not->toThrow(AlreadyBannedException::class);
    });

    it('handles feature-scoped syncs independently', function () {
        config(['ban.allow_overlapping_bans' => true]);

        $user = banUser();
        $user->syncBan(['feature' => 'forum',    'reason' => 'forum ban']);
        $user->syncBan(['feature' => 'comments', 'reason' => 'comment ban']);

        expect(Ban::count())->toBe(2);

        $user->syncBan(['feature' => 'forum', 'reason' => 'forum ban updated']);

        expect(Ban::count())->toBe(2)
            ->and(Ban::where('feature', 'forum')->first()?->reason)->toBe('forum ban updated')
            ->and(Ban::where('feature', 'comments')->first()?->reason)->toBe('comment ban');
    });

    it('creates a new ban when the previous one has expired', function () {
        $user = banUser();

        // Insert an expired ban directly (bypassing AlreadyBannedException)
        Ban::create([
            'bannable_type' => $user->getMorphClass(),
            'bannable_id'   => $user->getKey(),
            'reason'        => 'old expired',
            'status'        => BanStatus::ACTIVE->value,
            'expired_at'    => now()->subHour(),
        ]);

        $ban = $user->syncBan(['reason' => 'fresh ban']);

        expect(Ban::count())->toBe(2)
            ->and($ban->reason)->toBe('fresh ban');
    });

    it('returns null on recursive call (lock guard)', function () {
        $user   = banUser();
        $result = null;

        Event::listen(ModelBanned::class, function (ModelBanned $event) use (&$result) {
            $result = $event->bannable->syncBan(['reason' => 'recursive sync']);
        });

        $user->syncBan(['reason' => 'initial']);

        expect($result)->toBeNull();
    });

});
