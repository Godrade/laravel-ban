<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Attributes;

use Attribute;

/**
 * Marks a Livewire method (or an entire component class) as locked when the
 * authenticated user carries an active ban.
 *
 * Usage on a single method:
 *   #[LockedByBan]
 *   #[LockedByBan(feature: 'comments')]
 *
 * Usage on a component class (all methods become locked):
 *   #[LockedByBan]
 *   #[LockedByBan(feature: 'forum')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class LockedByBan
{
    /**
     * @param string|null $feature  When set, the lock only triggers if the user
     *                              is banned from this specific feature. When null,
     *                              any active global ban triggers the lock.
     */
    public function __construct(
        public readonly ?string $feature = null,
    ) {}
}
