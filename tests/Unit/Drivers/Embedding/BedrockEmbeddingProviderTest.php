<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Embedding;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use NonConvexLabs\Commonplace\Drivers\Embedding\BedrockEmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PartialBatchEmbeddingException;
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

        try {
            $this->provider->embed('hello');
            $this->fail('Expected EmbeddingProviderUnavailable.');
        } catch (EmbeddingProviderUnavailable $e) {
            $this->assertSame('bedrock', $e->provider);
            $this->assertSame('unexpected_payload', $e->reason);
            $this->assertSame(
                "Embedding provider 'bedrock' returned an unexpected payload.",
                $e->getMessage(),
            );
            // Pre-#132 the message interpolated $this->model(). Pin
            // that it no longer does.
            $this->assertStringNotContainsString($this->model, $e->getMessage());
        }
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

    public function test_embed_batch_preserves_input_order_via_content_aware_handler(): void
    {
        // CommandPool::batch internally writes results into a map keyed
        // by command index and ksort's that map before returning
        // (vendor/aws/aws-sdk-php/src/CommandPool.php — `cmpCallback`
        // + the `ksort($results)` in `batch`). The driver then reads
        // back by `$results[$i]`. Both guarantee input-order output.
        // This content-aware handler asserts the content-to-position
        // mapping is correct end-to-end. A regression that returned
        // raw `array_values($results)` would still pass against this
        // synchronous mock — the SDK's ksort behaviour is the load-
        // bearing contract here, not the test.
        config()->set('commonplace.embedding.bedrock.concurrency', 3);

        $vectors = ['a' => [1.0], 'b' => [2.0], 'c' => [3.0]];
        $this->useContentAwareHandler($vectors);

        $result = $this->provider->embedBatch(['a', 'b', 'c']);

        $this->assertSame([[1.0], [2.0], [3.0]], $result);

        $sentTexts = array_map(
            fn (array $cmd): string => json_decode($cmd['body'], true)['inputText'],
            $this->sentCommands,
        );
        sort($sentTexts);
        $this->assertSame(['a', 'b', 'c'], $sentTexts);
    }

    public function test_embed_batch_with_concurrency_one_is_serial_fallback(): void
    {
        config()->set('commonplace.embedding.bedrock.concurrency', 1);

        $this->queueEmbedding([7.0]);
        $this->queueEmbedding([8.0]);
        $this->queueEmbedding([9.0]);

        $result = $this->provider->embedBatch(['x', 'y', 'z']);

        $this->assertSame([[7.0], [8.0], [9.0]], $result);
        $this->assertCount(3, $this->sentCommands);
    }

    public function test_embed_batch_surfaces_partial_success_via_typed_exception(): void
    {
        // Two successes + one AWS failure. Caller should see the
        // completed embeddings on the exception so it can checkpoint
        // and re-run only the failed remainder.
        $this->queueEmbedding([1.0]);

        $failingCommand = (new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'fake', 'secret' => 'fake'],
        ]))->getCommand('InvokeModel', []);
        $this->mock->append(new AwsException('ThrottlingException', $failingCommand));

        $this->queueEmbedding([3.0]);

        try {
            $this->provider->embedBatch(['a', 'b', 'c']);
            $this->fail('Expected PartialBatchEmbeddingException.');
        } catch (PartialBatchEmbeddingException $e) {
            $this->assertSame(1, $e->failedIndex);
            $this->assertArrayHasKey(0, $e->completed);
            $this->assertSame([1.0], $e->completed[0]);
            $this->assertArrayNotHasKey(1, $e->completed);
            // #132 invariant: cause is curated end-to-end. The AWS
            // SDK exception lives as the doubled previous.
            $this->assertInstanceOf(EmbeddingProviderUnavailable::class, $e->getPrevious());
        }
    }

    public function test_embed_batch_exception_failure_at_index_zero_has_empty_completed(): void
    {
        $failingCommand = (new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'fake', 'secret' => 'fake'],
        ]))->getCommand('InvokeModel', []);
        $this->mock->append(new AwsException('ThrottlingException', $failingCommand));

        try {
            $this->provider->embedBatch(['a']);
            $this->fail('Expected PartialBatchEmbeddingException.');
        } catch (PartialBatchEmbeddingException $e) {
            $this->assertSame(0, $e->failedIndex);
            $this->assertSame([], $e->completed);
        }
    }

    public function test_embed_batch_clamps_concurrency_to_at_least_one(): void
    {
        // BEDROCK_EMBEDDING_CONCURRENCY=0 should not silently halt
        // the worker; clamp to 1 and complete the batch.
        config()->set('commonplace.embedding.bedrock.concurrency', 0);

        $this->queueEmbedding([1.0]);
        $this->queueEmbedding([2.0]);

        $result = $this->provider->embedBatch(['a', 'b']);

        $this->assertSame([[1.0], [2.0]], $result);
    }

    public function test_embed_batch_clamps_concurrency_to_batch_size(): void
    {
        config()->set('commonplace.embedding.bedrock.concurrency', 50);

        $this->queueEmbedding([1.0]);
        $this->queueEmbedding([2.0]);

        $result = $this->provider->embedBatch(['a', 'b']);

        $this->assertSame([[1.0], [2.0]], $result);
        $this->assertCount(2, $this->sentCommands);
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

    /**
     * @param  array<string, array<int, float>>  $textToVector
     */
    private function useContentAwareHandler(array $textToVector): void
    {
        $client = new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'fake', 'secret' => 'fake'],
            'handler' => function (CommandInterface $command, $request) use ($textToVector) {
                $this->sentCommands[] = $command->toArray();
                $body = json_decode($command['body'], true);
                $text = $body['inputText'] ?? '';
                $vector = $textToVector[$text]
                    ?? throw new RuntimeException("No mock vector for text '{$text}'");

                return Create::promiseFor(new Result([
                    'body' => Utils::streamFor(json_encode([
                        'embedding' => $vector,
                        'inputTextTokenCount' => 5,
                    ])),
                ]));
            },
        ]);

        $this->provider->setClient($client);
    }
}
