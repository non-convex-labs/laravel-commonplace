<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit;

use NonConvexLabs\Commonplace\Tests\TestCase;

class MigrationsAutoloadTest extends TestCase
{
    public function test_service_provider_registers_packages_migrations_directory_with_migrator(): void
    {
        $migrationsPath = realpath(__DIR__.'/../../database/migrations');

        $this->assertNotFalse($migrationsPath, 'package database/migrations directory must exist');

        $registered = array_map('realpath', $this->app['migrator']->paths());

        $this->assertContains(
            $migrationsPath,
            $registered,
            "Service provider must auto-load migrations from database/migrations so `php artisan migrate` works without a separate publish step. If this fails, restore the `\$this->loadMigrationsFrom(__DIR__.'/../database/migrations')` call in CommonplaceServiceProvider::packageBooted().",
        );
    }

    public function test_every_migration_file_on_disk_is_discoverable_by_the_migrator(): void
    {
        $migrationsPath = realpath(__DIR__.'/../../database/migrations');

        $onDisk = glob($migrationsPath.'/*.php');
        $this->assertNotEmpty($onDisk, 'package must ship at least one migration');

        $discovered = $this->app['migrator']->getMigrationFiles($this->app['migrator']->paths());

        foreach ($onDisk as $file) {
            $basename = basename($file, '.php');
            $this->assertArrayHasKey(
                $basename,
                $discovered,
                "Migration {$basename} exists on disk but the migrator did not discover it. The package previously maintained a manual `hasMigrations([...])` list that drifted from the filesystem — this test guards against that regression.",
            );
        }
    }
}
