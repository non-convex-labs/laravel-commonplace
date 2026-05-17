<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Embedding;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use NonConvexLabs\Commonplace\Drivers\Embedding\OpenAIEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

class OpenAIEmbeddingProviderTest extends TestCase
{
    private const OPENAI_URL = 'https://api.openai.com/v1/embeddings';

    private string $apiKey = 'test-key';

    private string $model = 'text-embedding-3-small';

    private OpenAIEmbeddingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.embedding.openai.api_key', $this->apiKey);
        config()->set('commonplace.embedding.openai.model', $this->model);
        config()->set('commonplace.embedding.openai.dimensions', 1536);

        $this->provider = new OpenAIEmbeddingProvider($this->app->make(HttpClient::class));
    }

    public function test_embed_returns_vector_from_api_response(): void
    {
        $vector = [0.1, 0.2, 0.3];

        Http::fake([
            self::OPENAI_URL => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => $vector],
                ],
            ], 200),
        ]);

        $result = $this->provider->embed('hello world');

        $this->assertSame($vector, $result);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::OPENAI_URL
                && $request->data()['input'] === ['hello world']
                && $request->data()['model'] === $this->model
                && $request->data()['dimensions'] === 1536
                && $request->data()['encoding_format'] === 'float';
        });
    }

    public function test_embed_sends_bearer_authorization_header(): void
    {
        Http::fake([
            self::OPENAI_URL => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.0]]],
            ], 200),
        ]);

        $this->provider->embed('hello');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer '.$this->apiKey);
        });
    }

    public function test_embed_uses_configured_model_and_dimensions(): void
    {
        config()->set('commonplace.embedding.openai.model', 'text-embedding-3-large');
        config()->set('commonplace.embedding.openai.dimensions', 3072);

        Http::fake([
            self::OPENAI_URL => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.5]]],
            ], 200),
        ]);

        $this->provider->embed('hi');

        Http::assertSent(function ($request) {
            return $request->data()['model'] === 'text-embedding-3-large'
                && $request->data()['dimensions'] === 3072;
        });
    }

    public function test_embed_throws_when_api_key_is_missing(): void
    {
        config()->set('commonplace.embedding.openai.api_key', null);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured');

        $this->provider->embed('hello');
    }

    public function test_embed_throws_when_response_is_not_successful(): void
    {
        Http::fake([
            self::OPENAI_URL => Http::response('upstream failure', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API error: upstream failure');

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
            self::OPENAI_URL => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => $vectors[0]],
                    ['index' => 1, 'embedding' => $vectors[1]],
                    ['index' => 2, 'embedding' => $vectors[2]],
                ],
            ], 200),
        ]);

        $result = $this->provider->embedBatch(['a', 'b', 'c']);

        $this->assertSame($vectors, $result);
        $this->assertCount(3, $result);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::OPENAI_URL
                && $request->data()['input'] === ['a', 'b', 'c']
                && $request->data()['model'] === $this->model;
        });
    }

    public function test_embed_batch_reorders_when_api_returns_results_out_of_order(): void
    {
        Http::fake([
            self::OPENAI_URL => Http::response([
                'data' => [
                    ['index' => 2, 'embedding' => [3.5]],
                    ['index' => 0, 'embedding' => [1.5]],
                    ['index' => 1, 'embedding' => [2.5]],
                ],
            ], 200),
        ]);

        $result = $this->provider->embedBatch(['a', 'b', 'c']);

        $this->assertSame([[1.5], [2.5], [3.5]], $result);
    }

    public function test_embed_batch_returns_empty_array_without_calling_api(): void
    {
        Http::fake();

        $result = $this->provider->embedBatch([]);

        $this->assertSame([], $result);

        Http::assertNothingSent();
    }

    public function test_embed_batch_chunks_inputs_larger_than_2048(): void
    {
        $texts = array_map(fn (int $i) => "text-{$i}", range(1, 2100));

        $firstChunk = array_map(
            fn (int $i) => ['index' => $i - 1, 'embedding' => [$i + 0.5]],
            range(1, 2048),
        );
        $secondChunk = array_map(
            fn (int $i) => ['index' => $i - 2049, 'embedding' => [$i + 0.5]],
            range(2049, 2100),
        );

        Http::fake([
            self::OPENAI_URL => Http::sequence()
                ->push(['data' => $firstChunk], 200)
                ->push(['data' => $secondChunk], 200),
        ]);

        $result = $this->provider->embedBatch($texts);

        $this->assertCount(2100, $result);
        $this->assertSame([1.5], $result[0]);
        $this->assertSame([2048.5], $result[2047]);
        $this->assertSame([2049.5], $result[2048]);
        $this->assertSame([2100.5], $result[2099]);

        Http::assertSentCount(2);
    }

    public function test_embed_batch_throws_when_api_key_is_missing(): void
    {
        config()->set('commonplace.embedding.openai.api_key', null);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured');

        $this->provider->embedBatch(['hello']);
    }

    public function test_embed_batch_throws_when_response_is_not_successful(): void
    {
        Http::fake([
            self::OPENAI_URL => Http::response('boom', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API error: boom');

        $this->provider->embedBatch(['hello']);
    }

    public function test_dimensions_returns_configured_value(): void
    {
        config()->set('commonplace.embedding.openai.dimensions', 512);

        $this->assertSame(512, $this->provider->dimensions());
    }

    public function test_dimensions_defaults_to_1536_when_not_configured(): void
    {
        config()->set('commonplace.embedding.openai', [
            'api_key' => $this->apiKey,
            'model' => $this->model,
        ]);

        $this->assertSame(1536, $this->provider->dimensions());
    }
}
