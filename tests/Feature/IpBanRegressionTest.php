<?php

declare(strict_types=1);

use Godrade\LaravelBan\Blade\BanDirectives;
use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Middleware\BlockBannedIp;
use Godrade\LaravelBan\Models\BannedIp;
use Godrade\LaravelBan\Support\IpBanLookup;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IpBanCreator extends Model
{
    protected $table = 'ip_ban_creators';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/2024_01_01_000002_create_banned_ips_table.php')->up();
    IpBanLookup::flush();
});

afterEach(function () {
    $this->travelBack();
});

it('shares IP memoization between middleware and Blade for one request', function () {
    $request = Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']);
    $this->app->instance('request', $request);
    DB::enableQueryLog();
    DB::flushQueryLog();

    (new BlockBannedIp)->handle($request, fn () => new Response('ok'));
    expect(Blade::check('bannedIp'))->toBeFalse()
        ->and(count(DB::getQueryLog()))->toBe(1);
});

it('does not share decisions between separate requests in the same process', function () {
    $first = Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']);
    $second = Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']);
    $middleware = new BlockBannedIp;
    expect($middleware->handle($first, fn () => new Response('ok'))->getStatusCode())->toBe(200);

    // An external writer does not fire Eloquent events in this worker.
    DB::table('banned_ips')->insert(['ip_address' => '192.0.2.1']);
    expect(fn () => $middleware->handle($second, fn () => new Response('ok')))
        ->toThrow(HttpException::class);
});

it('refreshes Blade decisions when the current request changes', function () {
    $this->app->instance('request', Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']));
    expect(Blade::check('bannedIp'))->toBeFalse();
    DB::table('banned_ips')->insert(['ip_address' => '192.0.2.1']);
    $this->app->instance('request', Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']));
    expect(Blade::check('bannedIp'))->toBeTrue();
});

it('invalidates both IP consumers on creation update deletion and restoration', function () {
    $request = request();
    expect(IpBanLookup::isBanned($request, '192.0.2.1', 'comments'))->toBeFalse();
    $ban = BannedIp::create(['ip_address' => '192.0.2.1', 'feature' => 'comments']);
    expect(Blade::check('bannedIp', '192.0.2.1', 'comments'))->toBeTrue();
    $ban->update(['feature' => 'forum']);
    expect(Blade::check('bannedIp', '192.0.2.1', 'comments'))->toBeFalse()
        ->and(Blade::check('bannedIp', '192.0.2.1', 'forum'))->toBeTrue();
    $ban->delete();
    expect(Blade::check('bannedIp', '192.0.2.1', 'forum'))->toBeFalse();
    $ban->restore();
    expect(Blade::check('bannedIp', '192.0.2.1', 'forum'))->toBeTrue();
});

it('stops enforcing expired IP bans during a long request', function () {
    $this->freezeTime();
    BannedIp::create(['ip_address' => '192.0.2.1', 'expired_at' => now()->addMinute()]);
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
    $this->travel(61)->seconds();
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
});

it('keeps permanent overlapping IP bans active after temporary bans expire', function () {
    $this->freezeTime();
    BannedIp::create(['ip_address' => '192.0.2.1', 'expired_at' => now()->addMinute()]);
    BannedIp::create(['ip_address' => '192.0.2.1']);
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
    $this->travel(61)->seconds();
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
});

it('does not cache uncommitted IP decisions after a rollback', function () {
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
    DB::beginTransaction();
    BannedIp::create(['ip_address' => '192.0.2.1']);
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
    DB::rollBack();
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
});

it('checks the primary database when read replicas lag behind bans and removals', function () {
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE banned_ips (ip_address TEXT, feature TEXT, expired_at DATETIME, deleted_at DATETIME)');
    DB::connection()->setReadPdo($replica);

    $ban = BannedIp::create(['ip_address' => '192.0.2.1']);
    expect(BannedIp::count())->toBe(0)
        ->and(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();

    $replica->exec("INSERT INTO banned_ips (ip_address) VALUES ('192.0.2.1')");
    $ban->delete();
    expect(BannedIp::count())->toBe(1)
        ->and(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
});

it('isolates IP decisions when one connection switches tenant databases within a request', function () {
    $firstDatabase = tempnam(sys_get_temp_dir(), 'ban-ip-tenant-');
    $secondDatabase = tempnam(sys_get_temp_dir(), 'ban-ip-tenant-');

    try {
        config(['database.connections.testing.database' => $firstDatabase]);
        DB::purge('testing');
        (require __DIR__.'/../../database/migrations/2024_01_01_000002_create_banned_ips_table.php')->up();
        expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();

        config(['database.connections.testing.database' => $secondDatabase]);
        DB::purge('testing');
        (require __DIR__.'/../../database/migrations/2024_01_01_000002_create_banned_ips_table.php')->up();
        DB::table('banned_ips')->insert(['ip_address' => '192.0.2.1']);
        expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
    } finally {
        DB::disconnect('testing');
        unlink($firstDatabase);
        unlink($secondDatabase);
    }
});

it('isolates IP decisions when tenant table prefixes change within a request', function () {
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
    Schema::create('tenant_banned_ips', function (Blueprint $table): void {
        $table->string('ip_address', 45);
        $table->string('feature')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->softDeletes();
    });
    DB::connection()->setTablePrefix('tenant_');
    DB::table('banned_ips')->insert(['ip_address' => '192.0.2.1']);

    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
});

it('retains explicit cache flushing after bulk writes for both public APIs', function () {
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
    DB::table('banned_ips')->insert(['ip_address' => '192.0.2.1']);
    BlockBannedIp::flushCache();
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue();
    DB::table('banned_ips')->delete();
    BanDirectives::flushIpCache();
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeFalse();
});

it('distinguishes null and literal star feature cache keys', function () {
    BannedIp::create(['ip_address' => '192.0.2.1', 'feature' => 'comments']);
    expect(Blade::check('bannedIp', '192.0.2.1'))->toBeTrue()
        ->and(Blade::check('bannedIp', '192.0.2.1', '*'))->toBeFalse();
});

it('normalizes equivalent IPv6 representations for storage and checks', function () {
    $ban = BannedIp::create(['ip_address' => '2001:0db8:0000:0000:0000:0000:0000:0001']);
    expect($ban->ip_address)->toBe('2001:db8::1')
        ->and(Blade::check('bannedIp', '2001:db8::1'))->toBeTrue()
        ->and(Blade::check('bannedIp', '2001:0DB8:0:0:0:0:0:1'))->toBeTrue();
});

it('accepts a creator model without inserting a created_by column', function () {
    Schema::create('ip_ban_creators', fn (Blueprint $table) => $table->id());
    $creator = IpBanCreator::create([]);
    $ban = BannedIp::create(['ip_address' => '192.0.2.1', 'created_by' => $creator]);
    expect($ban->fresh()->createdBy->is($creator))->toBeTrue()
        ->and($ban->created_by_type)->toBe($creator->getMorphClass())
        ->and($ban->created_by_id)->toBe($creator->id);
    $ban->update(['created_by' => null]);
    expect($ban->fresh()->created_by_id)->toBeNull()
        ->and($ban->created_by_type)->toBeNull();
});

it('uses the Bannable contract without requiring a particular implementation trait', function () {
    $model = Mockery::mock(Bannable::class);
    $model->shouldReceive('isBanned')->andReturnTrue();
    $model->shouldReceive('isBannedFrom')->with('comments')->andReturnTrue();
    expect(Blade::check('banned', $model))->toBeTrue()
        ->and(Blade::check('bannedFrom', 'comments', $model))->toBeTrue()
        ->and(Blade::check('anyBan', 'comments', $model))->toBeTrue()
        ->and(Blade::check('allBanned', 'comments', $model))->toBeTrue();

    $withoutContract = new class extends Model
    {
        use HasBans;
    };
    expect(Blade::check('banned', $withoutContract))->toBeFalse();
});
