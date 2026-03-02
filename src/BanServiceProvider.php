<?php

declare(strict_types=1);

namespace Godrade\LaravelBan;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Godrade\LaravelBan\Blade\BanDirectives;
use Godrade\LaravelBan\Console\Commands\BanConfigCommand;
use Godrade\LaravelBan\Console\Commands\BanUserCommand;
use Godrade\LaravelBan\Middleware\CheckBanned;

final class BanServiceProvider extends ServiceProvider
{
    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function boot(): void
    {
        $this->bootPublishables();
        $this->bootMigrations();
        $this->bootMiddleware();
        $this->bootBladeDirectives();
        $this->bootCommands();
    }

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------

    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__ . '/../config/ban.php',
            key: 'ban',
        );
    }

    // -------------------------------------------------------------------------
    // Boot Helpers
    // -------------------------------------------------------------------------

    private function bootPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__ . '/../config/ban.php' => config_path('ban.php'),
        ], 'ban-config');

        // Migrations
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'ban-migrations');
    }

    private function bootMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function bootMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $alias = config('ban.middleware_alias', 'banned');

        $router->aliasMiddleware($alias, CheckBanned::class);
    }

    private function bootBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', function ($blade): void {
            new BanDirectives()->register($blade);
        });
    }

    private function bootCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            BanUserCommand::class,
            BanConfigCommand::class,
        ]);
    }
}
