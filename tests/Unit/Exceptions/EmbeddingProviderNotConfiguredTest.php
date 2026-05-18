<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use LogicException;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EmbeddingProviderNotConfiguredTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new EmbeddingProviderNotConfigured('openai'));
    }

    public function test_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new EmbeddingProviderNotConfigured('openai'));
    }

    public function test_message_is_static_per_provider(): void
    {
        $this->assertSame(
            "Embedding provider 'openai' is not configured.",
            (new EmbeddingProviderNotConfigured('openai'))->getMessage(),
        );
        $this->assertSame(
            "Embedding provider 'voyage' is not configured.",
            (new EmbeddingProviderNotConfigured('voyage'))->getMessage(),
        );
        $this->assertSame(
            "Embedding provider 'cohere' is not configured.",
            (new EmbeddingProviderNotConfigured('cohere'))->getMessage(),
        );
        $this->assertSame(
            "Embedding provider 'bedrock' is not configured.",
            (new EmbeddingProviderNotConfigured('bedrock'))->getMessage(),
        );
    }

    public function test_unknown_provider_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Unknown embedding provider 'palm'");

        new EmbeddingProviderNotConfigured('palm');
    }

    public function test_no_config_keys_or_remediation_hints_in_message(): void
    {
        // The pre-#132 throws interpolated config keys like
        // 'commonplace.embedding.openai.api_key' and remediation hints
        // like 'composer require aws/aws-sdk-php'. Pin that the wire
        // message no longer carries them.
        $message = (new EmbeddingProviderNotConfigured('bedrock'))->getMessage();

        $this->assertStringNotContainsString('commonplace.embedding', $message);
        $this->assertStringNotContainsString('composer require', $message);
        $this->assertStringNotContainsString('aws-sdk-php', $message);
    }
}
