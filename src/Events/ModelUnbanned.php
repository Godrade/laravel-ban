<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Godrade\LaravelBan\Contracts\Bannable;

final class ModelUnbanned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Bannable $bannable,
        public readonly ?string $feature = null,
    ) {}
}
