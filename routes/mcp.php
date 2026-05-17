<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use NonConvexLabs\Commonplace\Mcp\CommonplaceMcpServer;

// MCP routes. Gated by config('commonplace.mcp.enabled') in the service
// provider — this file only loads when MCP is on.
//
// The middleware stack is applied as a **route group** rather than
// chained off `Mcp::web()`'s returned `Route`. The registrar inside
// `laravel/mcp` registers a 405-`Allow: POST` GET and a 405 DELETE
// alongside the POST handler; chaining only the POST would leave the
// 405 stubs unauthenticated. Wrapping the call in `Route::middleware(...)
// ->group(...)` catches every route the registrar adds — present and
// future (e.g. a GET for SSE). See PR #54 for the rationale.

Route::middleware((array) config('commonplace.mcp.middleware', ['auth:sanctum']))
    ->group(function (): void {
        Mcp::web(
            '/'.ltrim((string) config('commonplace.mcp.prefix', 'mcp/commonplace'), '/'),
            CommonplaceMcpServer::class,
        );
    });
