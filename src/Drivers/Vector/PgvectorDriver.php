<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Vector;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Exceptions\PgvectorDriverNotReady;

class PgvectorDriver implements VectorSearchDriver
{
    /** Memoized self-gate result. null = not yet checked, true = ready, false = not ready (also threw). */
    private ?bool $ready = null;

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function store(int $noteId, array $vector): void
    {
        $this->ensureReady();

        // embedding_dimensions is redundant for pgvector's vector(N) typed
        // column, but we write it anyway so the per-row dimension sentinel is
        // consistent across drivers and survives re-migrations to a different N.
        $this->db->table('commonplace_notes')
            ->where('id', $noteId)
            ->update([
                'embedding' => $this->formatVector($vector),
                'embedding_dimensions' => count($vector),
            ]);
    }

    public function search(Builder $baseQuery, array $vector, int $limit): Collection
    {
        $this->ensureReady();

        return $baseQuery
            ->whereNotNull('embedding')
            ->selectVectorDistance('embedding', $vector, 'distance')
            ->orderByVectorDistance('embedding', $vector)
            ->limit($limit)
            ->get();
    }

    public function parse(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        if (is_array($stored)) {
            return $stored === [] ? null : array_map(static fn ($v) => (float) $v, $stored);
        }

        if (! is_string($stored)) {
            return null;
        }

        // Strip outer brackets plus all whitespace; "[]" and "   " and ""
        // collapse to the empty string and signal "no usable vector".
        $trimmed = trim($stored, "[] \t\n\r\0\x0B");

        if ($trimmed === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $trimmed));

        // Reject malformed input ("garbage", "1,abc") rather than coercing
        // each token to 0.0 — silent zero-fills would poison cosine search.
        // Mirrors InPhpCosineDriver::parse which returns null on bad JSON.
        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return null;
            }
        }

        return array_map(static fn (string $v) => (float) $v, $parts);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function lastWarnings(): array
    {
        // pgvector pushes filtering/sorting down to the database — there is
        // no in-PHP candidate cap or dimension-mismatch path that could
        // surface a partial result. Always empty.
        return [];
    }

    public function defineColumn(Blueprint $table, string $column = 'embedding'): void
    {
        // Resolved lazily here (not via the constructor) so read-only workers
        // — replicas, health checks, cron jobs that only call search() — can
        // boot the driver without a working embedder configured. defineColumn
        // is only reached by the publishable migration, which runs in an
        // environment where the embedder is already wired up.
        $dimensions = app(EmbeddingProvider::class)->dimensions();

        $table->vector($column, $dimensions)->nullable();
    }

    private function ensureReady(): void
    {
        if ($this->ready === true) {
            return;
        }

        if ($this->ready === false) {
            throw new PgvectorDriverNotReady($this->notReadyMessage('previously failed readiness check'));
        }

        if ($this->db->getDriverName() !== 'pgsql') {
            $this->ready = false;
            throw new PgvectorDriverNotReady($this->notReadyMessage(
                "connected to '{$this->db->getDriverName()}', but pgvector driver requires PostgreSQL"
            ));
        }

        $extension = $this->db->selectOne(
            "SELECT 1 AS present FROM pg_extension WHERE extname = 'vector'"
        );

        if ($extension === null) {
            $this->ready = false;
            throw new PgvectorDriverNotReady($this->notReadyMessage(
                'pgvector extension is not installed (run "CREATE EXTENSION vector" in your database)'
            ));
        }

        $column = $this->db->selectOne(
            'SELECT udt_name FROM information_schema.columns '
            ."WHERE table_name = 'commonplace_notes' AND column_name = 'embedding'"
        );

        if ($column === null) {
            $this->ready = false;
            throw new PgvectorDriverNotReady($this->notReadyMessage(
                'commonplace_notes.embedding column not found (run "php artisan migrate")'
            ));
        }

        if (($column->udt_name ?? null) !== 'vector') {
            $this->ready = false;
            throw new PgvectorDriverNotReady($this->notReadyMessage(
                "commonplace_notes.embedding column is '{$column->udt_name}', not 'vector' "
                .'(publish the pgvector migration: '
                .'php artisan vendor:publish --tag=commonplace-pgvector-migration && php artisan migrate)'
            ));
        }

        $this->ready = true;
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function formatVector(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $v) => (string) $v, $vector)).']';
    }

    private function notReadyMessage(string $reason): string
    {
        return "Commonplace pgvector driver is not ready: {$reason}. "
            .'Run `php artisan commonplace:doctor` for a full diagnostic.';
    }
}
