<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Exceptions\EmbeddingProviderUnavailable;
use NonConvexLabs\Commonplace\Exceptions\PartialBatchEmbeddingException;
use NonConvexLabs\Commonplace\Jobs\ReindexNotes;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\RecordingEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;
use ReflectionClass;
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

    public function test_it_dispatches_to_the_embeddings_queue(): void
    {
        Queue::fake();

        ReindexNotes::dispatch();

        // External-API + sleep-heavy; pinned off the user-facing
        // queues so a slow provider can't starve them. See
        // styleguide §6.
        Queue::assertPushedOn('commonplace-embeddings', ReindexNotes::class);
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
        $tries = (new ReflectionClass(ReindexNotes::class))
            ->getAttributes(Tries::class)[0]->newInstance();

        $this->assertSame(3, $tries->tries);
    }

    public function test_it_declares_backoff(): void
    {
        $backoff = (new ReflectionClass(ReindexNotes::class))
            ->getAttributes(Backoff::class)[0]->newInstance();

        $this->assertSame([10, 30, 120], $backoff->backoff);
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

    public function test_partial_batch_exception_checkpoints_completed_notes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        Log::spy();

        config()->set('commonplace.reindex.batch_size', 3);

        for ($i = 0; $i < 3; $i++) {
            Note::factory()->create([
                'updated_at' => now()->subMinutes(120),
                'indexed_at' => null,
            ]);
        }

        // Embedder fails on the third note after embedding the first two.
        // The completed vectors must land on disk and the third note
        // must keep indexed_at NULL so the next run picks it up.
        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(string $text): array
            {
                return [0.0];
            }

            public function embedQuery(string $text): array
            {
                return [0.0];
            }

            public function embedBatch(array $texts): array
            {
                throw new PartialBatchEmbeddingException(
                    completed: [
                        0 => [0.11, 0.22, 0.33],
                        1 => [0.44, 0.55, 0.66],
                    ],
                    failedIndex: 2,
                    // Type-enforced: cause must implement PublicMessage.
                    cause: new EmbeddingProviderUnavailable('voyage', 'rate_limited'),
                );
            }

            public function dimensions(): int
            {
                return 3;
            }
        });

        Bus::dispatchSync(new ReindexNotes);

        $notes = Note::orderBy('id')->get();
        $this->assertNotNull($notes[0]->indexed_at);
        $this->assertNotNull($notes[1]->indexed_at);
        $this->assertNull($notes[2]->indexed_at);
        $this->assertSame([0.11, 0.22, 0.33], $notes[0]->embedding);
        $this->assertSame([0.44, 0.55, 0.66], $notes[1]->embedding);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Commonplace reindex batch partially failed'
                    && ($context['completed'] ?? null) === 2
                    && ($context['remaining'] ?? null) === 1;
            });
    }

    private function bindRecordingEmbedder(RecordingEmbeddingProvider $recorder): void
    {
        $this->app->instance(EmbeddingProvider::class, $recorder);
    }
}
