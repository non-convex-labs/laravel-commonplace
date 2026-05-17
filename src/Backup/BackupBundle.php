<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Backup;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use NonConvexLabs\Commonplace\Models\Note;
use RuntimeException;

/**
 * The unit of work a BackupDestination receives. Captures the source
 * of notes plus a JSON manifest so destinations don't have to re-derive
 * metadata or re-hash content.
 *
 * Format (v1):
 *   - One markdown file per note, at the note's `path` (`.md` enforced).
 *   - manifest.json at the bundle root:
 *     {
 *       "version": "1.0",
 *       "generated_at": "ISO-8601",
 *       "note_count": N,
 *       "notes": [
 *         {"path": "notes/foo.md", "title": "Foo", "checksum": "sha256:..."},
 *         ...
 *       ]
 *     }
 *
 * Memory: `fromQuery()` streams notes via `lazyById()` so large vaults
 * don't load all content into memory at once. The manifest is built in
 * a first streaming pass that holds only metadata; `files()` re-streams
 * to surface content to destinations. Eager-eager construction via
 * `fromNotes()` is preserved for tests and small vaults.
 */
final class BackupBundle
{
    public const SCHEMA_VERSION = '1.0';

    public const MANIFEST_FILENAME = 'manifest.json';

    /** @var Collection<int, Note>|null Eager source. Null when streaming. */
    private readonly ?Collection $eagerNotes;

    /** @var Builder<Note>|null Lazy source. Null when eager. */
    private readonly ?Builder $lazyQuery;

    /** @var array<string, mixed> */
    public readonly array $manifest;

    private function __construct(?Collection $eagerNotes, ?Builder $lazyQuery, array $manifest)
    {
        $this->eagerNotes = $eagerNotes;
        $this->lazyQuery = $lazyQuery;
        $this->manifest = $manifest;
    }

    /**
     * @param  Collection<int, Note>  $notes
     */
    public static function fromNotes(Collection $notes): self
    {
        $entries = [];

        foreach ($notes as $note) {
            $entries[] = self::manifestEntryFor($note);
        }

        return new self($notes, null, self::manifestFor($entries));
    }

    /**
     * @param  Builder<Note>  $query
     */
    public static function fromQuery(Builder $query): self
    {
        $entries = [];

        // First streaming pass: build the manifest without holding
        // content. Uses lazyById to avoid offset pagination drift if
        // notes are concurrently inserted.
        foreach ($query->clone()->lazyById() as $note) {
            $entries[] = self::manifestEntryFor($note);
        }

        return new self(null, $query, self::manifestFor($entries));
    }

    /**
     * @return iterable<int, array{path: string, content: string}>
     */
    public function files(): iterable
    {
        if ($this->eagerNotes !== null) {
            foreach ($this->eagerNotes as $note) {
                yield [
                    'path' => self::safePathFor($note),
                    'content' => (string) $note->content,
                ];
            }

            return;
        }

        // Second streaming pass: emit content one note at a time so
        // even a 100k-note vault stays memory-bounded.
        yield from $this->streamFromQuery();
    }

    /**
     * @return Generator<int, array{path: string, content: string}>
     */
    private function streamFromQuery(): Generator
    {
        // $lazyQuery is non-null on this branch by construction.
        // The instanceof narrows the LazyCollection<int, Model> from
        // the generic Builder back to Note so property access is typed.
        foreach ($this->lazyQuery?->clone()->lazyById() ?? [] as $note) {
            if (! $note instanceof Note) {
                continue;
            }
            yield [
                'path' => self::safePathFor($note),
                'content' => (string) $note->content,
            ];
        }
    }

    public function manifestJson(): string
    {
        return json_encode($this->manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
    }

    public function isEmpty(): bool
    {
        return ($this->manifest['note_count'] ?? 0) === 0;
    }

    /**
     * @return array<int, string> The set of normalized note paths
     *                            included in this bundle, suitable for
     *                            prune-vs-keep diffs at a destination.
     */
    public function knownPaths(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['path'],
            $this->manifest['notes'] ?? [],
        );
    }

    /**
     * @return array{path: string, title: string, checksum: string}
     */
    private static function manifestEntryFor(Note $note): array
    {
        return [
            'path' => self::safePathFor($note),
            'title' => (string) $note->title,
            'checksum' => 'sha256:'.hash('sha256', (string) $note->content),
        ];
    }

    /**
     * @param  array<int, array{path: string, title: string, checksum: string}>  $entries
     * @return array<string, mixed>
     */
    private static function manifestFor(array $entries): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'note_count' => count($entries),
            'notes' => $entries,
        ];
    }

    /**
     * Normalize the note's path for backup storage. Throws on traversal
     * attempts (`..` segments, leading `/`) so a malicious or buggy
     * note path can't escape the configured backup root.
     */
    private static function safePathFor(Note $note): string
    {
        $path = (string) $note->path;

        $normalized = ltrim($path, '/');
        $normalized = str_ends_with($normalized, '.md') ? $normalized : $normalized.'.md';

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new RuntimeException(sprintf(
                    'Commonplace backup: refusing to back up note with traversal segment in path "%s". '
                    .'Fix the source note before retrying.',
                    $path,
                ));
            }
        }

        return $normalized;
    }
}
