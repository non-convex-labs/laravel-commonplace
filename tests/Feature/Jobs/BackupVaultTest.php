<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;
use NonConvexLabs\Commonplace\Jobs\BackupVault;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

class BackupVaultTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    public function test_it_throws_when_no_destinations_configured(): void
    {
        config()->set('commonplace.backup.destinations', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('commonplace.backup.destinations');

        Bus::dispatchSync(new BackupVault);
    }

    public function test_it_writes_manifest_and_notes_to_filesystem_destination(): void
    {
        Storage::fake('backups');

        Note::factory()->create([
            'path' => 'notes/first',
            'title' => 'First',
            'content' => "---\ntitle: First\n---\n\nFirst body.",
        ]);
        Note::factory()->create([
            'path' => 'notes/second.md',
            'title' => 'Second',
            'content' => 'Second body.',
        ]);

        config()->set('commonplace.backup.destinations', ['filesystem.test']);
        config()->set('commonplace.backup.filesystem.test', [
            'disk' => 'backups',
            'path' => 'commonplace',
        ]);

        Bus::dispatchSync(new BackupVault);

        $disk = Storage::disk('backups');

        $this->assertTrue($disk->exists('commonplace/manifest.json'));
        $this->assertTrue($disk->exists('commonplace/notes/first.md'));
        $this->assertTrue($disk->exists('commonplace/notes/second.md'));

        $manifest = json_decode($disk->get('commonplace/manifest.json'), true);
        $this->assertSame(BackupBundle::SCHEMA_VERSION, $manifest['version']);
        $this->assertSame(2, $manifest['note_count']);
        $this->assertSame('notes/first.md', $manifest['notes'][0]['path']);
        $this->assertSame('notes/second.md', $manifest['notes'][1]['path']);
        $this->assertStringStartsWith('sha256:', $manifest['notes'][0]['checksum']);

        $this->assertSame(
            "---\ntitle: First\n---\n\nFirst body.",
            $disk->get('commonplace/notes/first.md'),
        );
    }

    public function test_it_skips_when_no_notes(): void
    {
        Storage::fake('backups');

        config()->set('commonplace.backup.destinations', ['filesystem.test']);
        config()->set('commonplace.backup.filesystem.test', [
            'disk' => 'backups',
            'path' => 'commonplace',
        ]);

        Bus::dispatchSync(new BackupVault);

        $this->assertFalse(Storage::disk('backups')->exists('commonplace/manifest.json'));
    }

    public function test_it_fans_out_to_multiple_destinations(): void
    {
        Storage::fake('backups');
        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response(['default_branch' => 'main']),
            'https://api.github.com/repos/octo/notes/git/ref/heads/main' => Http::response(['object' => ['sha' => 'base']]),
            'https://api.github.com/repos/octo/notes/git/commits/base' => Http::response(['tree' => ['sha' => 'tree']]),
            'https://api.github.com/repos/octo/notes/git/trees/tree*' => Http::response(['tree' => []]),
            'https://api.github.com/repos/octo/notes/git/blobs' => Http::response(['sha' => 'blob']),
            'https://api.github.com/repos/octo/notes/git/trees' => Http::response(['sha' => 'new-tree']),
            'https://api.github.com/repos/octo/notes/git/commits' => Http::response(['sha' => 'new-commit']),
            'https://api.github.com/repos/octo/notes/git/refs/heads/main' => Http::response(['object' => ['sha' => 'new-commit']]),
        ]);

        config()->set('commonplace.backup.github.repo', 'octo/notes');
        config()->set('commonplace.backup.github.token', 'gh-token');
        config()->set('commonplace.backup.destinations', ['github', 'filesystem.test']);
        config()->set('commonplace.backup.filesystem.test', [
            'disk' => 'backups',
            'path' => '',
        ]);

        Note::factory()->create([
            'path' => 'notes/one',
            'title' => 'One',
            'content' => 'Body.',
        ]);

        Bus::dispatchSync(new BackupVault);

        $this->assertTrue(Storage::disk('backups')->exists('manifest.json'));
        $this->assertTrue(Storage::disk('backups')->exists('notes/one.md'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/git/blobs'));
    }

    public function test_first_destination_failure_short_circuits_subsequent_destinations(): void
    {
        Storage::fake('backups');

        // First destination is a stub that always throws. Filesystem
        // destination is configured second — it must not run.
        $this->app->bind('failing-stub', fn () => new class implements BackupDestination
        {
            public function push(BackupBundle $bundle): void
            {
                throw new RuntimeException('forced failure');
            }
        });

        config()->set('commonplace.backup.destinations', ['failing-stub', 'filesystem.test']);
        config()->set('commonplace.backup.filesystem.test', [
            'disk' => 'backups',
            'path' => 'commonplace',
        ]);

        Note::factory()->create(['path' => 'notes/x', 'title' => 'X', 'content' => 'body']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forced failure');

        try {
            Bus::dispatchSync(new BackupVault);
        } finally {
            $this->assertFalse(Storage::disk('backups')->exists('commonplace/manifest.json'));
        }
    }

    public function test_traversal_in_note_path_aborts_the_backup(): void
    {
        config()->set('commonplace.backup.destinations', ['filesystem.test']);
        config()->set('commonplace.backup.filesystem.test', [
            'disk' => 'backups',
            'path' => 'commonplace',
        ]);

        Storage::fake('backups');

        // Bypass the model's path validation (if any) by direct insert
        // so the test exercises the bundle's defensive check, not the
        // controller's input validation.
        Note::factory()->create([
            'path' => '../etc/secret',
            'title' => 'Bad',
            'content' => 'body',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('traversal segment');

        Bus::dispatchSync(new BackupVault);
    }

    public function test_filesystem_destination_prunes_orphaned_md_files(): void
    {
        Storage::fake('backups');

        // Seed the disk with a leftover from a previous backup that no
        // longer exists in the vault — pruning should remove it.
        $disk = Storage::disk('backups');
        $disk->put('commonplace/notes/old-note.md', 'ghost content');
        $disk->put('commonplace/notes/unrelated.txt', 'should be kept');

        config()->set('commonplace.backup.destinations', ['filesystem.test']);
        config()->set('commonplace.backup.filesystem.test', [
            'disk' => 'backups',
            'path' => 'commonplace',
        ]);

        Note::factory()->create([
            'path' => 'notes/current',
            'title' => 'Current',
            'content' => 'fresh',
        ]);

        Bus::dispatchSync(new BackupVault);

        $this->assertTrue($disk->exists('commonplace/notes/current.md'));
        $this->assertFalse(
            $disk->exists('commonplace/notes/old-note.md'),
            'Orphaned markdown from a previous backup was not pruned.',
        );
        // Non-.md files are not touched — only .md orphans are pruned.
        $this->assertTrue($disk->exists('commonplace/notes/unrelated.txt'));
    }

    public function test_filesystem_destination_requires_disk_setting(): void
    {
        config()->set('commonplace.backup.destinations', ['filesystem.broken']);
        config()->set('commonplace.backup.filesystem.broken', ['path' => 'x']);

        Note::factory()->create(['path' => 'a', 'title' => 'a', 'content' => 'x']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('filesystem.broken');

        Bus::dispatchSync(new BackupVault);
    }
}
