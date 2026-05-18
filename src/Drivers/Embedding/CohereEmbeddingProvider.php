<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;

class CohereEmbeddingProvider implements EmbeddingProvider
{
    private const ENDPOINT = 'https://api.cohere.ai/v1/embed';

    private const BATCH_SIZE = 96;

    public function __construct(
        private readonly HttpClient $http,
    ) {}

    public function embed(string $text): array
    {
        $vectors = $this->request([$text], $this->indexInputType());

        return $vectors[0];
    }

    public function embedQuery(string $text): array
    {
        $vectors = $this->request([$text], $this->queryInputType());

        return $vectors[0];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $embeddings = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $chunk) {
            foreach ($this->request($chunk, $this->indexInputType()) as $vector) {
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
    private function request(array $texts, string $inputType): array
    {
        $payload = [
            'texts' => array_values($texts),
            'model' => $this->model(),
            'input_type' => $inputType,
            'embedding_types' => ['float'],
        ];

        try {
            $response = $this->http
                ->withToken($this->apiKey())
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            throw new EmbeddingProviderUnavailable('cohere', 'transport', previous: $e);
        }

        if ($response->failed()) {
            throw EmbeddingProviderUnavailable::fromStatus('cohere', $response->status());
        }

        // Cohere returns embeddings under `embeddings.float` when
        // `embedding_types` is specified.
        $vectors = $response->json('embeddings.float');

        if (! is_array($vectors)) {
            throw new EmbeddingProviderUnavailable('cohere', 'unexpected_payload');
        }

        return array_values($vectors);
    }

    private function apiKey(): string
    {
        $key = config('commonplace.embedding.cohere.api_key');

        if (! $key) {
            throw new EmbeddingProviderNotConfigured('cohere');
        }

        return (string) $key;
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.cohere.model', 'embed-english-v3.0');
    }

    private function indexInputType(): string
    {
        return (string) config('commonplace.embedding.cohere.index_input_type', 'search_document');
    }

    private function queryInputType(): string
    {
        return (string) config('commonplace.embedding.cohere.query_input_type', 'search_query');
    }
}
