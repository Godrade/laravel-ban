<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Blade;

use Illuminate\Support\Facades\Auth;
use Godrade\LaravelBan\Traits\HasBans;

final class BanDirectives
{
    public function register(\Illuminate\View\Compilers\BladeCompiler $blade): void
    {
        // @banned ... @endbanned
        $blade->if('banned', function (): bool {
            return $this->currentUserIsBanned();
        });

        // @notBanned ... @endnotBanned
        $blade->if('notBanned', function (): bool {
            return ! $this->currentUserIsBanned();
        });

        // @bannedFrom('feature') ... @endbannedFrom
        $blade->if('bannedFrom', function (string $feature): bool {
            return $this->currentUserIsBannedFrom($feature);
        });
    }

    private function currentUserIsBanned(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $this->usesTrait($user)) {
            return false;
        }

        return $user->isBanned();
    }

    private function currentUserIsBannedFrom(string $feature): bool
    {
        $user = Auth::user();

        if ($user === null || ! $this->usesTrait($user)) {
            return false;
        }

        return $user->isBannedFrom($feature);
    }

    private function usesTrait(mixed $user): bool
    {
        return in_array(HasBans::class, class_uses_recursive($user), strict: true);
    }
}
