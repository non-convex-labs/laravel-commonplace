<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Enums;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use NonConvexLabs\Commonplace\Models\Note;

enum SemanticSearchScope: string
{
    /** Only notes owned by the requesting user. Bounded by the user's own vault size. */
    case Mine = 'mine';

    /** Only notes with visibility=public. */
    case Public = 'public';

    /** Mine + public + notes shared with me (the broad reading scope). */
    case Accessible = 'accessible';

    /**
     * Apply this scope to a Note query as a filter.
     *
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    public function apply(Builder $query, Authenticatable $user): Builder
    {
        return match ($this) {
            self::Mine => $query->where('user_id', $user->getAuthIdentifier()),
            self::Public => $query->where('visibility', 'public'),
            self::Accessible => $query->where(function (Builder $q) use ($user) {
                $q->where('user_id', $user->getAuthIdentifier())
                    ->orWhere('visibility', 'public')
                    ->orWhereHas('shares', function (Builder $shareQuery) use ($user) {
                        $shareQuery->where('user_id', $user->getAuthIdentifier());
                    });
            }),
        };
    }
}
