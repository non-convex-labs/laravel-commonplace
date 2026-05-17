<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_doctor_passes_on_default_in_php_cosine_setup(): void
    {
        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('Commonplace doctor');
    }

    public function test_doctor_warns_when_unknown_driver_configured(): void
    {
        config(['commonplace.vector.driver' => 'made-up']);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(1);
    }

    public function test_doctor_fails_when_pgvector_chosen_on_sqlite(): void
    {
        config(['commonplace.vector.driver' => 'pgvector']);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('pgvector driver requires PostgreSQL');
    }
}
