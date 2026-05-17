<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Backup\Destinations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;
use RuntimeException;

/**
 * Pushes the bundle into a GitHub repository as a single commit on the
 * default branch. Uses GitHub's git data API so each backup is a real
 * commit (history-preserving), not a force-push.
 *
 * Reads credentials from `commonplace.backup.github.{repo,token}` so
 * config rotation doesn't require redeploying.
 */
final class GitHubBackupDestination implements BackupDestination
{
    private const API_BASE = 'https://api.github.com';

    private const COMMITTER_NAME = 'Commonplace Backup';

    private const COMMITTER_EMAIL = 'commonplace-backup@users.noreply.github.com';

    public function push(BackupBundle $bundle): void
    {
        $repo = config('commonplace.backup.github.repo');
        $token = config('commonplace.backup.github.token');

        if (! $repo || ! $token) {
            throw new RuntimeException(
                'Commonplace GitHub backup is not configured. Set commonplace.backup.github.repo and commonplace.backup.github.token.'
            );
        }

        // Bail before any API contact — avoids wasting a quota slice
        // on an empty bundle and keeps the log claim accurate.
        if ($bundle->isEmpty()) {
            Log::info('Commonplace GitHub backup: no notes to back up.');

            return;
        }

        $client = $this->client((string) $token);
        $repo = (string) $repo;

        $branch = $this->defaultBranch($client, $repo);
        $baseCommitSha = $this->latestCommitSha($client, $repo, $branch);
        $baseTreeSha = $this->commitTreeSha($client, $repo, $baseCommitSha);

        $tree = $this->buildTree($client, $repo, $bundle);

        if ($tree === []) {
            Log::info('Commonplace GitHub backup: no notes to back up.');

            return;
        }

        $newTreeSha = $this->createTree($client, $repo, $baseTreeSha, $tree);

        if ($newTreeSha === $baseTreeSha) {
            Log::info('Commonplace GitHub backup: no changes to commit.');

            return;
        }

        $timestamp = now()->toIso8601String();
        $newCommitSha = $this->createCommit(
            $client,
            $repo,
            "Commonplace backup {$timestamp}",
            $newTreeSha,
            [$baseCommitSha],
        );

        $this->updateRef($client, $repo, $branch, $newCommitSha);

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
    private function buildTree(PendingRequest $client, string $repo, BackupBundle $bundle): array
    {
        $tree = [];
        $seen = [];

        foreach ($bundle->files() as $file) {
            $path = $file['path'];

            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $tree[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $this->createBlob($client, $repo, $file['content']),
            ];
        }

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
     * Entries we control keep their blob SHAs; entries in the base tree
     * that we did not write are explicitly removed by setting their SHA
     * to null so the backup reflects the current set of notes exactly.
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

        foreach ($this->listBaseMarkdownPaths($client, $repo, $baseTreeSha) as $path) {
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
