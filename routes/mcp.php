<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// MCP routes. Gated by config('commonplace.mcp.enabled') in the service
// provider — this file only loads when MCP is on.

Route::prefix((string) config('commonplace.mcp.prefix', 'mcp/commonplace'))
    ->as('commonplace.mcp.')
    ->group(function (): void {
        // CommonplaceMcpServer routes wired here as the MCP server is ported.
    });
