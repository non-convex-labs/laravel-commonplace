<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NonConvexLabs\Commonplace\Database\Factories\NoteVersionFactory;

class NoteVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'commonplace_note_versions';

    protected $fillable = [
        'note_id',
        'note_path',
        'content',
        'content_hash',
        'changed_by',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'note_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(config('commonplace.user_model'), 'changed_by');
    }

    protected static function newFactory(): NoteVersionFactory
    {
        return NoteVersionFactory::new();
    }
}
