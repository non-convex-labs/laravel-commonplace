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
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

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
