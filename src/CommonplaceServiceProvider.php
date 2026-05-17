<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Console\DoctorCommand;
use NonConvexLabs\Commonplace\Console\ReindexCommand;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorSearch;
use NonConvexLabs\Commonplace\Contracts\VectorSearchDriver;
use NonConvexLabs\Commonplace\Contracts\VectorStorage;
use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;
use NonConvexLabs\Commonplace\Drivers\Embedding\BedrockEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\CohereEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\NullEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\OpenAIEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\VoyageEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Vector\InPhpCosineDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\NullDriver;
use NonConvexLabs\Commonplace\Drivers\Vector\PgvectorDriver;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Services\MarkdownRenderer;
use NonConvexLabs\Commonplace\Services\WikilinkParser;
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
            ->hasCommand(DoctorCommand::class)
            ->hasCommand(ReindexCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(EmbeddingProvider::class, function () {
            $driver = (string) config('commonplace.embedding.driver', 'null');

            return match ($driver) {
                'voyage' => $this->app->make(VoyageEmbeddingProvider::class),
                'openai' => $this->app->make(OpenAIEmbeddingProvider::class),
                'cohere' => $this->app->make(CohereEmbeddingProvider::class),
                'bedrock' => $this->app->make(BedrockEmbeddingProvider::class),
                'null' => $this->app->make(NullEmbeddingProvider::class),
                default => throw new InvalidArgumentException("Unknown commonplace embedding driver: {$driver}"),
            };
        });

        $this->app->singleton(VectorSearchDriver::class, function () {
            $driver = (string) config('commonplace.vector.driver', 'in_php_cosine');

            return match ($driver) {
                'pgvector' => $this->app->make(PgvectorDriver::class),
                'in_php_cosine' => $this->app->make(InPhpCosineDriver::class),
                'null' => $this->app->make(NullDriver::class),
                default => throw new InvalidArgumentException("Unknown commonplace vector driver: {$driver}"),
            };
        });

        // Narrow-contract aliases. Code that only needs storage or only needs
        // search should depend on these rather than the composite, so future
        // external-service drivers (Qdrant, Pinecone, Chroma) can be wired
        // with a separate VectorStorage binding (typically a no-op) without
        // changing consumers.
        $this->app->bind(VectorStorage::class, fn ($app) => $app->make(VectorSearchDriver::class));
        $this->app->bind(VectorSearch::class, fn ($app) => $app->make(VectorSearchDriver::class));

        // The default wikilink resolver wraps the package's WikilinkParser
        // and resolves against the Note model. Rebind in your own service
        // provider to point wikilinks at different models / external URLs.
        $this->app->bind(WikilinkResolver::class, fn ($app) => $app->make(WikilinkParser::class));

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

        // CSS source for consumers who want to restyle. The published
        // file is overrideable; the package's AssetController falls
        // back to the bundled source when no override is present.
        $this->publishes([
            __DIR__.'/../resources/css/commonplace/commonplace.css' => resource_path('css/commonplace/commonplace.css'),
        ], 'commonplace-css');
    }
}
