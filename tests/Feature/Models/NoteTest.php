<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Models\Tag;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

// User fixture chosen over Testbench default: orchestra/testbench has no first-party User
// model, so a minimal fixture + migration keeps the user-FK contract explicit.
class NoteTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_it_can_be_created_with_factory(): void
    {
        $note = Note::factory()->create([
            'title' => 'Hello World',
            'path' => 'notes/hello.md',
        ]);

        $this->assertDatabaseHas('commonplace_notes', [
            'id' => $note->id,
            'title' => 'Hello World',
            'path' => 'notes/hello.md',
        ]);
    }

    public function test_route_key_name_is_path(): void
    {
        $note = Note::factory()->make();

        $this->assertSame('path', $note->getRouteKeyName());
    }

    public function test_indexed_at_is_cast_to_datetime(): void
    {
        $note = Note::factory()->indexed()->create();

        $this->assertInstanceOf(Carbon::class, $note->fresh()->indexed_at);
    }

    public function test_embedding_accessor_delegates_to_driver(): void
    {
        $vector = [0.1, 0.2, 0.3, 0.4];

        $note = Note::factory()->create();
        app(VectorSearchDriver::class)->store($note->id, $vector);

        $this->assertSame($vector, $note->fresh()->embedding);
    }

    public function test_embedding_accessor_returns_null_and_logs_when_driver_resolution_throws(): void
    {
        $note = Note::factory()->create();

        // Rebind the driver to a closure that throws on resolution, simulating
        // a misconfigured queue worker / replica where the driver can't boot.
        $this->app->bind(VectorSearchDriver::class, function () {
            throw new RuntimeException('driver boot exploded');
        });

        Log::spy();

        $this->assertNull($note->fresh()->embedding);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'failed to resolve VectorSearchDriver')
                    && ($context['exception'] ?? null) === 'driver boot exploded'
                    && array_key_exists('note_id', $context);
            });
    }

    public function test_embedding_is_hidden_from_array_and_json(): void
    {
        $note = Note::factory()->create();
        app(VectorSearchDriver::class)->store($note->id, [0.1, 0.2, 0.3]);

        $array = $note->fresh()->toArray();
        $json = $note->fresh()->toJson();

        $this->assertArrayNotHasKey('embedding', $array);
        $this->assertStringNotContainsString('embedding', $json);
    }

    public function test_embedding_is_not_mass_assignable(): void
    {
        $note = Note::factory()->create();

        $note->fill(['embedding' => [9.9, 9.9, 9.9]]);
        $note->save();

        $this->assertNull($note->fresh()->embedding);
    }

    public function test_owner_relationship_returns_user(): void
    {
        $user = User::factory()->create();
        $note = Note::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($note->owner->is($user));
    }

    public function test_tags_relationship_attaches_through_pivot_table(): void
    {
        $note = Note::factory()->create();
        $tag = Tag::factory()->create(['name' => 'philosophy']);

        $note->tags()->attach($tag);

        $this->assertCount(1, $note->fresh()->tags);
        $this->assertSame('philosophy', $note->fresh()->tags->first()->name);
        $this->assertDatabaseHas('commonplace_note_tag', [
            'note_id' => $note->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_versions_relationship(): void
    {
        $note = Note::factory()->create();
        NoteVersion::factory()->count(3)->create(['note_id' => $note->id]);

        $this->assertCount(3, $note->fresh()->versions);
    }

    public function test_outgoing_and_incoming_links_relationships(): void
    {
        $source = Note::factory()->create();
        $target = Note::factory()->create();

        Link::factory()->create([
            'source_note_id' => $source->id,
            'target_note_id' => $target->id,
        ]);

        $this->assertCount(1, $source->fresh()->outgoingLinks);
        $this->assertCount(1, $target->fresh()->incomingLinks);
    }

    public function test_shares_relationship(): void
    {
        $note = Note::factory()->create();
        Share::factory()->create(['note_id' => $note->id]);

        $this->assertCount(1, $note->fresh()->shares);
    }

    public function test_accessible_by_scope_returns_owned_notes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $owned = Note::factory()->create(['user_id' => $user->id]);
        Note::factory()->create(['user_id' => $other->id, 'visibility' => 'private']);

        $results = Note::accessibleBy($user)->pluck('id')->all();

        $this->assertContains($owned->id, $results);
        $this->assertCount(1, $results);
    }

    public function test_accessible_by_scope_includes_public_notes(): void
    {
        $user = User::factory()->create();
        $publisher = User::factory()->create();

        $public = Note::factory()->create([
            'user_id' => $publisher->id,
            'visibility' => 'public',
        ]);

        $this->assertTrue(Note::accessibleBy($user)->where('id', $public->id)->exists());
    }

    public function test_accessible_by_scope_includes_shared_notes(): void
    {
        $user = User::factory()->create();
        $publisher = User::factory()->create();

        $shared = Note::factory()->create([
            'user_id' => $publisher->id,
            'visibility' => 'private',
        ]);
        Share::factory()->create([
            'note_id' => $shared->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue(Note::accessibleBy($user)->where('id', $shared->id)->exists());
    }

    public function test_in_folder_scope_matches_path_prefix(): void
    {
        Note::factory()->create(['path' => 'projects/alpha.md']);
        Note::factory()->create(['path' => 'projects/beta.md']);
        Note::factory()->create(['path' => 'archive/gamma.md']);

        $this->assertSame(2, Note::inFolder('projects')->count());
    }

    public function test_in_folder_scope_escapes_like_metacharacters(): void
    {
        Note::factory()->create(['path' => 'projects/safe.md']);
        Note::factory()->create(['path' => 'pXojects/sneaky.md']);

        $this->assertSame(1, Note::inFolder('projects')->count());
    }

    public function test_with_tag_scope_filters_by_tag_name(): void
    {
        $tagged = Note::factory()->create();
        $other = Note::factory()->create();
        $tag = Tag::factory()->create(['name' => 'todo']);

        $tagged->tags()->attach($tag);

        $results = Note::withTag('todo')->pluck('id')->all();

        $this->assertContains($tagged->id, $results);
        $this->assertNotContains($other->id, $results);
    }

    public function test_needs_reindexing_scope_returns_stale_unindexed_notes(): void
    {
        $stale = Note::factory()->create(['indexed_at' => null]);
        $stale->updated_at = now()->subHours(2);
        $stale->saveQuietly();

        Note::factory()->create([
            'indexed_at' => null,
            'updated_at' => now(),
        ]);

        Note::factory()->indexed()->create();

        $results = Note::needsReindexing(60)->pluck('id')->all();

        $this->assertSame([$stale->id], $results);
    }
}
