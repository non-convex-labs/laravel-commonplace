<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

class LinkTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_it_can_be_created_with_factory(): void
    {
        $link = Link::factory()->create(['target_path' => 'notes/target.md']);

        $this->assertDatabaseHas('commonplace_links', [
            'id' => $link->id,
            'target_path' => 'notes/target.md',
        ]);
    }

    public function test_source_note_relationship(): void
    {
        $note = Note::factory()->create();
        $link = Link::factory()->create(['source_note_id' => $note->id]);

        $this->assertTrue($link->sourceNote->is($note));
    }

    public function test_target_note_relationship(): void
    {
        $target = Note::factory()->create();
        $link = Link::factory()->create(['target_note_id' => $target->id]);

        $this->assertTrue($link->targetNote->is($target));
    }

    public function test_target_note_is_nulled_when_target_deleted(): void
    {
        $target = Note::factory()->create();
        $link = Link::factory()->create(['target_note_id' => $target->id]);

        $target->delete();

        $this->assertNull($link->fresh()->target_note_id);
    }

    public function test_unresolved_link_has_null_target_note(): void
    {
        $link = Link::factory()->create();

        $this->assertNull($link->targetNote);
    }
}
