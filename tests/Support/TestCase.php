<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Support;

use Godrade\LaravelBan\BanServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [BanServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory for all tests
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Disable cache for tests
        $app['config']->set('ban.cache_ttl', 0);
    }
}
