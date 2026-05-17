<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;

class DoctorCommand extends Command
{
    protected $signature = 'commonplace:doctor {--exit-code : Return a non-zero exit code if any check fails}';

    protected $description = 'Diagnose the Commonplace vector search configuration.';

    public function handle(Connection $db): int
    {
        $this->line('Commonplace doctor');
        $this->line(str_repeat('=', 60));

        $checks = [
            $this->checkConfiguredDriver(),
            $this->checkEmbeddingProvider(),
            $this->checkDatabaseDriver($db),
            $this->checkPgvectorExtension($db),
            $this->checkEmbeddingColumn($db),
            $this->checkDriverReadiness(),
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
