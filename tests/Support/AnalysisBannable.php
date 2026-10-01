<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Support;

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Database\Eloquent\Model;

/** Concrete Eloquent context for static analysis of the public HasBans trait. */
abstract class AnalysisBannable extends Model implements Bannable
{
    use HasBans;
}
