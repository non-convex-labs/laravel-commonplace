<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Illuminate\Http\Client\Factory as HttpClient;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use RuntimeException;

class CohereEmbeddingProvider implements EmbeddingProvider
{
    private const ENDPOINT = 'https://api.cohere.ai/v1/embed';

    private const BATCH_SIZE = 96;

    public function __construct(
        private readonly HttpClient $http,
    ) {}

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
        return (int) config('commonplace.embedding.cohere.dimensions', 1024);
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function request(array $texts): array
    {
        $payload = [
            'texts' => array_values($texts),
            'model' => $this->model(),
            'input_type' => $this->inputType(),
            'embedding_types' => ['float'],
        ];

        $response = $this->http
            ->withToken($this->apiKey())
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Cohere API error: '.$response->body());
        }

        // Cohere returns embeddings under `embeddings.float` when
        // `embedding_types` is specified.
        $vectors = $response->json('embeddings.float');

        if (! is_array($vectors)) {
            throw new RuntimeException('Cohere API returned an unexpected payload: '.$response->body());
        }

        return array_values($vectors);
    }

    private function apiKey(): string
    {
        $key = config('commonplace.embedding.cohere.api_key');

        if (! $key) {
            throw new RuntimeException(
                'Cohere API key is not configured (commonplace.embedding.cohere.api_key).'
            );
        }

        return (string) $key;
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.cohere.model', 'embed-english-v3.0');
    }

    private function inputType(): string
    {
        return (string) config('commonplace.embedding.cohere.input_type', 'search_document');
    }
}
