<?php

declare(strict_types=1);

namespace Godrade\LaravelBan;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Godrade\LaravelBan\Blade\BanDirectives;
use Godrade\LaravelBan\Console\Commands\BanConfigCommand;
use Godrade\LaravelBan\Console\Commands\BanUserCommand;
use Godrade\LaravelBan\Middleware\BlockBannedIp;
use Godrade\LaravelBan\Middleware\CheckBanned;
use Godrade\LaravelBan\Models\Ban;

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
        $this->bootDynamicRelations();
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
        $router->aliasMiddleware('ban.ip', BlockBannedIp::class);
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

    /**
     * Inject additional Eloquent relations on the Ban model from config('ban.relations').
     *
     * Config shape:
     *   'relations' => [
     *       'preset' => ['type' => 'belongsTo', 'related' => Preset::class, 'foreign_key' => 'preset_id'],
     *   ]
     */
    private function bootDynamicRelations(): void
    {
        /** @var array<string, array{type: string, related: class-string, foreign_key?: string, owner_key?: string}> $relations */
        $relations = config('ban.relations', []);

        if (empty($relations)) {
            return;
        }

        $reserved = config('ban.reserved_relations', ['bannable', 'createdBy', 'cause']);

        foreach ($relations as $name => $definition) {
            if (in_array($name, $reserved, strict: true)) {
                Log::warning("[LaravelBan] Cannot register dynamic relation \"{$name}\": name is reserved.", [
                    'reserved' => $reserved,
                ]);
                continue;
            }

            $related = $definition['related'] ?? null;

            if ($related === null || ! class_exists($related)) {
                Log::error("[LaravelBan] Cannot register dynamic relation \"{$name}\": related class \"{$related}\" does not exist.");
                continue;
            }

            $type       = $definition['type']        ?? 'belongsTo';
            $foreignKey = $definition['foreign_key'] ?? null;
            $ownerKey   = $definition['owner_key']   ?? null;

            Ban::resolveRelationUsing($name, function (Ban $ban) use ($type, $related, $foreignKey, $ownerKey) {
                $args = array_filter([$related, $foreignKey, $ownerKey], fn ($v) => $v !== null);

                return $ban->{$type}(...array_values($args));
            });
        }
    }
}
