<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Models\Note;
use RuntimeException;
use Throwable;

#[Tries(5)]
#[Backoff([30, 120, 300])]
class BackupToGitHub implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const API_BASE = 'https://api.github.com';

    private const COMMITTER_NAME = 'Commonplace Backup';

    private const COMMITTER_EMAIL = 'commonplace-backup@users.noreply.github.com';

    public function failed(Throwable $exception): void
    {
        Log::error('Commonplace GitHub backup failed', [
            'repo' => config('commonplace.backup.github.repo'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $repo = config('commonplace.backup.github.repo');
        $token = config('commonplace.backup.github.token');

        if (! $repo || ! $token) {
            throw new RuntimeException(
                'Commonplace GitHub backup is not configured. Set commonplace.backup.github.repo and commonplace.backup.github.token.'
            );
        }

        $client = $this->client((string) $token);

        $branch = $this->defaultBranch($client, (string) $repo);
        $baseCommitSha = $this->latestCommitSha($client, (string) $repo, $branch);
        $baseTreeSha = $this->commitTreeSha($client, (string) $repo, $baseCommitSha);

        $tree = $this->buildTree($client, (string) $repo);

        if ($tree === []) {
            Log::info('Commonplace GitHub backup: no notes to back up.');

            return;
        }

        $newTreeSha = $this->createTree($client, (string) $repo, $baseTreeSha, $tree);

        if ($newTreeSha === $baseTreeSha) {
            Log::info('Commonplace GitHub backup: no changes to commit.');

            return;
        }

        $timestamp = now()->toIso8601String();
        $newCommitSha = $this->createCommit(
            $client,
            (string) $repo,
            "Commonplace backup {$timestamp}",
            $newTreeSha,
            [$baseCommitSha],
        );

        $this->updateRef($client, (string) $repo, $branch, $newCommitSha);

        Log::info("Commonplace backed up to GitHub at {$timestamp}", [
            'repo' => $repo,
            'branch' => $branch,
            'commit' => $newCommitSha,
        ]);
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(self::API_BASE)
            ->withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->acceptJson();
    }

    private function defaultBranch(PendingRequest $client, string $repo): string
    {
        $response = $client->get("/repos/{$repo}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Commonplace GitHub backup: failed to read repository {$repo}: ".$response->body()
            );
        }

        return (string) $response->json('default_branch');
    }

    private function latestCommitSha(PendingRequest $client, string $repo, string $branch): string
    {
        $response = $client->get("/repos/{$repo}/git/ref/heads/{$branch}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Commonplace GitHub backup: failed to read ref heads/{$branch}: ".$response->body()
            );
        }

        return (string) $response->json('object.sha');
    }

    private function commitTreeSha(PendingRequest $client, string $repo, string $commitSha): string
    {
        $response = $client->get("/repos/{$repo}/git/commits/{$commitSha}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Commonplace GitHub backup: failed to read commit {$commitSha}: ".$response->body()
            );
        }

        return (string) $response->json('tree.sha');
    }

    /**
     * @return array<int, array{path: string, mode: string, type: string, sha: string|null}>
     */
    private function buildTree(PendingRequest $client, string $repo): array
    {
        $tree = [];
        $seen = [];

        Note::query()->orderBy('id')->each(function (Note $note) use ($client, $repo, &$tree, &$seen) {
            $path = $note->path;

            if (! str_ends_with($path, '.md')) {
                $path .= '.md';
            }

            if (isset($seen[$path])) {
                return;
            }

            $seen[$path] = true;

            $blobSha = $this->createBlob($client, $repo, (string) $note->content);

            $tree[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $blobSha,
            ];
        });

        return $tree;
    }

    private function createBlob(PendingRequest $client, string $repo, string $content): string
    {
        $response = $client->post("/repos/{$repo}/git/blobs", [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Commonplace GitHub backup: failed to create blob: '.$response->body()
            );
        }

        return (string) $response->json('sha');
    }

    /**
     * @param  array<int, array{path: string, mode: string, type: string, sha: string|null}>  $tree
     */
    private function createTree(PendingRequest $client, string $repo, string $baseTreeSha, array $tree): string
    {
        $response = $client->post("/repos/{$repo}/git/trees", [
            'base_tree' => $baseTreeSha,
            'tree' => $this->makeReplaceableTree($client, $repo, $baseTreeSha, $tree),
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Commonplace GitHub backup: failed to create tree: '.$response->body()
            );
        }

        return (string) $response->json('sha');
    }

    /**
     * Build the tree payload for the create-tree call. Entries we control
     * keep their blob SHAs; entries in the base tree that we did not write
     * are explicitly removed by setting their SHA to null so the backup
     * reflects the current set of notes exactly.
     *
     * @param  array<int, array{path: string, mode: string, type: string, sha: string|null}>  $tree
     * @return array<int, array{path: string, mode: string, type: string, sha: string|null}>
     */
    private function makeReplaceableTree(
        PendingRequest $client,
        string $repo,
        string $baseTreeSha,
        array $tree,
    ): array {
        $kept = [];
        foreach ($tree as $entry) {
            $kept[$entry['path']] = true;
        }

        $existing = $this->listBaseMarkdownPaths($client, $repo, $baseTreeSha);

        foreach ($existing as $path) {
            if (! isset($kept[$path])) {
                $tree[] = [
                    'path' => $path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => null,
                ];
            }
        }

        return $tree;
    }

    /**
     * @return array<int, string>
     */
    private function listBaseMarkdownPaths(PendingRequest $client, string $repo, string $baseTreeSha): array
    {
        $response = $client->get("/repos/{$repo}/git/trees/{$baseTreeSha}", [
            'recursive' => 1,
        ]);

        if ($response->failed()) {
            return [];
        }

        $paths = [];
        foreach ((array) $response->json('tree', []) as $entry) {
            if (($entry['type'] ?? null) !== 'blob') {
                continue;
            }

            $path = (string) ($entry['path'] ?? '');
            if ($path !== '' && str_ends_with($path, '.md')) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $parents
     */
    private function createCommit(
        PendingRequest $client,
        string $repo,
        string $message,
        string $treeSha,
        array $parents,
    ): string {
        $response = $client->post("/repos/{$repo}/git/commits", [
            'message' => $message,
            'tree' => $treeSha,
            'parents' => $parents,
            'author' => [
                'name' => self::COMMITTER_NAME,
                'email' => self::COMMITTER_EMAIL,
                'date' => now()->toIso8601String(),
            ],
            'committer' => [
                'name' => self::COMMITTER_NAME,
                'email' => self::COMMITTER_EMAIL,
                'date' => now()->toIso8601String(),
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Commonplace GitHub backup: failed to create commit: '.$response->body()
            );
        }

        return (string) $response->json('sha');
    }

    private function updateRef(PendingRequest $client, string $repo, string $branch, string $commitSha): void
    {
        $response = $client->patch("/repos/{$repo}/git/refs/heads/{$branch}", [
            'sha' => $commitSha,
            'force' => false,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Commonplace GitHub backup: failed to update ref heads/{$branch}: ".$response->body()
            );
        }
    }
}
