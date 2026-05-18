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

        return response($contents, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
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
