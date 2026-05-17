<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use NonConvexLabs\Commonplace\Models\Note;

interface VectorSearchDriver
{
    /**
     * Persist a freshly computed embedding for a note. Writes the embedding
     * column directly; callers update `indexed_at` separately.
     *
     * @param  array<int, float>  $vector
     */
    public function store(int $noteId, array $vector): void;

    /**
     * Run a similarity search against an already-scoped base query. The base
     * query must not have any vector operations applied yet — the driver owns
     * the distance column, ordering, and limit. Returned notes carry a
     * `distance` attribute (lower = closer).
     *
     * @param  Builder<Note>  $baseQuery
     * @param  array<int, float>  $vector
     * @return Collection<int, Note>
     */
    public function search(Builder $baseQuery, array $vector, int $limit): Collection;

    /**
     * Convert a stored embedding column value back into a float vector, or
     * return null if the column is empty. Drivers own their serialization
     * format; this is the only sanctioned reverse path.
     *
     * @return array<int, float>|null
     */
    public function parse(mixed $stored): ?array;

    /**
     * Whether this driver performs real semantic search. False indicates a
     * disabled driver (e.g. NullDriver) and callers should short-circuit.
     */
    public function isEnabled(): bool;

    /**
     * Add the driver-appropriate embedding column to a Blueprint. Used by the
     * driver's publishable migration; the base create-table migration uses
     * a neutral nullable longText so swapping drivers later is possible.
     */
    public function defineColumn(Blueprint $table, string $column = 'embedding'): void;
}
