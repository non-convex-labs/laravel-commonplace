<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use NonConvexLabs\Commonplace\Backup\Destinations\GitHubBackupDestination;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationUnavailable;
use NonConvexLabs\Commonplace\Jobs\BackupToGitHub;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;
use ReflectionClass;
use RuntimeException;

class BackupToGitHubTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.backup.github.repo', 'octo/notes');
        config()->set('commonplace.backup.github.token', 'gh-token');
    }

    public function test_it_dispatches_to_the_queue(): void
    {
        Queue::fake();

        BackupToGitHub::dispatch();

        Queue::assertPushed(BackupToGitHub::class);
    }

    public function test_it_throws_when_repo_is_missing(): void
    {
        config()->set('commonplace.backup.github.repo', null);

        $this->expectException(BackupDestinationNotConfigured::class);
        $this->expectExceptionMessage("Backup destination 'github' is not configured.");

        Bus::dispatchSync(new BackupToGitHub);
    }

    public function test_it_throws_when_token_is_missing(): void
    {
        config()->set('commonplace.backup.github.token', null);

        $this->expectException(BackupDestinationNotConfigured::class);
        $this->expectExceptionMessage("Backup destination 'github' is not configured.");

        Bus::dispatchSync(new BackupToGitHub);
    }

    public function test_it_skips_when_no_notes_exist(): void
    {
        $this->fakeGitHubInitial();

        Bus::dispatchSync(new BackupToGitHub);

        // Empty bundle now short-circuits before any API contact —
        // no wasted quota and the log claim "no notes to back up"
        // matches reality.
        Http::assertNothingSent();
    }

    public function test_it_creates_blobs_tree_commit_and_updates_ref(): void
    {
        Note::factory()->create([
            'path' => 'notes/first',
            'title' => 'First',
            'content' => "---\ntitle: First\n---\n\nFirst body.",
        ]);

        Note::factory()->create([
            'path' => 'notes/second.md',
            'title' => 'Second',
            'content' => "---\ntitle: Second\n---\n\nSecond body.",
        ]);

        $this->fakeGitHubInitial();

        Bus::dispatchSync(new BackupToGitHub);

        Http::assertSentCount(9);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/repos/octo/notes/git/blobs')
                && $request->data()['encoding'] === 'base64'
                && base64_decode($request->data()['content']) === "---\ntitle: First\n---\n\nFirst body.";
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/repos/octo/notes/git/blobs')
                && base64_decode($request->data()['content']) === "---\ntitle: Second\n---\n\nSecond body.";
        });

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/repos/octo/notes/git/trees')) {
                return false;
            }

            $paths = array_column($request->data()['tree'], 'path');

            return in_array('notes/first.md', $paths, true)
                && in_array('notes/second.md', $paths, true)
                && $request->data()['base_tree'] === 'base-tree-sha';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/repos/octo/notes/git/commits')
                && str_starts_with($request->data()['message'], 'Commonplace backup ')
                && $request->data()['tree'] === 'new-tree-sha'
                && $request->data()['parents'] === ['base-commit-sha'];
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/repos/octo/notes/git/refs/heads/main')
                && $request->data()['sha'] === 'new-commit-sha';
        });
    }

    public function test_it_marks_stale_remote_files_for_deletion(): void
    {
        Note::factory()->create([
            'path' => 'notes/kept.md',
            'content' => 'kept',
        ]);

        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response(['default_branch' => 'main'], 200),
            'https://api.github.com/repos/octo/notes/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'base-commit-sha'],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/commits/base-commit-sha' => Http::response([
                'tree' => ['sha' => 'base-tree-sha'],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/trees/base-tree-sha*' => Http::response([
                'tree' => [
                    ['path' => 'notes/kept.md', 'type' => 'blob'],
                    ['path' => 'notes/stale.md', 'type' => 'blob'],
                    ['path' => 'README.md', 'type' => 'blob'],
                    ['path' => 'images/cover.png', 'type' => 'blob'],
                ],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/blobs' => Http::response(['sha' => 'blob-sha'], 201),
            'https://api.github.com/repos/octo/notes/git/trees' => Http::response(['sha' => 'new-tree-sha'], 201),
            'https://api.github.com/repos/octo/notes/git/commits' => Http::response(['sha' => 'new-commit-sha'], 201),
            'https://api.github.com/repos/octo/notes/git/refs/heads/main' => Http::response([], 200),
        ]);

        Bus::dispatchSync(new BackupToGitHub);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/repos/octo/notes/git/trees')) {
                return false;
            }

            $entries = $request->data()['tree'];
            $byPath = [];
            foreach ($entries as $entry) {
                $byPath[$entry['path']] = $entry;
            }

            return isset($byPath['notes/kept.md'])
                && $byPath['notes/kept.md']['sha'] !== null
                && isset($byPath['notes/stale.md'])
                && $byPath['notes/stale.md']['sha'] === null
                && isset($byPath['README.md'])
                && $byPath['README.md']['sha'] === null
                && ! isset($byPath['images/cover.png']);
        });
    }

    public function test_it_skips_commit_when_resulting_tree_matches_base(): void
    {
        Note::factory()->create([
            'path' => 'notes/unchanged.md',
            'content' => 'same',
        ]);

        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response(['default_branch' => 'main'], 200),
            'https://api.github.com/repos/octo/notes/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'base-commit-sha'],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/commits/base-commit-sha' => Http::response([
                'tree' => ['sha' => 'base-tree-sha'],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/trees/base-tree-sha*' => Http::response([
                'tree' => [
                    ['path' => 'notes/unchanged.md', 'type' => 'blob'],
                ],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/blobs' => Http::response(['sha' => 'blob-sha'], 201),
            'https://api.github.com/repos/octo/notes/git/trees' => Http::response(['sha' => 'base-tree-sha'], 201),
        ]);

        Bus::dispatchSync(new BackupToGitHub);

        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/repos/octo/notes/git/commits');
        });

        Http::assertNotSent(function ($request) {
            return $request->method() === 'PATCH';
        });
    }

    public function test_it_declares_retry_configuration(): void
    {
        $reflection = new ReflectionClass(BackupToGitHub::class);

        $tries = $reflection->getAttributes(Tries::class)[0]->newInstance();
        $backoff = $reflection->getAttributes(Backoff::class)[0]->newInstance();

        $this->assertSame(5, $tries->tries);
        $this->assertSame([30, 120, 300], $backoff->backoff);
    }

    public function test_failed_logs_exception_context(): void
    {
        Log::spy();

        $job = new BackupToGitHub;
        $exception = new RuntimeException('boom');

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Commonplace GitHub backup failed'
                    && $context['repo'] === 'octo/notes'
                    && $context['exception'] === RuntimeException::class
                    && $context['message'] === 'boom';
            });
    }

    public function test_failed_logs_context_when_handle_throws_on_http_error(): void
    {
        Log::spy();

        // Seed at least one note so the bundle isn't empty (which
        // would short-circuit `push()` before any HTTP call).
        Note::factory()->create([
            'path' => 'notes/trigger',
            'title' => 'Trigger',
            'content' => 'force-non-empty-bundle',
        ]);

        // #132: this previously caught a bare RuntimeException whose
        // message embedded `$response->body()`. The curated
        // BackupDestinationUnavailable replaces that and pins the
        // wire-visible message away from response-body content.
        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response(['error' => 'server with embedded repo data'], 500),
        ]);

        $job = new BackupToGitHub;
        $destination = $this->app->make(GitHubBackupDestination::class);

        try {
            $job->handle($destination);
            $this->fail('Expected BackupDestinationUnavailable to be thrown.');
        } catch (BackupDestinationUnavailable $exception) {
            $this->assertSame('github', $exception->destination);
            $this->assertSame('transport', $exception->reason);
            $this->assertStringNotContainsString('repo data', $exception->getMessage());
            $job->failed($exception);
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Commonplace GitHub backup failed'
                    && $context['repo'] === 'octo/notes';
            });
    }

    private function fakeGitHubInitial(): void
    {
        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response(['default_branch' => 'main'], 200),
            'https://api.github.com/repos/octo/notes/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'base-commit-sha'],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/commits/base-commit-sha' => Http::response([
                'tree' => ['sha' => 'base-tree-sha'],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/trees/base-tree-sha*' => Http::response([
                'tree' => [],
            ], 200),
            'https://api.github.com/repos/octo/notes/git/blobs' => Http::sequence()
                ->push(['sha' => 'blob-a'], 201)
                ->push(['sha' => 'blob-b'], 201),
            'https://api.github.com/repos/octo/notes/git/trees' => Http::response(['sha' => 'new-tree-sha'], 201),
            'https://api.github.com/repos/octo/notes/git/commits' => Http::response(['sha' => 'new-commit-sha'], 201),
            'https://api.github.com/repos/octo/notes/git/refs/heads/main' => Http::response([], 200),
        ]);
    }
}
