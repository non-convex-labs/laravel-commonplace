<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NonConvexLabs\Commonplace\Database\Factories\ShareFactory;

class Share extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'commonplace_shares';

    protected $fillable = [
        'note_id',
        'user_id',
        'permission',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class, 'note_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('commonplace.user_model'));
    }

    protected static function newFactory(): ShareFactory
    {
        return ShareFactory::new();
    }
}
