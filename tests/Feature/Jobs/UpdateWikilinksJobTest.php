<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Jobs\UpdateWikilinksJob;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class UpdateWikilinksJobTest extends TestCase
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

    public function test_rewrites_plain_aliased_and_basename_forms(): void
    {
        $this->commonplace->createNote(
            path: 'projects/alpha/roadmap',
            content: '# Roadmap',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $sourceContent = <<<'MD'
            # Index

            See [[projects/alpha/roadmap]] for the plan, especially
            [[projects/alpha/roadmap|the milestones]]. Some old notes
            still call it just [[roadmap]] — that should still rewrite.
            MD;

        $this->commonplace->createNote(
            path: 'index',
            content: $sourceContent,
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'projects/alpha/roadmap')->value('id');
        Note::where('id', $movedId)->update(['path' => 'projects/alpha/plan']);

        UpdateWikilinksJob::dispatchSync($movedId, 'projects/alpha/roadmap', 'projects/alpha/plan');

        $rewritten = Note::where('path', 'index')->value('content');

        $this->assertStringContainsString('[[projects/alpha/plan]]', $rewritten);
        $this->assertStringContainsString('[[projects/alpha/plan|the milestones]]', $rewritten);
        $this->assertStringContainsString('[[projects/alpha/plan]]', $rewritten); // basename form rewrote to full new path
        $this->assertStringNotContainsString('[[projects/alpha/roadmap]]', $rewritten);
        $this->assertStringNotContainsString('[[projects/alpha/roadmap|', $rewritten);
        $this->assertStringNotContainsString('[[roadmap]]', $rewritten);
    }

    public function test_handles_paths_with_regex_metacharacters(): void
    {
        $this->commonplace->createNote(
            path: 'references/c++/notes',
            content: '# C++',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->createNote(
            path: 'index',
            content: 'Look up [[references/c++/notes]] and [[references/c++/notes|the C++ deck]].',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'references/c++/notes')->value('id');
        Note::where('id', $movedId)->update(['path' => 'archive/c++/notes']);

        UpdateWikilinksJob::dispatchSync($movedId, 'references/c++/notes', 'archive/c++/notes');

        $rewritten = Note::where('path', 'index')->value('content');

        $this->assertStringContainsString('[[archive/c++/notes]]', $rewritten);
        $this->assertStringContainsString('[[archive/c++/notes|the C++ deck]]', $rewritten);
        $this->assertStringNotContainsString('[[references/c++/notes', $rewritten);
    }

    public function test_rewrites_multiple_wikilinks_on_one_line(): void
    {
        $this->commonplace->createNote(
            path: 'target',
            content: 'target body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->createNote(
            path: 'source',
            content: 'See [[target]] and [[target|TGT]] and [[target]] again.',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'target')->value('id');
        Note::where('id', $movedId)->update(['path' => 'moved']);

        UpdateWikilinksJob::dispatchSync($movedId, 'target', 'moved');

        $this->assertSame(
            'See [[moved]] and [[moved|TGT]] and [[moved]] again.',
            Note::where('path', 'source')->value('content'),
        );
    }

    public function test_rewrites_self_reference_when_moved_note_links_to_itself(): void
    {
        $this->commonplace->createNote(
            path: 'hub',
            content: 'I am [[hub]] (self-reference).',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'hub')->value('id');
        Note::where('id', $movedId)->update(['path' => 'central']);

        UpdateWikilinksJob::dispatchSync($movedId, 'hub', 'central');

        $this->assertSame(
            'I am [[central]] (self-reference).',
            Note::where('path', 'central')->value('content'),
        );
    }

    public function test_replaces_old_link_rows_with_new_target_path(): void
    {
        $this->commonplace->createNote(
            path: 'target',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->createNote(
            path: 'source',
            content: 'See [[target]].',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'target')->value('id');
        Note::where('id', $movedId)->update(['path' => 'moved']);

        UpdateWikilinksJob::dispatchSync($movedId, 'target', 'moved');

        $sourceId = (int) Note::where('path', 'source')->value('id');

        $this->assertDatabaseMissing('commonplace_links', [
            'source_note_id' => $sourceId,
            'target_path' => 'target',
        ]);
        $this->assertDatabaseHas('commonplace_links', [
            'source_note_id' => $sourceId,
            'target_path' => 'moved',
            'target_note_id' => $movedId,
        ]);
    }

    public function test_re_resolves_orphan_link_rows_pointing_at_old_path(): void
    {
        $this->commonplace->createNote(
            path: 'source',
            content: 'See [[future-target]] which does not exist yet.',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $sourceId = (int) Note::where('path', 'source')->value('id');

        $this->assertDatabaseHas('commonplace_links', [
            'source_note_id' => $sourceId,
            'target_path' => 'future-target',
            'target_note_id' => null,
        ]);

        // Create the target at the old path, then immediately move it.
        // The orphan row still has target_path = 'future-target'.
        $this->commonplace->createNote(
            path: 'future-target',
            content: 'created later',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'future-target')->value('id');
        Note::where('id', $movedId)->update(['path' => 'now-here']);

        UpdateWikilinksJob::dispatchSync($movedId, 'future-target', 'now-here');

        $this->assertDatabaseMissing('commonplace_links', [
            'source_note_id' => $sourceId,
            'target_path' => 'future-target',
            'target_note_id' => null,
        ]);
    }

    public function test_idempotent_when_run_twice(): void
    {
        $this->commonplace->createNote(
            path: 'target',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->createNote(
            path: 'source',
            content: 'See [[target]].',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'target')->value('id');
        Note::where('id', $movedId)->update(['path' => 'moved']);

        UpdateWikilinksJob::dispatchSync($movedId, 'target', 'moved');
        UpdateWikilinksJob::dispatchSync($movedId, 'target', 'moved');

        $this->assertSame(1, Link::where('source_note_id', (int) Note::where('path', 'source')->value('id'))->count());
    }

    public function test_handles_no_source_links_gracefully(): void
    {
        $this->commonplace->createNote(
            path: 'lonely',
            content: 'No links here.',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'lonely')->value('id');
        Note::where('id', $movedId)->update(['path' => 'still-lonely']);

        // No exception, no rows touched.
        UpdateWikilinksJob::dispatchSync($movedId, 'lonely', 'still-lonely');

        $this->assertSame(0, Link::count());
    }

    public function test_does_not_rewrite_wikilinks_inside_fenced_code_block(): void
    {
        $this->commonplace->createNote(
            path: 'projects/alpha/roadmap',
            content: '# Roadmap',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $source = <<<'MD'
            Outside [[projects/alpha/roadmap]] should rewrite.

            ```text
            Inside [[projects/alpha/roadmap]] should not.
            ```

            Trailing [[projects/alpha/roadmap]] should rewrite.
            MD;

        $this->commonplace->createNote(
            path: 'index',
            content: $source,
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'projects/alpha/roadmap')->value('id');
        Note::where('id', $movedId)->update(['path' => 'projects/alpha/plan']);

        UpdateWikilinksJob::dispatchSync($movedId, 'projects/alpha/roadmap', 'projects/alpha/plan');

        $rewritten = Note::where('path', 'index')->value('content');

        $this->assertStringContainsString('Outside [[projects/alpha/plan]] should rewrite.', $rewritten);
        $this->assertStringContainsString('Inside [[projects/alpha/roadmap]] should not.', $rewritten);
        $this->assertStringContainsString('Trailing [[projects/alpha/plan]] should rewrite.', $rewritten);
    }

    public function test_does_not_rewrite_wikilinks_inside_tilde_fenced_code_block(): void
    {
        $this->commonplace->createNote(
            path: 'projects/alpha/roadmap',
            content: '# Roadmap',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $source = <<<'MD'
            ~~~
            Inside tilde fence: [[projects/alpha/roadmap]]
            ~~~

            After: [[projects/alpha/roadmap]].
            MD;

        $this->commonplace->createNote(
            path: 'index',
            content: $source,
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'projects/alpha/roadmap')->value('id');
        Note::where('id', $movedId)->update(['path' => 'projects/alpha/plan']);

        UpdateWikilinksJob::dispatchSync($movedId, 'projects/alpha/roadmap', 'projects/alpha/plan');

        $rewritten = Note::where('path', 'index')->value('content');

        $this->assertStringContainsString('Inside tilde fence: [[projects/alpha/roadmap]]', $rewritten);
        $this->assertStringContainsString('After: [[projects/alpha/plan]].', $rewritten);
    }

    public function test_does_not_rewrite_wikilinks_inside_inline_code(): void
    {
        $this->commonplace->createNote(
            path: 'projects/alpha/roadmap',
            content: '# Roadmap',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $source = 'Use the form `[[projects/alpha/roadmap]]` to link to [[projects/alpha/roadmap]].';

        $this->commonplace->createNote(
            path: 'index',
            content: $source,
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'projects/alpha/roadmap')->value('id');
        Note::where('id', $movedId)->update(['path' => 'projects/alpha/plan']);

        UpdateWikilinksJob::dispatchSync($movedId, 'projects/alpha/roadmap', 'projects/alpha/plan');

        $rewritten = Note::where('path', 'index')->value('content');

        $this->assertSame(
            'Use the form `[[projects/alpha/roadmap]]` to link to [[projects/alpha/plan]].',
            $rewritten,
        );
    }

    /**
     * Anchor-suffixed wikilinks (`[[a/b#heading]]`) are a pre-existing
     * limitation in `WikilinkParser::resolveTarget` — they don't
     * resolve, so the link row's `target_note_id` is NULL and the
     * rewrite job's "links where `target_note_id = movedNoteId`" query
     * skips them. This pins the current behavior so a future change
     * doesn't silently start rewriting them. Issue #54 explicitly
     * marks this out of scope; a follow-up should land anchor support
     * and update this test.
     */
    public function test_anchor_suffixed_wikilinks_are_not_rewritten_documented_out_of_scope(): void
    {
        $this->commonplace->createNote(
            path: 'notes/target',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $original = 'See [[notes/target#section]] for that bit.';
        $this->commonplace->createNote(
            path: 'index',
            content: $original,
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $movedId = (int) Note::where('path', 'notes/target')->value('id');
        Note::where('id', $movedId)->update(['path' => 'notes/moved']);

        UpdateWikilinksJob::dispatchSync($movedId, 'notes/target', 'notes/moved');

        $this->assertSame(
            $original,
            Note::where('path', 'index')->value('content'),
            'Anchor-suffixed wikilinks are currently not rewritten — see WikilinkParser::resolveTarget limitation.',
        );
    }
}
