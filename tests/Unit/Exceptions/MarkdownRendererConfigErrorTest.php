<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use NonConvexLabs\Commonplace\Exceptions\MarkdownRendererConfigError;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MarkdownRendererConfigErrorTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new MarkdownRendererConfigError);
    }

    public function test_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new MarkdownRendererConfigError);
    }

    public function test_message_is_static(): void
    {
        $this->assertSame(
            'The markdown extension pipeline is misconfigured.',
            (new MarkdownRendererConfigError)->getMessage(),
        );
    }

    public function test_no_class_strings_or_config_keys_in_message(): void
    {
        // Pre-#132 the underlying throw interpolated $entry (class
        // string from config) and `get_debug_type($resolved)` (which
        // could return a host-app FQN). Pin that those never appear.
        $message = (new MarkdownRendererConfigError)->getMessage();

        $this->assertStringNotContainsString('commonplace.markdown', $message);
        $this->assertStringNotContainsString('ExtensionInterface', $message);
        $this->assertStringNotContainsString('class string', $message);
    }
}
