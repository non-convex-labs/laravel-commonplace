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
    Route::middleware(config('commonplace.routes.public.middleware', ['web']))
        ->prefix((string) config('commonplace.routes.prefix', 'commonplace').'/public')
        ->as('commonplace.public.')
        ->group(function (): void {
            Route::get('/raw/{path}', [PublicNoteController::class, 'showRaw'])
                ->where('path', '.*')
                ->name('showRaw');

            Route::get('/{path}', [PublicNoteController::class, 'show'])
                ->where('path', '.*')
                ->name('show');
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
