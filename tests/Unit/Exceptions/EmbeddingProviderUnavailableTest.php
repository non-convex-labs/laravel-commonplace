<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use LogicException;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Message-pinning suite for [[EmbeddingProviderUnavailable]]. Mirrors
 * the precedent set by `test_not_ready_message_uses_driver_name_not_connection_name`
 * in PgvectorDriverTest: lock the exact wire-visible text so a future
 * "improve the message" refactor that splices in `$response->body()`
 * or any other caller-controlled value fails CI loudly.
 */
class EmbeddingProviderUnavailableTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $this->assertInstanceOf(PublicMessage::class, new EmbeddingProviderUnavailable('openai', 'rate_limited'));
    }

    public function test_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new EmbeddingProviderUnavailable('openai', 'rate_limited'));
    }

    public function test_rate_limited_message_is_static(): void
    {
        $e = new EmbeddingProviderUnavailable('voyage', 'rate_limited');

        $this->assertSame(
            "Embedding provider 'voyage' is unavailable (rate-limited). Retry with backoff.",
            $e->getMessage(),
        );
        $this->assertSame('voyage', $e->provider);
        $this->assertSame('rate_limited', $e->reason);
    }

    public function test_unauthorized_message_is_static(): void
    {
        $this->assertSame(
            "Embedding provider 'openai' rejected the request (unauthorized). Check the configured API key.",
            (new EmbeddingProviderUnavailable('openai', 'unauthorized'))->getMessage(),
        );
    }

    public function test_invalid_request_message_is_static(): void
    {
        $this->assertSame(
            "Embedding provider 'cohere' rejected the request as invalid. Do not retry.",
            (new EmbeddingProviderUnavailable('cohere', 'invalid_request'))->getMessage(),
        );
    }

    public function test_unexpected_payload_message_is_static(): void
    {
        $this->assertSame(
            "Embedding provider 'bedrock' returned an unexpected payload.",
            (new EmbeddingProviderUnavailable('bedrock', 'unexpected_payload'))->getMessage(),
        );
    }

    public function test_transport_message_is_static(): void
    {
        $this->assertSame(
            "Embedding provider 'openai' is unavailable (transport error). Retry with backoff.",
            (new EmbeddingProviderUnavailable('openai', 'transport'))->getMessage(),
        );
    }

    public function test_unknown_provider_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Unknown embedding provider 'palm'");

        new EmbeddingProviderUnavailable('palm', 'rate_limited');
    }

    public function test_unknown_reason_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Unknown reason 'mystery'");

        new EmbeddingProviderUnavailable('openai', 'mystery');
    }

    public function test_from_status_maps_429_to_rate_limited(): void
    {
        $e = EmbeddingProviderUnavailable::fromStatus('voyage', 429);

        $this->assertSame('rate_limited', $e->reason);
    }

    public function test_from_status_maps_401_and_403_to_unauthorized(): void
    {
        $this->assertSame('unauthorized', EmbeddingProviderUnavailable::fromStatus('openai', 401)->reason);
        $this->assertSame('unauthorized', EmbeddingProviderUnavailable::fromStatus('openai', 403)->reason);
    }

    public function test_from_status_maps_other_4xx_to_invalid_request(): void
    {
        $this->assertSame('invalid_request', EmbeddingProviderUnavailable::fromStatus('openai', 400)->reason);
        $this->assertSame('invalid_request', EmbeddingProviderUnavailable::fromStatus('openai', 404)->reason);
        $this->assertSame('invalid_request', EmbeddingProviderUnavailable::fromStatus('openai', 422)->reason);
    }

    public function test_from_status_maps_5xx_to_transport(): void
    {
        $this->assertSame('transport', EmbeddingProviderUnavailable::fromStatus('openai', 500)->reason);
        $this->assertSame('transport', EmbeddingProviderUnavailable::fromStatus('openai', 502)->reason);
        $this->assertSame('transport', EmbeddingProviderUnavailable::fromStatus('openai', 504)->reason);
    }

    /**
     * Adversarial input: a hypothetical caller passes the response body
     * as a "reason" by mistake. The constructor must reject — never
     * splice arbitrary text into the wire-visible message.
     */
    public function test_constructor_rejects_caller_supplied_text_as_reason(): void
    {
        $this->expectException(LogicException::class);

        new EmbeddingProviderUnavailable(
            'voyage',
            'Voyage AI API error: rate limited; your input "secret user note" exceeded quota',
        );
    }

    /**
     * The cause stays accessible via getPrevious() for operator
     * report() but is never interpolated into the wire message.
     */
    public function test_previous_chain_preserved_for_operator_report(): void
    {
        $cause = new RuntimeException('cause-only details');

        $e = new EmbeddingProviderUnavailable('openai', 'transport', previous: $cause);

        $this->assertSame($cause, $e->getPrevious());
        $this->assertStringNotContainsString('cause-only details', $e->getMessage());
    }
}
