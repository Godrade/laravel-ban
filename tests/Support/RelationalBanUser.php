<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Support;

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Database\Eloquent\Model;

final class RelationalBanUser extends Model implements Bannable
{
    use HasBans;

    protected $table = 'integration_users';

    protected $guarded = [];
}
