<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use NonConvexLabs\Commonplace\Models\Note;

/**
 * Performs similarity search and surfaces per-call diagnostics. Both
 * row-local drivers (InPhpCosine, Pgvector) and external-service drivers
 * (Qdrant, Pinecone, Chroma) implement this surface; storage is a separate
 * concern (see {@see VectorStorage}).
 */
interface VectorSearch
{
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
     * Whether this driver performs real semantic search. False indicates a
     * disabled driver (e.g. NullDriver) and callers should short-circuit.
     */
    public function isEnabled(): bool;

    /**
     * Structured, machine-readable warnings produced by the most recent call
     * to {@see search()}. Each entry has the shape
     * `['code' => string, 'message' => string, 'context' => array]`.
     * Drivers that never produce warnings return an empty array.
     *
     * Reset at the start of every search() call so callers see only the
     * warnings from the immediately preceding invocation.
     *
     * @return array<int, array{code: string, message: string, context: array<string, mixed>}>
     */
    public function lastWarnings(): array;
}
