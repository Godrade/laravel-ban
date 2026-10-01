<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Support;

use Godrade\LaravelBan\Traits\InterceptsBans;
use Livewire\Component;

/** Livewire context for static analysis of the optional integration trait. */
abstract class AnalysisComponent extends Component
{
    use InterceptsBans;
}
