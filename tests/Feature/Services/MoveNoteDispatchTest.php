<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use NonConvexLabs\Commonplace\Jobs\UpdateWikilinksJob;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

/**
 * Verifies that both path-change entry points (`moveNote` and
 * `updateNote(new_path: ...)`) dispatch `UpdateWikilinksJob` *after*
 * the path mutation commits. `Bus::fake` proves the job is queued
 * (not inline-run via `sync`); the transaction wrap in `moveNote` is
 * load-bearing because `DB::afterCommit` outside a transaction fires
 * the callback immediately.
 */
class MoveNoteDispatchTest extends TestCase
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

    public function test_move_note_dispatches_update_wikilinks_job(): void
    {
        Bus::fake();

        $this->commonplace->createNote(
            path: 'from/here',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $note = $this->commonplace->moveNote('from/here', 'to/there', $this->owner);

        Bus::assertDispatched(
            UpdateWikilinksJob::class,
            function (UpdateWikilinksJob $job) use ($note): bool {
                return $job->movedNoteId === (int) $note->getKey()
                    && $job->fromPath === 'from/here'
                    && $job->toPath === 'to/there';
            },
        );
    }

    public function test_update_note_with_new_path_dispatches_the_same_job(): void
    {
        Bus::fake();

        $this->commonplace->createNote(
            path: 'old/path',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            'old/path',
            ['new_path' => 'new/path'],
            $this->owner,
        );

        Bus::assertDispatched(
            UpdateWikilinksJob::class,
            fn (UpdateWikilinksJob $job): bool => $job->fromPath === 'old/path'
                && $job->toPath === 'new/path',
        );
    }

    public function test_no_op_move_does_not_dispatch_job(): void
    {
        Bus::fake();

        $this->commonplace->createNote(
            path: 'same',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->moveNote('same', 'same', $this->owner);

        Bus::assertNotDispatched(UpdateWikilinksJob::class);
    }

    public function test_update_note_without_new_path_does_not_dispatch_job(): void
    {
        Bus::fake();

        $this->commonplace->createNote(
            path: 'topic',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->updateNote(
            'topic',
            ['content' => 'updated body'],
            $this->owner,
        );

        Bus::assertNotDispatched(UpdateWikilinksJob::class);
    }

    public function test_collision_in_move_prevents_dispatch(): void
    {
        Bus::fake();

        $this->commonplace->createNote(
            path: 'from',
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

        try {
            $this->commonplace->moveNote('from', 'occupied', $this->owner);
            $this->fail('Expected an InvalidArgumentException for path collision.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        Bus::assertNotDispatched(UpdateWikilinksJob::class);
    }

    public function test_end_to_end_move_rewrites_links_when_run_sync(): void
    {
        // `rewrite_sync` runs the job inline via dispatchSync so the
        // rewrite lands before the next assertion. Without it, the
        // default `sync` queue driver in tests would still run the job
        // inline — but only after the transaction commits, and `Bus`
        // is not faked here so we exercise the real handler.
        config()->set('commonplace.wikilinks.rewrite_sync', true);

        $this->commonplace->createNote(
            path: 'old',
            content: 'body',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $this->commonplace->createNote(
            path: 'source',
            content: 'See [[old]].',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );

        $this->commonplace->moveNote('old', 'new', $this->owner);

        $this->assertSame(
            'See [[new]].',
            Note::where('path', 'source')->value('content'),
        );
    }
}
