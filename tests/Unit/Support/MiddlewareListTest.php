<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Support;

use NonConvexLabs\Commonplace\Support\MiddlewareList;
use PHPUnit\Framework\TestCase;

class MiddlewareListTest extends TestCase
{
    public function test_returns_empty_for_empty_string(): void
    {
        $this->assertSame([], MiddlewareList::parse(''));
    }

    public function test_returns_empty_for_whitespace_only(): void
    {
        $this->assertSame([], MiddlewareList::parse("  \t\n"));
    }

    public function test_parses_single_middleware(): void
    {
        $this->assertSame(['web'], MiddlewareList::parse('web'));
    }

    public function test_parses_simple_two_middleware_list(): void
    {
        $this->assertSame(['web', 'auth'], MiddlewareList::parse('web,auth'));
    }

    public function test_parses_middleware_with_single_parameter(): void
    {
        $this->assertSame(['web', 'auth:sanctum'], MiddlewareList::parse('web,auth:sanctum'));
    }

    public function test_preserves_comma_inside_throttle_parameters(): void
    {
        // The bug case from #108: a flat explode produced
        // ['web', 'throttle:30', '1'] and Laravel tried to construct
        // '1' as a middleware class.
        $this->assertSame(
            ['web', 'throttle:30,1'],
            MiddlewareList::parse('web,throttle:30,1'),
        );
    }

    public function test_parses_parameterized_middleware_followed_by_more_middleware(): void
    {
        $this->assertSame(
            ['web', 'throttle:30,1', 'auth:sanctum'],
            MiddlewareList::parse('web,throttle:30,1,auth:sanctum'),
        );
    }

    public function test_parses_multi_arg_throttle_with_named_limiter(): void
    {
        // Here 'api' is treated as a separate middleware identifier.
        // Operators who actually want `throttle:60,1,api` as a single
        // middleware with a 3rd prefix arg must set the stack via the
        // `commonplace.routes.*.middleware` config array directly —
        // documented in MiddlewareList's class doc.
        $this->assertSame(
            ['throttle:30,1', 'api'],
            MiddlewareList::parse('throttle:30,1,api'),
        );
    }

    public function test_trims_surrounding_whitespace_on_each_segment(): void
    {
        $this->assertSame(
            ['web', 'auth:sanctum'],
            MiddlewareList::parse('  web , auth:sanctum '),
        );
    }

    public function test_drops_empty_segments_from_trailing_commas(): void
    {
        $this->assertSame(['web', 'auth'], MiddlewareList::parse('web,auth,'));
    }

    public function test_drops_empty_segments_from_doubled_commas(): void
    {
        $this->assertSame(['web', 'auth'], MiddlewareList::parse('web,,auth'));
    }

    public function test_drops_empty_segments_from_leading_comma(): void
    {
        $this->assertSame(['web', 'auth'], MiddlewareList::parse(',web,auth'));
    }

    public function test_preserves_fully_qualified_class_middleware(): void
    {
        $this->assertSame(
            ['App\\Http\\Middleware\\Foo', 'auth'],
            MiddlewareList::parse('App\\Http\\Middleware\\Foo,auth'),
        );
    }

    public function test_preserves_decimal_parameter_value(): void
    {
        // Hypothetical middleware accepting a float parameter — the
        // splitter should not treat `.5` as an identifier start.
        $this->assertSame(
            ['custom:1.5,2', 'auth'],
            MiddlewareList::parse('custom:1.5,2,auth'),
        );
    }
}
