<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Facades;

use Illuminate\Support\Facades\Facade;

class Commonplace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'commonplace';
    }
}
