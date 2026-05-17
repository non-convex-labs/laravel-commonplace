<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Backup;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use NonConvexLabs\Commonplace\Backup\Destinations\FilesystemBackupDestination;
use NonConvexLabs\Commonplace\Backup\Destinations\GitHubBackupDestination;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;
use RuntimeException;
use Throwable;

/**
 * Resolves a destination name from `commonplace.backup.destinations`
 * into a concrete BackupDestination instance.
 *
 * Supported shapes:
 *   - `github` → reads commonplace.backup.github.{repo,token}
 *   - `filesystem.{name}` → reads commonplace.backup.filesystem.{name}.{disk,path}
 *   - Any container binding key, useful for custom user destinations
 *     (e.g. a service-provider-bound `s3-snapshot` alias).
 */
final class DestinationFactory
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function make(string $name): BackupDestination
    {
        if ($name === 'github') {
            return $this->container->make(GitHubBackupDestination::class);
        }

        if (str_starts_with($name, 'filesystem.')) {
            $key = substr($name, strlen('filesystem.'));
            $config = (array) config("commonplace.backup.filesystem.{$key}", []);

            $disk = $config['disk'] ?? null;
            if (! is_string($disk) || $disk === '') {
                throw new RuntimeException(sprintf(
                    'Commonplace backup destination "%s" requires `commonplace.backup.filesystem.%s.disk` to be set.',
                    $name,
                    $key,
                ));
            }

            return new FilesystemBackupDestination(
                disk: $disk,
                path: (string) ($config['path'] ?? ''),
            );
        }

        // Custom destinations registered via the container.
        try {
            $resolved = $this->container->make($name);
        } catch (BindingResolutionException $e) {
            throw new RuntimeException(sprintf(
                'Unknown Commonplace backup destination "%s". Available shorthands: '
                .'"github", "filesystem.{name}". For custom destinations, bind '
                .'them in a service provider before listing them in '
                .'commonplace.backup.destinations.',
                $name,
            ), previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'Commonplace backup destination "%s" could not be resolved: %s',
                $name,
                $e->getMessage(),
            ), previous: $e);
        }

        if (! $resolved instanceof BackupDestination) {
            throw new RuntimeException(sprintf(
                'Commonplace backup destination "%s" did not resolve to a BackupDestination (got %s). '
                .'Bind your class in a service provider or use the built-in `github` / `filesystem.{name}` shorthands.',
                $name,
                get_debug_type($resolved),
            ));
        }

        return $resolved;
    }
}
