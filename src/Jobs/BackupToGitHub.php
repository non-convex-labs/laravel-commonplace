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
use NonConvexLabs\Commonplace\Backup\Destinations\GitHubBackupDestination;
use NonConvexLabs\Commonplace\Models\Note;
use Throwable;

/**
 * Single-destination GitHub-only backup job. Kept for back-compat with
 * existing schedulers that dispatch `BackupToGitHub::dispatch()`.
 *
 * For multi-destination fan-out, use {@see BackupVault} with
 * `commonplace.backup.destinations`.
 */
#[Tries(5)]
#[Backoff([30, 120, 300])]
class BackupToGitHub implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        // Long-running outbound I/O. Pin to the shared backups queue
        // so worker concurrency can be tuned independently of
        // user-facing jobs.
        $this->onQueue('commonplace-backups');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Commonplace GitHub backup failed', [
            'repo' => config('commonplace.backup.github.repo'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(GitHubBackupDestination $destination): void
    {
        // Always delegate to the destination — its config validation
        // and empty-bundle handling are the source of truth so this
        // job and `BackupVault` agree on edge cases.
        $notes = Note::query()->orderBy('id')->get();
        $bundle = BackupBundle::fromNotes($notes);

        $destination->push($bundle);
    }
}
