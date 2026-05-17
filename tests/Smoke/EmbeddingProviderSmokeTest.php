<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Smoke;

use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
class EmbeddingProviderSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('COMMONPLACE_SMOKE_TEST') !== '1') {
            $this->markTestSkipped(
                'Live-API smoke tests are opt-in. Re-run with COMMONPLACE_SMOKE_TEST=1 '
                .'and the relevant provider credentials in the environment.'
            );
        }

        parent::setUp();
    }

    public function test_voyage_driver_against_live_api(): void
    {
        if (! getenv('VOYAGE_API_KEY')) {
            $this->markTestSkipped('VOYAGE_API_KEY not set.');
        }

        config()->set('commonplace.embedding.driver', 'voyage');

        $this->assertDriverProducesValidEmbeddings(
            $this->app->make(EmbeddingProvider::class),
        );
    }

    public function test_openai_driver_against_live_api(): void
    {
        if (! getenv('OPENAI_API_KEY')) {
            $this->markTestSkipped('OPENAI_API_KEY not set.');
        }

        config()->set('commonplace.embedding.driver', 'openai');

        $this->assertDriverProducesValidEmbeddings(
            $this->app->make(EmbeddingProvider::class),
        );
    }

    public function test_cohere_driver_against_live_api(): void
    {
        if (! getenv('COHERE_API_KEY')) {
            $this->markTestSkipped('COHERE_API_KEY not set.');
        }

        config()->set('commonplace.embedding.driver', 'cohere');

        $this->assertDriverProducesValidEmbeddings(
            $this->app->make(EmbeddingProvider::class),
        );
    }

    public function test_bedrock_driver_against_live_api(): void
    {
        // Bedrock uses the default AWS credential chain. There is no single
        // env var to gate on, so callers opt in explicitly via
        // COMMONPLACE_SMOKE_TEST_BEDROCK=1 once they have credentials and
        // region wired up.
        if (getenv('COMMONPLACE_SMOKE_TEST_BEDROCK') !== '1') {
            $this->markTestSkipped(
                'Bedrock smoke test is opt-in via COMMONPLACE_SMOKE_TEST_BEDROCK=1. '
                .'Ensure AWS credentials and AWS_BEDROCK_REGION are set.'
            );
        }

        config()->set('commonplace.embedding.driver', 'bedrock');

        $this->assertDriverProducesValidEmbeddings(
            $this->app->make(EmbeddingProvider::class),
        );
    }

    private function assertDriverProducesValidEmbeddings(EmbeddingProvider $provider): void
    {
        $dimensions = $provider->dimensions();

        $this->assertGreaterThan(0, $dimensions, 'Provider reports a non-positive dimension count.');

        $embed = $provider->embed('The quick brown fox jumps over the lazy dog.');
        $this->assertValidVector($embed, $dimensions, 'embed()');

        $query = $provider->embedQuery('quick brown fox');
        $this->assertValidVector($query, $dimensions, 'embedQuery()');

        $batch = $provider->embedBatch(['alpha bravo charlie', 'delta echo foxtrot']);
        $this->assertCount(2, $batch, 'embedBatch() did not return one vector per input.');
        $this->assertValidVector($batch[0], $dimensions, 'embedBatch()[0]');
        $this->assertValidVector($batch[1], $dimensions, 'embedBatch()[1]');
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function assertValidVector(array $vector, int $dimensions, string $label): void
    {
        $this->assertCount(
            $dimensions,
            $vector,
            "{$label} returned a vector with the wrong dimension count.",
        );

        foreach ($vector as $component) {
            $this->assertIsFloat($component, "{$label} returned a non-float component.");
        }

        // A genuinely live response has at least one non-zero component;
        // an all-zero vector indicates the Null driver leaked in or the
        // provider returned a placeholder.
        $this->assertNotSame(
            0.0,
            array_sum(array_map('abs', $vector)),
            "{$label} returned an all-zero vector.",
        );
    }
}
