# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- `InterceptsBans` trait for Livewire v2/v3 integration
- Blade directives: `@banned`, `@notBanned`, `@bannedFrom`, `@bannedIp`, `@anyBan`, `@allBanned`
- Artisan commands: `ban:user`, `ban:config`, `ban:list`, `ban:remove`
- Full Pest test suite

[Unreleased]: https://github.com/godrade/laravel-ban/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/godrade/laravel-ban/releases/tag/v1.0.0
