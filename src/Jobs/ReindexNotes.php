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
use NonConvexLabs\Commonplace\Models\Note;

#[Tries(3)]
#[Backoff([10, 30, 120])]
class ReindexNotes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly bool $force = false) {}

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

            try {
                $embeddings = $embedder->embedBatch(array_values($texts));

                foreach ($batch->values() as $index => $note) {
                    $vector->store($note->id, $embeddings[$index]);
                    $note->forceFill(['indexed_at' => now()])->save();
                }

                Log::info('Commonplace reindex batch complete', [
                    'batch' => $batchIndex + 1,
                    'notes' => $batch->count(),
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
