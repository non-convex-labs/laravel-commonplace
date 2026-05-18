<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use NonConvexLabs\Commonplace\Http\Controllers\AssetController;
use NonConvexLabs\Commonplace\Http\Controllers\GraphController;
use NonConvexLabs\Commonplace\Http\Controllers\NoteController;
use NonConvexLabs\Commonplace\Http\Controllers\PublicNoteController;
use NonConvexLabs\Commonplace\Http\Controllers\SearchController;

$authEnabled = (bool) config('commonplace.routes.enabled', true);
$publicEnabled = (bool) config('commonplace.routes.public.enabled', false);

if (! $authEnabled && ! $publicEnabled) {
    return;
}

// Asset routes register whenever any group is active. A public-only
// deployment (auth disabled, public-read enabled) still needs the CSS
// link the rendered template points at.
Route::middleware(['web'])
    ->prefix((string) config('commonplace.routes.prefix', 'commonplace'))
    ->as('commonplace.')
    ->group(function (): void {
        Route::get('/assets/commonplace.css', [AssetController::class, 'css'])->name('asset.css');
        Route::get('/assets/commonplace.js', [AssetController::class, 'js'])->name('asset.js');
    });

// Public-read group is registered BEFORE the authenticated group so
// `/{prefix}/public/foo` matches `commonplace.public.show` instead of
// being caught by the authenticated `{path}` catch-all (which would
// 302 the visitor to login).
if ($publicEnabled) {
    // Empty-string override falls back to the default; non-empty values get
    // their slashes normalized so leading/trailing `/` from .env don't
    // produce `//` or trailing-slash inconsistency at registration time.
    $rawPublicPrefix = config('commonplace.routes.public.prefix');
    $publicPrefix = is_string($rawPublicPrefix) && trim($rawPublicPrefix, '/') !== ''
        ? trim($rawPublicPrefix, '/')
        : trim((string) config('commonplace.routes.prefix', 'commonplace'), '/').'/public';

    Route::middleware(config('commonplace.routes.public.middleware', ['web']))
        ->prefix($publicPrefix)
        ->as('commonplace.public.')
        ->group(function (): void {
            // The bare public URL (`/{prefix}/public` and the trailing-slash
            // variant) is sealed off here. Without this, the empty-path
            // case doesn't match `/{path}` and falls into the auth
            // catch-all — leaking a 302 to /login for a route that is
            // supposed to be public-read only. See S-PUB-04 / #96.
            // Bound to a controller method (not a closure) so
            // `php artisan route:cache` works for downstream consumers.
            Route::get('/', [PublicNoteController::class, 'root'])->name('root');

            Route::get('/raw/{path}', [PublicNoteController::class, 'showRaw'])
                ->where('path', '.*')
                ->name('showRaw');

            Route::get('/{path}', [PublicNoteController::class, 'show'])
                ->where('path', '.*')
                ->name('show');
        });

    // Non-GET method trap at the active prefix, deliberately WITHOUT
    // `web` middleware. Without this, PUT/DELETE on a public URL fall
    // through to the authenticated catch-all and trip CSRF (419) before
    // auth runs. The trap returns 405 from a clean boundary instead.
    // See S-PUB-05 / #97.
    Route::prefix($publicPrefix)
        ->group(function (): void {
            Route::match(
                ['POST', 'PUT', 'PATCH', 'DELETE'],
                '/{any?}',
                [PublicNoteController::class, 'methodNotAllowed'],
            )->where('any', '.*');
        });
}

// When the public group is OFF, seal its *default* prefix
// (`<auth-prefix>/public/...`) with a no-middleware 404 trap. Without
// this, URLs under `/commonplace/public/...` fall through to the auth
// catch-all and 302 unauthenticated visitors to /login — leaking that
// the URL space is at least handled. An overridden public prefix
// doesn't need sealing because it sits outside the auth catch-all.
// See S-PUB-06 / #97.
if (! $publicEnabled) {
    $defaultPublicPrefix = trim((string) config('commonplace.routes.prefix', 'commonplace'), '/').'/public';

    Route::prefix($defaultPublicPrefix)
        ->group(function (): void {
            Route::any('/{any?}', [PublicNoteController::class, 'disabled'])->where('any', '.*');
        });
}

if (! $authEnabled) {
    return;
}

Route::middleware(config('commonplace.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('commonplace.routes.prefix', 'commonplace'))
    ->as('commonplace.')
    ->group(function (): void {
        Route::get('/', [NoteController::class, 'index'])->name('index');

        Route::get('/create', [NoteController::class, 'create'])->name('create');
        Route::post('/', [NoteController::class, 'store'])->name('store');

        Route::get('/graph', [GraphController::class, 'graph'])->name('graph');
        Route::get('/api/graph', [GraphController::class, 'graphApi'])->name('graph.api');

        Route::get('/search', [SearchController::class, 'search'])->name('search');
        Route::get('/api/search', [SearchController::class, 'searchApi'])->name('search.api');

        Route::get('/api/neighborhood/{path}', [GraphController::class, 'neighborhood'])
            ->where('path', '.*')
            ->name('neighborhood');

        Route::get('/api/suggested-links/{path}', [SearchController::class, 'suggestedLinks'])
            ->where('path', '.*')
            ->name('suggested-links');

        Route::get('/raw/{path}', [NoteController::class, 'showRaw'])
            ->where('path', '.*')
            ->name('showRaw');

        Route::get('/download/{path}', [NoteController::class, 'downloadRaw'])
            ->where('path', '.*')
            ->name('downloadRaw');

        Route::get('/edit/{path}', [NoteController::class, 'edit'])
            ->where('path', '.*')
            ->name('edit');

        Route::get('/history/{path}/{version}', [NoteController::class, 'historyVersion'])
            ->where('path', '.*')
            ->where('version', '[0-9]+')
            ->name('historyVersion');

        Route::get('/history/{path}', [NoteController::class, 'history'])
            ->where('path', '.*')
            ->name('history');

        Route::put('/{path}', [NoteController::class, 'update'])
            ->where('path', '.*')
            ->name('update');

        Route::delete('/{path}', [NoteController::class, 'destroy'])
            ->where('path', '.*')
            ->name('destroy');

        Route::get('/{path}', [NoteController::class, 'show'])
            ->where('path', '.*')
            ->name('show');
    });
