<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Fixtures;

use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;

class RecordingEmbeddingProvider implements EmbeddingProvider
{
    public int $batchCalls = 0;

    /** @var array<int, int> */
    public array $batchSizes = [];

    /** @var array<int, string> */
    public array $lastBatch = [];

    /** @var array<int, string> */
    public array $queryEmbeds = [];

    public function embed(string $text): array
    {
        return [0.1, 0.2, 0.3];
    }

    public function embedQuery(string $text): array
    {
        $this->queryEmbeds[] = $text;

        return [0.1, 0.2, 0.3];
    }

    public function embedBatch(array $texts): array
    {
        $this->batchCalls++;
        $this->batchSizes[] = count($texts);
        $this->lastBatch = array_values($texts);

        return array_map(fn () => [0.1, 0.2, 0.3], $texts);
    }

    public function dimensions(): int
    {
        return 3;
    }
}
