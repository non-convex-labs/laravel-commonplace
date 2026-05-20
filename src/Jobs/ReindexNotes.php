<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorStorage;
use NonConvexLabs\Commonplace\Exceptions\PartialBatchEmbeddingException;
use NonConvexLabs\Commonplace\Models\Note;

#[Tries(3)]
#[Backoff([10, 30, 120])]
class ReindexNotes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly bool $force = false)
    {
        // External-API + sleep-heavy. Pin to a dedicated queue so a
        // slow embedding provider can't starve user-facing jobs
        // (wikilink rewrites, backups). Operators can scale the
        // embeddings worker pool independently. See styleguide §6.
        $this->onQueue('commonplace-embeddings');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Commonplace reindex job failed', [
            'job' => self::class,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(EmbeddingProvider $embedder, VectorStorage $vector): void
    {
        $cooldown = (int) config('commonplace.reindex.cooldown_minutes', 60);
        $batchSize = (int) config('commonplace.reindex.batch_size', 10);
        $batchDelaySeconds = (int) config('commonplace.reindex.batch_delay_seconds', 25);

        $maxBatches = (int) floor(240 / max($batchDelaySeconds, 1));

        // Force bypasses the updated_at cooldown so a just-cleared row is
        // picked up immediately (used by `commonplace:reindex --force`).
        $query = $this->force
            ? Note::query()->whereNull('indexed_at')
            : Note::needsReindexing($cooldown);

        $notes = $query->limit($batchSize * $maxBatches)->get();

        if ($notes->isEmpty()) {
            return;
        }

        foreach ($notes->chunk($batchSize) as $batchIndex => $batch) {
            if ($batchIndex > 0) {
                sleep($batchDelaySeconds);
            }

            $texts = $batch->map(fn (Note $note) => $note->title."\n\n".$note->content)->all();
            $batchNotes = $batch->values();

            try {
                $embeddings = $embedder->embedBatch(array_values($texts));

                foreach ($batchNotes as $index => $note) {
                    $vector->store($note->id, $embeddings[$index]);
                    $note->forceFill(['indexed_at' => now()])->save();
                }

                Log::info('Commonplace reindex batch complete', [
                    'batch' => $batchIndex + 1,
                    'notes' => $batch->count(),
                ]);
            } catch (PartialBatchEmbeddingException $e) {
                // Checkpoint what the driver did manage to embed before
                // it gave up. The remaining notes keep `indexed_at IS
                // NULL` so the next ReindexNotes run picks them up
                // (queue-level Tries(3)/Backoff handles re-dispatch).
                $strayIndices = [];

                foreach ($e->completed as $index => $embedding) {
                    if (! isset($batchNotes[$index])) {
                        $strayIndices[] = $index;

                        continue;
                    }

                    $note = $batchNotes[$index];
                    $vector->store($note->id, $embedding);
                    $note->forceFill(['indexed_at' => now()])->save();
                }

                if ($strayIndices !== []) {
                    // Embeddings whose indices don't map to a batch note are
                    // paid-for and discarded — almost certainly a driver bug
                    // (off-by-one or wrong index basis). Log loudly so the
                    // discrepancy is investigable instead of silently dropped.
                    Log::warning('Commonplace reindex received stray embedding indices', [
                        'batch' => $batchIndex + 1,
                        'stray_indices' => $strayIndices,
                        'expected_keys' => $batchNotes->keys()->all(),
                    ]);
                }

                Log::warning('Commonplace reindex batch partially failed', [
                    'batch' => $batchIndex + 1,
                    'completed' => count($e->completed),
                    'remaining' => $batch->count() - count($e->completed),
                    'error' => $e->getMessage(),
                ]);
            } catch (\RuntimeException $e) {
                Log::error('Commonplace reindexing failed', [
                    'error' => $e->getMessage(),
                    'batch' => $batchIndex + 1,
                    'note_count' => $batch->count(),
                ]);
            }
        }
    }
}
