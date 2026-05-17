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
use NonConvexLabs\Commonplace\Exceptions\VectorCandidateLimitExceeded;
use NonConvexLabs\Commonplace\Models\Note;

class InPhpCosineDriver implements VectorSearchDriver
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
    ) {}

    public function store(int $noteId, array $vector): void
    {
        $this->db->table('commonplace_notes')
            ->where('id', $noteId)
            ->update(['embedding' => json_encode($vector, JSON_THROW_ON_ERROR)]);
    }

    public function search(Builder $baseQuery, array $vector, int $limit): Collection
    {
        $baseQuery = (clone $baseQuery)->whereNotNull('embedding');

        $candidateCount = $baseQuery->toBase()->getCountForPagination();

        $hardMax = (int) $this->config->get('commonplace.vector.in_php_cosine.hard_max_candidates', 20000);
        $softMax = (int) $this->config->get('commonplace.vector.in_php_cosine.max_candidates', 2000);

        if ($candidateCount > $hardMax) {
            throw new VectorCandidateLimitExceeded(
                "In-PHP cosine driver candidate set ({$candidateCount}) exceeds hard cap ({$hardMax}). "
                .'Switch to the pgvector driver or narrow the search scope (e.g. scope=mine).'
            );
        }

        if ($candidateCount > $softMax) {
            Log::warning('Commonplace InPhpCosine candidate set above soft cap', [
                'candidates' => $candidateCount,
                'soft_cap' => $softMax,
                'hard_cap' => $hardMax,
            ]);
        }

        $ranked = $baseQuery->get()
            ->map(function (Note $note) use ($vector) {
                $stored = $this->parse($note->getRawOriginal('embedding'));

                if ($stored === null) {
                    return null;
                }

                $note->setAttribute('distance', $this->cosineDistance($vector, $stored));

                return $note;
            })
            ->filter()
            ->sortBy('distance')
            ->take($limit)
            ->values()
            ->all();

        return new Collection($ranked);
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

        try {
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
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

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineDistance(array $a, array $b): float
    {
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
}
