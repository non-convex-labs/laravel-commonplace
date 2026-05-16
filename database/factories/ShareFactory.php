<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Share;

class ShareFactory extends Factory
{
    protected $model = Share::class;

    public function definition(): array
    {
        return [
            'note_id' => Note::factory(),
            'user_id' => $this->createUser(),
            'permission' => 'read',
        ];
    }

    protected function createUser(): int
    {
        $userClass = config('commonplace.user_model');

        return $userClass::factory()->create()->getKey();
    }
}
