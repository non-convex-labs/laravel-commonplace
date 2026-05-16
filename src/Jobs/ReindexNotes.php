<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Models\Note;

class ReindexNotes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 30, 120];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Commonplace reindex job failed', [
            'job' => self::class,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(EmbeddingProvider $embedder): void
    {
        $cooldown = (int) config('commonplace.reindex.cooldown_minutes', 60);
        $batchSize = (int) config('commonplace.reindex.batch_size', 10);
        $batchDelaySeconds = (int) config('commonplace.reindex.batch_delay_seconds', 25);

        $maxBatches = (int) floor(240 / max($batchDelaySeconds, 1));

        $notes = Note::needsReindexing($cooldown)
            ->limit($batchSize * $maxBatches)
            ->get();

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
                    $note->embedding = $embeddings[$index];
                    $note->indexed_at = now();
                    $note->save();
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
