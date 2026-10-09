<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Livewire;

use Godrade\LaravelBan\Traits\InterceptsBans;
use Livewire\Component;
use Livewire\Features\SupportEvents\SupportEvents;

use function Livewire\before;

/** Registered only when the optional Livewire integration is available. */
final class BanActionGuard
{
    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        // Run before Livewire's component hooks: SupportEvents executes event
        // listeners inside its own "call" hook, before normal action dispatch.
        before('call', function (Component $component, string $method, array $params): void {
            if (! in_array(InterceptsBans::class, class_uses_recursive($component), true)) {
                return;
            }

            if ($method === '__dispatch') {
                $event = $params[0] ?? null;

                if (! is_string($event) || ! in_array($event, SupportEvents::getListenerEventNames($component), true)) {
                    return; // Let Livewire reject an unknown or malformed event.
                }

                $method = SupportEvents::getListenerMethodName($component, $event);
            }

            // An early-return callback does not stop subsequent hooks, so it
            // cannot safely prevent an event listener from executing.
            abort_if((new BanLock)->denies($component, $method), 403, __('Your account has been suspended.'));
        });
    }
}
