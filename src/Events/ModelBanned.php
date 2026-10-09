<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Events;

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Models\Ban;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ModelBanned
{
    use Dispatchable, SerializesModels;

    public readonly ?string $feature;

    public function __construct(
        public readonly Bannable $bannable,
        public readonly Ban $ban,
    ) {
        $this->feature = $ban->feature;
    }
}
