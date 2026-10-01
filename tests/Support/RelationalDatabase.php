<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Support;

use InvalidArgumentException;

/** Settings shared by the opt-in database suite and its independent workers. */
final class RelationalDatabase
{
    public static function enabled(): bool
    {
        return self::environment('BAN_TEST_DB_DRIVER', '') !== '';
    }

    public static function settings(): array
    {
        $driver = self::environment('BAN_TEST_DB_DRIVER', '');

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException('BAN_TEST_DB_DRIVER must be mysql or pgsql.');
        }

        return [
            'database.default' => 'integration',
            'database.connections.integration' => [
                'driver' => $driver,
                'host' => self::environment('BAN_TEST_DB_HOST', '127.0.0.1'),
                'port' => self::environment('BAN_TEST_DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
                'database' => self::environment('BAN_TEST_DB_DATABASE', 'laravel_ban'),
                'username' => self::environment('BAN_TEST_DB_USERNAME', $driver === 'pgsql' ? 'postgres' : 'root'),
                'password' => self::environment('BAN_TEST_DB_PASSWORD', ''),
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'ban.table_names.bans' => 'integration_bans',
            'ban.table_names.banned_ips' => 'integration_banned_ips',
            'ban.soft_delete' => true,
            'ban.statuses.default' => 'active',
            'ban.allow_overlapping_bans' => false,
            'ban.cache_driver' => 'array',
            'ban.cache_ttl' => 3600,
        ];
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
