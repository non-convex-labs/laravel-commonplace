<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Contracts\VectorSearch;
use NonConvexLabs\Commonplace\Enums\SemanticSearchScope;
use NonConvexLabs\Commonplace\Enums\Visibility;
use NonConvexLabs\Commonplace\Jobs\UpdateWikilinksJob;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Models\Tag;

class Commonplace
{
    /**
     * Boot-time-registered callbacks invoked with the CommonMark Environment
     * when MarkdownRenderer builds its converter. Register from your service
     * provider's `boot()` — not per-request — otherwise callbacks accumulate
     * across requests under Octane / queue workers and leak memory.
     *
     * @var array<int, callable(Environment): void>
     */
    private array $markdownExtenders = [];

    /**
     * Set when MarkdownRenderer reads the extender list — prevents
     * surprise mutations after the converter has been built. Calling
     * `extendMarkdown()` after this is set throws.
     */
    private bool $markdownExtendersFrozen = false;

    public function __construct(
        private readonly FrontmatterParser $frontmatterParser,
        private readonly WikilinkParser $wikilinkParser,
        private readonly EmbeddingProvider $embeddingProvider,
        private readonly VectorSearch $vectorDriver,
    ) {}

    /**
     * Register a callback that receives the CommonMark Environment after
     * the configured extensions have been added. Use for custom inline /
     * block parsers, renderers, or event listeners.
     *
     * **Call this from a service provider's boot() method only.**
     *
     * @param  callable(Environment): void  $callback
     */
    public function extendMarkdown(callable $callback): void
    {
        if ($this->markdownExtendersFrozen) {
            throw new \LogicException(
                'Commonplace::extendMarkdown() must be called from a service provider boot() — '
                .'the registry is frozen once the markdown renderer is first built. Adding '
                .'extenders per-request leaks memory under Octane / queue workers.'
            );
        }

        $this->markdownExtenders[] = $callback;
    }

    /**
     * @return array<int, callable(Environment): void>
     *
     * @internal Used by MarkdownRenderer.
     */
    public function registeredMarkdownExtenders(): array
    {
        $this->markdownExtendersFrozen = true;

        return $this->markdownExtenders;
    }

    /**
     * @internal Reset registered extenders. Tests + Octane request lifecycle.
     */
    public function clearMarkdownExtenders(): void
    {
        $this->markdownExtenders = [];
        $this->markdownExtendersFrozen = false;
    }

    public function createNote(
        string $path,
        string $content,
        array $tags,
        string $visibility,
        Authenticatable $owner,
    ): Note {
        $path = $this->normalizePath($path);
        $content = $this->normalizeContent($content);

        $parsed = $this->frontmatterParser->parse($content);
        $meta = $parsed['meta'];

        $title = $meta['title'] ?? Str::title(str_replace('-', ' ', basename($path)));
        $visibility = $this->validateVisibility($meta['visibility'] ?? $visibility);
        $tags = $meta['tags'] ?? $tags;

        $note = Note::create([
            'path' => $path,
            'title' => $title,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'visibility' => $visibility,
            'indexed_at' => null,
            'user_id' => $owner->getAuthIdentifier(),
        ]);

        $this->syncTags($note, $tags);
        $this->syncWikilinks($note, $content);

        return $note->load(['tags', 'owner', 'outgoingLinks']);
    }

    public function readNote(string $path, Authenticatable $user): Note
    {
        $path = $this->normalizePath($path);

        $note = Note::where('path', $path)->firstOrFail();

        $this->checkAccess($note, $user);

        return $note->load(['tags', 'owner']);
    }

    public function updateNote(string $path, array $data, Authenticatable $user): Note
    {
        $path = $this->normalizePath($path);

        if (isset($data['content'])) {
            $data['content'] = $this->normalizeContent($data['content']);
        }

        if (isset($data['new_path'])) {
            $data['new_path'] = $this->normalizePath($data['new_path']);
        }

        $note = Note::where('path', $path)->firstOrFail();

        $this->checkAccess($note, $user, 'write');

        if (isset($data['content'])) {
            $parsed = $this->frontmatterParser->parse($data['content']);
            $meta = $parsed['meta'];

            $newHash = hash('sha256', $data['content']);

            if ($newHash !== $note->content_hash) {
                NoteVersion::create([
                    'note_id' => $note->id,
                    'note_path' => $note->path,
                    'content' => $note->content,
                    'content_hash' => $note->content_hash,
                    'changed_by' => $user->getAuthIdentifier(),
                ]);

                $note->content = $data['content'];
                $note->content_hash = $newHash;
                $note->indexed_at = null;
            }

            if (isset($meta['title'])) {
                $note->title = $meta['title'];
            }

            if (isset($meta['visibility'])) {
                $note->visibility = $this->validateVisibility($meta['visibility']);
            }

            if (isset($meta['tags'])) {
                $data['tags'] = $meta['tags'];
            }

            $this->syncWikilinks($note, $data['content']);
        }

        if (isset($data['visibility'])) {
            $contentHasFrontmatterVisibility = isset($data['content'])
                && isset($this->frontmatterParser->parse($data['content'])['meta']['visibility']);

            if (! $contentHasFrontmatterVisibility) {
                $note->visibility = $this->validateVisibility($data['visibility']);
            }
        }

        $note->save();

        if (isset($data['tags'])) {
            $this->syncTags($note, $data['tags']);
        }

        // Path change is delegated to moveNote so the wikilink-rewrite
        // job dispatches from a single path-mutation site. Doing this
        // last keeps the prior field updates atomic with their own save
        // (versioning, tags) and avoids racing the rewrite job against
        // a half-saved note. `moveNote` re-runs `checkAccess` at the
        // `owner` level — strictly stricter than the `write` we already
        // passed, so a writable-but-not-owner caller can't bypass owner
        // gating by routing a move through `updateNote`.
        if (isset($data['new_path']) && $data['new_path'] !== $note->path) {
            $oldPath = $note->path;
            $this->moveNote($oldPath, $data['new_path'], $user);
            $note->refresh();
        }

        return $note->load(['tags', 'owner', 'outgoingLinks']);
    }

    public function editNote(
        string $path,
        string $oldString,
        string $newString,
        bool $replaceAll,
        Authenticatable $user,
    ): Note {
        $path = $this->normalizePath($path);
        $oldString = $this->normalizeContent($oldString);
        $newString = $this->normalizeContent($newString);

        $note = Note::where('path', $path)->firstOrFail();

        $this->checkAccess($note, $user, 'write');

        if ($oldString === '') {
            throw new \InvalidArgumentException('old_string must not be empty.');
        }

        if ($oldString === $newString) {
            throw new \InvalidArgumentException('old_string and new_string must be different.');
        }

        $occurrences = substr_count($note->content, $oldString);

        if ($occurrences === 0) {
            throw new \InvalidArgumentException('old_string not found in note content.');
        }

        if ($occurrences > 1 && ! $replaceAll) {
            throw new \InvalidArgumentException(
                "old_string appears {$occurrences} times in the note. "
                .'Provide a larger unique string or set replace_all to true.'
            );
        }

        $newContent = $replaceAll
            ? str_replace($oldString, $newString, $note->content)
            : $this->replaceFirst($note->content, $oldString, $newString);

        return $this->updateNote($path, ['content' => $newContent], $user);
    }

    public function deleteNote(string $path, Authenticatable $user): void
    {
        $path = $this->normalizePath($path);

        $note = Note::where('path', $path)->firstOrFail();

        $this->checkAccess($note, $user, 'owner');

        NoteVersion::create([
            'note_id' => $note->id,
            'note_path' => $note->path,
            'content' => $note->content,
            'content_hash' => $note->content_hash,
            'changed_by' => $user->getAuthIdentifier(),
        ]);

        $note->delete();
    }

    public function listNotes(
        ?string $folder,
        ?string $tag,
        ?string $visibility,
        Authenticatable $user,
    ): Collection {
        $query = Note::accessibleBy($user)->with(['tags', 'owner']);

        if ($folder !== null) {
            $query->inFolder($this->normalizePath($folder));
        }

        if ($tag !== null) {
            $query->withTag($tag);
        }

        if ($visibility !== null) {
            $query->where('visibility', $visibility);
        }

        return $query->orderByDesc('updated_at')->get();
    }

    public function searchNotes(string $query, Authenticatable $user): Collection
    {
        if (mb_strlen($query) < 2) {
            return new Collection;
        }

        $term = '%'.$query.'%';
        $operator = $this->likeOperator();

        return Note::accessibleBy($user)
            ->with(['tags', 'owner'])
            ->where(function ($q) use ($term, $operator) {
                $q->where('title', $operator, $term)
                    ->orWhere('content', $operator, $term);
            })
            ->orderByRaw(
                'CASE WHEN title '.$operator.' ? THEN 1 ELSE 2 END',
                [$term]
            )
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    public function semanticSearch(
        string $query,
        Authenticatable $user,
        SemanticSearchScope $scope = SemanticSearchScope::Accessible,
    ): Collection {
        if (! $this->vectorDriver->isEnabled()) {
            return new Collection;
        }

        $baseQuery = $scope->apply(Note::query(), $user)
            ->with(['tags', 'owner']);

        return $this->vectorDriver->search(
            $baseQuery,
            $this->embeddingProvider->embedQuery($query),
            20,
        );
    }

    /**
     * Warnings (cap truncation, dimension mismatches, etc.) emitted by the
     * driver during the immediately preceding semanticSearch() or
     * getSuggestedLinks() call. Empty for drivers that never warn (pgvector,
     * null).
     *
     * @return array<int, array{code: string, message: string, context: array<string, mixed>}>
     */
    public function lastSearchWarnings(): array
    {
        return $this->vectorDriver->lastWarnings();
    }

    public function getBacklinks(string $path, Authenticatable $user): Collection
    {
        $path = $this->normalizePath($path);

        $note = Note::where('path', $path)->firstOrFail();

        $sourceNoteIds = Link::where('target_note_id', $note->id)
            ->pluck('source_note_id');

        return Note::accessibleBy($user)
            ->with(['tags', 'owner'])
            ->whereIn('id', $sourceNoteIds)
            ->get();
    }

    public function moveNote(string $fromPath, string $toPath, Authenticatable $user): Note
    {
        $fromPath = $this->normalizePath($fromPath);
        $toPath = $this->normalizePath($toPath);

        $note = Note::where('path', $fromPath)->firstOrFail();

        $this->checkAccess($note, $user, 'owner');

        if ($fromPath === $toPath) {
            return $note->load(['tags', 'owner']);
        }

        if (Note::where('path', $toPath)->exists()) {
            throw new \InvalidArgumentException("A note already exists at path: {$toPath}");
        }

        // `DB::afterCommit` fires *immediately* when called outside a
        // transaction (documented Laravel behavior), which would
        // silently regress to the synchronous, path-locking rewrite
        // this job is meant to replace. The wrap is load-bearing.
        DB::transaction(function () use ($note, $fromPath, $toPath): void {
            $note->update(['path' => $toPath]);

            $noteId = (int) $note->getKey();
            $sync = (bool) config('commonplace.wikilinks.rewrite_sync', false);

            DB::afterCommit(function () use ($noteId, $fromPath, $toPath, $sync): void {
                if ($sync) {
                    UpdateWikilinksJob::dispatchSync($noteId, $fromPath, $toPath);

                    return;
                }

                UpdateWikilinksJob::dispatch($noteId, $fromPath, $toPath);
            });
        });

        return $note->load(['tags', 'owner']);
    }

    public function getHistory(string $path, Authenticatable $user): Collection
    {
        $path = $this->normalizePath($path);

        $note = Note::where('path', $path)->first();

        if ($note) {
            $this->checkAccess($note, $user);

            return $note->versions()
                ->with('author')
                ->orderByDesc('id')
                ->get();
        }

        return NoteVersion::where('note_path', $path)
            ->with('author')
            ->orderByDesc('id')
            ->get();
    }

    public function getNeighborhood(string $path, int $maxHops, Authenticatable $user): array
    {
        $path = $this->normalizePath($path);

        $note = Note::where('path', $path)->firstOrFail();
        $this->checkAccess($note, $user);

        $results = DB::select(<<<'SQL'
            WITH RECURSIVE graph AS (
                SELECT ? AS note_id, 0 AS depth, ARRAY[?::bigint] AS visited
                UNION ALL
                SELECT
                    CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                    END,
                    g.depth + 1,
                    g.visited || CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                    END
                FROM commonplace_links vl
                JOIN graph g ON (vl.source_note_id = g.note_id OR vl.target_note_id = g.note_id)
                WHERE g.depth < ?
                  AND CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                      END IS NOT NULL
                  AND NOT CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                      END = ANY(g.visited)
            )
            SELECT DISTINCT note_id, MIN(depth) AS depth
            FROM graph
            WHERE note_id != ?
            GROUP BY note_id
            ORDER BY depth, note_id
        SQL, [$note->id, $note->id, $maxHops, $note->id]);

        $noteIds = collect($results)->pluck('note_id')->all();
        $depths = collect($results)->keyBy('note_id');

        $notes = Note::accessibleBy($user)
            ->with('tags')
            ->whereIn('id', $noteIds)
            ->get();

        return $notes->map(fn (Note $n) => [
            'path' => $n->path,
            'title' => $n->title,
            'depth' => $depths[$n->id]->depth,
            'tags' => $n->tags->pluck('name')->all(),
        ])->sortBy('depth')->values()->all();
    }

    public function getShortestPath(string $fromPath, string $toPath, Authenticatable $user): ?array
    {
        $fromPath = $this->normalizePath($fromPath);
        $toPath = $this->normalizePath($toPath);

        $from = Note::where('path', $fromPath)->firstOrFail();
        $to = Note::where('path', $toPath)->firstOrFail();

        $this->checkAccess($from, $user);
        $this->checkAccess($to, $user);

        $results = DB::select(<<<'SQL'
            WITH RECURSIVE graph AS (
                SELECT ? AS note_id, ARRAY[?::bigint] AS path, 0 AS depth
                UNION ALL
                SELECT
                    CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                    END,
                    g.path || CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                    END,
                    g.depth + 1
                FROM commonplace_links vl
                JOIN graph g ON (vl.source_note_id = g.note_id OR vl.target_note_id = g.note_id)
                WHERE g.depth < 10
                  AND CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                      END IS NOT NULL
                  AND NOT CASE
                        WHEN vl.source_note_id = g.note_id THEN vl.target_note_id
                        ELSE vl.source_note_id
                      END = ANY(g.path)
            )
            SELECT path, depth
            FROM graph
            WHERE note_id = ?
            ORDER BY depth
            LIMIT 1
        SQL, [$from->id, $from->id, $to->id]);

        if (empty($results)) {
            return null;
        }

        $idPath = str_replace(['{', '}'], '', $results[0]->path);
        $noteIds = array_map('intval', explode(',', $idPath));

        $notes = Note::whereIn('id', $noteIds)->get()->keyBy('id');

        return collect($noteIds)
            ->map(fn (int $id) => $notes->get($id))
            ->filter()
            ->map(fn (Note $n) => ['path' => $n->path, 'title' => $n->title])
            ->values()
            ->all();
    }

    public function getHubNotes(Authenticatable $user, int $limit = 20): array
    {
        $results = DB::select(<<<'SQL'
            SELECT
                vn.id,
                vn.path,
                vn.title,
                COUNT(DISTINCT outgoing.id) AS outgoing_count,
                COUNT(DISTINCT incoming.id) AS incoming_count,
                COUNT(DISTINCT outgoing.id) + COUNT(DISTINCT incoming.id) AS total_links
            FROM commonplace_notes vn
            LEFT JOIN commonplace_links outgoing ON outgoing.source_note_id = vn.id
            LEFT JOIN commonplace_links incoming ON incoming.target_note_id = vn.id
            WHERE vn.user_id = ?
            GROUP BY vn.id, vn.path, vn.title
            HAVING COUNT(DISTINCT outgoing.id) + COUNT(DISTINCT incoming.id) > 0
            ORDER BY total_links DESC
            LIMIT ?
        SQL, [$user->getAuthIdentifier(), $limit]);

        return collect($results)->map(fn ($r) => [
            'path' => $r->path,
            'title' => $r->title,
            'outgoing_links' => (int) $r->outgoing_count,
            'incoming_links' => (int) $r->incoming_count,
            'total_links' => (int) $r->total_links,
        ])->all();
    }

    public function getOrphanNotes(Authenticatable $user): Collection
    {
        return Note::accessibleBy($user)
            ->with('tags')
            ->whereDoesntHave('outgoingLinks')
            ->whereDoesntHave('incomingLinks')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function getSuggestedLinks(
        string $path,
        Authenticatable $user,
        int $limit = 10,
        SemanticSearchScope $scope = SemanticSearchScope::Mine,
    ): array {
        if (! $this->vectorDriver->isEnabled()) {
            return [];
        }

        $path = $this->normalizePath($path);

        $note = Note::where('path', $path)->firstOrFail();
        $this->checkAccess($note, $user);

        $embedding = $note->embedding;

        if ($embedding === null) {
            return [];
        }

        $existingTargetIds = $note->outgoingLinks()->pluck('target_note_id')->filter()->all();
        $existingSourceIds = $note->incomingLinks()->pluck('source_note_id')->filter()->all();
        $excludeIds = array_unique(array_merge($existingTargetIds, $existingSourceIds, [$note->id]));

        $baseQuery = $scope->apply(Note::query(), $user)
            ->whereNotIn('id', $excludeIds);

        $results = $this->vectorDriver->search($baseQuery, $embedding, $limit);

        return $results
            ->map(fn (Note $n) => [
                'path' => $n->path,
                'title' => $n->title,
                'distance' => round((float) $n->distance, 4),
            ])
            ->all();
    }

    private function checkAccess(Note $note, Authenticatable $user, string $level = 'read'): void
    {
        if ($note->user_id === $user->getAuthIdentifier()) {
            return;
        }

        if ($level === 'owner') {
            throw new AuthorizationException('Only the note owner can perform this action.');
        }

        $share = $note->shares()->where('user_id', $user->getAuthIdentifier())->first();

        if ($share) {
            if ($level === 'write' && $share->permission !== 'write') {
                throw new AuthorizationException('You do not have write access to this note.');
            }

            return;
        }

        if ($note->visibility === Visibility::Public && $level === 'read') {
            return;
        }

        throw new AuthorizationException('You do not have access to this note.');
    }

    private function syncTags(Note $note, array $tagNames): void
    {
        $tagIds = collect($tagNames)->map(function (string $name) {
            return Tag::firstOrCreate(['name' => trim($name)])->id;
        })->all();

        $note->tags()->sync($tagIds);
    }

    private function syncWikilinks(Note $note, string $content): void
    {
        $links = $this->wikilinkParser->extractLinks($content);

        $existingLinkIds = $note->outgoingLinks()->pluck('id')->all();
        $keepIds = [];

        foreach ($links as $link) {
            $resolved = $this->wikilinkParser->resolveTarget($link['target']);

            $vaultLink = Link::updateOrCreate(
                [
                    'source_note_id' => $note->id,
                    'target_path' => $link['target'],
                ],
                [
                    'target_note_id' => $resolved?->id,
                ]
            );

            $keepIds[] = $vaultLink->id;
        }

        $removeIds = array_diff($existingLinkIds, $keepIds);

        if (! empty($removeIds)) {
            Link::whereIn('id', $removeIds)->delete();
        }
    }

    private function replaceFirst(string $haystack, string $needle, string $replacement): string
    {
        $pos = strpos($haystack, $needle);

        if ($pos === false) {
            return $haystack;
        }

        return substr_replace($haystack, $replacement, $pos, strlen($needle));
    }

    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function validateVisibility(mixed $value): Visibility
    {
        if ($value instanceof Visibility) {
            return $value;
        }

        if (! is_string($value) || Visibility::tryFrom($value) === null) {
            throw new \InvalidArgumentException(
                'Invalid visibility value. Expected one of: '.Visibility::describe().'.'
            );
        }

        return Visibility::from($value);
    }

    private function normalizeContent(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }
}
