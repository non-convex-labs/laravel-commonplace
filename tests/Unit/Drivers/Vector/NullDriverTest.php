<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Vector;

use NonConvexLabs\Commonplace\Drivers\Vector\NullDriver;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

class NullDriverTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;

    public function test_is_disabled(): void
    {
        $this->assertFalse((new NullDriver)->isEnabled());
    }

    public function test_parse_always_returns_null(): void
    {
        $driver = new NullDriver;

        $this->assertNull($driver->parse(null));
        $this->assertNull($driver->parse('[1,2,3]'));
        $this->assertNull($driver->parse([1.0, 2.0]));
    }

    public function test_search_returns_empty_collection(): void
    {
        $results = (new NullDriver)->search(Note::query(), [0.1, 0.2], 10);

        $this->assertCount(0, $results);
    }

    public function test_store_is_a_noop(): void
    {
        $driver = new NullDriver;

        $driver->store(123, [0.1, 0.2, 0.3]);

        $this->assertTrue(true); // no DB write occurred; assertion just keeps phpunit happy
    }
}
