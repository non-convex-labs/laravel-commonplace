<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Drivers\Vector;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Drivers\Vector\InPhpCosineDriver;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Tag;
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

        // Reset the once-per-process log-dedupe flags so each test sees a
        // fresh logging surface. The driver itself is a container singleton
        // and intentionally remembers across calls in production.
        $this->resetLogFlags();

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
        $this->assertNull($this->driver->parse('   '));
        $this->assertNull($this->driver->parse('[]'));
    }

    public function test_store_persists_json_and_dimensions(): void
    {
        $note = Note::factory()->create(['user_id' => $this->owner->id]);

        $this->driver->store($note->id, [0.5, 0.25, 0.125]);

        $row = DB::table('commonplace_notes')->where('id', $note->id)->first();

        $this->assertSame([0.5, 0.25, 0.125], json_decode($row->embedding, true));
        $this->assertSame(3, (int) $row->embedding_dimensions);
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
        // Soft cap is purely advisory — no structured warning surfaces.
        $this->assertSame([], $this->driver->lastWarnings());
    }

    public function test_soft_cap_warning_is_logged_only_once_per_process(): void
    {
        config(['commonplace.vector.in_php_cosine.max_candidates' => 2]);
        config(['commonplace.vector.in_php_cosine.hard_max_candidates' => 100]);

        foreach (range(1, 3) as $i) {
            $note = Note::factory()->create(['user_id' => $this->owner->id]);
            $this->driver->store($note->id, [1.0 / $i, 0.0, 0.0]);
        }

        Log::spy();

        $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);
        $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_hard_cap_gracefully_degrades_with_warning_instead_of_throwing(): void
    {
        config(['commonplace.vector.in_php_cosine.max_candidates' => 1]);
        config(['commonplace.vector.in_php_cosine.hard_max_candidates' => 2]);

        // Three candidates total; hard cap is 2. The driver must keep the
        // two most recently updated and return a hard_cap_truncated warning
        // — NOT throw.
        $oldest = Note::factory()->create(['user_id' => $this->owner->id]);
        $this->driver->store($oldest->id, [1.0, 0.0, 0.0]);
        $oldest->forceFill(['updated_at' => now()->subDays(10)])->save();

        $middle = Note::factory()->create(['user_id' => $this->owner->id]);
        $this->driver->store($middle->id, [0.9, 0.1, 0.0]);
        $middle->forceFill(['updated_at' => now()->subDays(5)])->save();

        $newest = Note::factory()->create(['user_id' => $this->owner->id]);
        $this->driver->store($newest->id, [0.8, 0.2, 0.0]);
        $newest->forceFill(['updated_at' => now()])->save();

        Log::spy();

        $results = $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);

        $ids = $results->pluck('id')->all();

        // Oldest must be dropped; the two most recent survive and are scored.
        $this->assertNotContains($oldest->id, $ids);
        $this->assertCount(2, $results);

        $warnings = $this->driver->lastWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertSame('hard_cap_truncated', $warnings[0]['code']);
        $this->assertSame(3, $warnings[0]['context']['candidates']);
        $this->assertSame(2, $warnings[0]['context']['hard_cap']);

        Log::shouldHaveReceived('warning')->once();
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

    public function test_search_skips_candidates_with_dimension_mismatch(): void
    {
        $matching = Note::factory()->create(['user_id' => $this->owner->id]);
        $stale = Note::factory()->create(['user_id' => $this->owner->id]);

        $this->driver->store($matching->id, [1.0, 0.0, 0.0]);
        // Simulate a stale row from a previous embedding model with a different N.
        $this->app['db']->table('commonplace_notes')->where('id', $stale->id)->update([
            'embedding' => json_encode([0.1, 0.2, 0.3, 0.4]),
            'embedding_dimensions' => 4,
        ]);

        Log::spy();

        $results = $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->id);

        $warnings = $this->driver->lastWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('dimension_mismatch_skipped', $warnings[0]['code']);
        $this->assertSame(1, $warnings[0]['context']['skipped']);
        $this->assertSame(3, $warnings[0]['context']['query_dimensions']);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_search_runs_two_passes_and_hydrates_eager_loads(): void
    {
        // Seed 5 notes with embeddings and one tag each. Ask for top 2 with
        // tags eager-loaded; the second pass must rehydrate the relation so
        // the consumer doesn't trigger N+1 on render.
        $tag = Tag::create(['name' => 'shared']);

        foreach (range(1, 5) as $i) {
            $note = Note::factory()->create(['user_id' => $this->owner->id]);
            $this->driver->store($note->id, [1.0 / $i, 0.0, 0.0]);
            $note->tags()->attach($tag);
        }

        $results = $this->driver->search(
            Note::query()->with(['tags', 'owner']),
            [1.0, 0.0, 0.0],
            2,
        );

        $this->assertCount(2, $results);

        foreach ($results as $note) {
            $this->assertTrue($note->relationLoaded('tags'));
            $this->assertTrue($note->relationLoaded('owner'));
            $this->assertSame('shared', $note->tags->first()->name);
            $this->assertNotNull($note->distance);
        }
    }

    public function test_warnings_reset_between_searches(): void
    {
        config(['commonplace.vector.in_php_cosine.max_candidates' => 1]);
        config(['commonplace.vector.in_php_cosine.hard_max_candidates' => 1]);

        foreach (range(1, 3) as $i) {
            $note = Note::factory()->create(['user_id' => $this->owner->id]);
            $this->driver->store($note->id, [1.0 / $i, 0.0, 0.0]);
        }

        $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);
        $this->assertNotEmpty($this->driver->lastWarnings());

        // Lift the cap so the second call is silent; warnings must clear.
        config(['commonplace.vector.in_php_cosine.hard_max_candidates' => 100]);

        $this->driver->search(Note::query(), [1.0, 0.0, 0.0], 10);
        $this->assertSame([], $this->driver->lastWarnings());
    }

    private function resetLogFlags(): void
    {
        $reflection = new \ReflectionClass(InPhpCosineDriver::class);

        foreach (['softCapLogged', 'hardCapLogged', 'dimensionMismatchLogged'] as $name) {
            $property = $reflection->getProperty($name);
            $property->setValue(null, false);
        }
    }
}
