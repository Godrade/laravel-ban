<?php

declare(strict_types=1);

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

class BladeUser extends Model implements AuthenticatableContract, Bannable
{
    use HasBans, Authenticatable;

    protected $table   = 'blade_users';
    protected $guarded = [];
    public $timestamps = false;

    public function getMorphClass(): string { return 'blade_user'; }
}

// ---------------------------------------------------------------------------
// Schema helpers
// ---------------------------------------------------------------------------

function bladeCreateSchema(): void
{
    Schema::create('blade_users', function (Blueprint $table) {
        $table->id();
    });

    Schema::create('bans', function (Blueprint $table) {
        $table->id();
        $table->morphs('bannable');
        $table->nullableMorphs('created_by');
        $table->nullableMorphs('cause');
        $table->string('feature')->nullable();
        $table->text('reason')->nullable();
        $table->string('status', 50)->default('active');
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function bladeDropSchema(): void
{
    Schema::dropIfExists('bans');
    Schema::dropIfExists('blade_users');
}

function bladeUser(): BladeUser
{
    return BladeUser::create([]);
}

// ---------------------------------------------------------------------------
// @banned
// ---------------------------------------------------------------------------

describe('@banned directive', function () {

    beforeEach(function () {
        bladeCreateSchema();
        config(['ban.cache_ttl' => 0]);
    });

    afterEach(fn () => bladeDropSchema());

    it('returns true when the explicit model is banned', function () {
        $user = bladeUser();
        $user->ban();

        expect(Blade::check('banned', $user))->toBeTrue();
    });

    it('returns false when the explicit model is not banned', function () {
        $user = bladeUser();

        expect(Blade::check('banned', $user))->toBeFalse();
    });

    it('returns false when null is passed (no authenticated user)', function () {
        expect(Blade::check('banned', null))->toBeFalse();
    });

    it('uses auth()->user() when no argument is passed', function () {
        $user = bladeUser();
        $user->ban();

        $this->actingAs($user);

        expect(Blade::check('banned'))->toBeTrue();
    });

    it('returns false via auth()->user() when authenticated user is not banned', function () {
        $user = bladeUser();

        $this->actingAs($user);

        expect(Blade::check('banned'))->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// @bannedFrom
// ---------------------------------------------------------------------------

describe('@bannedFrom directive', function () {

    beforeEach(function () {
        bladeCreateSchema();
        config(['ban.cache_ttl' => 0]);
    });

    afterEach(fn () => bladeDropSchema());

    it('returns true when the model is banned from the specified feature', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);

        expect(Blade::check('bannedFrom', 'comments', $user))->toBeTrue();
    });

    it('returns false when the model is not banned from the specified feature', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'forum']);

        expect(Blade::check('bannedFrom', 'comments', $user))->toBeFalse();
    });

    it('returns false when the model has no bans at all', function () {
        $user = bladeUser();

        expect(Blade::check('bannedFrom', 'comments', $user))->toBeFalse();
    });

    it('returns false when null model is provided', function () {
        expect(Blade::check('bannedFrom', 'comments', null))->toBeFalse();
    });

    it('uses auth()->user() when no model argument is passed', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);

        $this->actingAs($user);

        expect(Blade::check('bannedFrom', 'comments'))->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// @anyBan
// ---------------------------------------------------------------------------

describe('@anyBan directive (OR logic)', function () {

    beforeEach(function () {
        bladeCreateSchema();
        config(['ban.cache_ttl' => 0]);
    });

    afterEach(fn () => bladeDropSchema());

    it('returns true when model is banned from at least one of the listed features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);

        expect(Blade::check('anyBan', 'comments', 'forum', $user))->toBeTrue();
    });

    it('returns false when model is not banned from any of the listed features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'chat']);

        expect(Blade::check('anyBan', 'comments', 'forum', $user))->toBeFalse();
    });

    it('returns false when the model has no bans at all', function () {
        $user = bladeUser();

        expect(Blade::check('anyBan', 'comments', 'forum', $user))->toBeFalse();
    });

    it('returns true for a global ban when no features are passed', function () {
        $user = bladeUser();
        $user->ban();

        expect(Blade::check('anyBan', $user))->toBeTrue();
    });

    it('returns false for a global ban check when user is not globally banned', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);

        expect(Blade::check('anyBan', $user))->toBeFalse();
    });

    it('returns false when null is the only argument (no authenticated user)', function () {
        expect(Blade::check('anyBan', null))->toBeFalse();
    });

    it('uses auth()->user() when no model is provided as the last argument', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'forum']);

        $this->actingAs($user);

        expect(Blade::check('anyBan', 'comments', 'forum'))->toBeTrue();
    });

    it('returns false via auth()->user() when not banned from any feature', function () {
        $user = bladeUser();

        $this->actingAs($user);

        expect(Blade::check('anyBan', 'comments', 'forum'))->toBeFalse();
    });

    it('returns false when no user is authenticated and no model is passed', function () {
        Auth::logout();

        expect(Blade::check('anyBan', 'comments', 'forum'))->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// @allBanned
// ---------------------------------------------------------------------------

describe('@allBanned directive (AND logic)', function () {

    beforeEach(function () {
        bladeCreateSchema();
        config(['ban.cache_ttl' => 0]);
    });

    afterEach(fn () => bladeDropSchema());

    it('returns true when model is banned from all listed features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);
        $user->ban(['feature' => 'forum']);

        expect(Blade::check('allBanned', 'comments', 'forum', $user))->toBeTrue();
    });

    it('returns false when model is banned from only some of the listed features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);

        expect(Blade::check('allBanned', 'comments', 'forum', $user))->toBeFalse();
    });

    it('returns false when model is not banned from any listed feature', function () {
        $user = bladeUser();

        expect(Blade::check('allBanned', 'comments', 'forum', $user))->toBeFalse();
    });

    it('returns true for a global ban check when user is globally banned and no features are passed', function () {
        $user = bladeUser();
        $user->ban();

        expect(Blade::check('allBanned', $user))->toBeTrue();
    });

    it('returns false when null is the only argument (no authenticated user)', function () {
        expect(Blade::check('allBanned', null))->toBeFalse();
    });

    it('uses auth()->user() when no model is provided as the last argument', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);
        $user->ban(['feature' => 'forum']);

        $this->actingAs($user);

        expect(Blade::check('allBanned', 'comments', 'forum'))->toBeTrue();
    });

    it('returns false via auth()->user() when banned from only some features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);

        $this->actingAs($user);

        expect(Blade::check('allBanned', 'comments', 'forum'))->toBeFalse();
    });

    it('returns false when no user is authenticated and no model is passed', function () {
        Auth::logout();

        expect(Blade::check('allBanned', 'comments', 'forum'))->toBeFalse();
    });

    it('returns true only when banned from all three features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);
        $user->ban(['feature' => 'forum']);
        $user->ban(['feature' => 'chat']);

        expect(Blade::check('allBanned', 'comments', 'forum', 'chat', $user))->toBeTrue();
    });

    it('returns false when missing one of three features', function () {
        $user = bladeUser();
        $user->ban(['feature' => 'comments']);
        $user->ban(['feature' => 'forum']);

        expect(Blade::check('allBanned', 'comments', 'forum', 'chat', $user))->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// @notBanned (sanity check for inverse of @banned)
// ---------------------------------------------------------------------------

describe('@notBanned directive', function () {

    beforeEach(function () {
        bladeCreateSchema();
        config(['ban.cache_ttl' => 0]);
    });

    afterEach(fn () => bladeDropSchema());

    it('returns false when the model is banned', function () {
        $user = bladeUser();
        $user->ban();

        expect(Blade::check('notBanned', $user))->toBeFalse();
    });

    it('returns true when the model is not banned', function () {
        $user = bladeUser();

        expect(Blade::check('notBanned', $user))->toBeTrue();
    });

    it('returns true when null is passed (not banned by default)', function () {
        expect(Blade::check('notBanned', null))->toBeTrue();
    });

});
