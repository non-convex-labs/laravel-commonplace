<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\RecordingEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
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

    public function test_pgvector_migration_precheck_bails_on_non_postgres(): void
    {
        // Default test connection is sqlite; the precheck should bail cleanly.
        // Single substring covers both "only meaningful on PostgreSQL" and the
        // connection name — they're on the same line, and the test harness
        // matches substrings against individual doWrite calls.
        $this->artisan('commonplace:doctor', ['--pgvector-migration-precheck' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain("only meaningful on PostgreSQL — your connection is 'sqlite'");
    }

    public function test_multi_user_check_emits_no_warning_for_single_user_in_php_driver(): void
    {
        $user = User::factory()->create();
        Note::factory()->create(['user_id' => $user->id]);

        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('is not recommended for multi-user vaults');
    }

    public function test_multi_user_check_warns_when_in_php_driver_used_with_multiple_users(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Note::factory()->create(['user_id' => $a->id]);
        Note::factory()->create(['user_id' => $b->id]);

        // Both the detail line and the recommendation line carry the count;
        // we test the recommendation line since it's the actionable message.
        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('Detected 2 distinct users in commonplace_notes');
    }

    public function test_multi_user_check_recommendation_names_pgvector(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Note::factory()->create(['user_id' => $a->id]);
        Note::factory()->create(['user_id' => $b->id]);

        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('Consider switching to `pgvector`');
    }

    public function test_multi_user_check_silent_when_driver_is_pgvector(): void
    {
        // The warning is specifically about in_php_cosine; pgvector handles
        // multi-user fine, so we should see no warning even with 2 users.
        // (pgvector check itself will fail on sqlite — that's expected and unrelated.)
        config(['commonplace.vector.driver' => 'pgvector']);

        $a = User::factory()->create();
        $b = User::factory()->create();
        Note::factory()->create(['user_id' => $a->id]);
        Note::factory()->create(['user_id' => $b->id]);

        $this->artisan('commonplace:doctor')
            ->doesntExpectOutputToContain('is not recommended for multi-user vaults');
    }

    public function test_multi_user_check_does_not_fail_exit_code_alone(): void
    {
        // Multi-user on InPhp is suboptimal but not broken — must not fail
        // exit-code on its own (warning only).
        $a = User::factory()->create();
        $b = User::factory()->create();
        Note::factory()->create(['user_id' => $a->id]);
        Note::factory()->create(['user_id' => $b->id]);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0);
    }

    public function test_in_php_candidate_count_info_is_reported(): void
    {
        $user = User::factory()->create();
        Note::factory()->withEmbedding([0.1, 0.2, 0.3])->create(['user_id' => $user->id]);
        Note::factory()->withEmbedding([0.4, 0.5, 0.6])->create(['user_id' => $user->id]);

        // Single substring assertion — the harness has a quirk where multiple
        // expectsOutputToContain calls on one line are checked against the
        // SAME first matching doWrite chunk, so we keep one assertion and
        // bake the rest into the substring.
        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('Indexed candidates (InPhp): 2 / soft cap 2,000 / hard cap 20,000');
    }

    public function test_in_php_candidate_count_warns_above_soft_cap(): void
    {
        config(['commonplace.vector.in_php_cosine.max_candidates' => 1]);

        $user = User::factory()->create();
        Note::factory()->withEmbedding([0.1])->create(['user_id' => $user->id]);
        Note::factory()->withEmbedding([0.2])->create(['user_id' => $user->id]);

        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('exceeds soft cap');
    }

    public function test_in_php_candidate_check_skipped_for_other_drivers(): void
    {
        config(['commonplace.vector.driver' => 'null']);

        $this->artisan('commonplace:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('not applicable (driver is null)');
    }

    public function test_dimension_drift_check_skips_when_no_embeddings_stored(): void
    {
        // Fresh install: table is migrated but no notes have been indexed yet.
        // The check must be a no-op — false-positives here scare users off
        // empty databases.
        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Embedding dimension drift: no stored embeddings yet');
    }

    public function test_dimension_drift_check_passes_when_provider_and_stored_match(): void
    {
        $this->app->instance(EmbeddingProvider::class, new RecordingEmbeddingProvider);

        $user = User::factory()->create();
        Note::factory()->withEmbedding([0.1, 0.2, 0.3])->create(['user_id' => $user->id]);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Embedding dimension drift: stored and provider both 3 dim');
    }

    public function test_dimension_drift_check_fails_when_stored_vector_length_disagrees_with_provider(): void
    {
        // RecordingEmbeddingProvider reports 3 dimensions; store a 5-dim vector
        // to simulate the "switched provider/model after indexing" foot-gun.
        $this->app->instance(EmbeddingProvider::class, new RecordingEmbeddingProvider);

        $user = User::factory()->create();
        Note::factory()
            ->withEmbedding([0.1, 0.2, 0.3, 0.4, 0.5])
            ->create(['user_id' => $user->id]);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('stored 5 dim vs provider 3 dim')
            ->expectsOutputToContain('php artisan commonplace:reindex');
    }
}
