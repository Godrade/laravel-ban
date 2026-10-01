# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Breaking:** optional Livewire integration now supports only Livewire 3 and 4. Locked actions and event listeners return HTTP 403 before execution instead of silently returning `null`.
- **Breaking:** removed the public `callMethod()` dispatcher; `checkBanLock()` is now protected and remains available for explicit checks inside a component.
- **Breaking:** `syncBan()` preserves omitted fields on update. Pass `null` explicitly to clear a reason, expiration, creator or cause.
- Ban events are dispatched after the surrounding transaction commits, after cache invalidation. Rolled-back changes no longer dispatch these events.
- User-ban cache entries use hashed, versioned `v2` keys. Previous cache entries are no longer read and expire naturally.
- Dynamic relations accept Eloquent models and the supported `belongsTo`, `hasOne` and `hasMany` types; invalid definitions are logged and ignored.

### Fixed

- Cache invalidation for global and feature bans, the literal `global` feature, expiration, direct Eloquent saves/deletes/restores, owner or scope changes, and polymorphic aliases.
- Cache reads inside transactions bypass shared entries; writes invalidate again after commit and prevent in-flight reads from repopulating the current generation with stale results.
- User and IP ban lookups use the write connection so read-replica lag cannot repopulate the cache with an older ban state.
- Ban creation and synchronization lock the persisted parent model in a transaction, including when no ban exists yet. Recursion protection now follows database identity across model instances.
- Created bans expose their active status immediately, and `cause` is persisted by `ban()` and `syncBan()`.
- Both ban models respect `soft_delete=false` without querying a missing `deleted_at` column.
- IP and Blade memoization is shared per HTTP request, respects expiration and transactions, and is invalidated by Eloquent mutations without manual Octane resets.
- Multiple bans can share an IP address across features or historical records. Valid IPv6 addresses are normalized when saved and queried, and `BannedIp::create()` accepts a `created_by` model.
- Livewire checks cover method and class attributes, inherited locks, feature overrides, and both attributed and configured event listeners without exposing private methods.
- `ban:list --status=cancelled` includes cancellation history, model filters resolve morph aliases, and removal invalidates cache without resolving a morph alias as a class name.
- Invalid CLI durations and duplicate bans produce a clear failure instead of creating unintended permanent bans or exposing uncaught errors.
- User middleware returns JSON 403 for API requests, supports relative redirect paths and falls back to HTTP 403 for missing routes or redirect loops.
- Dynamic `belongsTo` relations infer their foreign key from the configured relation name.
- Pruning documentation explicitly schedules both package models in Laravel 11+.

### Added

- Upgrade migration `2026_10_01_000003_allow_multiple_bans_per_ip.php`: removes single-IP uniqueness and normalizes existing IPv6 records. Its rollback deliberately preserves the non-unique index and normalized values to avoid losing newer data.
- Regression tests using real package migrations and real Livewire components, covering cache lifecycle, transactions, IP requests, schema configuration, HTTP responses and console validation.
- Composer commands for Pint formatting checks, Larastan/PHPStan analysis and tests, combined under `composer check`.
- Opt-in MySQL/PostgreSQL integration tests configured through `BAN_TEST_DB_*`, including concurrent mutations. These tests require a disposable database and recreate their `integration_*` tables.
- CI jobs for the Laravel 11/12/13 and Livewire 3/4 combinations, optional operation without Livewire, and MySQL 8/PostgreSQL 16 services.
- Compatibility documentation as of October 1, 2026: Laravel 11 remains declared compatible, but unresolved upstream dependency advisories block normal Composer installation. Only isolated legacy compatibility jobs permit those dependencies for testing; the current-dependency quality job retains strict security blocking and auditing.

## [1.0.0] - 2025-01-01

### Added

- Core `ban()`, `unban()`, `isBanned()`, and `isBannedFrom()` API with multi-driver cache support via `HasBans` trait
- Feature-scoped bans allowing bans to target specific areas (e.g. `comments`, `forum`)
- `AlreadyBannedException` with overlapping ban protection (configurable via `allow_overlapping_bans`)
- `syncBan()` upsert method for idempotent ban creation and updates
- `BanStatus` enum with `ACTIVE` and `CANCELLED` states — `unban()` cancels rather than deletes records
- Anti-recursion static lock in `HasBans` using `spl_object_hash`
- `Ban` and `BannedIp` Eloquent models with `MassPrunable` support
- Dynamic Eloquent relations on the `Ban` model via `config('ban.relations')`
- `cause()` polymorphic relation on the `Ban` model
- `ModelBanned`, `ModelUnbanned`, and `ModelBanUpdated` events
- `CheckBanned` middleware with feature scope and redirect configuration
- `BlockBannedIp` middleware with per-request memoization
- `#[LockedByBan]` PHP 8.2 attribute (`TARGET_METHOD | TARGET_CLASS`)
- Initial `InterceptsBans` trait for Livewire integration
- Blade directives: `@banned`, `@notBanned`, `@bannedFrom`, `@bannedIp`, `@anyBan`, `@allBanned`
- Artisan commands: `ban:user`, `ban:config`, `ban:list`, `ban:remove`
- Full Pest test suite

[Unreleased]: https://github.com/godrade/laravel-ban/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/godrade/laravel-ban/releases/tag/v1.0.0
