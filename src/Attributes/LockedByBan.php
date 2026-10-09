<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Attributes;

use Attribute;

/**
 * Marks a Livewire 3 or 4 action (or all actions on a component class) as
 * locked when the authenticated user carries an active ban. Requires the
 * InterceptsBans trait. Event listeners are checked against their target method.
 *
 * Usage on a single method:
 *   #[LockedByBan]
 *   #[LockedByBan(feature: 'comments')]
 *
 * Usage on a component class (all remotely invoked actions become locked):
 *   #[LockedByBan]
 *   #[LockedByBan(feature: 'forum')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class LockedByBan
{
    /**
     * @param  string|null  $feature  When set, the lock only triggers if the user
     *                                is banned from this specific feature. When null,
     *                                any active global ban triggers the lock.
     */
    public function __construct(
        public readonly ?string $feature = null,
    ) {}
}
