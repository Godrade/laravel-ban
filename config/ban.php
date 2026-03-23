<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Driver
    |--------------------------------------------------------------------------
    | The cache store to use for ban checks. Set to null to use the default
    | application cache driver. Any store defined in config/cache.php is valid
    | (e.g. 'redis', 'database', 'memcached', 'array').
    */
    'cache_driver' => env('BAN_CACHE_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    | All cache keys managed by this package will be prefixed with this value
    | to avoid collisions with other application cache entries.
    */
    'cache_prefix' => env('BAN_CACHE_PREFIX', 'laravel_ban_'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    | How long (in seconds) ban status is cached. Set to 0 to disable caching.
    | Default: 3600 (1 hour).
    */
    'cache_ttl' => (int)env('BAN_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Redirect URL
    |--------------------------------------------------------------------------
    | The named route or URL to redirect banned users to when they attempt
    | to access a protected resource. Defaults to the 'login' named route.
    */
    'redirect_url' => env('BAN_REDIRECT_URL', 'login'),

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    | Customize the database table names used by this package.
    */
    'table_names' => [
        'bans' => 'bans',
        'banned_ips' => 'banned_ips',
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Alias for the CheckBanned middleware, registered automatically by the
    | ServiceProvider. You may change the alias if it conflicts with another
    | package.
    */
    'middleware_alias' => 'banned',

    /*
    |--------------------------------------------------------------------------
    | Allow Overlapping Bans
    |--------------------------------------------------------------------------
    | When set to false (recommended), calling ban() on a model that already
    | has an active ban (globally or on the same feature) will throw a
    | Godrade\LaravelBan\Exceptions\AlreadyBannedException instead of creating
    | a duplicate ban record.
    |
    | Set to true to allow stacking multiple active bans on the same model /
    | feature (legacy behaviour).
    */
    'allow_overlapping_bans' => (bool)env('BAN_ALLOW_OVERLAPPING', false),

    /*
    |--------------------------------------------------------------------------
    | Dynamic Relations
    |--------------------------------------------------------------------------
    | Register additional Eloquent relations on the Ban model at boot time.
    | Each entry must follow the shape:
    |
    |   'relation_name' => [
    |       'type'        => 'belongsTo',           // Eloquent relation method name
    |       'related' => App\Models\Preset::class,
    |       'foreign_key' => 'preset_id',           // optional
    |       'owner_key'   => 'id',                  // optional (belongsTo only)
    | ]
    |
    | Names listed in reserved_relations cannot be overridden.
    */
    'relations' => [
        // Example:
        // 'preset' => [
        //     'type'        => 'belongsTo',
        //     'related'     => \App\Models\Preset::class,
        //     'foreign_key' => 'preset_id',
        // ],
    ],

    'reserved_relations' => ['bannable', 'createdBy', 'cause'],

    /*
    |--------------------------------------------------------------------------
    | Soft-Delete Bans
    |--------------------------------------------------------------------------
    | When enabled, ban records are soft-deleted instead of permanently removed.
    | This preserves the audit trail of all bans applied to a model.
    */
    'soft_delete' => true,

    /*
    |--------------------------------------------------------------------------
    | Ban Statuses
    |--------------------------------------------------------------------------
    | Internal status values used on the bans table.
    | 'default' is the status assigned to every newly created ban.
    | Do not change these values after the first migration has run.
    */
    'statuses' => [
        'default' => 'active',
    ],

];
