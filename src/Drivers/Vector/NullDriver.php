<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Drivers\Vector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;

class NullDriver implements VectorSearchDriver
{
    public function store(int $noteId, array $vector): void
    {
        // No-op: semantic search is disabled.
    }

    public function search(Builder $baseQuery, array $vector, int $limit): Collection
    {
        return new Collection;
    }

    public function parse(mixed $stored): ?array
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function defineColumn(Blueprint $table, string $column = 'embedding'): void
    {
        $table->longText($column)->nullable();
    }

    public function lastWarnings(): array
    {
        return [];
    }
}
