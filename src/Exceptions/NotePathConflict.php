<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use InvalidArgumentException;

/**
 * A move/rename target collides with an existing note path.
 *
 * Implements [[PublicMessage]] with a fully static message. The
 * pre-existing `\InvalidArgumentException` at this throw site
 * interpolated the destination path, which is agent-supplied today but
 * routed through `Response::error()` in the tool catch — bypassing the
 * MCP envelope chokepoint. A typed exception keeps the chokepoint
 * property: any non-`PublicMessage` exception that escapes the tool
 * handler is fail-closed by `CommonplaceMcpServer::publicMessageFor()`.
 *
 * Extends `\InvalidArgumentException` for source-compat with callers
 * (and tests) that catch on that family.
 */
final class NotePathConflict extends InvalidArgumentException implements PublicMessage
{
    public function __construct()
    {
        parent::__construct('A note already exists at the destination path.');
    }
}
