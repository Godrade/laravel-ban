<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Support;

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Database\Eloquent\Model;

class ConcurrentBanUser extends Model implements Bannable
{
    use HasBans;

    protected $table = 'concurrent_ban_users';

    protected $guarded = [];
}
