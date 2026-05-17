<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Console\DoctorCommand;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Drivers\Embedding\NullEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\VoyageEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Vector\InPhpCosineDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\NullDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\PgvectorDriver;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Services\MarkdownRenderer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CommonplaceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-commonplace')
            ->hasConfigFile('commonplace')
            ->hasViews('commonplace')
            ->hasRoute('web')
            ->hasMigrations([
                '2026_03_08_000002_create_commonplace_notes_table',
                '2026_03_08_000003_create_commonplace_note_versions_table',
                '2026_03_08_000004_create_commonplace_tags_table',
                '2026_03_08_000005_create_commonplace_note_tag_table',
                '2026_03_08_000006_create_commonplace_links_table',
                '2026_03_08_000007_create_commonplace_shares_table',
            ])
            ->hasCommand(DoctorCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(EmbeddingProvider::class, function () {
            $driver = (string) config('commonplace.embedding.driver', 'null');

            return match ($driver) {
                'voyage' => $this->app->make(VoyageEmbeddingProvider::class),
                'null' => $this->app->make(NullEmbeddingProvider::class),
                default => throw new InvalidArgumentException("Unknown commonplace embedding driver: {$driver}"),
            };
        });

        $this->app->bind(VectorSearchDriver::class, function () {
            $driver = (string) config('commonplace.vector.driver', 'in_php_cosine');

            return match ($driver) {
                'pgvector' => $this->app->make(PgvectorDriver::class),
                'in_php_cosine' => $this->app->make(InPhpCosineDriver::class),
                'null' => $this->app->make(NullDriver::class),
                default => throw new InvalidArgumentException("Unknown commonplace vector driver: {$driver}"),
            };
        });

        $this->app->singleton(MarkdownRenderer::class);

        $this->app->singleton(Commonplace::class);
        $this->app->alias(Commonplace::class, 'commonplace');
    }

    public function packageBooted(): void
    {
        if ((bool) config('commonplace.mcp.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        }

        $this->publishes([
            __DIR__.'/../database/pgvector-migrations/2026_05_16_000002_alter_commonplace_notes_embedding_to_vector.php' => database_path('migrations/'.date('Y_m_d_His').'_alter_commonplace_notes_embedding_to_vector.php'),
        ], 'commonplace-pgvector-migration');
    }
}
