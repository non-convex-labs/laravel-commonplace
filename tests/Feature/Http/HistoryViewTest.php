<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class HistoryViewTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private User $owner;

    private Commonplace $commonplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->commonplace = $this->app->make(Commonplace::class);
    }

    public function test_history_index_renders_versions_for_an_existing_note(): void
    {
        $this->commonplace->createNote(
            path: 'projects/launch',
            content: '# v1',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote('projects/launch', ['content' => '# v2'], $this->owner);
        $this->commonplace->updateNote('projects/launch', ['content' => '# v3'], $this->owner);

        $versions = NoteVersion::where('note_path', 'projects/launch')
            ->orderByDesc('id')
            ->get();

        $this->assertCount(2, $versions);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.history', ['path' => 'projects/launch']));

        $response->assertOk();
        $response->assertSee('Version history');
        $response->assertSee('projects/launch');

        foreach ($versions as $version) {
            $response->assertSee(substr($version->content_hash, 0, 8));
        }

        $content = (string) $response->getContent();
        $positions = $versions->map(fn (NoteVersion $v) => strpos($content, substr($v->content_hash, 0, 8)))->all();
        $this->assertSame($positions, collect($positions)->sort()->values()->all(), 'Versions should render newest-first.');
    }

    public function test_history_index_works_for_deleted_notes(): void
    {
        $this->commonplace->createNote(
            path: 'projects/gone',
            content: '# original',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote('projects/gone', ['content' => '# v2'], $this->owner);
        $this->commonplace->deleteNote('projects/gone', $this->owner);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.history', ['path' => 'projects/gone']));

        $response->assertOk();
        $response->assertSee('(note deleted)');
        $response->assertSee('Version history');
    }

    public function test_history_index_403s_for_deleted_notes_authored_by_someone_else(): void
    {
        $intruder = User::factory()->create();

        $this->commonplace->createNote(
            path: 'projects/private-tombstone',
            content: '# original',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->updateNote('projects/private-tombstone', ['content' => '# v2'], $this->owner);
        $this->commonplace->deleteNote('projects/private-tombstone', $this->owner);

        $this->actingAs($intruder)
            ->get(route('commonplace.history', ['path' => 'projects/private-tombstone']))
            ->assertForbidden();

        // The version-detail route inherits the same gate.
        $version = NoteVersion::where('note_path', 'projects/private-tombstone')
            ->orderByDesc('id')
            ->first();

        $this->actingAs($intruder)
            ->get(route('commonplace.historyVersion', ['path' => 'projects/private-tombstone', 'version' => $version->id]))
            ->assertForbidden();
    }

    public function test_history_link_appears_on_note_show_page(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'projects/launch',
            'title' => 'Launch',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.show', ['path' => 'projects/launch']));

        $response->assertOk();
        $response->assertSee(route('commonplace.history', ['path' => 'projects/launch']), false);
        $response->assertSee('>History<', false);
    }

    public function test_history_version_renders_revision_content(): void
    {
        $this->commonplace->createNote(
            path: 'projects/launch',
            content: '# v1 unique-revision-marker',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote('projects/launch', ['content' => '# v2'], $this->owner);

        $version = NoteVersion::where('note_path', 'projects/launch')->firstOrFail();

        $response = $this->actingAs($this->owner)->get(route('commonplace.historyVersion', [
            'path' => 'projects/launch',
            'version' => $version->id,
        ]));

        $response->assertOk();
        $response->assertSee('unique-revision-marker');
        $response->assertSee(substr($version->content_hash, 0, 8));
    }

    public function test_history_version_404s_when_id_does_not_belong_to_path(): void
    {
        $this->commonplace->createNote(
            path: 'projects/launch',
            content: '# v1',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->updateNote('projects/launch', ['content' => '# v2'], $this->owner);

        $this->commonplace->createNote(
            path: 'projects/other',
            content: '# o1',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->updateNote('projects/other', ['content' => '# o2'], $this->owner);

        $otherVersion = NoteVersion::where('note_path', 'projects/other')->firstOrFail();

        $response = $this->actingAs($this->owner)->get(route('commonplace.historyVersion', [
            'path' => 'projects/launch',
            'version' => $otherVersion->id,
        ]));

        $response->assertNotFound();
    }

    public function test_history_index_requires_auth(): void
    {
        $response = $this->get(route('commonplace.history', ['path' => 'projects/launch']));

        $response->assertRedirect();
    }

    public function test_history_index_404s_for_a_path_with_no_versions_and_no_live_note(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.history', ['path' => 'never/existed']));

        $response->assertNotFound();
    }
}
