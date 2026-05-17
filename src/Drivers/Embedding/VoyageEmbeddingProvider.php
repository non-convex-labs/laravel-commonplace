<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Illuminate\Http\Client\Factory as HttpClient;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use RuntimeException;

class VoyageEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly HttpClient $http,
    ) {}

    public function embedQuery(string $text): array
    {
        return $this->embed($text);
    }

    public function embed(string $text): array
    {
        $response = $this->http
            ->withToken($this->apiKey())
            ->post('https://api.voyageai.com/v1/embeddings', [
                'input' => [$text],
                'model' => $this->model(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Voyage AI API error: '.$response->body());
        }

        return $response->json('data.0.embedding');
    }

    public function embedBatch(array $texts): array
    {
        $embeddings = [];

        foreach (array_chunk($texts, 128) as $chunk) {
            $response = $this->http
                ->withToken($this->apiKey())
                ->post('https://api.voyageai.com/v1/embeddings', [
                    'input' => $chunk,
                    'model' => $this->model(),
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Voyage AI API error: '.$response->body());
            }

            foreach ($response->json('data') as $item) {
                $embeddings[] = $item['embedding'];
            }
        }

        return $embeddings;
    }

    public function dimensions(): int
    {
        return (int) config('commonplace.embedding.voyage.dimensions', 1024);
    }

    private function apiKey(): string
    {
        $key = config('commonplace.embedding.voyage.api_key');

        if (! $key) {
            throw new RuntimeException(
                'Voyage API key is not configured (commonplace.embedding.voyage.api_key).'
            );
        }

        return (string) $key;
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.voyage.model', 'voyage-3.5');
    }
}
