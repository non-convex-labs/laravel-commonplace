<?php

declare(strict_types=1);

namespace NonconvexLabs\Commonplace\Contracts;

interface EmbeddingProvider
{
    /**
     * Generate an embedding vector for a single piece of text.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * Generate embedding vectors for a batch of texts. Implementations may
     * chunk requests internally; callers should treat ordering as preserved.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * The dimensionality of vectors produced by this provider. Used to size
     * the storage column and validate stored embeddings against the current
     * provider.
     */
    public function dimensions(): int;
}
