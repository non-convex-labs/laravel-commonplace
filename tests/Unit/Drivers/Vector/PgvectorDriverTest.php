<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Vector;

use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Vector\PgvectorDriver;
use NonConvexLabs\Commonplace\Exceptions\PgvectorDriverNotReady;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

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
        $this->assertNull($driver->parse('   '));
        $this->assertNull($driver->parse([]));
    }

    public function test_last_warnings_is_always_empty(): void
    {
        // pgvector pushes filtering to the database; it has no in-PHP cap
        // or stale-row skip path that could surface a warning.
        $driver = $this->app->make(PgvectorDriver::class);

        $this->assertSame([], $driver->lastWarnings());
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

    /**
     * #118 invariant: `PgvectorDriverNotReady` is the first in-package
     * exception marked `PublicMessage`, so its messages land verbatim
     * in the MCP envelope (and the public HTTP error body when those
     * paths reach the same sanitiser). Today the message references
     * only `$this->db->getDriverName()` — the framework-owned driver
     * string (`sqlite`, `mysql`, `pgsql`) — and never the userland
     * connection name (`getName()`), host, or database. Pin that
     * contract: if a future change to `notReadyMessage()` quietly
     * splices in the connection name (e.g. `tenant_42_replica` in a
     * multi-tenant app) or any host config field, this test fails CI.
     */
    public function test_not_ready_message_uses_driver_name_not_connection_name(): void
    {
        // Default testbench connection name is `testing`. Drive the
        // not-ready path against it and assert the connection name
        // never lands in the message.
        $this->assertSame('testing', $this->app['db']->getDefaultConnection());

        $driver = $this->app->make(PgvectorDriver::class);

        try {
            $driver->search(Note::query(), [0.1, 0.2], 10);
            $this->fail('expected PgvectorDriverNotReady to be thrown');
        } catch (PgvectorDriverNotReady $e) {
            $this->assertStringContainsString(
                "'sqlite'",
                $e->getMessage(),
                'message should reference the framework-owned driver name',
            );
            $this->assertStringNotContainsString(
                "'testing'",
                $e->getMessage(),
                'message must not reference the userland connection name',
            );
            $this->assertStringNotContainsString('host', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('port', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('password', strtolower($e->getMessage()));
        }
    }

    public function test_driver_resolves_without_a_working_embedder(): void
    {
        // Read-only workers (replicas, health checks, search-only cron) must
        // be able to resolve the driver even when the embedder is misconfigured
        // — the embedder is only needed by defineColumn(), which they never call.
        $this->app->bind(EmbeddingProvider::class, function () {
            throw new RuntimeException('embedder boot exploded');
        });

        $driver = $this->app->make(PgvectorDriver::class);

        $this->assertInstanceOf(PgvectorDriver::class, $driver);
        // parse() / isEnabled() should remain usable without ever touching the embedder.
        $this->assertNull($driver->parse(null));
        $this->assertTrue($driver->isEnabled());
    }
}
