<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Models\Link;
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
            ->expectsOutputToContain('Embedding dimension drift: all stored rows match provider 3 dim');
    }

    public function test_dimension_drift_check_warns_when_stored_length_disagrees_with_provider(): void
    {
        // RecordingEmbeddingProvider reports 3 dimensions; store a 5-dim vector
        // to simulate the "switched provider/model after indexing" foot-gun.
        // Status is `warn` (not `fail`), so --exit-code stays green — a routine
        // package upgrade must not silently red downstream CI. The detail line
        // is prefixed `DRIFT:` so it surfaces above sibling warns.
        $this->app->instance(EmbeddingProvider::class, new RecordingEmbeddingProvider);

        $user = User::factory()->create();
        Note::factory()
            ->withEmbedding([0.1, 0.2, 0.3, 0.4, 0.5])
            ->create(['user_id' => $user->id]);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRIFT: provider expects 3 dim, found rows with [5]')
            ->expectsOutputToContain('php artisan commonplace:reindex');
    }

    public function test_dimension_drift_check_detects_partial_reindex_even_when_newest_row_matches(): void
    {
        // The canonical drift scenario: user switched provider, then a reindex
        // ran but only completed partially (queue backlog, failed jobs, etc.).
        // The NEWEST row matches the current provider; OLDER rows still carry
        // the previous provider's dimensions. A "sample newest row" check would
        // miss this entirely — the sentinel-driven EXISTS catches it.
        $this->app->instance(EmbeddingProvider::class, new RecordingEmbeddingProvider);

        $user = User::factory()->create();
        Note::factory()->withEmbedding([0.9, 0.9, 0.9, 0.9, 0.9])->create(['user_id' => $user->id]);   // old, 5-dim
        Note::factory()->withEmbedding([0.1, 0.2, 0.3])->create(['user_id' => $user->id]);             // new, 3-dim

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRIFT: provider expects 3 dim, found rows with [5]')
            ->expectsOutputToContain('php artisan commonplace:reindex');
    }

    public function test_dimension_drift_check_resolves_provider_via_service_provider_dispatch(): void
    {
        // Exercise the real dispatch path: `null` driver + dimensions=3 in
        // config, rather than $this->app->instance(...) which bypasses the
        // service provider's match() table. Stores a 5-dim vector → drift.
        config([
            'commonplace.embedding.driver' => 'null',
            'commonplace.embedding.null.dimensions' => 3,
        ]);

        $user = User::factory()->create();
        Note::factory()
            ->withEmbedding([0.1, 0.2, 0.3, 0.4, 0.5])
            ->create(['user_id' => $user->id]);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRIFT: provider expects 3 dim, found rows with [5]');
    }

    public function test_dimension_drift_check_treats_rows_missing_sentinel_as_drift(): void
    {
        // Rows with `embedding` populated but `embedding_dimensions` null
        // (pre-sentinel data, hand-inserted SQL fixtures) are folded into the
        // drift case as `unknown` — we can't quantify the length but the row
        // is still suspect. Pair it with a clean 5-dim row to assert both
        // values appear in the message.
        $this->app->instance(EmbeddingProvider::class, new RecordingEmbeddingProvider);

        $user = User::factory()->create();
        Note::factory()->withEmbedding([0.1, 0.2, 0.3, 0.4, 0.5])->create(['user_id' => $user->id]);
        $orphan = Note::factory()->create(['user_id' => $user->id]);

        \DB::table('commonplace_notes')
            ->where('id', $orphan->id)
            ->update(['embedding' => '[0.1,0.2,0.3]', 'embedding_dimensions' => null]);

        $this->artisan('commonplace:doctor', ['--exit-code' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRIFT: provider expects 3 dim, found rows with [5, unknown]')
            ->expectsOutputToContain('php artisan commonplace:reindex');
    }

    public function test_doctor_warns_when_orphan_link_count_exceeds_threshold(): void
    {
        config(['commonplace.wikilinks.orphan_warn_threshold' => 2]);

        $owner = User::factory()->create();
        $source = Note::factory()->create(['path' => 'source', 'user_id' => $owner->id]);

        foreach (['a', 'b', 'c'] as $missing) {
            Link::create([
                'source_note_id' => $source->id,
                'target_path' => $missing,
                'target_note_id' => null,
            ]);
        }

        Artisan::call('commonplace:doctor');
        $output = Artisan::output();
        $this->assertStringContainsString('Orphaned wikilinks', $output);
        $this->assertStringContainsString('3 (over threshold 2)', $output);
        $this->assertStringContainsString('php artisan commonplace:relink', $output);
    }

    public function test_doctor_silent_when_orphan_count_under_threshold(): void
    {
        config(['commonplace.wikilinks.orphan_warn_threshold' => 50]);

        $owner = User::factory()->create();
        $source = Note::factory()->create(['path' => 'source', 'user_id' => $owner->id]);

        Link::create([
            'source_note_id' => $source->id,
            'target_path' => 'missing',
            'target_note_id' => null,
        ]);

        $exit = Artisan::call('commonplace:doctor', ['--exit-code' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 (under threshold 50)', $output);
    }

    public function test_doctor_skips_mcp_middleware_check_when_mcp_disabled(): void
    {
        config(['commonplace.mcp.enabled' => false]);

        Artisan::call('commonplace:doctor');
        $output = Artisan::output();

        $this->assertStringContainsString('MCP middleware', $output);
        $this->assertStringContainsString('mcp.enabled = false', $output);
    }

    public function test_doctor_fails_when_mcp_enabled_but_middleware_empty(): void
    {
        config(['commonplace.mcp.enabled' => true]);
        config(['commonplace.mcp.middleware' => []]);

        $exit = Artisan::call('commonplace:doctor', ['--exit-code' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('MCP middleware', $output);
        $this->assertStringContainsString('empty', $output);
        $this->assertStringContainsString('COMMONPLACE_MCP_MIDDLEWARE', $output);
    }

    public function test_doctor_fails_when_sanctum_default_but_sanctum_not_installed(): void
    {
        // Sanctum is NOT in this package's `require`; it's `suggest`. The
        // test environment doesn't pull it in, so the check fires under
        // the default middleware stack and recommends the install.
        config(['commonplace.mcp.enabled' => true]);
        config(['commonplace.mcp.middleware' => ['auth:sanctum']]);

        $exit = Artisan::call('commonplace:doctor', ['--exit-code' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('MCP middleware', $output);
        $this->assertStringContainsString('auth:sanctum', $output);
        $this->assertStringContainsString('composer require laravel/sanctum', $output);
    }

    public function test_doctor_passes_when_middleware_does_not_require_missing_package(): void
    {
        config(['commonplace.mcp.enabled' => true]);
        config(['commonplace.mcp.middleware' => ['auth']]);

        $exit = Artisan::call('commonplace:doctor', ['--exit-code' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('MCP middleware', $output);
        $this->assertStringContainsString('auth', $output);
    }
}
