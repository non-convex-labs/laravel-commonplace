<?php

declare(strict_types=1);

use Laravel\Mcp\Facades\Mcp;
use NonConvexLabs\Commonplace\Mcp\CommonplaceMcpServer;

// MCP routes. Gated by config('commonplace.mcp.enabled') in the service
// provider — this file only loads when MCP is on.

Mcp::web(
    '/'.ltrim((string) config('commonplace.mcp.prefix', 'mcp/commonplace'), '/'),
    CommonplaceMcpServer::class,
);
