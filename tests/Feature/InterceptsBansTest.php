<?php

declare(strict_types=1);

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Middleware\BlockBannedIp;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Models\BannedIp;
use Godrade\LaravelBan\Traits\HasBans;
use Godrade\LaravelBan\Traits\InterceptsBans;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

/**
 * Minimal User stub with ban capability.
 */
class BanUser extends Model implements AuthenticatableContract, Bannable
{
    use HasBans, Authenticatable;

    protected $table      = 'users';
    protected $guarded    = [];
    public $timestamps    = false;

    public function getMorphClass(): string { return 'user'; }
}

/**
 * Minimal Livewire component base class that simulates v2's callMethod.
 * In real usage this would be Livewire\Component.
 */
abstract class FakeLivewireComponent
{
    /** Tracks which methods have actually been executed. */
    public array $executed = [];

    public function callMethod(
        string $method,
        array $params = [],
        ?callable $captureReturnValueCallback = null,
    ): mixed {
        $result = $this->{$method}(...$params);
        $this->executed[] = $method;

        if ($captureReturnValueCallback !== null) {
            ($captureReturnValueCallback)($result);
        }

        return $result;
    }
}

/**
 * Component with a method-level lock (no feature scope).
 */
class MethodLockedComponent extends FakeLivewireComponent
{
    use InterceptsBans;

    #[LockedByBan]
    public function postComment(): string
    {
        return 'comment_posted';
    }

    public function viewPosts(): string
    {
        return 'posts_viewed';
    }
}

/**
 * Component with a feature-scoped method-level lock.
 */
class FeatureLockedComponent extends FakeLivewireComponent
{
    use InterceptsBans;

    #[LockedByBan(feature: 'comments')]
    public function postComment(): string
    {
        return 'comment_posted';
    }
}

/**
 * Component where the entire class is locked (no feature scope).
 */
#[LockedByBan]
class ClassLockedComponent extends FakeLivewireComponent
{
    use InterceptsBans;

    public function postComment(): string
    {
        return 'comment_posted';
    }

    public function editProfile(): string
    {
        return 'profile_edited';
    }
}

/**
 * Component where the class is locked to a feature, but one method overrides
 * the lock with its own feature scope.
 */
#[LockedByBan(feature: 'forum')]
class MixedLockComponent extends FakeLivewireComponent
{
    use InterceptsBans;

    // Inherits class-level lock on feature 'forum'
    public function postThread(): string
    {
        return 'thread_posted';
    }

    // Method-level lock takes precedence over class-level
    #[LockedByBan(feature: 'comments')]
    public function postComment(): string
    {
        return 'comment_posted';
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createSchema(): void
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
        $table->string('status', 50)->default('active');
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('banned_ips', function (Blueprint $table) {
        $table->id();
        $table->string('ip_address', 45)->unique();
        $table->string('feature')->nullable();
        $table->text('reason')->nullable();
        $table->nullableMorphs('created_by');
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function dropSchema(): void
{
    Schema::dropIfExists('bans');
    Schema::dropIfExists('banned_ips');
    Schema::dropIfExists('users');
}

function createUser(): BanUser
{
    return BanUser::create(['name' => 'Alice']);
}

// ---------------------------------------------------------------------------
// InterceptsBans — method-level lock
// ---------------------------------------------------------------------------

describe('InterceptsBans – method-level #[LockedByBan]', function () {

    beforeEach(fn () => createSchema());
    afterEach(function () {
        Auth::logout();
        dropSchema();
    });

    it('executes the method when the user is NOT banned', function () {
        $user = createUser();
        Auth::login($user);

        $component = new MethodLockedComponent();
        $result    = $component->callMethod('postComment');

        expect($result)->toBe('comment_posted')
            ->and($component->executed)->toContain('postComment');
    });

    it('blocks the method and flashes ban_error when the user IS banned', function () {
        $user = createUser();
        $user->ban(['reason' => 'Spam']);
        Auth::login($user);

        $component = new MethodLockedComponent();
        $result    = $component->callMethod('postComment');

        expect($result)->toBeNull()
            ->and($component->executed)->not->toContain('postComment')
            ->and(session('ban_error'))->not->toBeEmpty();
    });

    it('does NOT block an unlocked method on the same component', function () {
        $user = createUser();
        $user->ban(['reason' => 'Spam']);
        Auth::login($user);

        $component = new MethodLockedComponent();
        $result    = $component->callMethod('viewPosts');

        expect($result)->toBe('posts_viewed')
            ->and($component->executed)->toContain('viewPosts');
    });

    it('does not block when no user is authenticated', function () {
        $component = new MethodLockedComponent();
        $result    = $component->callMethod('postComment');

        expect($result)->toBe('comment_posted');
    });

});

// ---------------------------------------------------------------------------
// InterceptsBans — feature-scoped lock
// ---------------------------------------------------------------------------

describe('InterceptsBans – feature-scoped #[LockedByBan(feature:)]', function () {

    beforeEach(fn () => createSchema());
    afterEach(function () {
        Auth::logout();
        dropSchema();
    });

    it('blocks when user is banned from the locked feature', function () {
        $user = createUser();
        $user->ban(['feature' => 'comments']);
        Auth::login($user);

        $component = new FeatureLockedComponent();
        $result    = $component->callMethod('postComment');

        expect($result)->toBeNull()
            ->and(session('ban_error'))->not->toBeEmpty();
    });

    it('blocks when user has a global ban (global implies all features)', function () {
        $user = createUser();
        $user->ban(); // global ban
        Auth::login($user);

        $component = new FeatureLockedComponent();
        $result    = $component->callMethod('postComment');

        expect($result)->toBeNull();
    });

    it('does NOT block when the user is banned from a different feature', function () {
        $user = createUser();
        $user->ban(['feature' => 'forum']); // banned from forum, NOT comments
        Auth::login($user);

        $component = new FeatureLockedComponent();
        $result    = $component->callMethod('postComment');

        expect($result)->toBe('comment_posted');
    });

});

// ---------------------------------------------------------------------------
// InterceptsBans — class-level lock
// ---------------------------------------------------------------------------

describe('InterceptsBans – class-level #[LockedByBan]', function () {

    beforeEach(fn () => createSchema());
    afterEach(function () {
        Auth::logout();
        dropSchema();
    });

    it('blocks ALL methods when the class is locked and user is banned', function () {
        $user = createUser();
        $user->ban();
        Auth::login($user);

        $component = new ClassLockedComponent();

        expect($component->callMethod('postComment'))->toBeNull()
            ->and($component->callMethod('editProfile'))->toBeNull()
            ->and($component->executed)->toBeEmpty();
    });

    it('allows ALL methods when the class is locked but user is NOT banned', function () {
        $user = createUser();
        Auth::login($user);

        $component = new ClassLockedComponent();

        expect($component->callMethod('postComment'))->toBe('comment_posted')
            ->and($component->callMethod('editProfile'))->toBe('profile_edited');
    });

});

// ---------------------------------------------------------------------------
// InterceptsBans — method attribute takes precedence over class attribute
// ---------------------------------------------------------------------------

describe('InterceptsBans – method attribute overrides class attribute', function () {

    beforeEach(fn () => createSchema());
    afterEach(function () {
        Auth::logout();
        dropSchema();
    });

    it('uses the method feature scope even when class has a different feature scope', function () {
        $user = createUser();
        // Banned from 'comments' only, NOT 'forum'
        $user->ban(['feature' => 'comments']);
        Auth::login($user);

        $component = new MixedLockComponent();

        // postComment has #[LockedByBan(feature:'comments')] → blocked
        expect($component->callMethod('postComment'))->toBeNull();

        // postThread inherits class #[LockedByBan(feature:'forum')] → NOT blocked
        expect($component->callMethod('postThread'))->toBe('thread_posted');
    });

});

// ---------------------------------------------------------------------------
// BlockBannedIp Middleware
// ---------------------------------------------------------------------------

describe('BlockBannedIp middleware', function () {

    beforeEach(function () {
        createSchema();
        BlockBannedIp::flushCache();
    });

    afterEach(fn () => dropSchema());

    it('allows a request from a non-banned IP', function () {
        $request  = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $response = (new BlockBannedIp())->handle($request, fn ($r) => response('ok'));

        expect($response->getContent())->toBe('ok');
    });

    it('aborts with 403 for a banned IP', function () {
        BannedIp::create(['ip_address' => '1.2.3.4', 'reason' => 'attacker']);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);

        expect(fn () => (new BlockBannedIp())->handle($request, fn ($r) => response('ok')))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });

    it('does NOT block when the IP ban has expired', function () {
        BannedIp::create([
            'ip_address' => '1.2.3.4',
            'expired_at' => now()->subMinute(),
        ]);

        $request  = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);
        $response = (new BlockBannedIp())->handle($request, fn ($r) => response('ok'));

        expect($response->getContent())->toBe('ok');
    });

    it('memoizes the result and does not query the database twice', function () {
        BannedIp::create(['ip_address' => '5.5.5.5']);

        $queryCount = 0;
        \DB::listen(function () use (&$queryCount) { $queryCount++; });

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '5.5.5.5']);
        $mw      = new BlockBannedIp();

        // Two calls with the same IP → only 1 DB query thanks to static cache
        try { $mw->handle($request, fn ($r) => response('ok')); } catch (\Throwable) {}
        try { $mw->handle($request, fn ($r) => response('ok')); } catch (\Throwable) {}

        expect($queryCount)->toBe(1);
    });

    it('blocks a feature-scoped IP ban', function () {
        BannedIp::create(['ip_address' => '9.9.9.9', 'feature' => 'api']);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '9.9.9.9']);

        expect(fn () => (new BlockBannedIp())->handle($request, fn ($r) => response('ok'), 'api'))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });

    it('does NOT block when the IP is banned on a different feature', function () {
        BannedIp::create(['ip_address' => '9.9.9.9', 'feature' => 'api']);

        $request  = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '9.9.9.9']);
        $response = (new BlockBannedIp())->handle($request, fn ($r) => response('ok'), 'web');

        expect($response->getContent())->toBe('ok');
    });

});
