<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Exceptions\NotePathConflict;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;

class NotePathConflictTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new NotePathConflict);
    }

    public function test_extends_invalid_argument_exception(): void
    {
        // Source-compat: callers (and tests) that catch on
        // \InvalidArgumentException should still match. The throw site
        // in Commonplace::moveNote() previously raised a bare
        // \InvalidArgumentException — preserving the family avoids a
        // breaking change for consumers catching by that class.
        $this->assertInstanceOf(InvalidArgumentException::class, new NotePathConflict);
    }

    public function test_message_is_static(): void
    {
        // Byte-for-byte pin. The original throw interpolated $toPath,
        // bypassing the MCP envelope chokepoint when MoveTool caught
        // and re-emitted via Response::error(). Any future drift that
        // reintroduces caller input would change the message and
        // fail here.
        $this->assertSame(
            'A note already exists at the destination path.',
            (new NotePathConflict)->getMessage(),
        );
    }
}
