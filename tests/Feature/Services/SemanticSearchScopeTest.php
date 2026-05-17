<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Enums\SemanticSearchScope;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class SemanticSearchScopeTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private Commonplace $commonplace;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embed(string $text): array
            {
                return [1.0, 0.0];
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
                return 2;
            }
        });
        $this->app->forgetInstance(Commonplace::class);

        $this->commonplace = $this->app->make(Commonplace::class);
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    public function test_mine_scope_returns_only_users_own_notes(): void
    {
        $mine = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->alice->id]);
        Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->bob->id, 'visibility' => 'public']);

        $ids = $this->commonplace
            ->semanticSearch('q', $this->alice, SemanticSearchScope::Mine)
            ->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
    }

    public function test_public_scope_returns_only_public_notes(): void
    {
        Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->alice->id, 'visibility' => 'private']);
        $public = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->bob->id, 'visibility' => 'public']);

        $ids = $this->commonplace
            ->semanticSearch('q', $this->alice, SemanticSearchScope::Public)
            ->pluck('id')->all();

        $this->assertSame([$public->id], $ids);
    }

    public function test_accessible_scope_includes_mine_public_and_shared(): void
    {
        $mine = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->alice->id, 'visibility' => 'private']);
        $public = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->bob->id, 'visibility' => 'public']);
        $shared = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->bob->id, 'visibility' => 'private']);
        Share::factory()->create(['note_id' => $shared->id, 'user_id' => $this->alice->id]);

        $hidden = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['user_id' => $this->bob->id, 'visibility' => 'private']);

        $ids = $this->commonplace
            ->semanticSearch('q', $this->alice, SemanticSearchScope::Accessible)
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($public->id, $ids);
        $this->assertContains($shared->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_suggested_links_defaults_to_mine_scope(): void
    {
        $source = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['path' => 'src', 'user_id' => $this->alice->id]);

        $myCandidate = Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['path' => 'mine', 'user_id' => $this->alice->id]);
        Note::factory()->withEmbedding([1.0, 0.0])
            ->create(['path' => 'theirs', 'user_id' => $this->bob->id, 'visibility' => 'public']);

        $paths = collect($this->commonplace->getSuggestedLinks('src', $this->alice))
            ->pluck('path')->all();

        $this->assertContains('mine', $paths);
        $this->assertNotContains('theirs', $paths);
        $this->assertNotContains('src', $paths);
        $this->assertSame($source->id, Note::where('path', 'src')->value('id'));
        $this->assertSame($myCandidate->id, Note::where('path', 'mine')->value('id'));
    }
}
