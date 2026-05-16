<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;

class LinkFactory extends Factory
{
    protected $model = Link::class;

    public function definition(): array
    {
        return [
            'source_note_id' => Note::factory(),
            'target_path' => 'notes/'.$this->faker->slug().'.md',
            'target_note_id' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'target_note_id' => Note::factory(),
        ]);
    }
}
