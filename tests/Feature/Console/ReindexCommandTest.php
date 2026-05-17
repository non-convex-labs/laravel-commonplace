<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Jobs\ReindexNotes;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\RecordingEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class ReindexCommandTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_default_invocation_dispatches_job_without_force(): void
    {
        Queue::fake();

        $this->artisan('commonplace:reindex')
            ->assertExitCode(0)
            ->expectsOutputToContain('Reindex job dispatched');

        Queue::assertPushed(ReindexNotes::class, fn (ReindexNotes $job) => $job->force === false);
    }

    public function test_force_flag_clears_indexed_at_and_dispatches_force_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        Note::factory()->count(3)->create([
            'user_id' => $owner->id,
            'indexed_at' => now()->subDay(),
        ]);

        $this->artisan('commonplace:reindex', ['--force' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Cleared indexed_at on 3 note(s).');

        $this->assertSame(3, Note::whereNull('indexed_at')->count());
        Queue::assertPushed(ReindexNotes::class, fn (ReindexNotes $job) => $job->force === true);
    }

    public function test_sync_flag_runs_reindex_inline(): void
    {
        Bus::fake();

        $this->artisan('commonplace:reindex', ['--sync' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Reindex completed (sync).');

        Bus::assertDispatchedSync(ReindexNotes::class);
    }

    public function test_force_sync_actually_reindexes_existing_notes(): void
    {
        $this->app->instance(EmbeddingProvider::class, $recorder = new RecordingEmbeddingProvider);

        $owner = User::factory()->create();
        $notes = Note::factory()->count(2)->create([
            'user_id' => $owner->id,
            'indexed_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $this->artisan('commonplace:reindex', ['--force' => true, '--sync' => true])
            ->assertExitCode(0);

        $this->assertSame(1, $recorder->batchCalls);
        $this->assertSame([2], $recorder->batchSizes);

        foreach ($notes as $note) {
            $this->assertNotNull($note->fresh()->indexed_at);
        }
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('commonplace:reindex');
    }
}
