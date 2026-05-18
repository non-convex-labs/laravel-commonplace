<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandPool;
use Aws\Result;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PartialBatchEmbeddingException;
use Throwable;

class BedrockEmbeddingProvider implements EmbeddingProvider
{
    private ?BedrockRuntimeClient $client = null;

    public function embedQuery(string $text): array
    {
        return $this->embed($text);
    }

    public function embed(string $text): array
    {
        $result = $this->client()->invokeModel($this->commandArgsFor($text));

        return $this->decodeEmbedding($result);
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $texts = array_values($texts);
        $client = $this->client();

        $commands = [];
        foreach ($texts as $i => $text) {
            $commands[$i] = $client->getCommand('InvokeModel', $this->commandArgsFor($text));
        }

        $results = CommandPool::batch($client, $commands, [
            'concurrency' => $this->effectiveConcurrency(count($texts)),
        ]);

        $embeddings = [];

        foreach ($texts as $i => $_text) {
            $entry = $results[$i] ?? null;

            if ($entry instanceof Result) {
                $embeddings[$i] = $this->decodeEmbedding($entry);

                continue;
            }

            // First failure aborts the rest. Surface what already
            // succeeded via PartialBatchEmbeddingException so callers
            // can checkpoint and only retry the remainder, instead of
            // re-billing tokens for work that's already done.
            //
            // Wrap the AWS-SDK Throwable (or non-Throwable rejection)
            // in a curated EmbeddingProviderUnavailable so the
            // `previous:` chain on PartialBatchEmbeddingException
            // stays PublicMessage end-to-end. AWS SDK exception
            // messages can carry signed URLs and other operator-only
            // detail — operators still see the original via report()
            // through the doubled previous chain.
            $awsCause = $entry instanceof Throwable ? $entry : null;
            $cause = new EmbeddingProviderUnavailable('bedrock', 'transport', previous: $awsCause);

            throw new PartialBatchEmbeddingException(
                completed: $embeddings,
                failedIndex: $i,
                cause: $cause,
            );
        }

        return array_values($embeddings);
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
     * @return array<string, mixed>
     */
    private function commandArgsFor(string $text): array
    {
        return [
            'modelId' => $this->model(),
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'inputText' => $text,
                'dimensions' => $this->dimensions(),
                'normalize' => (bool) config('commonplace.embedding.bedrock.normalize', true),
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array<int, float>
     */
    private function decodeEmbedding(Result $result): array
    {
        $decoded = json_decode((string) $result['body'], true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! isset($decoded['embedding']) || ! is_array($decoded['embedding'])) {
            throw new EmbeddingProviderUnavailable('bedrock', 'unexpected_payload');
        }

        return array_map(static fn (mixed $v): float => (float) $v, $decoded['embedding']);
    }

    private function effectiveConcurrency(int $batchSize): int
    {
        $configured = (int) config('commonplace.embedding.bedrock.concurrency', 2);

        return max(1, min($configured, $batchSize));
    }

    private function client(): BedrockRuntimeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(BedrockRuntimeClient::class)) {
            // Operator gets the remediation hint (composer require) via
            // the framework's exception trail; the agent-visible message
            // stays generic.
            throw new EmbeddingProviderNotConfigured('bedrock');
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
