<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Coerce any stray non-canonical `visibility` value (most commonly the
 * fictional `'shared'`, which earlier schemas advertised but the runtime
 * always treated as `'private'`) into `'private'`. The model now casts
 * `visibility` through the `Visibility` backed enum — a row outside
 * (`private`, `public`) would throw `ValueError` on hydration.
 *
 * Pre-release, no user data to preserve. The package is still 0.x, so
 * the data semantics for `'shared'` rows do not regress: callers that
 * stored them got `private` access behavior already.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('commonplace_notes')
            ->whereNotIn('visibility', ['private', 'public'])
            ->update(['visibility' => 'private']);
    }

    public function down(): void
    {
        // Irreversible: the original non-canonical values cannot be
        // reconstructed and were always equivalent to `private` anyway.
    }
};
