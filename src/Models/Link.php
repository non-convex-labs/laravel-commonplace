<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NonConvexLabs\Commonplace\Database\Factories\LinkFactory;

class Link extends Model
{
    use HasFactory;

    protected $table = 'commonplace_links';

    protected $fillable = [
        'source_note_id',
        'target_path',
        'target_note_id',
    ];

    public function sourceNote(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'source_note_id');
    }

    public function targetNote(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'target_note_id');
    }

    protected static function newFactory(): LinkFactory
    {
        return LinkFactory::new();
    }
}
