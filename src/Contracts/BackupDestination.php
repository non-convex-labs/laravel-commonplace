<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

use NonConvexLabs\Commonplace\Backup\BackupBundle;

/**
 * A target the BackupVault job can push a {@see BackupBundle} to.
 *
 * Implementations should treat `push()` as the whole story: open
 * connections, write files, close, log. The orchestrator iterates
 * destinations sequentially and aborts on the first thrown exception
 * — so destinations *after* a failed one will not run on this attempt.
 * The job retries (5 tries, exponential backoff), which re-pushes
 * already-succeeded destinations on subsequent attempts. Make `push()`
 * idempotent when feasible (the built-in GitHub destination is — it
 * short-circuits when the new tree hash matches the base).
 */
interface BackupDestination
{
    public function push(BackupBundle $bundle): void;
}
