<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Console;

use Illuminate\Console\Command;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Services\WikilinkParser;

/**
 * Walks every `commonplace_links` row with `target_note_id IS NULL` and
 * re-runs `WikilinkParser::resolveTarget` against the literal `target_path`
 * stored on the row. Idempotent — rows already linked to a target are
 * left alone. Designed as the recovery path when `UpdateWikilinksJob`
 * never ran (queue worker down, dispatch black-holed) or when a target
 * note was created after the source's `syncWikilinks` last ran.
 *
 * Known blind spot: only repairs orphans (NULL `target_note_id`). Mis-
 * resolved links — where `target_note_id` is non-null but points at the
 * wrong note because `resolveTarget` fell through to its trailing-
 * segment match with non-deterministic ordering — are not detected
 * here. Tracked separately as a `--verify` follow-up.
 */
class RelinkCommand extends Command
{
    protected $signature = 'commonplace:relink '
        .'{--exit-code : Return a non-zero exit code if any orphan remains after the run}';

    protected $description = 'Re-resolve commonplace_links rows whose target_note_id is NULL.';

    public function handle(WikilinkParser $parser): int
    {
        $orphans = Link::query()->whereNull('target_note_id')->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphaned link rows. Nothing to do.');

            return self::SUCCESS;
        }

        $resolved = 0;
        $stillOrphaned = 0;

        foreach ($orphans as $orphan) {
            $note = $parser->resolveTarget($orphan->target_path);

            if ($note === null) {
                $stillOrphaned++;

                continue;
            }

            $orphan->update(['target_note_id' => $note->id]);
            $resolved++;
        }

        $this->info("Resolved {$resolved} orphan(s); {$stillOrphaned} remain (target note still missing).");

        if ($stillOrphaned > 0 && $this->option('exit-code')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
