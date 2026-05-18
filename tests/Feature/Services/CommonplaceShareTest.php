<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class CommonplaceShareTest extends TestCase
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

    public function test_grant_share_creates_share_row_with_permission(): void
    {
        $recipient = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/grant',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $share = $this->commonplace->grantShare($note, $recipient, 'read', $this->owner);

        $this->assertSame($note->id, $share->note_id);
        $this->assertSame($recipient->id, $share->user_id);
        $this->assertSame('read', $share->permission);
        $this->assertDatabaseHas('commonplace_shares', [
            'note_id' => $note->id,
            'user_id' => $recipient->id,
            'permission' => 'read',
        ]);
    }

    public function test_grant_share_is_idempotent_and_updates_existing_permission(): void
    {
        $recipient = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/idempotent',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $this->commonplace->grantShare($note, $recipient, 'read', $this->owner);
        $second = $this->commonplace->grantShare($note, $recipient, 'write', $this->owner);

        $this->assertSame('write', $second->permission);
        $this->assertSame(
            1,
            Share::where('note_id', $note->id)->where('user_id', $recipient->id)->count(),
        );
    }

    public function test_grant_share_rejects_invalid_permission_value(): void
    {
        $recipient = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/invalid',
            'user_id' => $this->owner->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->commonplace->grantShare($note, $recipient, 'admin', $this->owner);
    }

    public function test_grant_share_rejects_non_owner_when_owner_passed(): void
    {
        $intruder = User::factory()->create();
        $recipient = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/protected',
            'user_id' => $this->owner->id,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->commonplace->grantShare($note, $recipient, 'read', $intruder);
    }

    public function test_revoke_share_removes_existing_share_row(): void
    {
        $recipient = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/revoke',
            'user_id' => $this->owner->id,
        ]);
        Share::create([
            'note_id' => $note->id,
            'user_id' => $recipient->id,
            'permission' => 'read',
        ]);

        $result = $this->commonplace->revokeShare($note, $recipient, $this->owner);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('commonplace_shares', [
            'note_id' => $note->id,
            'user_id' => $recipient->id,
        ]);
    }

    public function test_revoke_share_returns_false_when_no_share_exists(): void
    {
        $recipient = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/no-row',
            'user_id' => $this->owner->id,
        ]);

        $result = $this->commonplace->revokeShare($note, $recipient, $this->owner);

        $this->assertFalse($result);
    }

    public function test_list_shares_returns_shares_for_note(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $note = Note::factory()->create([
            'path' => 'shared/list',
            'user_id' => $this->owner->id,
        ]);
        Share::create(['note_id' => $note->id, 'user_id' => $alice->id, 'permission' => 'read']);
        Share::create(['note_id' => $note->id, 'user_id' => $bob->id, 'permission' => 'write']);

        $shares = $this->commonplace->listShares($note, $this->owner);

        $this->assertCount(2, $shares);
        $this->assertEqualsCanonicalizing(
            [$alice->id, $bob->id],
            $shares->pluck('user_id')->all(),
        );
        $this->assertTrue($shares->first()->relationLoaded('user'));
    }
}
