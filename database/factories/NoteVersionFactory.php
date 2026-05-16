<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;

class NoteVersionFactory extends Factory
{
    protected $model = NoteVersion::class;

    public function definition(): array
    {
        $content = $this->faker->paragraphs(2, true);

        return [
            'note_id' => Note::factory(),
            'note_path' => 'notes/'.$this->faker->slug().'.md',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'changed_by' => null,
        ];
    }
}
