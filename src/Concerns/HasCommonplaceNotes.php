<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;

trait HasCommonplaceNotes
{
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'user_id');
    }

    public function recentNotes(int $limit = 10)
    {
        return $this->notes()
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function noteVersions(): HasMany
    {
        return $this->hasMany(NoteVersion::class, 'changed_by');
    }
}
