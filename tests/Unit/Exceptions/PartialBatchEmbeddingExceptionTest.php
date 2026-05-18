<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Exceptions;

use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PartialBatchEmbeddingException;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PartialBatchEmbeddingExceptionTest extends TestCase
{
    public function test_implements_public_message_marker(): void
    {
        $exception = new PartialBatchEmbeddingException(
            completed: [],
            failedIndex: 0,
            cause: new EmbeddingProviderUnavailable('voyage', 'transport'),
        );

        $this->assertInstanceOf(PublicMessage::class, $exception);
    }

    public function test_message_no_longer_concatenates_cause(): void
    {
        // Pre-#132 the message embedded `$cause->getMessage()`, which
        // for an embedding driver could carry `$response->body()` —
        // potentially the user's note content sent for embedding.
        // Verify the cause's message does not appear in the wire text.
        $cause = new RuntimeException('user note content quoted by the provider');

        $e = new PartialBatchEmbeddingException(
            completed: [[0.1], [0.2]],
            failedIndex: 2,
            cause: $cause,
        );

        $this->assertSame(
            'Batch embedding partially failed at index 2 after 2 successes.',
            $e->getMessage(),
        );
        $this->assertStringNotContainsString('user note content', $e->getMessage());
        $this->assertStringNotContainsString('provider', $e->getMessage());
    }

    public function test_cause_is_preserved_as_previous_for_operator_report(): void
    {
        $cause = new EmbeddingProviderUnavailable('voyage', 'rate_limited');

        $e = new PartialBatchEmbeddingException(
            completed: [],
            failedIndex: 0,
            cause: $cause,
        );

        $this->assertSame($cause, $e->getPrevious());
    }

    public function test_completed_and_failed_index_are_exposed_as_public_readonly(): void
    {
        $e = new PartialBatchEmbeddingException(
            completed: [[0.1, 0.2], [0.3, 0.4]],
            failedIndex: 2,
            cause: new EmbeddingProviderUnavailable('bedrock', 'transport'),
        );

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $e->completed);
        $this->assertSame(2, $e->failedIndex);
    }
}
