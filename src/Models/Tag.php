<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use NonConvexLabs\Commonplace\Database\Factories\TagFactory;

class Tag extends Model
{
    use HasFactory;

    protected $table = 'commonplace_tags';

    protected $fillable = [
        'name',
    ];

    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(Note::class, 'commonplace_note_tag', 'tag_id', 'note_id');
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
