<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Backup\Destinations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Backup\Destinations\GitHubBackupDestination;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationUnavailable;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

/**
 * Message-pinning suite for the GitHub backup destination's curated
 * exception throws. The destination runs out-of-band (no MCP envelope
 * sees it directly today) but the same wire-side discipline as the
 * embedding-provider drivers applies for consistency, and so that the
 * operator log surface — which IS visible to a wider audience than
 * `report()` — never carries GitHub URLs, repo names, or response
 * bodies via the exception's `getMessage()`.
 */
class GitHubBackupDestinationTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    private GitHubBackupDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commonplace.backup.github.repo', 'octo/notes');
        config()->set('commonplace.backup.github.token', 'gh-token');

        $this->destination = $this->app->make(GitHubBackupDestination::class);
    }

    public function test_missing_repo_throws_not_configured(): void
    {
        config()->set('commonplace.backup.github.repo', null);

        $bundle = $this->bundleWithOneNote();

        $this->expectException(BackupDestinationNotConfigured::class);
        $this->expectExceptionMessage("Backup destination 'github' is not configured.");

        $this->destination->push($bundle);
    }

    public function test_missing_token_throws_not_configured(): void
    {
        config()->set('commonplace.backup.github.token', null);

        $bundle = $this->bundleWithOneNote();

        $this->expectException(BackupDestinationNotConfigured::class);
        $this->expectExceptionMessage("Backup destination 'github' is not configured.");

        $this->destination->push($bundle);
    }

    public function test_500_from_repo_lookup_throws_unavailable_transport_without_body(): void
    {
        // The fake body echoes what a noisy GitHub error response might
        // contain — including the repo path. Pin that it never appears
        // in the wire-visible exception message.
        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response(
                ['message' => 'octo/notes is broken: internal server detail'],
                500,
            ),
        ]);

        try {
            $this->destination->push($this->bundleWithOneNote());
            $this->fail('Expected BackupDestinationUnavailable.');
        } catch (BackupDestinationUnavailable $e) {
            $this->assertSame('github', $e->destination);
            $this->assertSame('transport', $e->reason);
            $this->assertSame(
                "Backup destination 'github' is unavailable (transport error). Retry with backoff.",
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('octo/notes', $e->getMessage());
            $this->assertStringNotContainsString('internal server detail', $e->getMessage());
        }
    }

    public function test_429_maps_to_rate_limited(): void
    {
        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response('throttled', 429),
        ]);

        try {
            $this->destination->push($this->bundleWithOneNote());
            $this->fail('Expected BackupDestinationUnavailable.');
        } catch (BackupDestinationUnavailable $e) {
            $this->assertSame('rate_limited', $e->reason);
            $this->assertSame(
                "Backup destination 'github' is unavailable (rate-limited). Retry with backoff.",
                $e->getMessage(),
            );
        }
    }

    public function test_401_maps_to_unauthorized(): void
    {
        Http::fake([
            'https://api.github.com/repos/octo/notes' => Http::response('bad token', 401),
        ]);

        try {
            $this->destination->push($this->bundleWithOneNote());
            $this->fail('Expected BackupDestinationUnavailable.');
        } catch (BackupDestinationUnavailable $e) {
            $this->assertSame('unauthorized', $e->reason);
        }
    }

    public function test_connection_failure_maps_to_transport(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to api.github.com');
        });

        try {
            $this->destination->push($this->bundleWithOneNote());
            $this->fail('Expected BackupDestinationUnavailable.');
        } catch (BackupDestinationUnavailable $e) {
            $this->assertSame('transport', $e->reason);
            // The cause is preserved for operator report(); the
            // wire-visible message is the curated form.
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
            $this->assertStringNotContainsString('api.github.com', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    private function bundleWithOneNote(): BackupBundle
    {
        $note = Note::factory()->create([
            'path' => 'notes/sample',
            'title' => 'Sample',
            'content' => "# Sample\n\nContent.",
        ]);

        return BackupBundle::fromNotes(new Collection([$note]));
    }
}
