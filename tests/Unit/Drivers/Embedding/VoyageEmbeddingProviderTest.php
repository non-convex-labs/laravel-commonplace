<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Embedding;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use NonConvexLabs\Commonplace\Drivers\Embedding\VoyageEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

class VoyageEmbeddingProviderTest extends TestCase
{
    private const VOYAGE_URL = 'https://api.voyageai.com/v1/embeddings';

    private string $apiKey = 'test-key';

    private string $model = 'voyage-3.5';

    private VoyageEmbeddingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.embedding.voyage.api_key', $this->apiKey);
        config()->set('commonplace.embedding.voyage.model', $this->model);

        $this->provider = new VoyageEmbeddingProvider($this->app->make(HttpClient::class));
    }

    public function test_embed_returns_vector_from_api_response(): void
    {
        $vector = [0.1, 0.2, 0.3];

        Http::fake([
            self::VOYAGE_URL => Http::response([
                'data' => [
                    ['embedding' => $vector],
                ],
            ], 200),
        ]);

        $result = $this->provider->embed('hello world');

        $this->assertSame($vector, $result);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::VOYAGE_URL
                && $request->data()['input'] === ['hello world']
                && $request->data()['model'] === $this->model;
        });
    }

    public function test_embed_sends_bearer_authorization_header(): void
    {
        Http::fake([
            self::VOYAGE_URL => Http::response([
                'data' => [['embedding' => [0.0]]],
            ], 200),
        ]);

        $this->provider->embed('hello');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer '.$this->apiKey);
        });
    }

    public function test_embed_uses_configured_model(): void
    {
        config()->set('commonplace.embedding.voyage.model', 'voyage-large-2');

        Http::fake([
            self::VOYAGE_URL => Http::response([
                'data' => [['embedding' => [0.5]]],
            ], 200),
        ]);

        $this->provider->embed('hi');

        Http::assertSent(function ($request) {
            return $request->data()['model'] === 'voyage-large-2';
        });
    }

    public function test_embed_throws_when_api_key_is_missing(): void
    {
        config()->set('commonplace.embedding.voyage.api_key', null);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voyage API key is not configured');

        $this->provider->embed('hello');
    }

    public function test_embed_throws_when_response_is_not_successful(): void
    {
        Http::fake([
            self::VOYAGE_URL => Http::response('upstream failure', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voyage AI API error: upstream failure');

        $this->provider->embed('hello');
    }

    public function test_embed_batch_returns_vectors_in_input_order(): void
    {
        $vectors = [
            [0.1, 0.2],
            [0.3, 0.4],
            [0.5, 0.6],
        ];

        Http::fake([
            self::VOYAGE_URL => Http::response([
                'data' => [
                    ['embedding' => $vectors[0]],
                    ['embedding' => $vectors[1]],
                    ['embedding' => $vectors[2]],
                ],
            ], 200),
        ]);

        $result = $this->provider->embedBatch(['a', 'b', 'c']);

        $this->assertSame($vectors, $result);
        $this->assertCount(3, $result);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::VOYAGE_URL
                && $request->data()['input'] === ['a', 'b', 'c']
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

    public function test_embed_batch_chunks_inputs_larger_than_128(): void
    {
        $texts = array_map(fn (int $i) => "text-{$i}", range(1, 150));

        $firstChunk = array_map(fn (int $i) => ['embedding' => [$i + 0.5]], range(1, 128));
        $secondChunk = array_map(fn (int $i) => ['embedding' => [$i + 0.5]], range(129, 150));

        Http::fake([
            self::VOYAGE_URL => Http::sequence()
                ->push(['data' => $firstChunk], 200)
                ->push(['data' => $secondChunk], 200),
        ]);

        $result = $this->provider->embedBatch($texts);

        $this->assertCount(150, $result);
        $this->assertSame([1.5], $result[0]);
        $this->assertSame([128.5], $result[127]);
        $this->assertSame([129.5], $result[128]);
        $this->assertSame([150.5], $result[149]);

        Http::assertSentCount(2);
    }

    public function test_embed_batch_throws_when_api_key_is_missing(): void
    {
        config()->set('commonplace.embedding.voyage.api_key', null);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voyage API key is not configured');

        $this->provider->embedBatch(['hello']);
    }

    public function test_embed_batch_throws_when_response_is_not_successful(): void
    {
        Http::fake([
            self::VOYAGE_URL => Http::response('boom', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voyage AI API error: boom');

        $this->provider->embedBatch(['hello']);
    }

    public function test_dimensions_returns_configured_value(): void
    {
        config()->set('commonplace.embedding.voyage.dimensions', 256);

        $this->assertSame(256, $this->provider->dimensions());
    }

    public function test_dimensions_defaults_to_1024_when_not_configured(): void
    {
        config()->set('commonplace.embedding.voyage', [
            'api_key' => $this->apiKey,
            'model' => $this->model,
        ]);

        $this->assertSame(1024, $this->provider->dimensions());
    }
}
