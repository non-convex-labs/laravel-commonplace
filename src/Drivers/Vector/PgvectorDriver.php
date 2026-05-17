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

        $this->db->table('commonplace_notes')
            ->where('id', $noteId)
            ->update(['embedding' => $this->formatVector($vector)]);
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
        if ($stored === null || $stored === '') {
            return null;
        }

        if (is_array($stored)) {
            return array_map(static fn ($v) => (float) $v, $stored);
        }

        if (! is_string($stored)) {
            return null;
        }

        $trimmed = trim($stored, "[] \t\n\r\0\x0B");

        if ($trimmed === '') {
            return null;
        }

        return array_map(static fn (string $v) => (float) $v, explode(',', $trimmed));
    }

    public function isEnabled(): bool
    {
        return true;
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
