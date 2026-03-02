<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Exceptions;

use Godrade\LaravelBan\Models\Ban;
use RuntimeException;

final class AlreadyBannedException extends RuntimeException
{
    public function __construct(
        public readonly Ban $existingBan,
    ) {
        $scope = $existingBan->feature
            ? "feature [{$existingBan->feature}]"
            : 'globally';

        $expiry = $existingBan->expired_at
            ? "expires at {$existingBan->expired_at->toDateTimeString()}"
            : 'permanent';

        parent::__construct(
            "This model is already banned {$scope} ({$expiry}). " .
            'Call unban() first or wait for the existing ban to expire.',
        );
    }
}
