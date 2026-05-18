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
 * underlying provider exception directly. This type is reserved for
 * fan-out drivers (currently: Bedrock, plus Voyage's chunked retry
 * path).
 *
 * Implements [[PublicMessage]]. The constructor message is composed
 * from two integers ($failedIndex and count($completed)) which are
 * package-controlled (the driver's own batching state, not user
 * input). The original message concatenated `$cause->getMessage()` —
 * that's now dropped: cause messages from the embedding drivers could
 * embed `$response->body()`, which can echo back the user's note
 * content. The cause is preserved as `previous:` so operators see the
 * full chain via `report()`.
 *
 * Invariant (enforced by #132): every driver that throws this passes a
 * [[PublicMessage]]-implementing cause, so the `$previous` chain is
 * curated end-to-end. If a future driver passes a bare RuntimeException
 * as cause, the chain leaks operator-only detail back into agent
 * visibility through any future caller that walks `getPrevious()`.
 */
final class PartialBatchEmbeddingException extends RuntimeException implements PublicMessage
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
                'Batch embedding partially failed at index %d after %d successes.',
                $failedIndex,
                count($completed),
            ),
            previous: $cause,
        );
    }
}
