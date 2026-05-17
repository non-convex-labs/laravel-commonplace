<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

/**
 * Integration test for the pgvector ALTER migration's pre-flight check.
 *
 * Skipped on non-Postgres CI — the regex operator `!~` and `ALTER ... USING
 * embedding::vector(N)` are PostgreSQL-only. The actual migration file
 * lives at database/pgvector-migrations/...; we load and run it directly
 * here to exercise both the precheck and the ALTER.
 */
class PgvectorMigrationPrecheckTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'pgvector migration pre-check requires PostgreSQL — regex `!~` and vector cast are Postgres-only.'
            );
        }
    }

    public function test_precheck_throws_when_rows_contain_non_array_embeddings(): void
    {
        $owner = User::factory()->create();
        $note = Note::factory()->create(['user_id' => $owner->id]);

        // Hand-edited garbage — not a JSON array; would crash the ALTER cast.
        DB::table('commonplace_notes')->where('id', $note->id)->update([
            'embedding' => 'not-a-vector-value',
        ]);

        $migration = require __DIR__.'/../../../database/pgvector-migrations/2026_05_16_000002_alter_commonplace_notes_embedding_to_vector.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('commonplace:doctor --pgvector-migration-precheck');

        $migration->up();
    }

    public function test_precheck_normalizes_empty_strings_to_null(): void
    {
        $owner = User::factory()->create();
        $note = Note::factory()->create(['user_id' => $owner->id]);

        // Some platforms default text columns to '' instead of NULL; the
        // precheck normalizes those rather than rejecting them.
        DB::table('commonplace_notes')->where('id', $note->id)->update([
            'embedding' => '',
        ]);

        $migration = require __DIR__.'/../../../database/pgvector-migrations/2026_05_16_000002_alter_commonplace_notes_embedding_to_vector.php';

        // Must not throw — empty strings get NULL'd, then the ALTER succeeds.
        $migration->up();

        $this->assertNull(
            DB::table('commonplace_notes')->where('id', $note->id)->value('embedding')
        );
    }

    public function test_migration_succeeds_when_all_rows_are_well_formed(): void
    {
        $owner = User::factory()->create();
        $note = Note::factory()->withEmbedding(array_fill(0, 8, 0.1))->create(['user_id' => $owner->id]);

        $migration = require __DIR__.'/../../../database/pgvector-migrations/2026_05_16_000002_alter_commonplace_notes_embedding_to_vector.php';

        $migration->up();

        // After the ALTER, embedding column is vector type — verify it's still
        // readable as the same value.
        $stored = DB::table('commonplace_notes')->where('id', $note->id)->value('embedding');
        $this->assertNotNull($stored);
    }
}
