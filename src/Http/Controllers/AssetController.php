<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class AssetController extends Controller
{
    public function css(): Response
    {
        $path = __DIR__.'/../../../resources/css/commonplace/commonplace.css';

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
