<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Enums;

enum BanStatus: string
{
    /** The ban is currently enforced. */
    case ACTIVE = 'active';

    /** The ban was manually cancelled (via unban()). */
    case CANCELLED = 'cancelled';

    // Note: EXPIRED is intentionally absent — expiry is a calculated state
    // derived from the expired_at timestamp, not a stored status value.
}
