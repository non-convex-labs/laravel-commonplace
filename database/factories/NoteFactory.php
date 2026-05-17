<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
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

    /**
     * Persist an embedding via the active VectorSearchDriver after the note is
     * created. Pass an explicit vector for deterministic ranking tests; otherwise
     * defaults to a small uniform vector.
     *
     * @param  array<int, float>|null  $vector
     */
    public function withEmbedding(?array $vector = null): static
    {
        return $this->afterCreating(function (Note $note) use ($vector) {
            $vector ??= array_fill(0, 8, 0.1);
            app(VectorSearchDriver::class)->store($note->id, $vector);
            $note->refresh();
        });
    }

    protected function createUser(): int
    {
        $userClass = config('commonplace.user_model');

        return $userClass::factory()->create()->getKey();
    }
}
