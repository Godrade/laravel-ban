<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Support;

use Godrade\LaravelBan\Models\BannedIp;
use Illuminate\Http\Request;
use WeakMap;

/** Shared memoization whose lifetime is tied to the actual HTTP request. */
final class IpBanLookup
{
    /** @var WeakMap<Request, array<string, array{banned: bool, expires: float|null}>>|null */
    private static ?WeakMap $requests = null;

    public static function isBanned(Request $request, string $ip, ?string $feature = null): bool
    {
        if ($ip === '') {
            return false;
        }

        $model = new BannedIp;
        $connection = $model->getConnection();
        $inTransaction = $connection->transactionLevel() > 0;
        $key = json_encode([
            BannedIp::normalizeIp($ip), $feature, $connection->getName(),
            $connection->getDatabaseName(), $connection->getTablePrefix(),
            $model->getTable(), config('ban.soft_delete', true),
        ], JSON_THROW_ON_ERROR);
        self::$requests ??= new WeakMap;
        $entries = self::$requests[$request] ?? [];
        $entry = $entries[$key] ?? null;

        if (! $inTransaction && $entry !== null
            && ($entry['expires'] === null || now()->getTimestampMs() / 1000 < $entry['expires'])) {
            return $entry['banned'];
        }

        // Enforce the committed state even when a read replica is lagging.
        $query = $model->newQuery()->useWritePdo()->active()->forIp($ip);

        if ($feature !== null) {
            $query->forFeature($feature);
        }

        // Cache the decision only until the earliest matching ban expires.
        $bans = $query->get(['expired_at']);
        $expires = $bans->pluck('expired_at')->filter()->min();
        $banned = $bans->isNotEmpty();

        if (! $inTransaction) {
            $entries[$key] = [
                'banned' => $banned,
                'expires' => $expires === null ? null : $expires->getTimestampMs() / 1000.0,
            ];
            self::$requests[$request] = $entries;
        }

        return $banned;
    }

    public static function flush(): void
    {
        self::$requests = new WeakMap;
    }
}
