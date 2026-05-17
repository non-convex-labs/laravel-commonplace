<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Database\Factories\NoteFactory;

class Note extends Model
{
    use HasFactory;

    protected $table = 'commonplace_notes';

    protected $fillable = [
        'path',
        'title',
        'content',
        'content_hash',
        'visibility',
        'indexed_at',
        'user_id',
    ];

    protected $hidden = [
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'indexed_at' => 'datetime',
            'visibility' => 'string',
        ];
    }

    /**
     * Embedding is read-only on the model — the active VectorSearchDriver
     * owns serialization and the write path is `$driver->store($id, $vec)`.
     */
    protected function embedding(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => app(VectorSearchDriver::class)->parse($value),
        )->shouldCache();
    }

    public function getRouteKeyName(): string
    {
        return 'path';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('commonplace.user_model'), 'user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NoteVersion::class, 'note_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'commonplace_note_tag', 'note_id', 'tag_id');
    }

    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'source_note_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(Link::class, 'target_note_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'note_id');
    }

    public function scopeAccessibleBy(Builder $query, Authenticatable $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->getAuthIdentifier())
                ->orWhere('visibility', 'public')
                ->orWhereHas('shares', function (Builder $shareQuery) use ($user) {
                    $shareQuery->where('user_id', $user->getAuthIdentifier());
                });
        });
    }

    public function scopeInFolder(Builder $query, string $folder): Builder
    {
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $folder);

        return $query->where('path', 'like', $escaped.'/%');
    }

    public function scopeWithTag(Builder $query, string $tagName): Builder
    {
        return $query->whereHas('tags', function (Builder $tagQuery) use ($tagName) {
            $tagQuery->where('name', $tagName);
        });
    }

    public function scopeNeedsReindexing(Builder $query, int $cooldownMinutes = 60): Builder
    {
        return $query->whereNull('indexed_at')
            ->where('updated_at', '<', now()->subMinutes($cooldownMinutes));
    }

    protected static function newFactory(): NoteFactory
    {
        return NoteFactory::new();
    }
}
