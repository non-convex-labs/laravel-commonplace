<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class HasCommonplaceNotesTraitTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_notes_relationship_returns_user_owned_notes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Note::factory()->count(3)->create(['user_id' => $user->id]);
        Note::factory()->create(['user_id' => $other->id]);

        $this->assertCount(3, $user->notes);
    }

    public function test_recent_notes_orders_by_updated_at_desc_and_limits(): void
    {
        $user = User::factory()->create();

        $older = Note::factory()->create(['user_id' => $user->id]);
        $older->updated_at = now()->subDays(2);
        $older->saveQuietly();

        $newer = Note::factory()->create(['user_id' => $user->id]);
        $newer->updated_at = now();
        $newer->saveQuietly();

        $recent = $user->recentNotes(1);

        $this->assertCount(1, $recent);
        $this->assertTrue($recent->first()->is($newer));
    }

    public function test_recent_notes_default_limit_is_ten(): void
    {
        $user = User::factory()->create();
        Note::factory()->count(12)->create(['user_id' => $user->id]);

        $this->assertCount(10, $user->recentNotes());
    }

    public function test_note_versions_returns_versions_authored_by_user(): void
    {
        $user = User::factory()->create();
        NoteVersion::factory()->count(2)->create(['changed_by' => $user->id]);
        NoteVersion::factory()->create(['changed_by' => null]);

        $this->assertCount(2, $user->noteVersions);
    }
}
