<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Backup\Destinations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationNotConfigured;
use NonConvexLabs\Commonplace\Exceptions\BackupDestinationUnavailable;

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
            throw new BackupDestinationNotConfigured('github');
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
        $response = $this->getOrFail($client, "/repos/{$repo}");

        return (string) $response->json('default_branch');
    }

    private function latestCommitSha(PendingRequest $client, string $repo, string $branch): string
    {
        $response = $this->getOrFail($client, "/repos/{$repo}/git/ref/heads/{$branch}");

        return (string) $response->json('object.sha');
    }

    private function commitTreeSha(PendingRequest $client, string $repo, string $commitSha): string
    {
        $response = $this->getOrFail($client, "/repos/{$repo}/git/commits/{$commitSha}");

        return (string) $response->json('tree.sha');
    }

    /**
     * GET wrapper that converts both connection failures and failed
     * status responses into a curated [[BackupDestinationUnavailable]].
     * The repo path / response body never reach `getMessage()` —
     * operators see them via report() on the previous chain (when set)
     * and existing Laravel HTTP-client logging.
     */
    private function getOrFail(PendingRequest $client, string $path): Response
    {
        try {
            $response = $client->get($path);
        } catch (ConnectionException $e) {
            throw new BackupDestinationUnavailable('github', 'transport', previous: $e);
        }

        if ($response->failed()) {
            throw BackupDestinationUnavailable::fromStatus('github', $response->status());
        }

        return $response;
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
        $response = $this->postOrFail($client, "/repos/{$repo}/git/blobs", [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);

        return (string) $response->json('sha');
    }

    /**
     * @param  array<int, array{path: string, mode: string, type: string, sha: string|null}>  $tree
     */
    private function createTree(PendingRequest $client, string $repo, string $baseTreeSha, array $tree): string
    {
        $response = $this->postOrFail($client, "/repos/{$repo}/git/trees", [
            'base_tree' => $baseTreeSha,
            'tree' => $this->makeReplaceableTree($client, $repo, $baseTreeSha, $tree),
        ]);

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
        $response = $this->postOrFail($client, "/repos/{$repo}/git/commits", [
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

        return (string) $response->json('sha');
    }

    private function updateRef(PendingRequest $client, string $repo, string $branch, string $commitSha): void
    {
        try {
            $response = $client->patch("/repos/{$repo}/git/refs/heads/{$branch}", [
                'sha' => $commitSha,
                'force' => false,
            ]);
        } catch (ConnectionException $e) {
            throw new BackupDestinationUnavailable('github', 'transport', previous: $e);
        }

        if ($response->failed()) {
            throw BackupDestinationUnavailable::fromStatus('github', $response->status());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postOrFail(PendingRequest $client, string $path, array $payload): Response
    {
        try {
            $response = $client->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new BackupDestinationUnavailable('github', 'transport', previous: $e);
        }

        if ($response->failed()) {
            throw BackupDestinationUnavailable::fromStatus('github', $response->status());
        }

        return $response;
    }
}
