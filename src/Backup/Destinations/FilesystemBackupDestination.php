<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Backup\Destinations;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;
use RuntimeException;

/**
 * Writes the bundle to a Laravel filesystem disk. Works with any
 * configured disk (local, s3, gcs, …) because all the storage details
 * live in `config/filesystems.php`.
 *
 * Layout under `{path}`:
 *   manifest.json
 *   notes/foo.md
 *   notes/nested/bar.md
 *   ...
 */
final class FilesystemBackupDestination implements BackupDestination
{
    public function __construct(
        private readonly string $disk,
        private readonly string $path = '',
    ) {}

    public function push(BackupBundle $bundle): void
    {
        $disk = $this->resolveDisk();

        $manifestPath = $this->join($this->path, BackupBundle::MANIFEST_FILENAME);

        if (! $disk->put($manifestPath, $bundle->manifestJson())) {
            throw new RuntimeException(sprintf(
                'Commonplace filesystem backup: failed to write %s on disk "%s".',
                $manifestPath,
                $this->disk,
            ));
        }

        $written = [];
        foreach ($bundle->files() as $file) {
            $target = $this->join($this->path, $file['path']);
            $written[] = $target;

            if (! $disk->put($target, $file['content'])) {
                throw new RuntimeException(sprintf(
                    'Commonplace filesystem backup: failed to write %s on disk "%s".',
                    $target,
                    $this->disk,
                ));
            }
        }

        $this->pruneOrphans($disk, $written, $manifestPath);
    }

    /**
     * Prune `.md` files under the configured root that aren't in the
     * current bundle. Mirrors the GitHub destination's tree-replace
     * semantics so a restore from this backup matches the current
     * state of the vault exactly (no ghost notes).
     *
     * Only files we ourselves wrote into get pruned — non-`.md` files
     * and the manifest are preserved.
     *
     * @param  array<int, string>  $written
     */
    private function pruneOrphans(Filesystem $disk, array $written, string $manifestPath): void
    {
        $expected = array_flip($written);

        // Walk every file under the root and delete `.md` files that
        // we didn't write this run. Use `allFiles` to be recursive.
        foreach ($disk->allFiles($this->path) as $existing) {
            if ($existing === $manifestPath || ! str_ends_with($existing, '.md')) {
                continue;
            }

            if (! isset($expected[$existing])) {
                $disk->delete($existing);
            }
        }
    }

    private function resolveDisk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    private function join(string ...$parts): string
    {
        $clean = array_filter(array_map(static fn (string $p) => trim($p, '/'), $parts), static fn (string $p) => $p !== '');

        return implode('/', $clean);
    }
}
