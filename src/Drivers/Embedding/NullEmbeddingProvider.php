<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;

class NullEmbeddingProvider implements EmbeddingProvider
{
    public function embedQuery(string $text): array
    {
        return $this->embed($text);
    }

    public function embed(string $text): array
    {
        return array_fill(0, $this->dimensions(), 0.0);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $text) => $this->embed($text), $texts);
    }

    public function dimensions(): int
    {
        return (int) config('commonplace.embedding.null.dimensions', 1024);
    }
}
