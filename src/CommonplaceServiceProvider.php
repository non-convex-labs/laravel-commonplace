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
            ->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        $this->app->bind(EmbeddingProvider::class, function () {
            $driver = (string) config('commonplace.embedding.driver', 'null');

            return match ($driver) {
                'voyage' => $this->app->make(VoyageEmbeddingProvider::class),
                'null'   => $this->app->make(NullEmbeddingProvider::class),
                default  => throw new InvalidArgumentException("Unknown commonplace embedding driver: {$driver}"),
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
