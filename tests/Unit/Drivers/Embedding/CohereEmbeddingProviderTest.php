<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Embedding;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use NonConvexLabs\Commonplace\Drivers\Embedding\CohereEmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Tests\TestCase;

class CohereEmbeddingProviderTest extends TestCase
{
    private const COHERE_URL = 'https://api.cohere.ai/v1/embed';

    private string $apiKey = 'test-key';

    private string $model = 'embed-english-v3.0';

    private CohereEmbeddingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.embedding.cohere.api_key', $this->apiKey);
        config()->set('commonplace.embedding.cohere.model', $this->model);
        config()->set('commonplace.embedding.cohere.dimensions', 1024);
        config()->set('commonplace.embedding.cohere.index_input_type', 'search_document');
        config()->set('commonplace.embedding.cohere.query_input_type', 'search_query');

        $this->provider = new CohereEmbeddingProvider($this->app->make(HttpClient::class));
    }

    public function test_embed_returns_vector_from_api_response(): void
    {
        $vector = [0.1, 0.2, 0.3];

        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => [
                    'float' => [$vector],
                ],
            ], 200),
        ]);

        $result = $this->provider->embed('hello world');

        $this->assertSame($vector, $result);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::COHERE_URL
                && $request->data()['texts'] === ['hello world']
                && $request->data()['model'] === $this->model
                && $request->data()['input_type'] === 'search_document'
                && $request->data()['embedding_types'] === ['float'];
        });
    }

    public function test_embed_sends_bearer_authorization_header(): void
    {
        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => ['float' => [[0.0]]],
            ], 200),
        ]);

        $this->provider->embed('hello');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer '.$this->apiKey);
        });
    }

    public function test_embed_uses_configured_model_and_index_input_type(): void
    {
        config()->set('commonplace.embedding.cohere.model', 'embed-multilingual-v3.0');
        config()->set('commonplace.embedding.cohere.index_input_type', 'classification');

        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => ['float' => [[0.5]]],
            ], 200),
        ]);

        $this->provider->embed('hi');

        Http::assertSent(function ($request) {
            return $request->data()['model'] === 'embed-multilingual-v3.0'
                && $request->data()['input_type'] === 'classification';
        });
    }

    public function test_embed_query_sends_query_input_type(): void
    {
        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => ['float' => [[0.5]]],
            ], 200),
        ]);

        $this->provider->embedQuery('how do indexes work');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->data()['texts'] === ['how do indexes work']
                && $request->data()['input_type'] === 'search_query';
        });
    }

    public function test_embed_query_honours_configured_query_input_type(): void
    {
        config()->set('commonplace.embedding.cohere.query_input_type', 'clustering');

        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => ['float' => [[0.5]]],
            ], 200),
        ]);

        $this->provider->embedQuery('hi');

        Http::assertSent(fn ($request) => $request->data()['input_type'] === 'clustering');
    }

    public function test_embed_batch_uses_index_input_type(): void
    {
        config()->set('commonplace.embedding.cohere.index_input_type', 'search_document');
        config()->set('commonplace.embedding.cohere.query_input_type', 'search_query');

        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => ['float' => [[0.1], [0.2]]],
            ], 200),
        ]);

        $this->provider->embedBatch(['a', 'b']);

        Http::assertSent(fn ($request) => $request->data()['input_type'] === 'search_document');
    }

    public function test_embed_throws_when_api_key_is_missing(): void
    {
        config()->set('commonplace.embedding.cohere.api_key', null);

        Http::fake();

        $this->expectException(EmbeddingProviderNotConfigured::class);
        $this->expectExceptionMessage("Embedding provider 'cohere' is not configured.");

        $this->provider->embed('hello');
    }

    public function test_embed_throws_when_response_is_not_successful(): void
    {
        Http::fake([
            self::COHERE_URL => Http::response('upstream failure echoing the request input', 500),
        ]);

        try {
            $this->provider->embed('hello');
            $this->fail('Expected EmbeddingProviderUnavailable.');
        } catch (EmbeddingProviderUnavailable $e) {
            $this->assertSame('cohere', $e->provider);
            $this->assertSame('transport', $e->reason);
            $this->assertSame(
                "Embedding provider 'cohere' is unavailable (transport error). Retry with backoff.",
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('upstream failure', $e->getMessage());
            $this->assertStringNotContainsString('request input', $e->getMessage());
        }
    }

    public function test_embed_throws_when_response_payload_is_malformed(): void
    {
        Http::fake([
            self::COHERE_URL => Http::response(['something' => 'unexpected'], 200),
        ]);

        try {
            $this->provider->embed('hello');
            $this->fail('Expected EmbeddingProviderUnavailable.');
        } catch (EmbeddingProviderUnavailable $e) {
            $this->assertSame('cohere', $e->provider);
            $this->assertSame('unexpected_payload', $e->reason);
            $this->assertSame(
                "Embedding provider 'cohere' returned an unexpected payload.",
                $e->getMessage(),
            );
        }
    }

    public function test_embed_batch_returns_vectors_in_input_order(): void
    {
        $vectors = [
            [0.1, 0.2],
            [0.3, 0.4],
            [0.5, 0.6],
        ];

        Http::fake([
            self::COHERE_URL => Http::response([
                'embeddings' => ['float' => $vectors],
            ], 200),
        ]);

        $result = $this->provider->embedBatch(['a', 'b', 'c']);

        $this->assertSame($vectors, $result);
        $this->assertCount(3, $result);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::COHERE_URL
                && $request->data()['texts'] === ['a', 'b', 'c']
                && $request->data()['model'] === $this->model;
        });
    }

    public function test_embed_batch_returns_empty_array_without_calling_api(): void
    {
        Http::fake();

        $result = $this->provider->embedBatch([]);

        $this->assertSame([], $result);

        Http::assertNothingSent();
    }

    public function test_embed_batch_chunks_inputs_larger_than_96(): void
    {
        $texts = array_map(fn (int $i) => "text-{$i}", range(1, 150));

        $firstChunk = array_map(fn (int $i) => [$i + 0.5], range(1, 96));
        $secondChunk = array_map(fn (int $i) => [$i + 0.5], range(97, 150));

        Http::fake([
            self::COHERE_URL => Http::sequence()
                ->push(['embeddings' => ['float' => $firstChunk]], 200)
                ->push(['embeddings' => ['float' => $secondChunk]], 200),
        ]);

        $result = $this->provider->embedBatch($texts);

        $this->assertCount(150, $result);
        $this->assertSame([1.5], $result[0]);
        $this->assertSame([96.5], $result[95]);
        $this->assertSame([97.5], $result[96]);
        $this->assertSame([150.5], $result[149]);

        Http::assertSentCount(2);
    }

    public function test_embed_batch_throws_when_api_key_is_missing(): void
    {
        config()->set('commonplace.embedding.cohere.api_key', null);

        Http::fake();

        $this->expectException(EmbeddingProviderNotConfigured::class);
        $this->expectExceptionMessage("Embedding provider 'cohere' is not configured.");

        $this->provider->embedBatch(['hello']);
    }

    public function test_embed_batch_throws_when_response_is_not_successful(): void
    {
        Http::fake([
            self::COHERE_URL => Http::response('boom', 500),
        ]);

        $this->expectException(EmbeddingProviderUnavailable::class);
        $this->expectExceptionMessage(
            "Embedding provider 'cohere' is unavailable (transport error). Retry with backoff."
        );

        $this->provider->embedBatch(['hello']);
    }

    public function test_dimensions_returns_configured_value(): void
    {
        config()->set('commonplace.embedding.cohere.dimensions', 384);

        $this->assertSame(384, $this->provider->dimensions());
    }

    public function test_dimensions_defaults_to_1024_when_not_configured(): void
    {
        config()->set('commonplace.embedding.cohere', [
            'api_key' => $this->apiKey,
            'model' => $this->model,
        ]);

        $this->assertSame(1024, $this->provider->dimensions());
    }
}
