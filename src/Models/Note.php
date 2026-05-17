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
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Contracts\VectorStorage;
use NonConvexLabs\Commonplace\Database\Factories\NoteFactory;
use NonConvexLabs\Commonplace\Enums\Visibility;
use Throwable;

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
        'embedding_dimensions',
    ];

    protected function casts(): array
    {
        return [
            'indexed_at' => 'datetime',
            'visibility' => Visibility::class,
        ];
    }

    /**
     * Embedding is read-only on the model — the active VectorStorage
     * implementation owns serialization and the write path is
     * `$storage->store($id, $vec)`.
     *
     * Depends on the narrower VectorStorage contract (not the composite
     * VectorSearchDriver) because the accessor only needs parse(). This
     * keeps future external-service drivers free to bind storage
     * independently from search.
     *
     * Deliberately NOT cached: the binding is resolved on every read so
     * test rebinds (`$app->instance(VectorStorage::class, ...)`) take
     * effect against already-hydrated Notes. Parsing a JSON array is
     * cheap relative to anything that consumes it.
     *
     * Driver resolution is wrapped defensively: a misconfigured driver
     * (bad `commonplace.vector.driver` value, missing dependency on a
     * queue worker, etc.) must not brick model hydration — broadcasting,
     * `dd($note)`, queue payloads, and toArray() all read this accessor
     * and should degrade to `null` rather than throw far from the cause.
     */
    protected function embedding(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value) {
                try {
                    return app(VectorStorage::class)->parse($value);
                } catch (Throwable $e) {
                    static $logged = false;

                    if (! $logged) {
                        $logged = true;
                        Log::warning('Commonplace: failed to resolve VectorStorage for Note::embedding accessor; returning null.', [
                            'note_id' => $this->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }

                    return null;
                }
            },
        );
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
