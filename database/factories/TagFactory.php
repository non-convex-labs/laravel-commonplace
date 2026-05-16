<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NonConvexLabs\Commonplace\Models\Tag;

class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(2),
        ];
    }
}
