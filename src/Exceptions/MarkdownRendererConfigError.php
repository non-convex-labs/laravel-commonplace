<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use RuntimeException;

/**
 * Misconfiguration of the markdown extension pipeline — an entry in
 * `commonplace.markdown.extensions` that does not resolve to an
 * `ExtensionInterface`, or is neither a class string nor an extension
 * instance.
 *
 * Implements [[PublicMessage]] with a fully static message. The
 * original throw text interpolated the offending class string and
 * `get_debug_type(...)` — both of those are config-derived rather than
 * request input, but a class FQN can still reveal host application
 * structure (e.g. `Acme\Internal\TenantMarkdownExtension`). The
 * operator gets the full detail via `report()`; the wire never sees it.
 *
 * The throw fires inside `MarkdownRenderer::buildConverter()`, which is
 * lazy on first `render()` — so this surfaces on a note-read MCP tool
 * call, not at boot. The fixed message is what reaches the agent;
 * `commonplace:doctor` is where the operator should see the detail.
 */
final class MarkdownRendererConfigError extends RuntimeException implements PublicMessage
{
    public function __construct()
    {
        parent::__construct('The markdown extension pipeline is misconfigured.');
    }
}
