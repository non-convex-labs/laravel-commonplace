<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Vector;

use NonConvexLabs\Commonplace\Drivers\Vector\InPhpCosineDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\PgvectorDriver;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cross-driver parse() contract: both real drivers must agree on the same
 * "no useful vector" inputs. Divergence here is a bug magnet — the Note
 * model accessor and the search loop both call parse() and assume null
 * means "skip this row", not "an empty array I should still iterate over".
 */
class ParseContractTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyInputs(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace string' => ['   '];
        yield 'empty json array' => ['[]'];
        yield 'empty pg vector literal' => ['[ ]'];
        yield 'malformed json' => ['garbage'];
        yield 'empty array' => [[]];
        yield 'integer' => [42];
    }

    #[DataProvider('emptyInputs')]
    public function test_both_drivers_return_null_for_empty_inputs(mixed $input): void
    {
        $inPhp = $this->app->make(InPhpCosineDriver::class);
        $pg = $this->app->make(PgvectorDriver::class);

        $this->assertNull($inPhp->parse($input), 'InPhp must return null for: '.var_export($input, true));
        $this->assertNull($pg->parse($input), 'Pgvector must return null for: '.var_export($input, true));
    }

    public function test_both_drivers_parse_valid_vector_to_floats(): void
    {
        $inPhp = $this->app->make(InPhpCosineDriver::class);
        $pg = $this->app->make(PgvectorDriver::class);

        $this->assertSame([0.1, 0.2, 0.3], $inPhp->parse('[0.1,0.2,0.3]'));
        $this->assertSame([0.1, 0.2, 0.3], $pg->parse('[0.1,0.2,0.3]'));
    }

    public function test_both_drivers_accept_array_input(): void
    {
        $inPhp = $this->app->make(InPhpCosineDriver::class);
        $pg = $this->app->make(PgvectorDriver::class);

        $this->assertSame([1.0, 2.0], $inPhp->parse([1, 2]));
        $this->assertSame([1.0, 2.0], $pg->parse([1, 2]));
    }
}
