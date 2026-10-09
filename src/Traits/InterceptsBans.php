<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Traits;

use Godrade\LaravelBan\Livewire\BanLock;

/**
 * Opt a Livewire 3 or 4 component into automatic #[LockedByBan] checks.
 *
 * The package's action guard runs before Livewire dispatches actions and event
 * listeners. A denied action receives HTTP 403 and flashes "ban_error".
 */
trait InterceptsBans
{
    /**
     * Check a lock during an internal PHP call, which does not pass through
     * Livewire's action dispatcher. The caller must return when this is true.
     *
     * Protected so that this helper is not exposed as a Livewire action.
     */
    protected function checkBanLock(string $method): bool
    {
        return (new BanLock)->denies($this, $method);
    }
}
