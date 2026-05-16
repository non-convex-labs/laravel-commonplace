<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class NoteVersionTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_it_can_be_created_with_factory(): void
    {
        $version = NoteVersion::factory()->create([
            'note_path' => 'notes/a.md',
            'content' => 'old text',
        ]);

        $this->assertDatabaseHas('commonplace_note_versions', [
            'id' => $version->id,
            'note_path' => 'notes/a.md',
        ]);
    }

    public function test_updated_at_is_disabled(): void
    {
        $this->assertNull(NoteVersion::UPDATED_AT);
    }

    public function test_note_relationship(): void
    {
        $note = Note::factory()->create();
        $version = NoteVersion::factory()->create(['note_id' => $note->id]);

        $this->assertTrue($version->note->is($note));
    }

    public function test_author_relationship(): void
    {
        $user = User::factory()->create();
        $version = NoteVersion::factory()->create(['changed_by' => $user->id]);

        $this->assertTrue($version->author->is($user));
    }

    public function test_note_id_is_nulled_when_note_deleted(): void
    {
        $note = Note::factory()->create();
        $version = NoteVersion::factory()->create(['note_id' => $note->id]);

        $note->delete();

        $this->assertNull($version->fresh()->note_id);
    }
}
