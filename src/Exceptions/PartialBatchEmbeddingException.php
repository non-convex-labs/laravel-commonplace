<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a batch embedding call partially succeeded: at least one
 * sub-call completed before another failed. The completed embeddings
 * are exposed so the caller can checkpoint them and only retry the
 * failed remainder — avoids re-billing tokens for work that already
 * succeeded and reduces load on a throttled endpoint.
 *
 * Drivers that fail the entire batch on the first request (no
 * partial state possible — e.g. HTTP-batch providers like OpenAI /
 * Voyage where a single call returns all-or-nothing) throw the
 * underlying RuntimeException directly. This type is reserved for
 * fan-out drivers (currently: Bedrock).
 */
final class PartialBatchEmbeddingException extends RuntimeException
{
    /**
     * @param  array<int, array<int, float>>  $completed  Index → embedding vector
     * @param  int  $failedIndex  Position in the input array of the first failure
     */
    public function __construct(
        public readonly array $completed,
        public readonly int $failedIndex,
        Throwable $cause,
    ) {
        parent::__construct(
            sprintf(
                'Batch embedding partially failed at index %d after %d successes: %s',
                $failedIndex,
                count($completed),
                $cause->getMessage(),
            ),
            previous: $cause,
        );
    }
}
