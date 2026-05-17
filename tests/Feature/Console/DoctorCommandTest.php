<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_doctor_passes_on_default_in_php_cosine_setup_with_exit_code_flag(): void
    {
        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Commonplace doctor');
    }

    public function test_doctor_default_mode_always_exits_zero_even_on_failure(): void
    {
        config(['commonplace.vector.driver' => 'pgvector']);

        // Default mode (no --exit-code): doctor reports but doesn't fail CI.
        $this->artisan('commonplace:doctor')->assertExitCode(0);
    }

    public function test_doctor_exit_code_flag_returns_failure_on_unknown_driver(): void
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

    public function test_doctor_fails_when_table_missing(): void
    {
        Schema::dropIfExists('commonplace_notes');

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('not present')
            ->expectsOutputToContain('php artisan migrate');
    }
}
