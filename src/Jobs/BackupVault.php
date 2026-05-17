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
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Backup\DestinationFactory;
use NonConvexLabs\Commonplace\Models\Note;
use RuntimeException;
use Throwable;

/**
 * Builds a {@see BackupBundle} once and pushes it to every destination
 * listed in `commonplace.backup.destinations`. Destinations run
 * sequentially; the first failure stops subsequent destinations and
 * the job is retried (subject to {@see Tries} / {@see Backoff}). This
 * is intentional — we don't want to silently succeed on one
 * destination while another quietly drifted out of sync.
 */
#[Tries(5)]
#[Backoff([30, 120, 300])]
class BackupVault implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function failed(Throwable $exception): void
    {
        Log::error('Commonplace backup failed', [
            'destinations' => (array) config('commonplace.backup.destinations', []),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(DestinationFactory $factory): void
    {
        $names = (array) config('commonplace.backup.destinations', []);

        if ($names === []) {
            throw new RuntimeException(
                'Commonplace backup: `commonplace.backup.destinations` is empty. '
                .'Configure at least one destination (e.g. "github" or "filesystem.local-backup").'
            );
        }

        // Stream the source query so large vaults stay memory-bounded.
        $bundle = BackupBundle::fromQuery(Note::query()->orderBy('id'));

        if ($bundle->isEmpty()) {
            Log::info('Commonplace backup: no notes to back up.');

            return;
        }

        foreach ($names as $name) {
            $destination = $factory->make((string) $name);
            $destination->push($bundle);
        }
    }
}
