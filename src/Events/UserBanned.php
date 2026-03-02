<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Godrade\LaravelBan\Models\Ban;

final class UserBanned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $bannable,
        public readonly Ban $ban,
    ) {}
}
