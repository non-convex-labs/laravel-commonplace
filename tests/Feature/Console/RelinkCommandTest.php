<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class RelinkCommandTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_relinks_orphan_rows_that_now_resolve(): void
    {
        $owner = User::factory()->create();

        $source = Note::factory()->create([
            'path' => 'source',
            'user_id' => $owner->id,
            'content' => 'See [[target]]',
        ]);
        $target = Note::factory()->create([
            'path' => 'target',
            'user_id' => $owner->id,
            'content' => 'body',
        ]);

        Link::create([
            'source_note_id' => $source->id,
            'target_path' => 'target',
            'target_note_id' => null, // orphan
        ]);

        $this->artisan('commonplace:relink')
            ->expectsOutputToContain('Resolved 1 orphan(s)')
            ->assertSuccessful();

        $this->assertDatabaseHas('commonplace_links', [
            'source_note_id' => $source->id,
            'target_path' => 'target',
            'target_note_id' => $target->id,
        ]);
    }

    public function test_leaves_unresolvable_orphans_alone(): void
    {
        $owner = User::factory()->create();
        $source = Note::factory()->create(['path' => 'source', 'user_id' => $owner->id]);

        Link::create([
            'source_note_id' => $source->id,
            'target_path' => 'still-missing',
            'target_note_id' => null,
        ]);

        $this->artisan('commonplace:relink')
            ->expectsOutputToContain('1 remain')
            ->assertSuccessful();

        $this->assertDatabaseHas('commonplace_links', [
            'source_note_id' => $source->id,
            'target_path' => 'still-missing',
            'target_note_id' => null,
        ]);
    }

    public function test_idempotent_when_no_orphans_exist(): void
    {
        $this->artisan('commonplace:relink')
            ->expectsOutputToContain('No orphaned link rows')
            ->assertSuccessful();
    }

    public function test_exit_code_flag_fails_when_orphans_remain(): void
    {
        $owner = User::factory()->create();
        $source = Note::factory()->create(['path' => 'source', 'user_id' => $owner->id]);

        Link::create([
            'source_note_id' => $source->id,
            'target_path' => 'missing',
            'target_note_id' => null,
        ]);

        $this->artisan('commonplace:relink --exit-code')->assertFailed();
    }
}
