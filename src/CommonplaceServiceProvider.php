<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace;

use InvalidArgumentException;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\NullEmbeddingProvider;
use NonConvexLabs\Commonplace\Drivers\Embedding\VoyageEmbeddingProvider;
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
            ]);
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
    }

    public function packageBooted(): void
    {
        if ((bool) config('commonplace.mcp.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        }
    }
}
