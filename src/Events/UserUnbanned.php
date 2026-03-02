<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class UserUnbanned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $bannable,
        public readonly ?string $feature = null,
    ) {}
}
