<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\NullEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\VoyageEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;

class EmbeddingProviderResolutionTest extends TestCase
{
    public function test_null_driver_resolves_from_container(): void
    {
        config()->set('commonplace.embedding.driver', 'null');

        $this->assertInstanceOf(
            NullEmbeddingProvider::class,
            $this->app->make(EmbeddingProvider::class),
        );
    }

    public function test_voyage_driver_resolves_from_container(): void
    {
        config()->set('commonplace.embedding.driver', 'voyage');

        $this->assertInstanceOf(
            VoyageEmbeddingProvider::class,
            $this->app->make(EmbeddingProvider::class),
        );
    }

    public function test_unknown_driver_throws(): void
    {
        config()->set('commonplace.embedding.driver', 'bogus');

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(EmbeddingProvider::class);
    }

    public function test_null_driver_returns_zero_vectors_of_configured_dimension(): void
    {
        config()->set('commonplace.embedding.null.dimensions', 8);

        $provider = $this->app->make(NullEmbeddingProvider::class);

        $vector = $provider->embed('hello');

        $this->assertCount(8, $vector);
        $this->assertSame(0.0, $vector[0]);
        $this->assertSame(0.0, $vector[7]);
    }
}
