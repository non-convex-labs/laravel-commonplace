<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

interface EmbeddingProvider
{
    /**
     * Generate an embedding vector for a piece of content being indexed.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * Generate embedding vectors for a batch of content being indexed.
     * Implementations may chunk requests internally; callers should treat
     * ordering as preserved.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * Generate an embedding vector for a search query. Drivers whose APIs
     * distinguish between indexing and querying (e.g. Cohere's `input_type`,
     * Voyage's `input_type`) should diverge from `embed()` here.
     *
     * @return array<int, float>
     */
    public function embedQuery(string $text): array;

    /**
     * The dimensionality of vectors produced by this provider. Used to size
     * the storage column and validate stored embeddings against the current
     * provider.
     */
    public function dimensions(): int;
}
