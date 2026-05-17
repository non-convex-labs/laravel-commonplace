<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Vector;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Models\Note;

class InPhpCosineDriver implements VectorSearchDriver
{
    /**
     * Once-per-process dedupe flags for the noisy log lines. The driver is a
     * container singleton, so these survive across calls within the same
     * worker/request, which is what we want — repeated soft-cap warnings on
     * the same request stream would just spam.
     */
    private static bool $softCapLogged = false;

    private static bool $hardCapLogged = false;

    private static bool $dimensionMismatchLogged = false;

    /**
     * Structured warnings produced by the most recent search() call. Drained
     * by callers via {@see lastWarnings()}. Reset at the top of every search().
     *
     * @var array<int, array{code: string, message: string, context: array<string, mixed>}>
     */
    private array $warnings = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
    ) {}

    public function store(int $noteId, array $vector): void
    {
        $this->db->table('commonplace_notes')
            ->where('id', $noteId)
            ->update([
                'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
                'embedding_dimensions' => count($vector),
            ]);
    }

    public function search(Builder $baseQuery, array $vector, int $limit): Collection
    {
        $this->warnings = [];

        $hardMax = (int) $this->config->get('commonplace.vector.in_php_cosine.hard_max_candidates', 20000);
        $softMax = (int) $this->config->get('commonplace.vector.in_php_cosine.max_candidates', 2000);

        // Cheap pre-check: count via the toBase() query without materializing rows.
        $candidateCount = (clone $baseQuery)
            ->whereNotNull('embedding')
            ->toBase()
            ->getCountForPagination();

        $truncated = false;
        if ($candidateCount > $hardMax) {
            $truncated = true;
            $this->logHardCap($candidateCount, $hardMax);
            $this->warnings[] = [
                'code' => 'hard_cap_truncated',
                'message' => "InPhp cosine driver candidate set ({$candidateCount}) exceeds hard cap ({$hardMax}); "
                    .'restricted to the '.$hardMax.' most recently updated notes. '
                    .'Older notes were not considered. Switch to pgvector or narrow the scope to avoid missing results.',
                'context' => [
                    'candidates' => $candidateCount,
                    'hard_cap' => $hardMax,
                    'soft_cap' => $softMax,
                ],
            ];
        } elseif ($candidateCount > $softMax) {
            $this->logSoftCap($candidateCount, $softMax, $hardMax);
        }

        // --- Scoring pass: project only (id, embedding, embedding_dimensions),
        // strip eager loads so we don't hydrate tags/owner for thousands of
        // candidates we will throw away. Row width here is ~bytes per note,
        // not kilobytes.
        $scoringQuery = (clone $baseQuery)
            ->setEagerLoads([])
            ->select(['id', 'embedding', 'embedding_dimensions', 'updated_at'])
            ->whereNotNull('embedding');

        if ($truncated) {
            // Graceful degradation: keep only the most-recent $hardMax candidates.
            // updated_at desc is the closest proxy we have to "the user cares
            // most about recent notes" without per-tenant signals.
            $scoringQuery->orderByDesc('updated_at')->limit($hardMax);
        }

        $queryDim = count($vector);
        $skippedDimMismatch = 0;
        $scored = [];

        foreach ($scoringQuery->get() as $candidate) {
            $parsed = $this->parse($candidate->getRawOriginal('embedding'));

            if ($parsed === null) {
                continue;
            }

            $storedDim = (int) ($candidate->getRawOriginal('embedding_dimensions') ?? count($parsed));

            if ($storedDim !== $queryDim || count($parsed) !== $queryDim) {
                $skippedDimMismatch++;

                continue;
            }

            $scored[] = [
                'id' => $candidate->id,
                'distance' => $this->cosineDistance($vector, $parsed),
            ];
        }

        if ($skippedDimMismatch > 0) {
            $this->recordDimensionMismatch($skippedDimMismatch, $queryDim);
        }

        if ($scored === []) {
            return new Collection;
        }

        usort($scored, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

        $top = array_slice($scored, 0, $limit);
        $topIds = array_column($top, 'id');
        $distanceById = array_column($top, 'distance', 'id');

        // --- Hydration pass: re-run the original query (eager loads intact)
        // for only the winning IDs. We then re-sort in PHP using the scored
        // ordering — portable across MySQL / sqlite / Postgres without needing
        // FIELD()/CASE shenanigans.
        $hydrated = (clone $baseQuery)
            ->whereIn('id', $topIds)
            ->get()
            ->each(function (Note $note) use ($distanceById) {
                $note->setAttribute('distance', $distanceById[$note->id] ?? null);
            })
            ->sortBy(fn (Note $note) => $distanceById[$note->id] ?? PHP_FLOAT_MAX)
            ->values();

        return $hydrated;
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

        $trimmed = trim($stored);

        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return array_map(static fn ($v) => (float) $v, $decoded);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function defineColumn(Blueprint $table, string $column = 'embedding'): void
    {
        $table->longText($column)->nullable();
    }

    public function lastWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineDistance(array $a, array $b): float
    {
        // Defensive: callers in this driver pre-check dimensions, but a
        // future caller or accidental misuse should still get a useful error
        // rather than a silently-wrong score.
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException(
                'Cosine distance requires vectors of equal length; got '.count($a).' vs '.count($b).'.'
            );
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $ai) {
            $bi = $b[$i];
            $dot += $ai * $bi;
            $magA += $ai * $ai;
            $magB += $bi * $bi;
        }

        if ($magA === 0.0 || $magB === 0.0) {
            return 1.0;
        }

        return 1.0 - ($dot / (sqrt($magA) * sqrt($magB)));
    }

    private function logSoftCap(int $candidates, int $softMax, int $hardMax): void
    {
        if (self::$softCapLogged) {
            return;
        }

        self::$softCapLogged = true;

        Log::warning('Commonplace InPhpCosine candidate set above soft cap', [
            'candidates' => $candidates,
            'soft_cap' => $softMax,
            'hard_cap' => $hardMax,
        ]);
    }

    private function logHardCap(int $candidates, int $hardMax): void
    {
        if (self::$hardCapLogged) {
            return;
        }

        self::$hardCapLogged = true;

        Log::warning('Commonplace InPhpCosine candidate set above hard cap; falling back to most-recent slice', [
            'candidates' => $candidates,
            'hard_cap' => $hardMax,
        ]);
    }

    private function recordDimensionMismatch(int $skipped, int $queryDim): void
    {
        $message = "{$skipped} candidate(s) skipped due to embedding dimension mismatch "
            .'(query dim '.$queryDim.'). Likely stale rows from a previous embedding '
            .'provider/model — re-run the reindex job to refresh them.';

        $this->warnings[] = [
            'code' => 'dimension_mismatch_skipped',
            'message' => $message,
            'context' => [
                'skipped' => $skipped,
                'query_dimensions' => $queryDim,
            ],
        ];

        if (self::$dimensionMismatchLogged) {
            return;
        }

        self::$dimensionMismatchLogged = true;

        Log::warning('Commonplace InPhpCosine skipped embeddings with mismatched dimensions', [
            'skipped' => $skipped,
            'query_dimensions' => $queryDim,
        ]);
    }
}
