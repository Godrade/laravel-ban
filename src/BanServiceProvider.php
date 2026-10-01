<?php

declare(strict_types=1);

namespace Godrade\LaravelBan;

use Godrade\LaravelBan\Blade\BanDirectives;
use Godrade\LaravelBan\Console\Commands\BanConfigCommand;
use Godrade\LaravelBan\Console\Commands\BanListCommand;
use Godrade\LaravelBan\Console\Commands\BanRemoveCommand;
use Godrade\LaravelBan\Console\Commands\BanUserCommand;
use Godrade\LaravelBan\Livewire\BanActionGuard;
use Godrade\LaravelBan\Middleware\BlockBannedIp;
use Godrade\LaravelBan\Middleware\CheckBanned;
use Godrade\LaravelBan\Models\Ban;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;

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
        $this->bootLivewire();
    }

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------

    public function register(): void
    {
        $this->app->singleton(BanActionGuard::class);

        $this->mergeConfigFrom(
            path: __DIR__.'/../config/ban.php',
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
            __DIR__.'/../config/ban.php' => config_path('ban.php'),
        ], 'ban-config');

        // Migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'ban-migrations');
    }

    private function bootMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
            (new BanDirectives)->register($blade);
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
            BanListCommand::class,
            BanRemoveCommand::class,
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
        $relations = config('ban.relations', []);

        if (! is_array($relations) || empty($relations)) {
            return;
        }

        $reserved = array_merge(['bannable', 'createdBy', 'cause'], config('ban.reserved_relations', []));

        foreach ($relations as $name => $definition) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) || method_exists(Ban::class, $name) || in_array($name, $reserved, strict: true)) {
                Log::warning("[LaravelBan] Cannot register dynamic relation \"{$name}\": name is reserved.", [
                    'reserved' => $reserved,
                ]);

                continue;
            }

            if (! is_array($definition)) {
                Log::error("[LaravelBan] Cannot register dynamic relation \"{$name}\": definition must be an array.");

                continue;
            }

            $related = $definition['related'] ?? null;

            if (! is_string($related) || ! is_subclass_of($related, Model::class)) {
                Log::error("[LaravelBan] Cannot register dynamic relation \"{$name}\": related must be an Eloquent model class.");

                continue;
            }

            $type = $definition['type'] ?? 'belongsTo';

            if (! in_array($type, ['belongsTo', 'hasOne', 'hasMany'], true)) {
                Log::error("[LaravelBan] Cannot register dynamic relation \"{$name}\": supported types are belongsTo, hasOne and hasMany.");

                continue;
            }

            $foreignKey = $definition['foreign_key'] ?? null;
            $ownerKey = $definition['owner_key'] ?? null;
            $localKey = $definition['local_key'] ?? null;

            Ban::resolveRelationUsing($name, function (Ban $ban) use ($name, $type, $related, $foreignKey, $ownerKey, $localKey) {
                return match ($type) {
                    'belongsTo' => $ban->belongsTo($related, $foreignKey, $ownerKey, $name),
                    'hasOne' => $ban->hasOne($related, $foreignKey, $localKey),
                    'hasMany' => $ban->hasMany($related, $foreignKey, $localKey),
                };
            });
        }
    }

    private function bootLivewire(): void
    {
        $this->app->booted(function (): void {
            if (class_exists(Component::class) && $this->app->bound('livewire')) {
                $this->app->make(BanActionGuard::class)->register();
            }
        });
    }
}
