<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NonConvexLabs\Commonplace\Models\Note;

class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(4);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

        return [
            'path' => 'notes/'.$slug.'-'.$this->faker->unique()->randomNumber(6).'.md',
            'title' => $title,
            'content' => $this->faker->paragraphs(3, true),
            'content_hash' => hash('sha256', $this->faker->text(200)),
            'visibility' => 'private',
            'indexed_at' => null,
            'embedding' => null,
            'user_id' => $this->createUser(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['visibility' => 'public']);
    }

    public function indexed(): static
    {
        return $this->state(fn () => ['indexed_at' => now()]);
    }

    public function withEmbedding(int $dimensions = 8): static
    {
        return $this->state(fn () => [
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);
    }

    protected function createUser(): int
    {
        $userClass = config('commonplace.user_model');

        return $userClass::factory()->create()->getKey();
    }
}
