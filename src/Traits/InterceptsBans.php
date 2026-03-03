<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Traits;

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Adds declarative ban-locking to Livewire components.
 *
 * Add this trait to any Livewire component. Then annotate methods (or the
 * component class itself) with #[LockedByBan] to block execution when the
 * authenticated user is banned.
 *
 * Class-level attribute  → every method call on this component is locked.
 * Method-level attribute → only that specific method is locked.
 * Method attribute takes precedence over class attribute for the feature scope.
 *
 * Compatible with Livewire v2 (callMethod) and usable in v3 via the public
 * checkBanLock() helper in lifecycle hooks.
 */
trait InterceptsBans
{
    // -------------------------------------------------------------------------
    // Livewire v2 – callMethod override
    // -------------------------------------------------------------------------

    /**
     * Intercept every Livewire method call.
     *
     * Livewire v2 calls this before dispatching to the actual component method.
     * We inspect the target method for #[LockedByBan] (and the class itself),
     * run the ban check, and either abort or forward to the real implementation.
     *
     * @param  string        $method
     * @param  array<mixed>  $params
     * @param  callable|null $captureReturnValueCallback
     */
    public function callMethod(
        string $method,
        array $params = [],
        ?callable $captureReturnValueCallback = null,
    ): mixed {
        if ($this->checkBanLock($method)) {
            return null;
        }

        // Forward to parent (Livewire\Component) when available, otherwise
        // invoke the method directly (useful for tests without Livewire).
        if (is_callable(['parent', 'callMethod'])) {
            return parent::callMethod($method, $params, $captureReturnValueCallback);
        }

        $result = $this->{$method}(...$params);

        if ($captureReturnValueCallback !== null) {
            ($captureReturnValueCallback)($result);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Public helper (Livewire v3 / manual use)
    // -------------------------------------------------------------------------

    /**
     * Check whether a method call should be blocked due to a ban.
     *
     * Returns true and flashes ban_error when the call must be aborted.
     * Can be called manually in Livewire v3 lifecycle hooks.
     *
     * @example
     *   public function boot(): void
     *   {
     *       if ($this->checkBanLock('postComment')) { return; }
     *   }
     */
    public function checkBanLock(string $method): bool
    {
        $lock = $this->resolveLock($method);

        if ($lock === null) {
            return false;
        }

        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if (! in_array(HasBans::class, class_uses_recursive($user), strict: true)) {
            return false;
        }

        $banned = $lock->feature !== null
            ? $user->isBannedFrom($lock->feature)
            : $user->isBanned();

        if ($banned) {
            session()->flash('ban_error', __('Your account has been suspended.'));
        }

        return $banned;
    }

    // -------------------------------------------------------------------------
    // Reflection helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the effective #[LockedByBan] attribute for a given method name.
     *
     * Priority:
     *   1. Method-level attribute (most specific)
     *   2. Class-level attribute  (component-wide lock)
     *   3. null → no lock
     */
    private function resolveLock(string $method): ?LockedByBan
    {
        try {
            $class = new ReflectionClass($this);
        } catch (ReflectionException) {
            return null;
        }

        // 1. Method-level
        if ($class->hasMethod($method)) {
            $refMethod    = $class->getMethod($method);
            $methodAttrs  = $refMethod->getAttributes(LockedByBan::class);

            if (! empty($methodAttrs)) {
                /** @var LockedByBan */
                return $methodAttrs[0]->newInstance();
            }
        }

        // 2. Class-level
        $classAttrs = $class->getAttributes(LockedByBan::class);

        if (! empty($classAttrs)) {
            /** @var LockedByBan */
            return $classAttrs[0]->newInstance();
        }

        return null;
    }
}
