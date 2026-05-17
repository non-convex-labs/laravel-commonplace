<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;

/**
 * Switch commonplace_notes.embedding from the neutral longText column shipped
 * by the base migration to a typed pgvector column.
 *
 * Existing embeddings written by InPhpCosineDriver are stored as JSON arrays
 * like `[0.1,0.2,...]`, which is byte-identical to pgvector's text input
 * format — so `ALTER ... USING embedding::vector(N)` preserves them on the
 * way up, and `embedding::text` preserves them on the way down. This is the
 * data-preserving path; if the column contains anything other than NULL or a
 * well-formed JSON/pgvector array, the cast will fail and the migration
 * aborts so you can clean up rather than silently lose embeddings.
 */
return new class extends Migration
{
    /**
     * Switch the embedding column to pgvector after a pre-flight integrity check.
     *
     * WARNING — long lock. The `ALTER TABLE ... USING embedding::vector(N)`
     * statement holds an ACCESS EXCLUSIVE lock on commonplace_notes for the
     * full duration of the row scan. On large tables this is minutes of
     * blocked writes (and blocked reads — ACCESS EXCLUSIVE excludes
     * everything, including SELECT). Run during a low-traffic window.
     *
     * The pre-flight `SELECT ... !~ '^\[.*\]$'` catches rows that would
     * crash the cast mid-`ALTER` (hand-edited content, partial-reindex
     * garbage, empty-string defaults). Crashing mid-`ALTER` is the worst
     * case: the lock is held for the entire scan only to be rolled back.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        // Clear empty-string defaults that aren't JSON arrays but also aren't
        // real data. Without this, the format check below trips on them.
        DB::statement("UPDATE commonplace_notes SET embedding = NULL WHERE embedding = ''");

        $offenders = DB::select(
            'SELECT id FROM commonplace_notes '
            ."WHERE embedding IS NOT NULL AND embedding !~ '^\\[.*\\]$' LIMIT 5"
        );

        if ($offenders !== []) {
            $ids = implode(', ', array_map(static fn ($row) => (string) $row->id, $offenders));

            throw new RuntimeException(
                'Refusing to ALTER commonplace_notes.embedding to vector: rows contain values that are not in '
                .'pgvector-compatible format (expected NULL or a JSON-array string like [0.1,0.2,...]). '
                ."Sample offending row IDs: {$ids}. "
                .'Run `php artisan commonplace:doctor --pgvector-migration-precheck` to see all offending rows, '
                .'fix them (set to NULL or repair the value), then re-run this migration.'
            );
        }

        $dimensions = (int) app(EmbeddingProvider::class)->dimensions();

        DB::statement(
            "ALTER TABLE commonplace_notes ALTER COLUMN embedding TYPE vector({$dimensions}) "
            ."USING embedding::vector({$dimensions})"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE commonplace_notes ALTER COLUMN embedding TYPE text '
            .'USING embedding::text'
        );
    }
};
