<?php

declare(strict_types=1);

use Godrade\LaravelBan\Middleware\BlockBannedIp;
use Godrade\LaravelBan\Models\BannedIp;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

function createIpMiddlewareSchema(): void
{
    (require __DIR__.'/../../database/migrations/2024_01_01_000002_create_banned_ips_table.php')->up();
}

function dropIpMiddlewareSchema(): void
{
    (require __DIR__.'/../../database/migrations/2024_01_01_000002_create_banned_ips_table.php')->down();
}

describe('BlockBannedIp middleware', function () {

    beforeEach(function () {
        createIpMiddlewareSchema();
        BlockBannedIp::flushCache();
    });

    afterEach(fn () => dropIpMiddlewareSchema());

    it('allows a request from a non-banned IP', function () {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $response = (new BlockBannedIp)->handle($request, fn ($r) => response('ok'));

        expect($response->getContent())->toBe('ok');
    });

    it('aborts with 403 for a banned IP', function () {
        BannedIp::create(['ip_address' => '1.2.3.4', 'reason' => 'attacker']);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);

        expect(fn () => (new BlockBannedIp)->handle($request, fn ($r) => response('ok')))
            ->toThrow(HttpException::class);
    });

    it('does NOT block when the IP ban has expired', function () {
        BannedIp::create([
            'ip_address' => '1.2.3.4',
            'expired_at' => now()->subMinute(),
        ]);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.2.3.4']);
        $response = (new BlockBannedIp)->handle($request, fn ($r) => response('ok'));

        expect($response->getContent())->toBe('ok');
    });

    it('memoizes the result and does not query the database twice', function () {
        BannedIp::create(['ip_address' => '5.5.5.5']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '5.5.5.5']);
        $mw = new BlockBannedIp;

        // Two calls with the same IP → only 1 DB query within one request
        try {
            $mw->handle($request, fn ($r) => response('ok'));
        } catch (Throwable) {
        }
        try {
            $mw->handle($request, fn ($r) => response('ok'));
        } catch (Throwable) {
        }

        expect($queryCount)->toBe(1);
    });

    it('blocks a feature-scoped IP ban', function () {
        BannedIp::create(['ip_address' => '9.9.9.9', 'feature' => 'api']);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '9.9.9.9']);

        expect(fn () => (new BlockBannedIp)->handle($request, fn ($r) => response('ok'), 'api'))
            ->toThrow(HttpException::class);
    });

    it('does NOT block when the IP is banned on a different feature', function () {
        BannedIp::create(['ip_address' => '9.9.9.9', 'feature' => 'api']);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '9.9.9.9']);
        $response = (new BlockBannedIp)->handle($request, fn ($r) => response('ok'), 'web');

        expect($response->getContent())->toBe('ok');
    });

});
