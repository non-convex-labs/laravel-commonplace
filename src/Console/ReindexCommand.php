<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Console;

use Illuminate\Console\Command;
use NonConvexLabs\Commonplace\Jobs\ReindexNotes;
use NonConvexLabs\Commonplace\Models\Note;

class ReindexCommand extends Command
{
    protected $signature = 'commonplace:reindex '
        .'{--force : Clear indexed_at on every note before reindexing — required after switching embedding driver or model} '
        .'{--sync : Run the reindex inline instead of dispatching to the queue}';

    protected $description = 'Reindex notes through the configured embedding provider. Use --force after switching driver or model.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if ($force) {
            $count = Note::query()->update(['indexed_at' => null]);
            $this->info("Cleared indexed_at on {$count} note(s).");
        }

        if ($this->option('sync')) {
            ReindexNotes::dispatchSync($force);
            $this->info('Reindex completed (sync).');

            return self::SUCCESS;
        }

        ReindexNotes::dispatch($force);
        $this->info('Reindex job dispatched. Ensure a queue worker is running.');

        return self::SUCCESS;
    }
}
