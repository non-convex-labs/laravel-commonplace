<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type-hint surface for user models that own commonplace notes.
 *
 * Adopting this interface is optional — the package only relies on
 * `getAuthIdentifier()` (via Laravel's Authenticatable) when assigning
 * notes, and `name` when displaying version-history attribution. Use
 * the {@see \NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes}
 * trait to satisfy this contract structurally without writing the
 * relations by hand.
 *
 * The FK column names `user_id` (notes) and `changed_by` (versions)
 * are currently hardcoded; the user model's primary key must be `id`.
 */
interface CommonplaceUser
{
    public function notes(): HasMany;

    /**
     * @return Collection<int, \NonConvexLabs\Commonplace\Models\Note>
     */
    public function recentNotes(int $limit = 10): Collection;

    public function noteVersions(): HasMany;
}
