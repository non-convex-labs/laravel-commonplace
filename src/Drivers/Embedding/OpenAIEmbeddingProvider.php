<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Illuminate\Http\Client\Factory as HttpClient;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use RuntimeException;

class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    private const BATCH_SIZE = 2048;

    public function __construct(
        private readonly HttpClient $http,
    ) {}

    public function embedQuery(string $text): array
    {
        return $this->embed($text);
    }

    public function embed(string $text): array
    {
        $vectors = $this->request([$text]);

        return $vectors[0];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $embeddings = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $chunk) {
            foreach ($this->request($chunk) as $vector) {
                $embeddings[] = $vector;
            }
        }

        return $embeddings;
    }

    public function dimensions(): int
    {
        return (int) config('commonplace.embedding.openai.dimensions', 1536);
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function request(array $texts): array
    {
        $payload = [
            'input' => array_values($texts),
            'model' => $this->model(),
            'dimensions' => $this->dimensions(),
            'encoding_format' => 'float',
        ];

        $response = $this->http
            ->withToken($this->apiKey())
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI API error: '.$response->body());
        }

        $data = $response->json('data') ?? [];

        // OpenAI may return results out of order; sort by `index` to preserve
        // the caller's input ordering.
        usort($data, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(static fn (array $row): array => $row['embedding'], $data);
    }

    private function apiKey(): string
    {
        $key = config('commonplace.embedding.openai.api_key');

        if (! $key) {
            throw new RuntimeException(
                'OpenAI API key is not configured (commonplace.embedding.openai.api_key).'
            );
        }

        return (string) $key;
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.openai.model', 'text-embedding-3-small');
    }
}
