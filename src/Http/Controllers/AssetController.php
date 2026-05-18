<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AssetController extends Controller
{
    public function css(): Response
    {
        // S-INT-17: consumers who publish `commonplace-css` can override
        // any --commonplace-* custom property by editing the published
        // file. The published path matches the destination registered in
        // CommonplaceServiceProvider::packageBooted. If absent, fall back
        // to the package's bundled copy inside vendor/.
        $published = resource_path('css/commonplace/commonplace.css');
        $bundled = __DIR__.'/../../../resources/css/commonplace/commonplace.css';

        $path = is_file($published) ? $published : $bundled;

        if (! is_file($path)) {
            abort(404);
        }

        $contents = (string) file_get_contents($path);

        // When debug is on (any environment — local dev, a staging deploy
        // chasing a prod-shaped bug, etc.) the consumer is iterating on
        // theme variables and needs each refresh to actually re-fetch.
        // Filenames are unversioned, so there's no query-string buster
        // available to them. `no-store` is sufficient and unambiguous
        // across intermediaries; `no-cache, must-revalidate` add nothing
        // once `no-store` is present.
        $cacheControl = (bool) config('app.debug')
            ? 'no-store'
            : 'public, max-age=3600';

        return response($contents, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => $cacheControl,
        ]);
    }

    public function js(): Response
    {
        // JS is not a published asset (no `commonplace-js` tag exists)
        // and is intentionally not consumer-overrideable; the script
        // surface is internal to the package's Blade layouts.
        $path = __DIR__.'/../../../resources/js/commonplace/commonplace.js';

        if (! is_file($path)) {
            abort(404);
        }

        $contents = (string) file_get_contents($path);

        return response($contents, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
