<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_search_view_requires_authentication(): void
    {
        $response = $this->get(route('commonplace.search'));

        $response->assertRedirect();
    }

    public function test_search_returns_matching_note_in_full_text_path(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/penguins',
            'title' => 'Penguins of Antarctica',
            'content' => 'Notes about emperor penguins.',
            'visibility' => 'private',
        ]);

        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/whales',
            'title' => 'Whales',
            'content' => 'Notes about cetaceans.',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.search', ['q' => 'penguin']));

        $response->assertOk();
        $response->assertSee('Penguins of Antarctica');
        $response->assertDontSee('Whales');
    }

    public function test_search_shows_empty_state_when_no_results(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/penguins',
            'title' => 'Penguins',
            'content' => 'About birds.',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('commonplace.search', ['q' => 'mongoose']));

        $response->assertOk();
        $response->assertSee('No notes matched your search.', false);
    }

    public function test_search_without_query_returns_blank_search_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('commonplace.search'));

        $response->assertOk();
        $response->assertSee('Enter a search term to find notes.', false);
    }

    public function test_search_api_returns_json_results(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'topics/penguins',
            'title' => 'Penguins',
            'content' => 'Birds that swim.',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('commonplace.search.api', ['q' => 'penguin']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['title' => 'Penguins']);
    }

    public function test_search_api_returns_empty_for_short_queries(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson(route('commonplace.search.api', ['q' => 'a']));

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_semantic_search_path_is_exercised_but_skipped_on_sqlite(): void
    {
        // Semantic search uses pgvector's `<=>` distance operator (see issue #1).
        // SQLite cannot resolve it, so this test exists solely as a placeholder
        // for the route being wired; the SQL itself is exercised by the
        // service tests (which mark themselves skipped).
        $this->markTestSkipped('Semantic search requires pgvector; see issue #1.');
    }

    public function test_suggested_links_api_returns_404_for_missing_note(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson(route('commonplace.suggested-links', ['path' => 'does/not/exist']));

        $response->assertNotFound();
    }
}
