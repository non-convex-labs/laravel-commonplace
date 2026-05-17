<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Vector;

use NonConvexLabs\Commonplace\Drivers\Vector\PgvectorDriver;
use NonConvexLabs\Commonplace\Exceptions\PgvectorDriverNotReady;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

class PgvectorDriverTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;

    public function test_parse_strips_brackets_and_casts_floats(): void
    {
        $driver = $this->app->make(PgvectorDriver::class);

        $this->assertSame([0.1, 0.2, 0.3], $driver->parse('[0.1,0.2,0.3]'));
    }

    public function test_parse_returns_null_on_empty_or_non_string(): void
    {
        $driver = $this->app->make(PgvectorDriver::class);

        $this->assertNull($driver->parse(null));
        $this->assertNull($driver->parse(''));
        $this->assertNull($driver->parse('[]'));
    }

    public function test_parse_accepts_array_input(): void
    {
        $driver = $this->app->make(PgvectorDriver::class);

        $this->assertSame([1.0, 2.0], $driver->parse([1, 2]));
    }

    public function test_is_enabled(): void
    {
        $driver = $this->app->make(PgvectorDriver::class);

        $this->assertTrue($driver->isEnabled());
    }

    public function test_search_throws_not_ready_on_non_postgres(): void
    {
        // The default test connection is sqlite, so the driver's readiness
        // gate should reject it the moment we try to use it.
        $driver = $this->app->make(PgvectorDriver::class);

        $this->expectException(PgvectorDriverNotReady::class);
        $this->expectExceptionMessage('pgvector driver requires PostgreSQL');

        $driver->search(Note::query(), [0.1, 0.2], 10);
    }

    public function test_store_throws_not_ready_on_non_postgres(): void
    {
        $driver = $this->app->make(PgvectorDriver::class);

        $this->expectException(PgvectorDriverNotReady::class);

        $driver->store(1, [0.1, 0.2]);
    }
}
