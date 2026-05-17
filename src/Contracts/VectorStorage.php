<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

use Illuminate\Database\Schema\Blueprint;

/**
 * Owns the row-level embedding column: schema definition, write path, and
 * the reverse read path. Drivers that keep embeddings inside the notes table
 * (InPhpCosine, Pgvector, the neutral default column) implement this.
 *
 * External-service drivers that push vectors to a remote store (Qdrant,
 * Pinecone, Chroma) should bind a no-op {@see VectorStorage} implementation
 * separately from their {@see VectorSearch} binding instead of forcing this
 * surface to lie.
 */
interface VectorStorage
{
    /**
     * Persist a freshly computed embedding for a note. Writes the embedding
     * column (and any companion columns like `embedding_dimensions`) directly;
     * callers update `indexed_at` separately.
     *
     * @param  array<int, float>  $vector
     */
    public function store(int $noteId, array $vector): void;

    /**
     * Convert a stored embedding column value back into a float vector, or
     * return null if the column is empty. Drivers own their serialization
     * format; this is the only sanctioned reverse path.
     *
     * @return array<int, float>|null
     */
    public function parse(mixed $stored): ?array;

    /**
     * Add the driver-appropriate embedding column to a Blueprint. Used by the
     * driver's publishable migration; the base create-table migration uses
     * a neutral nullable longText so swapping drivers later is possible.
     */
    public function defineColumn(Blueprint $table, string $column = 'embedding'): void;
}
