<?php

declare(strict_types=1);

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HttpBanUser extends Authenticatable implements Bannable
{
    use HasBans;

    protected $table = 'http_ban_users';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    config(['ban.cache_driver' => 'array', 'ban.cache_ttl' => 3600]);
    Schema::create('http_ban_users', fn (Blueprint $table) => $table->id());
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);
    Route::middleware(['web', 'banned'])->get('/protected', fn () => 'allowed');
    Route::middleware('banned')->get('/api/protected', fn () => 'allowed');
    Route::middleware('banned:comments')->get('/comments', fn () => 'allowed');
});

afterEach(function () {
    Relation::morphMap([], false);
});

it('returns JSON 403 for a banned API user without a login route', function () {
    $user = HttpBanUser::create();
    $user->ban();

    $this->actingAs($user)->getJson('/api/protected')
        ->assertForbidden()
        ->assertJson(['message' => 'Your account has been suspended.']);
});

it('redirects browser requests and flashes the ban message', function () {
    Route::get('/sign-in', fn () => 'login')->name('login');
    $user = HttpBanUser::create();
    $user->ban();

    $this->actingAs($user)->get('/protected')
        ->assertRedirect('/sign-in')
        ->assertSessionHas('ban_error');
});

it('accepts relative redirect URLs', function () {
    config(['ban.redirect_url' => '/suspended']);
    $user = HttpBanUser::create();
    $user->ban();

    $this->actingAs($user)->get('/protected')->assertRedirect('/suspended');
});

it('returns 403 instead of failing when no redirect route exists', function () {
    $user = HttpBanUser::create();
    $user->ban();

    $this->actingAs($user)->get('/protected')->assertForbidden();
});

it('prevents redirect loops on a protected destination', function () {
    config(['ban.redirect_url' => '/protected']);
    $user = HttpBanUser::create();
    $user->ban();

    $this->actingAs($user)->get('/protected')->assertForbidden();
});

it('allows guests and users without matching bans', function () {
    $this->get('/api/protected')->assertOk();
    $user = HttpBanUser::create();
    $user->ban(['feature' => 'forum']);
    $this->actingAs($user)->get('/comments')->assertOk();
    $user->ban(['feature' => 'comments']);
    $this->getJson('/comments')->assertForbidden();
});

it('rejects invalid command durations without creating a ban', function (string $duration) {
    $user = HttpBanUser::create();
    $exit = Artisan::call('ban:user', ['id' => $user->id, '--model' => HttpBanUser::class, '--duration' => $duration]);

    expect($exit)->toBe(1)->and(Ban::count())->toBe(0);
})->with(['0', '-1', '1h', '1.5', '999999999999999999999999999']);

it('creates a temporary ban from a valid duration', function () {
    $this->freezeTime();
    $user = HttpBanUser::create();
    $exit = Artisan::call('ban:user', ['id' => $user->id, '--model' => HttpBanUser::class, '--duration' => '60']);

    expect($exit)->toBe(0)
        ->and($user->bans()->first()->expired_at->getTimestamp())->toBe(now()->addHour()->getTimestamp());
});

it('reports an already banned user without throwing', function () {
    $user = HttpBanUser::create();
    $user->ban();
    $exit = Artisan::call('ban:user', ['id' => $user->id, '--model' => HttpBanUser::class]);

    expect($exit)->toBe(1)->and(Ban::count())->toBe(1);
});

it('lists cancellation history without an expired flag', function () {
    $user = HttpBanUser::create();
    $user->ban(['reason' => 'cancelled marker']);
    $user->unban();
    Artisan::call('ban:list', ['--status' => 'cancelled']);

    expect(Artisan::output())->toContain('cancelled marker');
});

it('rejects unknown status filters', function () {
    expect(Artisan::call('ban:list', ['--status' => 'unknown']))->toBe(1);
});

it('resolves morph aliases for list filters and deletion cache invalidation', function () {
    Relation::morphMap(['http_user' => HttpBanUser::class]);
    $user = HttpBanUser::create();
    $ban = $user->ban(['reason' => 'mapped ban']);
    expect($user->isBanned())->toBeTrue();

    Artisan::call('ban:list', ['--model' => HttpBanUser::class]);
    expect(Artisan::output())->toContain('mapped ban');

    expect(Artisan::call('ban:remove', ['id' => $ban->id, '--no-confirm' => true]))->toBe(0);
    expect($user->isBanned())->toBeFalse();
});
