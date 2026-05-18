<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Models\Tag;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\RecordingEmbeddingProvider;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class CommonplaceTest extends TestCase
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

    public function test_create_note_persists_record_with_hashed_content(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/alpha',
            content: '# Alpha',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertDatabaseHas('commonplace_notes', [
            'path' => 'projects/alpha',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
            'content_hash' => hash('sha256', '# Alpha'),
        ]);
        $this->assertSame('Alpha', $note->title);
    }

    public function test_create_note_uses_frontmatter_title_when_present(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/raw-slug',
            content: "---\ntitle: From Frontmatter\n---\n\nBody.",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertSame('From Frontmatter', $note->title);
    }

    public function test_create_note_falls_back_to_humanised_basename_for_title(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/some-deep-note',
            content: 'Body without frontmatter.',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertSame('Some Deep Note', $note->title);
    }

    public function test_create_note_attaches_tags_from_frontmatter(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/tagged',
            content: "---\ntags: [ai, tooling]\n---\n\nBody.",
            tags: ['ignored'],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertEqualsCanonicalizing(
            ['ai', 'tooling'],
            $note->tags->pluck('name')->all(),
        );
    }

    public function test_create_note_records_wikilinks(): void
    {
        Note::factory()->create([
            'path' => 'reference/target',
            'title' => 'Target',
            'user_id' => $this->owner->id,
        ]);

        $note = $this->commonplace->createNote(
            path: 'projects/source',
            content: 'See [[reference/target]] and [[Totally Unrelated Phrase]].',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $links = Link::where('source_note_id', $note->id)->get();

        $this->assertCount(2, $links);
        $this->assertNotNull($links->firstWhere('target_path', 'reference/target')->target_note_id);
        $this->assertNull($links->firstWhere('target_path', 'Totally Unrelated Phrase')->target_note_id);
    }

    public function test_create_note_does_not_write_a_note_version(): void
    {
        // NoteVersion captures *displaced* content (overwritten by update,
        // removed by delete), not a snapshot of every state. The live Note
        // row is the original content. See docs/model-relationships.md#noteversion.
        $note = $this->commonplace->createNote(
            path: 'projects/just-created',
            content: 'original body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->assertSame(0, NoteVersion::where('note_id', $note->id)->count());
        $this->assertSame(0, NoteVersion::where('note_path', 'projects/just-created')->count());
    }

    public function test_update_note_snapshots_previous_version_on_content_change(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/draft',
            content: 'v1 body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/draft',
            data: ['content' => 'v2 body'],
            user: $this->owner,
        );

        $this->assertSame(1, NoteVersion::where('note_id', $note->id)->count());
        $this->assertSame('v1 body', NoteVersion::where('note_id', $note->id)->first()->content);
        $this->assertSame('v2 body', $note->fresh()->content);
    }

    public function test_update_note_skips_version_snapshot_when_content_unchanged(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/static',
            content: 'same body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/static',
            data: ['content' => 'same body'],
            user: $this->owner,
        );

        $this->assertSame(0, NoteVersion::where('note_id', $note->id)->count());
    }

    public function test_update_note_regenerates_title_from_basename_when_frontmatter_title_removed(): void
    {
        $this->commonplace->createNote(
            path: 'projects/launch-plan',
            content: "---\ntitle: Custom Plan\n---\n\nbody",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/launch-plan',
            data: ['content' => "no frontmatter, just body\n"],
            user: $this->owner,
        );

        $this->assertSame(
            'Launch Plan',
            Note::where('path', 'projects/launch-plan')->first()->title,
        );
    }

    public function test_update_note_preserves_frontmatter_title_when_present(): void
    {
        $this->commonplace->createNote(
            path: 'projects/v1',
            content: "---\ntitle: First\n---\n\nbody",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/v1',
            data: ['content' => "---\ntitle: Second\n---\n\nbody"],
            user: $this->owner,
        );

        $this->assertSame(
            'Second',
            Note::where('path', 'projects/v1')->first()->title,
        );
    }

    public function test_update_note_regenerates_title_from_new_basename_on_rename_with_stripped_frontmatter(): void
    {
        $this->commonplace->createNote(
            path: 'projects/launch-plan',
            content: "---\ntitle: Custom Plan\n---\n\nbody",
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/launch-plan',
            data: [
                'content' => "no frontmatter, just body\n",
                'new_path' => 'projects/q3-roadmap',
            ],
            user: $this->owner,
        );

        $this->assertSame(
            'Q3 Roadmap',
            Note::where('path', 'projects/q3-roadmap')->first()->title,
        );
    }

    public function test_update_note_renames_path_via_new_path(): void
    {
        $this->commonplace->createNote(
            path: 'projects/old',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/old',
            data: ['new_path' => 'projects/renamed'],
            user: $this->owner,
        );

        $this->assertDatabaseHas('commonplace_notes', ['path' => 'projects/renamed']);
        $this->assertDatabaseMissing('commonplace_notes', ['path' => 'projects/old']);
    }

    public function test_edit_note_replaces_unique_substring(): void
    {
        $this->commonplace->createNote(
            path: 'projects/edit',
            content: 'alpha bravo charlie',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $note = $this->commonplace->editNote(
            path: 'projects/edit',
            oldString: 'bravo',
            newString: 'delta',
            replaceAll: false,
            user: $this->owner,
        );

        $this->assertSame('alpha delta charlie', $note->content);
    }

    public function test_edit_note_requires_replace_all_for_ambiguous_substring(): void
    {
        $this->commonplace->createNote(
            path: 'projects/dup',
            content: 'foo foo foo',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->expectException(InvalidArgumentException::class);

        $this->commonplace->editNote(
            path: 'projects/dup',
            oldString: 'foo',
            newString: 'bar',
            replaceAll: false,
            user: $this->owner,
        );
    }

    public function test_edit_note_replace_all_replaces_every_occurrence(): void
    {
        $this->commonplace->createNote(
            path: 'projects/dup-all',
            content: 'foo foo foo',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $note = $this->commonplace->editNote(
            path: 'projects/dup-all',
            oldString: 'foo',
            newString: 'bar',
            replaceAll: true,
            user: $this->owner,
        );

        $this->assertSame('bar bar bar', $note->content);
    }

    public function test_edit_note_rejects_missing_substring(): void
    {
        $this->commonplace->createNote(
            path: 'projects/none',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->expectException(InvalidArgumentException::class);

        $this->commonplace->editNote(
            path: 'projects/none',
            oldString: 'missing',
            newString: 'whatever',
            replaceAll: false,
            user: $this->owner,
        );
    }

    public function test_delete_note_records_a_final_version_and_removes_note(): void
    {
        $note = $this->commonplace->createNote(
            path: 'projects/doomed',
            content: 'last body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->deleteNote('projects/doomed', $this->owner);

        $this->assertDatabaseMissing('commonplace_notes', ['id' => $note->id]);
        $this->assertSame(1, NoteVersion::where('note_path', 'projects/doomed')->count());
    }

    public function test_delete_note_prunes_tag_rows_that_become_orphaned(): void
    {
        $this->commonplace->createNote(
            path: 'projects/first',
            content: 'body',
            tags: ['a', 'b'],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->createNote(
            path: 'projects/second',
            content: 'body',
            tags: ['a'],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->deleteNote('projects/first', $this->owner);

        $this->assertDatabaseHas('commonplace_tags', ['name' => 'a']);
        $this->assertDatabaseMissing('commonplace_tags', ['name' => 'b']);
    }

    public function test_update_note_prunes_tag_rows_that_become_orphaned(): void
    {
        $this->commonplace->createNote(
            path: 'projects/swap',
            content: 'body',
            tags: ['a', 'b'],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            path: 'projects/swap',
            data: ['tags' => ['a', 'c']],
            user: $this->owner,
        );

        $this->assertDatabaseHas('commonplace_tags', ['name' => 'a']);
        $this->assertDatabaseHas('commonplace_tags', ['name' => 'c']);
        $this->assertDatabaseMissing('commonplace_tags', ['name' => 'b']);
    }

    public function test_delete_note_keeps_tag_rows_still_used_by_other_notes(): void
    {
        $this->commonplace->createNote(
            path: 'projects/one',
            content: 'body',
            tags: ['a'],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->createNote(
            path: 'projects/two',
            content: 'body',
            tags: ['a'],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->deleteNote('projects/one', $this->owner);

        $this->assertDatabaseHas('commonplace_tags', ['name' => 'a']);
    }

    public function test_list_notes_returns_only_accessible_notes(): void
    {
        $stranger = User::factory()->create();

        Note::factory()->create(['path' => 'mine/a', 'user_id' => $this->owner->id, 'visibility' => 'private']);
        Note::factory()->create(['path' => 'mine/b', 'user_id' => $this->owner->id, 'visibility' => 'public']);
        Note::factory()->create(['path' => 'theirs/private', 'user_id' => $stranger->id, 'visibility' => 'private']);
        Note::factory()->create(['path' => 'theirs/public', 'user_id' => $stranger->id, 'visibility' => 'public']);

        $paths = $this->commonplace
            ->listNotes(null, null, null, $this->owner)
            ->pluck('path')
            ->all();

        $this->assertEqualsCanonicalizing(['mine/a', 'mine/b', 'theirs/public'], $paths);
    }

    public function test_list_notes_filters_by_folder(): void
    {
        Note::factory()->create(['path' => 'work/a', 'user_id' => $this->owner->id]);
        Note::factory()->create(['path' => 'personal/b', 'user_id' => $this->owner->id]);

        $paths = $this->commonplace
            ->listNotes('work', null, null, $this->owner)
            ->pluck('path')
            ->all();

        $this->assertSame(['work/a'], $paths);
    }

    public function test_list_notes_filters_by_tag(): void
    {
        $tagged = Note::factory()->create(['path' => 'projects/tagged', 'user_id' => $this->owner->id]);
        $untagged = Note::factory()->create(['path' => 'projects/untagged', 'user_id' => $this->owner->id]);

        $tag = Tag::create(['name' => 'ai']);
        $tagged->tags()->attach($tag);

        $paths = $this->commonplace
            ->listNotes(null, 'ai', null, $this->owner)
            ->pluck('path')
            ->all();

        $this->assertSame(['projects/tagged'], $paths);
        $this->assertNotContains($untagged->path, $paths);
    }

    public function test_list_notes_filters_by_visibility(): void
    {
        Note::factory()->create(['path' => 'a', 'user_id' => $this->owner->id, 'visibility' => 'public']);
        Note::factory()->create(['path' => 'b', 'user_id' => $this->owner->id, 'visibility' => 'private']);

        $paths = $this->commonplace
            ->listNotes(null, null, 'public', $this->owner)
            ->pluck('path')
            ->all();

        $this->assertSame(['a'], $paths);
    }

    public function test_read_note_throws_when_user_lacks_access(): void
    {
        $stranger = User::factory()->create();

        Note::factory()->create([
            'path' => 'secret/note',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $this->expectException(AuthorizationException::class);

        $this->commonplace->readNote('secret/note', $stranger);
    }

    public function test_read_note_allows_explicit_share(): void
    {
        $reader = User::factory()->create();

        $note = Note::factory()->create([
            'path' => 'shared/note',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        Share::create([
            'note_id' => $note->id,
            'user_id' => $reader->id,
            'permission' => 'read',
        ]);

        $result = $this->commonplace->readNote('shared/note', $reader);

        $this->assertTrue($result->is($note));
    }

    public function test_update_note_rejects_user_without_write_share(): void
    {
        $reader = User::factory()->create();

        $note = Note::factory()->create([
            'path' => 'shared/readonly',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        Share::create([
            'note_id' => $note->id,
            'user_id' => $reader->id,
            'permission' => 'read',
        ]);

        $this->expectException(AuthorizationException::class);

        $this->commonplace->updateNote(
            path: 'shared/readonly',
            data: ['content' => 'tampering'],
            user: $reader,
        );
    }

    public function test_delete_note_rejects_non_owner_even_with_write_share(): void
    {
        $collaborator = User::factory()->create();

        $note = Note::factory()->create([
            'path' => 'shared/writable',
            'user_id' => $this->owner->id,
        ]);

        Share::create([
            'note_id' => $note->id,
            'user_id' => $collaborator->id,
            'permission' => 'write',
        ]);

        $this->expectException(AuthorizationException::class);

        $this->commonplace->deleteNote('shared/writable', $collaborator);
    }

    public function test_search_notes_returns_empty_for_short_queries(): void
    {
        Note::factory()->create(['path' => 'a', 'user_id' => $this->owner->id, 'title' => 'Apple']);

        $this->assertCount(0, $this->commonplace->searchNotes('a', $this->owner));
    }

    public function test_search_notes_matches_title_or_content(): void
    {
        Note::factory()->create([
            'path' => 'fruit/apple',
            'user_id' => $this->owner->id,
            'title' => 'Apple',
            'content' => 'A red fruit.',
        ]);
        Note::factory()->create([
            'path' => 'fruit/banana',
            'user_id' => $this->owner->id,
            'title' => 'Banana',
            'content' => 'Yellow and sometimes apple-shaped.',
        ]);
        Note::factory()->create([
            'path' => 'misc/other',
            'user_id' => $this->owner->id,
            'title' => 'Carrot',
            'content' => 'Orange root vegetable.',
        ]);

        $paths = $this->commonplace
            ->searchNotes('apple', $this->owner)
            ->pluck('path')
            ->all();

        $this->assertEqualsCanonicalizing(['fruit/apple', 'fruit/banana'], $paths);
    }

    public function test_get_backlinks_returns_notes_that_link_to_the_target(): void
    {
        $target = Note::factory()->create(['path' => 'hub', 'user_id' => $this->owner->id]);
        $source = Note::factory()->create(['path' => 'spoke', 'user_id' => $this->owner->id]);

        Link::create([
            'source_note_id' => $source->id,
            'target_note_id' => $target->id,
            'target_path' => 'hub',
        ]);

        $backlinks = $this->commonplace->getBacklinks('hub', $this->owner);

        $this->assertCount(1, $backlinks);
        $this->assertSame('spoke', $backlinks->first()->path);
    }

    public function test_get_backlinks_throws_authorization_exception_when_target_is_inaccessible(): void
    {
        Note::factory()->create([
            'path' => 'private/bobs-note',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $other = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        // Tool layer collapses this to "Note not found." (matching the
        // ModelNotFoundException case) so the response is indistinguishable
        // from a missing path. The service-level throw is what makes that
        // collapse possible; without it `getBacklinks` would silently
        // return an empty collection and leak the path's existence.
        $this->commonplace->getBacklinks('private/bobs-note', $other);
    }

    public function test_move_note_updates_path_and_rejects_collision(): void
    {
        $this->commonplace->createNote(
            path: 'from/here',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->createNote(
            path: 'occupied',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $moved = $this->commonplace->moveNote('from/here', 'to/there', $this->owner);

        $this->assertSame('to/there', $moved->path);

        $this->expectException(InvalidArgumentException::class);

        $this->commonplace->moveNote('to/there', 'occupied', $this->owner);
    }

    public function test_get_history_returns_versions_for_existing_note(): void
    {
        $this->commonplace->createNote(
            path: 'projects/history',
            content: 'v1',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->updateNote('projects/history', ['content' => 'v2'], $this->owner);
        $this->commonplace->updateNote('projects/history', ['content' => 'v3'], $this->owner);

        $history = $this->commonplace->getHistory('projects/history', $this->owner);

        $this->assertCount(2, $history);
    }

    public function test_get_history_returns_orphaned_versions_after_delete(): void
    {
        $this->commonplace->createNote(
            path: 'projects/will-be-deleted',
            content: 'last body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->deleteNote('projects/will-be-deleted', $this->owner);

        $history = $this->commonplace->getHistory('projects/will-be-deleted', $this->owner);

        $this->assertGreaterThanOrEqual(1, $history->count());
    }

    public function test_get_orphan_notes_returns_unlinked_notes(): void
    {
        $linked = Note::factory()->create(['path' => 'a', 'user_id' => $this->owner->id]);
        $other = Note::factory()->create(['path' => 'b', 'user_id' => $this->owner->id]);
        Note::factory()->create(['path' => 'c-orphan', 'user_id' => $this->owner->id]);

        Link::create([
            'source_note_id' => $linked->id,
            'target_note_id' => $other->id,
            'target_path' => 'b',
        ]);

        $paths = $this->commonplace->getOrphanNotes($this->owner)->pluck('path')->all();

        $this->assertSame(['c-orphan'], $paths);
    }

    public function test_read_note_throws_for_missing_path(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->commonplace->readNote('does/not/exist', $this->owner);
    }

    public function test_semantic_search_returns_empty_when_driver_disabled(): void
    {
        config(['commonplace.vector.driver' => 'null']);

        $results = $this->commonplace->semanticSearch('anything', $this->owner);

        $this->assertCount(0, $results);
    }

    public function test_semantic_search_ranks_by_cosine_distance(): void
    {
        $near = Note::factory()->withEmbedding([1.0, 0.0, 0.0])->create(['user_id' => $this->owner->id]);
        $mid = Note::factory()->withEmbedding([0.7, 0.7, 0.0])->create(['user_id' => $this->owner->id]);
        $far = Note::factory()->withEmbedding([0.0, 0.0, 1.0])->create(['user_id' => $this->owner->id]);

        $this->app->instance(
            EmbeddingProvider::class,
            new class implements EmbeddingProvider
            {
                public function embed(string $text): array
                {
                    return [1.0, 0.0, 0.0];
                }

                public function embedQuery(string $text): array
                {
                    return $this->embed($text);
                }

                public function embedBatch(array $texts): array
                {
                    return array_map(fn () => $this->embed(''), $texts);
                }

                public function dimensions(): int
                {
                    return 3;
                }
            }
        );
        $this->app->forgetInstance(Commonplace::class);

        $commonplace = $this->app->make(Commonplace::class);
        $ordered = $commonplace->semanticSearch('query', $this->owner)->pluck('id')->all();

        $this->assertSame([$near->id, $mid->id, $far->id], $ordered);
    }

    public function test_semantic_search_routes_query_through_embed_query(): void
    {
        $recorder = new RecordingEmbeddingProvider;
        $this->app->instance(EmbeddingProvider::class, $recorder);
        $this->app->forgetInstance(Commonplace::class);
        $commonplace = $this->app->make(Commonplace::class);

        Note::factory()->withEmbedding([0.1, 0.2, 0.3])->create(['user_id' => $this->owner->id]);

        $commonplace->semanticSearch('how do indexes work', $this->owner);

        $this->assertSame(['how do indexes work'], $recorder->queryEmbeds);
    }

    public function test_get_suggested_links_returns_empty_when_driver_disabled(): void
    {
        config(['commonplace.vector.driver' => 'null']);

        $note = Note::factory()->create(['path' => 'src', 'user_id' => $this->owner->id]);

        $this->assertSame([], $this->commonplace->getSuggestedLinks('src', $this->owner));

        // Suppress unused-variable warning; the factory is the side effect we care about.
        $this->assertSame($note->id, Note::where('path', 'src')->value('id'));
    }

    public function test_get_suggested_links_excludes_self_and_existing_links(): void
    {
        $source = Note::factory()->withEmbedding([1.0, 0.0, 0.0])->create([
            'path' => 'src',
            'user_id' => $this->owner->id,
        ]);
        $linked = Note::factory()->withEmbedding([1.0, 0.0, 0.0])->create([
            'path' => 'linked',
            'user_id' => $this->owner->id,
        ]);
        $candidate = Note::factory()->withEmbedding([0.9, 0.1, 0.0])->create([
            'path' => 'candidate',
            'user_id' => $this->owner->id,
        ]);

        Link::create([
            'source_note_id' => $source->id,
            'target_note_id' => $linked->id,
            'target_path' => 'linked',
        ]);

        $results = $this->commonplace->getSuggestedLinks('src', $this->owner);

        $this->assertCount(1, $results);
        $this->assertSame('candidate', $results[0]['path']);
        $this->assertSame($candidate->id, Note::where('path', 'candidate')->value('id'));
    }

    public function test_get_neighborhood_requires_pgvector_recursive_cte(): void
    {
        $this->markTestSkipped(
            'getNeighborhood() uses PostgreSQL ARRAY semantics in a recursive CTE (issue #1). '
            .'sqlite does not support ARRAY[]; covered separately in the host app integration tests.'
        );
    }

    public function test_get_shortest_path_requires_pgvector_recursive_cte(): void
    {
        $this->markTestSkipped(
            'getShortestPath() uses PostgreSQL ARRAY semantics in a recursive CTE (issue #1). '
            .'Same constraint as getNeighborhood().'
        );
    }

    public function test_get_hub_notes_requires_postgres_aggregation(): void
    {
        $this->markTestSkipped(
            'getHubNotes() uses raw SQL that depends on PostgreSQL aggregation semantics (issue #1). '
            .'Covered in the host app integration tests.'
        );
    }

    public function test_get_neighborhood_casts_seed_note_id_to_bigint(): void
    {
        // Regression guard for issue #109. Without the `?::bigint` cast on the
        // seed, PDO sends `note_id` as text and Postgres rejects the recursive
        // join with `operator does not exist: bigint = text`. We can't execute
        // the recursive CTE on the SQLite test connection, so we assert the
        // shape of the SQL literal in the source instead.
        $this->assertSeedNoteIdCastInMethod('getNeighborhood');
    }

    public function test_get_shortest_path_casts_seed_note_id_to_bigint(): void
    {
        // Regression guard for issue #109 — same failure mode as
        // getNeighborhood(). See that test for the rationale.
        $this->assertSeedNoteIdCastInMethod('getShortestPath');
    }

    /**
     * Assert that the recursive-CTE seed inside the given Commonplace method
     * casts its first bound parameter to bigint, e.g.
     * `SELECT ?::bigint AS note_id`. Reads the SQL literal out of the method
     * source via reflection so the test does not need a live Postgres.
     */
    private function assertSeedNoteIdCastInMethod(string $method): void
    {
        $reflection = new \ReflectionMethod(Commonplace::class, $method);
        $file = $reflection->getFileName();
        $this->assertNotFalse($file, "Could not locate source file for Commonplace::{$method}().");

        $source = file($file);
        $this->assertNotFalse($source, "Could not read source file for Commonplace::{$method}().");

        $body = implode('', array_slice(
            $source,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $this->assertStringContainsString(
            'WITH RECURSIVE graph AS',
            $body,
            "Commonplace::{$method}() no longer contains a recursive CTE — update this regression test.",
        );
        $this->assertMatchesRegularExpression(
            '/SELECT\s+\?::bigint\s+AS\s+note_id/i',
            $body,
            "Commonplace::{$method}() recursive CTE seed must cast `?` to bigint (Postgres rejects `bigint = text`).",
        );
    }
}
