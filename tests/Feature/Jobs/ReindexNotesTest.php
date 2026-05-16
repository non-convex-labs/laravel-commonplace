<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Jobs\ReindexNotes;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\RecordingEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

class ReindexNotesTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.reindex.cooldown_minutes', 60);
        config()->set('commonplace.reindex.batch_size', 2);
        config()->set('commonplace.reindex.batch_delay_seconds', 0);
    }

    public function test_it_dispatches_to_the_queue(): void
    {
        Queue::fake();

        ReindexNotes::dispatch();

        Queue::assertPushed(ReindexNotes::class);
    }

    public function test_it_runs_without_error_when_no_notes_need_reindexing(): void
    {
        $this->bindRecordingEmbedder($recorder = new RecordingEmbeddingProvider);

        Bus::dispatchSync(new ReindexNotes);

        $this->assertSame(0, $recorder->batchCalls);
    }

    public function test_it_embeds_and_indexes_notes_that_need_reindexing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        Note::factory()->create([
            'title' => 'First Note',
            'content' => 'first content',
            'updated_at' => now()->subMinutes(120),
            'indexed_at' => null,
        ]);

        Note::factory()->create([
            'title' => 'Second Note',
            'content' => 'second content',
            'updated_at' => now()->subMinutes(120),
            'indexed_at' => null,
        ]);

        $this->bindRecordingEmbedder($recorder = new RecordingEmbeddingProvider);

        Bus::dispatchSync(new ReindexNotes);

        $this->assertSame(1, $recorder->batchCalls);
        $this->assertCount(2, $recorder->lastBatch);
        $this->assertStringContainsString('First Note', $recorder->lastBatch[0]);
        $this->assertStringContainsString('first content', $recorder->lastBatch[0]);

        $notes = Note::orderBy('id')->get();
        $this->assertNotNull($notes[0]->indexed_at);
        $this->assertNotNull($notes[1]->indexed_at);
        $this->assertSame([0.1, 0.2, 0.3], $notes[0]->embedding);
        $this->assertSame([0.1, 0.2, 0.3], $notes[1]->embedding);
    }

    public function test_cooldown_excludes_recently_updated_notes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        Note::factory()->create([
            'updated_at' => now()->subMinutes(10),
            'indexed_at' => null,
        ]);

        $this->bindRecordingEmbedder($recorder = new RecordingEmbeddingProvider);

        Bus::dispatchSync(new ReindexNotes);

        $this->assertSame(0, $recorder->batchCalls);
    }

    public function test_batch_size_is_respected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        config()->set('commonplace.reindex.batch_size', 2);

        for ($i = 0; $i < 3; $i++) {
            Note::factory()->create([
                'updated_at' => now()->subMinutes(120),
                'indexed_at' => null,
            ]);
        }

        $this->bindRecordingEmbedder($recorder = new RecordingEmbeddingProvider);

        Bus::dispatchSync(new ReindexNotes);

        $this->assertSame([2, 1], $recorder->batchSizes);
    }

    public function test_it_uses_the_configured_embedding_provider(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        Note::factory()->create([
            'updated_at' => now()->subMinutes(120),
            'indexed_at' => null,
        ]);

        $this->bindRecordingEmbedder($recorder = new RecordingEmbeddingProvider);

        Bus::dispatchSync(new ReindexNotes);

        $this->assertGreaterThan(0, $recorder->batchCalls);
    }

    public function test_it_declares_tries(): void
    {
        $job = new ReindexNotes;

        $this->assertSame(3, $job->tries);
    }

    public function test_it_declares_backoff(): void
    {
        $job = new ReindexNotes;

        $this->assertSame([10, 30, 120], $job->backoff());
    }

    public function test_failed_logs_the_failure(): void
    {
        Log::spy();

        $job = new ReindexNotes;
        $job->failed(new RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Commonplace reindex job failed'
                    && ($context['job'] ?? null) === ReindexNotes::class
                    && ($context['exception'] ?? null) === RuntimeException::class
                    && ($context['message'] ?? null) === 'boom';
            });
    }

    private function bindRecordingEmbedder(RecordingEmbeddingProvider $recorder): void
    {
        $this->app->instance(EmbeddingProvider::class, $recorder);
    }
}
