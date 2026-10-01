<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Livewire;

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Contracts\Bannable;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;

/** Resolve ban locks without requiring Livewire to be installed. */
final class BanLock
{
    public function denies(object $component, string $method): bool
    {
        $lock = $this->resolve($component, $method);
        $user = Auth::user();

        if ($lock === null || ! $user instanceof Bannable) {
            return false;
        }

        $banned = $lock->feature === null
            ? $user->isBanned()
            : $user->isBannedFrom($lock->feature);

        if ($banned) {
            session()->flash('ban_error', __('Your account has been suspended.'));
        }

        return $banned;
    }

    private function resolve(object $component, string $method): ?LockedByBan
    {
        $class = new ReflectionClass($component);

        if ($class->hasMethod($method)) {
            $attributes = $class->getMethod($method)->getAttributes(LockedByBan::class);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        // PHP does not inherit class attributes. Use the nearest declaration,
        // including a parent component's lock when a subclass has no override.
        do {
            $attributes = $class->getAttributes(LockedByBan::class);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        } while ($class = $class->getParentClass());

        return null;
    }
}
