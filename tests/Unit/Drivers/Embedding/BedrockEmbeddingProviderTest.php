<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Embedding;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use NonConvexLabs\Commonplace\Drivers\Embedding\BedrockEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

class BedrockEmbeddingProviderTest extends TestCase
{
    private string $model = 'amazon.titan-embed-text-v2:0';

    private BedrockEmbeddingProvider $provider;

    private MockHandler $mock;

    /** @var array<int, array<string, mixed>> */
    private array $sentCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.embedding.bedrock.region', 'us-east-1');
        config()->set('commonplace.embedding.bedrock.model', $this->model);
        config()->set('commonplace.embedding.bedrock.dimensions', 1024);
        config()->set('commonplace.embedding.bedrock.normalize', true);

        $this->sentCommands = [];
        $this->mock = new MockHandler;

        $client = new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'fake', 'secret' => 'fake'],
            'handler' => function (CommandInterface $command, $request) {
                $this->sentCommands[] = $command->toArray();

                return ($this->mock)($command, $request);
            },
        ]);

        $this->provider = (new BedrockEmbeddingProvider)->setClient($client);
    }

    public function test_embed_returns_vector_from_invoke_model_response(): void
    {
        $this->queueEmbedding([0.1, 0.2, 0.3]);

        $result = $this->provider->embed('hello world');

        $this->assertSame([0.1, 0.2, 0.3], $result);
        $this->assertCount(1, $this->sentCommands);

        $command = $this->sentCommands[0];
        $this->assertSame($this->model, $command['modelId']);
        $this->assertSame('application/json', $command['contentType']);
        $this->assertSame('application/json', $command['accept']);

        $body = json_decode($command['body'], true);
        $this->assertSame('hello world', $body['inputText']);
        $this->assertSame(1024, $body['dimensions']);
        $this->assertTrue($body['normalize']);
    }

    public function test_embed_sends_configured_model_and_dimensions(): void
    {
        config()->set('commonplace.embedding.bedrock.model', 'amazon.titan-embed-text-v2:0');
        config()->set('commonplace.embedding.bedrock.dimensions', 256);
        config()->set('commonplace.embedding.bedrock.normalize', false);

        $this->queueEmbedding(array_fill(0, 256, 0.0));

        $this->provider->embed('sized');

        $body = json_decode($this->sentCommands[0]['body'], true);
        $this->assertSame(256, $body['dimensions']);
        $this->assertFalse($body['normalize']);
    }

    public function test_embed_throws_on_malformed_response(): void
    {
        $this->mock->append(new Result([
            'body' => Utils::streamFor('{"unexpected":"shape"}'),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bedrock returned an unexpected payload');

        $this->provider->embed('hello');
    }

    public function test_embed_batch_invokes_model_once_per_text(): void
    {
        $this->queueEmbedding([1.0]);
        $this->queueEmbedding([2.0]);
        $this->queueEmbedding([3.0]);

        $result = $this->provider->embedBatch(['a', 'b', 'c']);

        $this->assertSame([[1.0], [2.0], [3.0]], $result);
        $this->assertCount(3, $this->sentCommands);

        $this->assertSame('a', json_decode($this->sentCommands[0]['body'], true)['inputText']);
        $this->assertSame('b', json_decode($this->sentCommands[1]['body'], true)['inputText']);
        $this->assertSame('c', json_decode($this->sentCommands[2]['body'], true)['inputText']);
    }

    public function test_embed_batch_returns_empty_array_without_calling_api(): void
    {
        $result = $this->provider->embedBatch([]);

        $this->assertSame([], $result);
        $this->assertSame([], $this->sentCommands);
    }

    public function test_dimensions_returns_configured_value(): void
    {
        config()->set('commonplace.embedding.bedrock.dimensions', 512);

        $this->assertSame(512, $this->provider->dimensions());
    }

    public function test_dimensions_defaults_to_1024_when_not_configured(): void
    {
        config()->set('commonplace.embedding.bedrock', [
            'region' => 'us-east-1',
            'model' => $this->model,
        ]);

        $this->assertSame(1024, $this->provider->dimensions());
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function queueEmbedding(array $vector): void
    {
        $this->mock->append(new Result([
            'body' => Utils::streamFor(json_encode([
                'embedding' => $vector,
                'inputTextTokenCount' => 5,
            ])),
        ]));
    }
}
