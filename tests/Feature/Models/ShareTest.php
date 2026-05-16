<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Models;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class ShareTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_it_can_be_created_with_factory(): void
    {
        $share = Share::factory()->create(['permission' => 'read']);

        $this->assertDatabaseHas('commonplace_shares', [
            'id' => $share->id,
            'permission' => 'read',
        ]);
    }

    public function test_updated_at_is_disabled(): void
    {
        $this->assertNull(Share::UPDATED_AT);
    }

    public function test_default_permission_is_read(): void
    {
        $note = Note::factory()->create();
        $user = User::factory()->create();

        $share = Share::create([
            'note_id' => $note->id,
            'user_id' => $user->id,
        ]);

        $this->assertSame('read', $share->fresh()->permission);
    }

    public function test_unique_constraint_on_note_user_pair(): void
    {
        $note = Note::factory()->create();
        $user = User::factory()->create();

        Share::create(['note_id' => $note->id, 'user_id' => $user->id, 'permission' => 'read']);

        $this->expectException(QueryException::class);

        Share::create(['note_id' => $note->id, 'user_id' => $user->id, 'permission' => 'write']);
    }

    public function test_note_relationship(): void
    {
        $note = Note::factory()->create();
        $share = Share::factory()->create(['note_id' => $note->id]);

        $this->assertTrue($share->note->is($note));
    }

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();
        $share = Share::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($share->user->is($user));
    }
}
