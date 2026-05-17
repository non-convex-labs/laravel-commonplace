<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use RuntimeException;

class BedrockEmbeddingProvider implements EmbeddingProvider
{
    private ?BedrockRuntimeClient $client = null;

    public function embed(string $text): array
    {
        return $this->invokeOne($text);
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return array_map(
            fn (string $text): array => $this->invokeOne($text),
            array_values($texts),
        );
    }

    public function dimensions(): int
    {
        return (int) config('commonplace.embedding.bedrock.dimensions', 1024);
    }

    public function setClient(BedrockRuntimeClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return array<int, float>
     */
    private function invokeOne(string $text): array
    {
        $payload = [
            'inputText' => $text,
            'dimensions' => $this->dimensions(),
            'normalize' => (bool) config('commonplace.embedding.bedrock.normalize', true),
        ];

        $result = $this->client()->invokeModel([
            'modelId' => $this->model(),
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $decoded = json_decode((string) $result['body'], true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! isset($decoded['embedding']) || ! is_array($decoded['embedding'])) {
            throw new RuntimeException('Bedrock returned an unexpected payload for model '.$this->model().'.');
        }

        return array_map(static fn (mixed $v): float => (float) $v, $decoded['embedding']);
    }

    private function client(): BedrockRuntimeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(BedrockRuntimeClient::class)) {
            throw new RuntimeException(
                'The Bedrock embedding driver requires aws/aws-sdk-php. '
                .'Install it with: composer require aws/aws-sdk-php'
            );
        }

        return $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region' => $this->region(),
        ]);
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.bedrock.model', 'amazon.titan-embed-text-v2:0');
    }

    private function region(): string
    {
        return (string) config('commonplace.embedding.bedrock.region', 'us-east-1');
    }
}
