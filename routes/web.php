<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

if (! (bool) config('commonplace.routes.enabled', true)) {
    return;
}

Route::middleware(config('commonplace.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('commonplace.routes.prefix', 'commonplace'))
    ->as('commonplace.')
    ->group(function (): void {
        // Controllers will be wired here as they are ported from the
        // upstream nonconvexlabs-com application.
    });
