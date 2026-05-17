<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;
use NonConvexLabs\Commonplace\Contracts\CommonplaceUser;

class User extends Authenticatable implements CommonplaceUser
{
    use HasCommonplaceNotes;
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
