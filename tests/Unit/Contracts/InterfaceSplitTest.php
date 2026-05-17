<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Contracts;

use NonConvexLabs\Commonplace\Contracts\VectorSearch;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Contracts\VectorStorage;
use NonConvexLabs\Commonplace\Drivers\Vector\InPhpCosineDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\NullDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\PgvectorDriver;
use NonConvexLabs\Commonplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Pins the structural contract for the VectorSearchDriver split.
 *
 * The composite VectorSearchDriver extends two narrower contracts so
 * future external-service drivers (Qdrant, Pinecone, Chroma) can implement
 * only VectorSearch and bind storage to a separate no-op. These tests fail
 * loudly if anyone "tidies up" the split or breaks the alias bindings.
 */
class InterfaceSplitTest extends TestCase
{
    public function test_composite_extends_both_narrow_contracts(): void
    {
        $reflection = new ReflectionClass(VectorSearchDriver::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertTrue(
            $reflection->isSubclassOf(VectorStorage::class),
            'VectorSearchDriver must extend VectorStorage.',
        );
        $this->assertTrue(
            $reflection->isSubclassOf(VectorSearch::class),
            'VectorSearchDriver must extend VectorSearch.',
        );
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function bundledDrivers(): array
    {
        return [
            'null' => [NullDriver::class],
            'in_php_cosine' => [InPhpCosineDriver::class],
            'pgvector' => [PgvectorDriver::class],
        ];
    }

    /**
     * @param  class-string  $driverClass
     */
    #[DataProvider('bundledDrivers')]
    public function test_bundled_driver_implements_all_three_contracts(string $driverClass): void
    {
        $reflection = new ReflectionClass($driverClass);

        $this->assertTrue(
            $reflection->implementsInterface(VectorStorage::class),
            "{$driverClass} must implement VectorStorage.",
        );
        $this->assertTrue(
            $reflection->implementsInterface(VectorSearch::class),
            "{$driverClass} must implement VectorSearch.",
        );
        $this->assertTrue(
            $reflection->implementsInterface(VectorSearchDriver::class),
            "{$driverClass} must implement the VectorSearchDriver composite.",
        );
    }

    public function test_vector_storage_alias_resolves_to_active_driver_singleton(): void
    {
        config()->set('commonplace.vector.driver', 'in_php_cosine');

        $composite = $this->app->make(VectorSearchDriver::class);
        $storage = $this->app->make(VectorStorage::class);

        $this->assertSame(
            $composite,
            $storage,
            'app(VectorStorage::class) must resolve to the same singleton as app(VectorSearchDriver::class).',
        );
        $this->assertInstanceOf(InPhpCosineDriver::class, $storage);
    }

    public function test_vector_search_alias_resolves_to_active_driver_singleton(): void
    {
        config()->set('commonplace.vector.driver', 'in_php_cosine');

        $composite = $this->app->make(VectorSearchDriver::class);
        $search = $this->app->make(VectorSearch::class);

        $this->assertSame(
            $composite,
            $search,
            'app(VectorSearch::class) must resolve to the same singleton as app(VectorSearchDriver::class).',
        );
        $this->assertInstanceOf(InPhpCosineDriver::class, $search);
    }

    public function test_aliases_track_driver_swap_via_config(): void
    {
        // Resolve once under in_php_cosine, then forget and re-resolve under
        // null — the alias must follow the rebound composite, not cache the
        // first resolution. (Aliases use bind(), not singleton(), so each
        // alias resolution defers to make(VectorSearchDriver::class).)
        config()->set('commonplace.vector.driver', 'in_php_cosine');
        $this->app->make(VectorStorage::class);

        $this->app->forgetInstance(VectorSearchDriver::class);
        config()->set('commonplace.vector.driver', 'null');

        $this->assertInstanceOf(NullDriver::class, $this->app->make(VectorStorage::class));
        $this->assertInstanceOf(NullDriver::class, $this->app->make(VectorSearch::class));
    }
}
