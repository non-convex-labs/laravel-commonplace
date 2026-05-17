<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class CommonplaceNormalizationTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private Commonplace $commonplace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commonplace = $this->app->make(Commonplace::class);
        $this->owner = User::factory()->create();
    }

    public function test_create_note_canonicalizes_crlf_content_to_lf(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/crlf',
            content: "line one\r\nline two\r\nline three",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertSame("line one\nline two\nline three", $note->fresh()->content);
        $this->assertStringNotContainsString("\r", $note->fresh()->content);
    }

    public function test_update_note_treats_crlf_and_lf_as_equal_for_version_dedup(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/dedup',
            content: "alpha\nbravo\ncharlie",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/dedup',
            data: ['content' => "alpha\r\nbravo\r\ncharlie"],
            user: $this->owner,
        );

        $this->assertSame(0, NoteVersion::where('note_id', $note->id)->count());
    }

    public function test_edit_note_matches_crlf_old_string_against_lf_stored_content(): void
    {
        $this->commonplace->createNote(
            path: 'projects/edit-crlf',
            content: "header\nbody\nfooter",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->editNote(
            path: 'projects/edit-crlf',
            oldString: "header\r\nbody",
            newString: "header\r\nupdated body",
            replaceAll: false,
            user: $this->owner,
        );

        $this->assertDatabaseHas('commonplace_notes', [
            'path' => 'projects/edit-crlf',
            'content' => "header\nupdated body\nfooter",
        ]);
    }

    public function test_create_note_normalizes_backslash_path_to_forward_slash(): void
    {
        $this->commonplace->createNote(
            path: 'projects\\alpha\\intro',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertDatabaseHas('commonplace_notes', ['path' => 'projects/alpha/intro']);
        $this->assertDatabaseMissing('commonplace_notes', ['path' => 'projects\\alpha\\intro']);
    }

    public function test_read_note_finds_record_when_caller_supplies_backslash_path(): void
    {
        $this->commonplace->createNote(
            path: 'projects/lookup',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $note = $this->commonplace->readNote('projects\\lookup', $this->owner);

        $this->assertSame('projects/lookup', $note->path);
    }

    public function test_update_note_normalizes_new_path(): void
    {
        $this->commonplace->createNote(
            path: 'projects/movable',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/movable',
            data: ['new_path' => 'archive\\moved'],
            user: $this->owner,
        );

        $this->assertDatabaseHas('commonplace_notes', ['path' => 'archive/moved']);
    }

    public function test_move_note_normalizes_both_paths(): void
    {
        $this->commonplace->createNote(
            path: 'projects/source',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->moveNote('projects\\source', 'projects\\target', $this->owner);

        $this->assertDatabaseHas('commonplace_notes', ['path' => 'projects/target']);
        $this->assertDatabaseMissing('commonplace_notes', ['path' => 'projects/source']);
    }
}
