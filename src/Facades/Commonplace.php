<?php

declare(strict_types=1);

namespace NonconvexLabs\Commonplace\Facades;

use Illuminate\Support\Facades\Facade;

class Commonplace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'commonplace';
    }
}
