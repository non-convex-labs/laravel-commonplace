<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class GraphControllerTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_graph_page_requires_authentication(): void
    {
        $response = $this->get(route('commonplace.graph'));

        $response->assertRedirect();
    }

    public function test_graph_page_renders_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->owner)->get(route('commonplace.graph'));

        $response->assertOk();
        $response->assertSee('Knowledge Graph');
    }

    public function test_graph_api_returns_nodes_and_edges(): void
    {
        $alpha = Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'projects/alpha',
            'title' => 'Alpha',
            'visibility' => 'private',
        ]);
        $beta = Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'projects/beta',
            'title' => 'Beta',
            'visibility' => 'private',
        ]);

        Link::create([
            'source_note_id' => $alpha->id,
            'target_note_id' => $beta->id,
            'target_path' => $beta->path,
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('commonplace.graph.api'));

        $response->assertOk();
        $response->assertJsonStructure([
            'nodes' => [['id', 'title', 'folder', 'tags', 'updated_at']],
            'edges' => [['source', 'target']],
        ]);

        $payload = $response->json();
        $this->assertSame(2, count($payload['nodes']));
        $this->assertSame(1, count($payload['edges']));
        $this->assertSame('projects/alpha', $payload['edges'][0]['source']);
        $this->assertSame('projects/beta', $payload['edges'][0]['target']);
    }

    public function test_graph_api_excludes_inaccessible_notes(): void
    {
        Note::factory()->create([
            'user_id' => $this->owner->id,
            'path' => 'own/note',
            'title' => 'Own',
            'visibility' => 'private',
        ]);

        $stranger = User::factory()->create();
        Note::factory()->create([
            'user_id' => $stranger->id,
            'path' => 'private/other',
            'title' => 'Other',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('commonplace.graph.api'));

        $response->assertOk();
        $payload = $response->json();
        $titles = array_map(fn ($n) => $n['title'], $payload['nodes']);
        $this->assertContains('Own', $titles);
        $this->assertNotContains('Other', $titles);
    }

    public function test_neighborhood_endpoint_returns_404_for_missing_note(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson(route('commonplace.neighborhood', ['path' => 'does/not/exist']));

        $response->assertNotFound();
    }
}
