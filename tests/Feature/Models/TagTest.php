<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Models;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Tag;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

class TagTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_it_can_be_created_with_factory(): void
    {
        $tag = Tag::factory()->create(['name' => 'philosophy']);

        $this->assertDatabaseHas('commonplace_tags', [
            'id' => $tag->id,
            'name' => 'philosophy',
        ]);
    }

    public function test_name_must_be_unique(): void
    {
        Tag::factory()->create(['name' => 'unique-tag']);

        $this->expectException(QueryException::class);

        Tag::factory()->create(['name' => 'unique-tag']);
    }

    public function test_notes_relationship(): void
    {
        $tag = Tag::factory()->create();
        $notes = Note::factory()->count(2)->create();

        $tag->notes()->attach($notes->pluck('id'));

        $this->assertCount(2, $tag->fresh()->notes);
    }
}
