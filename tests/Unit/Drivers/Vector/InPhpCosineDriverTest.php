<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Vector;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Drivers\Vector\InPhpCosineDriver;
use NonConvexLabs\Commonplace\Exceptions\VectorCandidateLimitExceeded;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class InPhpCosineDriverTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private InPhpCosineDriver $driver;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->app->make(InPhpCosineDriver::class);
        $this->owner = User::factory()->create();
    }

    public function test_is_enabled(): void
    {
        $this->assertTrue($this->driver->isEnabled());
    }

    public function test_parse_decodes_json_strings(): void
    {
        $this->assertSame([0.1, 0.2, 0.3], $this->driver->parse('[0.1,0.2,0.3]'));
    }

    public function test_parse_returns_null_on_empty_or_invalid(): void
    {
        $this->assertNull($this->driver->parse(null));
        $this->assertNull($this->driver->parse(''));
        $this->assertNull($this->driver->parse('not-json'));
    }

    public function test_store_persists_json_to_embedding_column(): void
    {
        $note = Note::factory()->create(['user_id' => $this->owner->id]);

        $this->driver->store($note->id, [0.5, 0.25, 0.125]);

        $raw = $note->fresh()->getRawOriginal('embedding');

        $this->assertSame([0.5, 0.25, 0.125], json_decode($raw, true));
    }

    public function test_search_returns_empty_when_no_candidates_have_embeddings(): void
    {
        Note::factory()->count(3)->create(['user_id' => $this->owner->id]);

        $results = $this->driver->search(Note::query(), [1.0, 0.0], 10);

        $this->assertCount(0, $results);
    }

    public function test_search_ranks_identical_vector_as_distance_zero(): void
    {
        $note = Note::factory()->create(['user_id' => $this->owner->id]);
        $this->driver->store($note->id, [1.0, 0.0, 0.0]);

        $results = $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(0.0, $results->first()->distance, 1e-9);
    }

    public function test_search_ranks_orthogonal_vector_as_distance_one(): void
    {
        $note = Note::factory()->create(['user_id' => $this->owner->id]);
        $this->driver->store($note->id, [1.0, 0.0]);

        $results = $this->driver->search(Note::query(), [0.0, 1.0], 10);

        $this->assertEqualsWithDelta(1.0, $results->first()->distance, 1e-9);
    }

    public function test_search_orders_by_distance_ascending(): void
    {
        $near = Note::factory()->create(['user_id' => $this->owner->id]);
        $mid = Note::factory()->create(['user_id' => $this->owner->id]);
        $far = Note::factory()->create(['user_id' => $this->owner->id]);

        $this->driver->store($near->id, [1.0, 0.0, 0.0]);
        $this->driver->store($mid->id, [0.7, 0.7, 0.0]);
        $this->driver->store($far->id, [0.0, 0.0, 1.0]);

        $ordered = $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10)
            ->pluck('id')->all();

        $this->assertSame([$near->id, $mid->id, $far->id], $ordered);
    }

    public function test_search_honors_limit(): void
    {
        foreach (range(1, 5) as $i) {
            $note = Note::factory()->create(['user_id' => $this->owner->id]);
            $this->driver->store($note->id, [1.0 / $i, 0.0, 0.0]);
        }

        $this->assertCount(3, $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 3));
    }

    public function test_soft_cap_logs_warning_but_returns_results(): void
    {
        config(['commonplace.vector.in_php_cosine.max_candidates' => 2]);
        config(['commonplace.vector.in_php_cosine.hard_max_candidates' => 100]);

        foreach (range(1, 3) as $i) {
            $note = Note::factory()->create(['user_id' => $this->owner->id]);
            $this->driver->store($note->id, [1.0 / $i, 0.0, 0.0]);
        }

        Log::spy();

        $results = $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);

        Log::shouldHaveReceived('warning')->once();
        $this->assertCount(3, $results);
    }

    public function test_hard_cap_throws(): void
    {
        config(['commonplace.vector.in_php_cosine.max_candidates' => 1]);
        config(['commonplace.vector.in_php_cosine.hard_max_candidates' => 1]);

        foreach (range(1, 3) as $i) {
            $note = Note::factory()->create(['user_id' => $this->owner->id]);
            $this->driver->store($note->id, [1.0 / $i, 0.0, 0.0]);
        }

        $this->expectException(VectorCandidateLimitExceeded::class);

        $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);
    }

    public function test_search_skips_candidates_with_unparseable_embedding(): void
    {
        $good = Note::factory()->create(['user_id' => $this->owner->id]);
        $bad = Note::factory()->create(['user_id' => $this->owner->id]);

        $this->driver->store($good->id, [1.0, 0.0]);
        // Write garbage directly bypassing the driver so parse() returns null.
        $this->app['db']->table('commonplace_notes')->where('id', $bad->id)
            ->update(['embedding' => 'not-json-at-all']);

        $results = $this->driver->search(Note::query(), [1.0, 0.0], 10);

        $this->assertCount(1, $results);
        $this->assertSame($good->id, $results->first()->id);
    }
}
