<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NonConvexLabs\Commonplace\Contracts\EmbeddingProvider;
use NonConvexLabs\Commonplace\Models\Link;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\NoteVersion;
use NonConvexLabs\Commonplace\Models\Tag;

class Commonplace
{
    public function __construct(
        private readonly FrontmatterParser $frontmatterParser,
        private readonly WikilinkParser $wikilinkParser,
        private readonly EmbeddingProvider $embeddingProvider,
    ) {}

    public function createNote(
        string $path,
        string $content,
        array $tags,
        string $visibility,
        Authenticatable $owner,
    ): Note {
        $parsed = $this->frontmatterParser->parse($content);
        $meta = $parsed['meta'];

        $title = $meta['title'] ?? Str::title(str_replace('-', ' ', basename($path)));
        $visibility = $meta['visibility'] ?? $visibility;
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
        $note = Note::where('path', $path)->firstOrFail();

        $this->checkAccess($note, $user);

        return $note->load(['tags', 'owner']);
    }

    public function updateNote(string $path, array $data, Authenticatable $user): Note
    {
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
                $note->visibility = $meta['visibility'];
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
                $note->visibility = $data['visibility'];
            }
        }

        if (isset($data['new_path'])) {
            $note->path = $data['new_path'];
        }

        $note->save();

        if (isset($data['tags'])) {
            $this->syncTags($note, $data['tags']);
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
            $query->inFolder($folder);
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

    public function semanticSearch(string $query, Authenticatable $user): Collection
    {
        return $this->searchByVectorDistance(
            Note::accessibleBy($user)
                ->with(['tags', 'owner'])
                ->whereNotNull('embedding'),
            $this->embeddingProvider->embed($query),
            20,
        );
    }

    public function getBacklinks(string $path, Authenticatable $user): Collection
    {
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
        $note = Note::where('path', $fromPath)->firstOrFail();

        $this->checkAccess($note, $user, 'owner');

        if (Note::where('path', $toPath)->exists()) {
            throw new \InvalidArgumentException("A note already exists at path: {$toPath}");
        }

        $note->update(['path' => $toPath]);

        // TODO(chunk-7): dispatch UpdateWikilinksJob here once the job class lands.

        return $note->load(['tags', 'owner']);
    }

    public function getHistory(string $path, Authenticatable $user): Collection
    {
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

    public function getSuggestedLinks(string $path, Authenticatable $user, int $limit = 10): array
    {
        $note = Note::where('path', $path)->firstOrFail();
        $this->checkAccess($note, $user);

        if (! $note->embedding) {
            return [];
        }

        $existingTargetIds = $note->outgoingLinks()->pluck('target_note_id')->filter()->all();
        $existingSourceIds = $note->incomingLinks()->pluck('source_note_id')->filter()->all();
        $excludeIds = array_unique(array_merge($existingTargetIds, $existingSourceIds, [$note->id]));

        $results = $this->searchByVectorDistance(
            Note::accessibleBy($user)
                ->whereNotNull('embedding')
                ->whereNotIn('id', $excludeIds),
            $note->embedding,
            $limit,
        );

        return $results
            ->map(fn (Note $n) => [
                'path' => $n->path,
                'title' => $n->title,
                'distance' => round($n->distance, 4),
            ])
            ->all();
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function searchByVectorDistance($baseQuery, array $vector, int $limit): Collection
    {
        return $baseQuery
            ->selectVectorDistance('embedding', $vector, 'distance')
            ->orderByVectorDistance('embedding', $vector)
            ->limit($limit)
            ->get();
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

        if ($note->visibility === 'public' && $level === 'read') {
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
}
