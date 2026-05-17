<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class NoteControllerTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get(route('commonplace.index'));

        $response->assertRedirect();
    }

    public function test_index_lists_only_notes_visible_to_user(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'own-note',
            'title' => 'Own note',
            'visibility' => 'private',
        ]);

        $stranger = User::factory()->create();
        Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'private-other',
            'title' => 'Private other',
            'visibility' => 'private',
        ]);

        Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'public-note',
            'title' => 'Public note',
            'visibility' => 'public',
        ]);

        $response = $this->actingAs($this->owner)->get(route('commonplace.index'));

        $response->assertOk();
        $response->assertSee('Own note');
        $response->assertSee('Public note');
        $response->assertDontSee('Private other');
    }

    public function test_index_shows_empty_state_when_no_notes_exist(): void
    {
        $response = $this->actingAs($this->owner)->get(route('commonplace.index'));

        $response->assertOk();
        $response->assertSee('Your vault is empty.', false);
    }

    public function test_show_returns_the_note_when_user_owns_it(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/llms',
            'title' => 'Notes on LLMs',
            'content' => '# Heading',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.show', ['path' => 'topics/llms']));

        $response->assertOk();
        $response->assertSee('Notes on LLMs');
    }

    public function test_show_forbids_access_to_private_note_owned_by_another_user(): void
    {
        $stranger = User::factory()->create();
        Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'private/secret',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.show', ['path' => 'private/secret']));

        $response->assertForbidden();
    }

    public function test_show_allows_access_to_shared_note(): void
    {
        $stranger = User::factory()->create();
        $note = Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'shared/topic',
            'title' => 'Shared topic',
            'visibility' => 'private',
        ]);

        Share::create([
            'note_id' => $note->id,
            'user_id' => $this->owner->id,
            'permission' => 'read',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.show', ['path' => 'shared/topic']));

        $response->assertOk();
        $response->assertSee('Shared topic');
    }

    public function test_show_falls_back_to_folder_browse_when_path_is_a_folder(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'projects/alpha/intro',
            'title' => 'Alpha intro',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.show', ['path' => 'projects/alpha']));

        $response->assertOk();
        $response->assertSee('Alpha intro');
    }

    public function test_show_with_journal_path_renders_calendar(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'journal/2026-05-16',
            'title' => 'May 16 entry',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.show', ['path' => 'journal']).'?year=2026&month=5');

        $response->assertOk();
        $response->assertSee('Journal');
        $response->assertSee('May 2026');
    }

    public function test_create_renders_form(): void
    {
        $response = $this->actingAs($this->owner)->get(route('commonplace.create'));

        $response->assertOk();
        $response->assertSee('New Note');
        $response->assertSee('name="_token"', false);
    }

    public function test_store_creates_note_and_redirects_to_show(): void
    {
        $response = $this->actingAs($this->owner)->post(route('commonplace.store'), [
            'path' => 'inbox/quick-thought',
            'content' => '# Quick thought',
            'tags' => 'idea, draft',
            'visibility' => 'private',
        ]);

        $response->assertRedirect(route('commonplace.show', ['path' => 'inbox/quick-thought']));

        $this->assertDatabaseHas('commonplace_notes', [
            'path' => 'inbox/quick-thought',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $note = Note::where('path', 'inbox/quick-thought')->firstOrFail();
        $this->assertSame(['idea', 'draft'], $note->tags->pluck('name')->all());
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->owner)->post(route('commonplace.store'), [
            'path' => '',
            'content' => '',
        ]);

        $response->assertSessionHasErrors(['path', 'content']);
    }

    public function test_store_parses_frontmatter_via_service(): void
    {
        $content = "---\ntitle: From Frontmatter\n---\n\nBody.";

        $this->actingAs($this->owner)->post(route('commonplace.store'), [
            'path' => 'topics/frontmattered',
            'content' => $content,
        ]);

        $note = Note::where('path', 'topics/frontmattered')->firstOrFail();
        $this->assertSame('From Frontmatter', $note->title);
    }

    public function test_edit_renders_form_for_owner(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/editable',
            'title' => 'Editable note',
            'content' => '# Original',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.edit', ['path' => 'topics/editable']));

        $response->assertOk();
        $response->assertSee('Edit Note');
        $response->assertSee('# Original');
    }

    public function test_edit_forbids_non_owner(): void
    {
        $stranger = User::factory()->create();
        Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'private/secret',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.edit', ['path' => 'private/secret']));

        $response->assertForbidden();
    }

    public function test_update_snapshots_version_and_persists_changes(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/version-me',
            'content' => '# First',
            'content_hash' => hash('sha256', '# First'),
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)->put(
            route('commonplace.update', ['path' => 'topics/version-me']),
            ['content' => '# Second']
        );

        $response->assertRedirect(route('commonplace.show', ['path' => 'topics/version-me']));

        $note = Note::where('path', 'topics/version-me')->firstOrFail();
        $this->assertSame('# Second', $note->content);

        $this->assertSame(1, NoteVersion::where('note_id', $note->id)->count());
    }

    public function test_update_renames_path_via_new_path(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'inbox/temp',
            'content' => '# Temp',
            'content_hash' => hash('sha256', '# Temp'),
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)->put(
            route('commonplace.update', ['path' => 'inbox/temp']),
            ['new_path' => 'topics/permanent']
        );

        $response->assertRedirect(route('commonplace.show', ['path' => 'topics/permanent']));
        $this->assertDatabaseHas('commonplace_notes', ['path' => 'topics/permanent']);
    }

    public function test_destroy_deletes_note_and_redirects_to_index(): void
    {
        $note = Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'inbox/toss',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->delete(route('commonplace.destroy', ['path' => 'inbox/toss']));

        $response->assertRedirect(route('commonplace.index'));
        $this->assertDatabaseMissing('commonplace_notes', ['id' => $note->id]);
    }

    public function test_destroy_forbids_non_owner(): void
    {
        $stranger = User::factory()->create();
        Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'inbox/strangers',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->delete(route('commonplace.destroy', ['path' => 'inbox/strangers']));

        $response->assertForbidden();
    }

    public function test_show_raw_returns_plain_markdown(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/raw',
            'title' => 'Raw note',
            'content' => '# Body content',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.showRaw', ['path' => 'topics/raw']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('# Raw note', $response->getContent());
        $this->assertStringContainsString('# Body content', $response->getContent());
    }

    public function test_download_raw_streams_markdown_attachment(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/downloadable',
            'title' => 'Downloadable',
            'content' => 'Body',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.downloadRaw', ['path' => 'topics/downloadable']));

        $response->assertOk();
        $this->assertStringContainsString('downloadable.md', (string) $response->headers->get('Content-Disposition'));
    }
}
