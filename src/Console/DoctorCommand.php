<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;

class DoctorCommand extends Command
{
    protected $signature = 'commonplace:doctor '
        .'{--exit-code : Return a non-zero exit code if any check fails} '
        .'{--live : Exercise the embedding provider with a real embedQuery call (burns paid API quota; off by default)} '
        .'{--pgvector-migration-precheck : Scan commonplace_notes.embedding for rows that would break the pgvector ALTER and exit}';

    protected $description = 'Diagnose the Commonplace install: vector search, multi-user posture, wikilink graph, MCP auth.';

    public function handle(Connection $db): int
    {
        if ($this->option('pgvector-migration-precheck')) {
            return $this->runPgvectorMigrationPrecheck($db);
        }

        $this->line('Commonplace doctor');
        $this->line(str_repeat('=', 60));

        $checks = [
            $this->checkConfiguredDriver(),
            $this->checkEmbeddingProvider(),
            $this->checkEmbeddingProviderProbe(),
            $this->checkDatabaseDriver($db),
            $this->checkSchema(),
            $this->checkPgvectorExtension($db),
            $this->checkEmbeddingColumn($db),
            $this->checkEmbeddingDimensionDrift($db),
            $this->checkDriverReadiness(),
            $this->checkInPhpCosineCandidates(),
            $this->checkMultiUserVault(),
            $this->checkOrphanedLinks(),
            $this->checkMcpMiddleware(),
        ];

        $failed = collect($checks)->where('status', 'fail')->count();
        $warned = collect($checks)->where('status', 'warn')->count();

        $this->line('');
        $this->line(str_repeat('-', 60));
        $this->line('Checks: '.count($checks).", warnings: {$warned}, failures: {$failed}");

        if ($failed > 0 || $warned > 0) {
            $this->line('');
            $this->line('Recommendations:');
            foreach ($checks as $check) {
                if (! empty($check['recommendation'])) {
                    $this->line("  - {$check['recommendation']}");
                }
            }
        }

        if ($failed > 0 && $this->option('exit-code')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Standalone preflight scan: list every row in commonplace_notes whose
     * embedding column would crash the `ALTER ... USING embedding::vector(N)`
     * cast in the pgvector migration. Skips the rest of the doctor flow.
     */
    private function runPgvectorMigrationPrecheck(Connection $db): int
    {
        $this->line('Commonplace doctor — pgvector migration pre-check');
        $this->line(str_repeat('=', 60));

        if ($db->getDriverName() !== 'pgsql') {
            $this->line(
                "This check is only meaningful on PostgreSQL — your connection is '{$db->getDriverName()}'."
            );

            return self::SUCCESS;
        }

        try {
            $schemaExists = Schema::hasTable('commonplace_notes');
        } catch (\Throwable $e) {
            $this->line('Could not query schema: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $schemaExists) {
            $this->line('commonplace_notes table is not present. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        try {
            $offenders = $db->select(
                'SELECT id, LEFT(embedding, 80) AS preview FROM commonplace_notes '
                ."WHERE embedding IS NOT NULL AND embedding !~ '^\\[.*\\]$' LIMIT 100"
            );
        } catch (\Throwable $e) {
            $this->line('Pre-check query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($offenders === []) {
            $this->line('All embeddings are in pgvector-compatible format — safe to migrate.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn ($row) => [(string) $row->id, (string) ($row->preview ?? '')],
            $offenders,
        );

        $this->table(['id', 'preview'], $rows);
        $this->line('Found '.count($offenders).' offending row(s) (capped at 100).');
        $this->line('Fix these rows (set embedding to NULL or repair the JSON-array value) before running the migration.');

        return $this->option('exit-code') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkConfiguredDriver(): array
    {
        $driver = (string) config('commonplace.vector.driver', 'in_php_cosine');

        return $this->report(
            label: 'Configured vector driver',
            status: in_array($driver, ['pgvector', 'in_php_cosine', 'null'], true) ? 'ok' : 'fail',
            detail: $driver,
            recommendation: in_array($driver, ['pgvector', 'in_php_cosine', 'null'], true)
                ? null
                : 'Set COMMONPLACE_VECTOR_DRIVER to one of: pgvector, in_php_cosine, null.'
        );
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkEmbeddingProvider(): array
    {
        try {
            $provider = app(EmbeddingProvider::class);
            $dims = $provider->dimensions();

            return $this->report(
                label: 'Embedding provider',
                status: 'ok',
                detail: $provider::class." (dimensions={$dims})",
            );
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Embedding provider',
                status: 'fail',
                detail: $e->getMessage(),
                recommendation: 'Verify commonplace.embedding.driver and provider-specific config.',
            );
        }
    }

    /**
     * Live probe of the configured embedding provider. Opt-in via
     * `--live` or `commonplace.doctor.probe_embedding_provider` because
     * a routine doctor run would otherwise burn paid API quota on every
     * invocation. When skipped, the row is `[SKIP]` (not absent) so the
     * "probe disabled" state is visible. A success message includes the
     * returned vector's length so a misconfigured driver returning
     * empty payloads is visible without combing logs.
     *
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkEmbeddingProviderProbe(): array
    {
        $enabled = (bool) $this->option('live')
            || (bool) config('commonplace.doctor.probe_embedding_provider', false);

        if (! $enabled) {
            return $this->report(
                label: 'Embedding provider probe',
                status: 'skip',
                detail: 'disabled (pass --live or set commonplace.doctor.probe_embedding_provider=true)',
            );
        }

        try {
            $provider = app(EmbeddingProvider::class);
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Embedding provider probe',
                status: 'fail',
                detail: $e->getMessage(),
                recommendation: 'Verify commonplace.embedding.driver and provider-specific config.',
            );
        }

        try {
            $vector = $provider->embedQuery('doctor probe');
            $length = count($vector);

            return $this->report(
                label: 'Embedding provider probe',
                status: 'ok',
                detail: "embedQuery() returned a {$length}-dim vector",
            );
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Embedding provider probe',
                status: 'fail',
                detail: $e->getMessage(),
                recommendation: 'Live embedQuery() probe failed. Verify API key / quota / network access for the configured provider.',
            );
        }
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkDatabaseDriver(Connection $db): array
    {
        $name = $db->getDriverName();
        $configured = (string) config('commonplace.vector.driver', 'in_php_cosine');

        if ($configured === 'pgvector' && $name !== 'pgsql') {
            return $this->report(
                label: 'Database driver',
                status: 'fail',
                detail: $name,
                recommendation: "pgvector driver requires PostgreSQL but DB is '{$name}'. "
                    .'Switch COMMONPLACE_VECTOR_DRIVER to in_php_cosine or move to Postgres.',
            );
        }

        return $this->report(label: 'Database driver', status: 'ok', detail: $name);
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkSchema(): array
    {
        try {
            $exists = Schema::hasTable('commonplace_notes');
        } catch (\Throwable $e) {
            return $this->report(
                label: 'commonplace_notes table',
                status: 'fail',
                detail: 'could not query schema: '.$e->getMessage(),
                recommendation: 'Verify database connection works.',
            );
        }

        if (! $exists) {
            return $this->report(
                label: 'commonplace_notes table',
                status: 'fail',
                detail: 'not present',
                recommendation: 'Run `php artisan migrate`.',
            );
        }

        return $this->report(label: 'commonplace_notes table', status: 'ok', detail: 'present');
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkPgvectorExtension(Connection $db): array
    {
        if ($db->getDriverName() !== 'pgsql') {
            return $this->report(
                label: 'pgvector extension',
                status: 'skip',
                detail: 'not applicable (non-Postgres)',
            );
        }

        try {
            $present = $db->selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'vector'") !== null;
        } catch (\Throwable $e) {
            return $this->report(
                label: 'pgvector extension',
                status: 'warn',
                detail: 'could not query: '.$e->getMessage(),
            );
        }

        if (! $present) {
            return $this->report(
                label: 'pgvector extension',
                status: (string) config('commonplace.vector.driver') === 'pgvector' ? 'fail' : 'warn',
                detail: 'not installed',
                recommendation: 'Install pgvector: CREATE EXTENSION vector; (requires superuser).',
            );
        }

        return $this->report(label: 'pgvector extension', status: 'ok', detail: 'installed');
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkEmbeddingColumn(Connection $db): array
    {
        if ($db->getDriverName() !== 'pgsql') {
            return $this->report(
                label: 'embedding column type',
                status: 'skip',
                detail: 'not applicable (non-Postgres)',
            );
        }

        $column = $db->selectOne(
            'SELECT udt_name FROM information_schema.columns '
            ."WHERE table_name = 'commonplace_notes' AND column_name = 'embedding'"
        );

        if ($column === null) {
            return $this->report(
                label: 'embedding column type',
                status: 'fail',
                detail: 'commonplace_notes.embedding not found',
                recommendation: 'Run `php artisan migrate`.',
            );
        }

        $type = (string) ($column->udt_name ?? 'unknown');
        $configured = (string) config('commonplace.vector.driver', 'in_php_cosine');

        if ($configured === 'pgvector' && $type !== 'vector') {
            return $this->report(
                label: 'embedding column type',
                status: 'fail',
                detail: $type,
                recommendation: 'Publish and run the pgvector migration: '
                    .'php artisan vendor:publish --tag=commonplace-pgvector-migration && php artisan migrate',
            );
        }

        return $this->report(label: 'embedding column type', status: 'ok', detail: $type);
    }

    /**
     * Compare the configured provider's dimensions() against what's actually
     * stored. The check is structurally a yes/no question — "does any row
     * disagree with the current provider?" — so we ask it that way:
     *
     *   1. EXISTS short-circuits on the first mismatched row (drift case).
     *   2. On the healthy path it falls through to an index scan over
     *      `embedding_dimensions` (indexed in the base migration) and
     *      confirms no disagreement.
     *   3. Only if drift is found do we run a bounded follow-up to pull
     *      distinct values for the user-facing message.
     *
     * Rows where `embedding` is set but the sentinel is NULL are folded into
     * the drift case as `unknown` — we can't quantify the dimension, but the
     * row is still suspect.
     *
     * Status is `warn`, not `fail`: --exit-code stays green so a routine
     * upgrade doesn't silently break downstream CI pipelines. The detail
     * line is prefixed `DRIFT:` so the recommendations footer surfaces it
     * above the other warns.
     *
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkEmbeddingDimensionDrift(Connection $db): array
    {
        try {
            $expected = app(EmbeddingProvider::class)->dimensions();
        } catch (\Throwable) {
            return $this->report(
                label: 'Embedding dimension drift',
                status: 'skip',
                detail: 'embedding provider not resolvable',
            );
        }

        try {
            if (! Schema::hasTable('commonplace_notes')) {
                return $this->report(
                    label: 'Embedding dimension drift',
                    status: 'skip',
                    detail: 'commonplace_notes not present',
                );
            }

            $hasEmbeddings = $db->table('commonplace_notes')
                ->whereNotNull('embedding')
                ->exists();

            if (! $hasEmbeddings) {
                return $this->report(
                    label: 'Embedding dimension drift',
                    status: 'skip',
                    detail: 'no stored embeddings yet',
                );
            }

            $hasDrift = $db->table('commonplace_notes')
                ->whereNotNull('embedding')
                ->where(function ($q) use ($expected) {
                    $q->whereNull('embedding_dimensions')
                        ->orWhere('embedding_dimensions', '!=', $expected);
                })
                ->exists();

            if (! $hasDrift) {
                return $this->report(
                    label: 'Embedding dimension drift',
                    status: 'ok',
                    detail: "all stored rows match provider {$expected} dim",
                );
            }

            $drifted = $db->table('commonplace_notes')
                ->whereNotNull('embedding')
                ->where(function ($q) use ($expected) {
                    $q->whereNull('embedding_dimensions')
                        ->orWhere('embedding_dimensions', '!=', $expected);
                })
                ->distinct()
                ->limit(10)
                ->pluck('embedding_dimensions')
                ->all();
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Embedding dimension drift',
                status: 'warn',
                detail: 'could not sample commonplace_notes: '.$e->getMessage(),
            );
        }

        $values = array_map(
            static fn ($v) => $v === null ? 'unknown' : (string) (int) $v,
            $drifted,
        );
        sort($values);
        $summary = '['.implode(', ', $values).']';

        return $this->report(
            label: 'Embedding dimension drift',
            status: 'warn',
            detail: "DRIFT: provider expects {$expected} dim, found rows with {$summary}",
            recommendation: "Embedding dimensions drifted: stored rows have dimensions {$summary} but the "
                ."configured provider produces {$expected}. Searches will return wrong results "
                .'(or pgvector will error at query time). Re-embed your notes with '
                .'`php artisan commonplace:reindex`.',
        );
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkDriverReadiness(): array
    {
        try {
            $driver = app(VectorSearchDriver::class);

            return $this->report(
                label: 'Vector driver resolution',
                status: 'ok',
                detail: $driver::class.' (enabled='.($driver->isEnabled() ? 'true' : 'false').')',
            );
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Vector driver resolution',
                status: 'fail',
                detail: $e->getMessage(),
                recommendation: 'See exception message above.',
            );
        }
    }

    /**
     * Info-level row for InPhpCosine users: where are they relative to the
     * candidate caps? Skips silently for other drivers.
     *
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkInPhpCosineCandidates(): array
    {
        $configured = (string) config('commonplace.vector.driver', 'in_php_cosine');

        if ($configured !== 'in_php_cosine') {
            return $this->report(
                label: 'Indexed candidates (InPhp)',
                status: 'skip',
                detail: 'not applicable (driver is '.$configured.')',
            );
        }

        try {
            $count = Note::query()->whereNotNull('embedding')->count();
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Indexed candidates (InPhp)',
                status: 'warn',
                detail: 'could not query commonplace_notes: '.$e->getMessage(),
            );
        }

        $softCap = (int) config('commonplace.vector.in_php_cosine.max_candidates', 2000);
        $hardCap = (int) config('commonplace.vector.in_php_cosine.hard_max_candidates', 20000);

        $status = match (true) {
            $count > $hardCap => 'fail',
            $count > $softCap => 'warn',
            default => 'ok',
        };

        $recommendation = match ($status) {
            'fail' => 'Indexed candidate count exceeds hard cap — semantic search will silently '
                .'fall back to the most-recently-updated slice and surface a "hard_cap_truncated" warning. '
                .'Switch to pgvector or narrow search scope (e.g. scope=mine) to see all candidates.',
            'warn' => 'Indexed candidate count exceeds soft cap — searches will log warnings. '
                .'Consider switching to pgvector for better scaling.',
            default => null,
        };

        return $this->report(
            label: 'Indexed candidates (InPhp)',
            status: $status,
            detail: number_format($count).' / soft cap '.number_format($softCap).' / hard cap '.number_format($hardCap),
            recommendation: $recommendation,
        );
    }

    /**
     * Multi-user vaults on InPhpCosine are a recipe for the candidate cap:
     * the Accessible scope sees every user's public notes plus shares, so
     * the candidate set grows with total install size, not per-user data.
     * pgvector pushes that work down to indexed similarity in the database
     * and is the right answer for any vault with more than one user.
     *
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkMultiUserVault(): array
    {
        try {
            $userCount = (int) Note::query()
                ->whereNotNull('user_id')
                ->distinct()
                ->count('user_id');
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Vault user count',
                status: 'warn',
                detail: 'could not query commonplace_notes: '.$e->getMessage(),
            );
        }

        $configured = (string) config('commonplace.vector.driver', 'in_php_cosine');

        if ($userCount > 1 && $configured === 'in_php_cosine') {
            return $this->report(
                label: 'Vault user count',
                status: 'warn',
                detail: $userCount.' distinct users (in_php_cosine configured)',
                recommendation: "Detected {$userCount} distinct users in commonplace_notes. "
                    .'`in_php_cosine` driver is not recommended for multi-user vaults — '
                    .'Accessible-scope searches will scan all candidates and may hit the candidate cap. '
                    .'Consider switching to `pgvector`.',
            );
        }

        return $this->report(
            label: 'Vault user count',
            status: 'ok',
            detail: $userCount.' distinct user(s)',
        );
    }

    /**
     * Orphan link rows (`target_note_id IS NULL`) are the symptom of a
     * `UpdateWikilinksJob` that never ran — queue worker down, exception
     * silently swallowed, or wikilinks typed at non-existent paths and
     * the target was never created. A small handful is normal; a growing
     * count means rewrites are dropping on the floor. Threshold is
     * configurable.
     *
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkOrphanedLinks(): array
    {
        try {
            if (! Schema::hasTable('commonplace_links')) {
                return $this->report(
                    label: 'Orphaned wikilinks',
                    status: 'skip',
                    detail: 'commonplace_links not present',
                );
            }

            $count = Link::query()->whereNull('target_note_id')->count();
        } catch (\Throwable $e) {
            return $this->report(
                label: 'Orphaned wikilinks',
                status: 'warn',
                detail: 'could not query commonplace_links: '.$e->getMessage(),
            );
        }

        $threshold = (int) config('commonplace.wikilinks.orphan_warn_threshold', 50);

        if ($count === 0) {
            return $this->report(label: 'Orphaned wikilinks', status: 'ok', detail: '0');
        }

        if ($count <= $threshold) {
            return $this->report(
                label: 'Orphaned wikilinks',
                status: 'ok',
                detail: "{$count} (under threshold {$threshold})",
            );
        }

        return $this->report(
            label: 'Orphaned wikilinks',
            status: 'warn',
            detail: "{$count} (over threshold {$threshold})",
            recommendation: "{$count} link rows have target_note_id IS NULL. Run "
                .'`php artisan commonplace:relink` to re-resolve. If the count keeps growing, '
                .'check that a queue worker is processing UpdateWikilinksJob.',
        );
    }

    /**
     * The MCP transport ships unauthenticated if its middleware stack is
     * empty — the registrar's GET / DELETE 405 stubs would be reachable
     * by anyone, and the POST relies on tools' own `$request->user()`
     * fail-closed (`AuthorizationException`) to avoid leaking data. That
     * fail-closed is defense-in-depth, not the auth boundary. Default
     * is `auth:sanctum`; if it's the default, Sanctum must be installed
     * or the guard resolution explodes at first request.
     *
     * Skipped when MCP is disabled — no routes register, nothing to gate.
     *
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function checkMcpMiddleware(): array
    {
        if (! (bool) config('commonplace.mcp.enabled', false)) {
            return $this->report(
                label: 'MCP middleware',
                status: 'skip',
                detail: 'mcp.enabled = false',
            );
        }

        $middleware = (array) config('commonplace.mcp.middleware', []);

        if ($middleware === []) {
            return $this->report(
                label: 'MCP middleware',
                status: 'fail',
                detail: 'empty',
                recommendation: 'Set COMMONPLACE_MCP_MIDDLEWARE (or commonplace.mcp.middleware) to a non-empty stack. '
                    .'Default is `auth:sanctum`. The MCP transport must not ship unauthenticated.',
            );
        }

        $referencesSanctum = false;
        foreach ($middleware as $entry) {
            if (is_string($entry) && (
                $entry === 'auth:sanctum'
                || str_starts_with($entry, 'auth:sanctum,')
                || str_contains($entry, ',sanctum')
            )) {
                $referencesSanctum = true;
                break;
            }
        }

        // String form keeps the class reference unresolved for static
        // analysers — Sanctum is a `suggest` dep, not a require, and
        // referring to the class symbol triggers a `P1009 Undefined
        // type` warning in installs that haven't pulled it in.
        if ($referencesSanctum && ! class_exists('Laravel\\Sanctum\\Sanctum')) {
            return $this->report(
                label: 'MCP middleware',
                status: 'fail',
                detail: '`auth:sanctum` in stack but Laravel\\Sanctum\\Sanctum class is not loaded',
                recommendation: 'Run `composer require laravel/sanctum` and publish its config, '
                    .'or switch COMMONPLACE_MCP_MIDDLEWARE to a guard you do have installed '
                    .'(e.g. `auth:api` for Passport).',
            );
        }

        return $this->report(
            label: 'MCP middleware',
            status: 'ok',
            detail: implode(', ', array_map(static fn ($m) => (string) $m, $middleware)),
        );
    }

    /**
     * @return array{label: string, status: string, detail: string, recommendation?: string}
     */
    private function report(string $label, string $status, string $detail, ?string $recommendation = null): array
    {
        $marker = match ($status) {
            'ok' => '[OK]  ',
            'warn' => '[WARN]',
            'fail' => '[FAIL]',
            'skip' => '[SKIP]',
            default => '[? ]  ',
        };

        $this->line("{$marker} {$label}: {$detail}");

        return compact('label', 'status', 'detail', 'recommendation');
    }
}
