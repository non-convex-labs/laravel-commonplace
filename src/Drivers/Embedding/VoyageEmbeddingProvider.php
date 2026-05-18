<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Embedding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PartialBatchEmbeddingException;

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
        $response = $this->postWithRetry([$text]);

        return $response->json('data.0.embedding');
    }

    public function embedBatch(array $texts): array
    {
        $embeddings = [];

        // We chunk in 128s and treat each chunk as its own retry unit.
        // If a chunk still fails after retries AND prior chunks
        // succeeded, surface them via PartialBatchEmbeddingException so
        // the caller can checkpoint completed work instead of re-billing
        // tokens for it on the next attempt.
        foreach (array_chunk($texts, 128) as $chunkIndex => $chunk) {
            try {
                $response = $this->postWithRetry($chunk);
            } catch (EmbeddingProviderUnavailable $e) {
                if ($embeddings !== []) {
                    throw new PartialBatchEmbeddingException(
                        completed: $embeddings,
                        failedIndex: count($embeddings),
                        cause: $e,
                    );
                }

                throw $e;
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

    /**
     * @param  array<int, string>  $input
     */
    private function postWithRetry(array $input): Response
    {
        $maxRetries = (int) config('commonplace.embedding.voyage.retry_max', 3);
        $baseDelay = (float) config('commonplace.embedding.voyage.retry_base_delay', 1.0);

        $attempt = 0;

        while (true) {
            try {
                $response = $this->http
                    ->withToken($this->apiKey())
                    ->post('https://api.voyageai.com/v1/embeddings', [
                        'input' => $input,
                        'model' => $this->model(),
                    ]);
            } catch (ConnectionException $e) {
                throw new EmbeddingProviderUnavailable('voyage', 'transport', previous: $e);
            }

            if (! $response->failed()) {
                return $response;
            }

            // Only 429s are retried. Other failures (5xx, timeouts) are
            // not necessarily transient on the same timescale and would
            // burn quota retrying — surface them immediately so the
            // queue-level retry on ReindexNotes handles the next attempt.
            if ($response->status() !== 429 || $attempt >= $maxRetries) {
                throw EmbeddingProviderUnavailable::fromStatus('voyage', $response->status());
            }

            $delay = min(
                $baseDelay * (2 ** $attempt) + random_int(0, 200) / 1000,
                30.0,
            );

            Sleep::for((int) round($delay * 1000))->milliseconds();

            $attempt++;
        }
    }

    private function apiKey(): string
    {
        $key = config('commonplace.embedding.voyage.api_key');

        if (! $key) {
            throw new EmbeddingProviderNotConfigured('voyage');
        }

        return (string) $key;
    }

    private function model(): string
    {
        return (string) config('commonplace.embedding.voyage.model', 'voyage-3.5');
    }
}
