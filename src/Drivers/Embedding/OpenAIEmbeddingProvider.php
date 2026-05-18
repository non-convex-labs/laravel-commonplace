<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;

class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    private const BATCH_SIZE = 2048;

    private const DIMENSIONS_CONFIG_KEY = 'commonplace.embedding.openai.dimensions';

    /**
     * Native (default) vector size per known model. Used when no
     * `dimensions` override is configured, and to size the storage column.
     */
    private const NATIVE_DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

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
        $this->validateModelDimensionsCompatibility();

        $configured = config(self::DIMENSIONS_CONFIG_KEY);

        if ($configured !== null) {
            return (int) $configured;
        }

        $model = $this->model();

        if (isset(self::NATIVE_DIMENSIONS[$model])) {
            return self::NATIVE_DIMENSIONS[$model];
        }

        // Unknown model: refuse to guess. Wrong storage column size would
        // corrupt the vector store silently. Operator gets the model
        // name + remediation hint via report() on the underlying detail
        // logged by the framework; the agent gets a generic "not
        // configured" message that doesn't echo config values.
        throw new EmbeddingProviderNotConfigured('openai');
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function request(array $texts): array
    {
        $this->validateModelDimensionsCompatibility();

        $payload = [
            'input' => array_values($texts),
            'model' => $this->model(),
            'encoding_format' => 'float',
        ];

        if ($this->supportsCustomDimensions()) {
            $payload['dimensions'] = $this->dimensions();
        }

        try {
            $response = $this->http
                ->withToken($this->apiKey())
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            throw new EmbeddingProviderUnavailable('openai', 'transport', previous: $e);
        }

        if ($response->failed()) {
            throw EmbeddingProviderUnavailable::fromStatus('openai', $response->status());
        }

        $data = $response->json('data') ?? [];

        // OpenAI may return results out of order; sort by `index` to preserve
        // the caller's input ordering.
        usort($data, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(static fn (array $row): array => $row['embedding'], $data);
    }

    private function supportsCustomDimensions(): bool
    {
        return str_starts_with($this->model(), 'text-embedding-3-');
    }

    private function validateModelDimensionsCompatibility(): void
    {
        if ($this->supportsCustomDimensions()) {
            return;
        }

        $configured = config(self::DIMENSIONS_CONFIG_KEY);

        if ($configured === null) {
            return;
        }

        throw new EmbeddingProviderNotConfigured('openai');
    }

    private function apiKey(): string
    {
        $key = config('commonplace.embedding.openai.api_key');

        if (! $key) {
            throw new EmbeddingProviderNotConfigured('openai');
        }

        return (string) $key;
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.openai.model', 'text-embedding-3-small');
    }
}
