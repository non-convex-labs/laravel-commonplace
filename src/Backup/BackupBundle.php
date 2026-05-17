<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Backup;

use Illuminate\Support\Collection;
use NonConvexLabs\Commonplace\Models\Note;

/**
 * The unit of work a BackupDestination receives. Captures the notes
 * being backed up plus a JSON manifest so destinations don't have to
 * re-derive metadata or re-hash content.
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
 */
final class BackupBundle
{
    public const SCHEMA_VERSION = '1.0';

    public const MANIFEST_FILENAME = 'manifest.json';

    /**
     * @param  Collection<int, Note>  $notes
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public readonly Collection $notes,
        public readonly array $manifest,
    ) {}

    public static function fromNotes(Collection $notes): self
    {
        $entries = [];

        foreach ($notes as $note) {
            $entries[] = [
                'path' => self::pathFor($note),
                'title' => (string) $note->title,
                'checksum' => 'sha256:'.hash('sha256', (string) $note->content),
            ];
        }

        return new self($notes, [
            'version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'note_count' => count($entries),
            'notes' => $entries,
        ]);
    }

    /**
     * @return iterable<int, array{path: string, content: string}>
     */
    public function files(): iterable
    {
        foreach ($this->notes as $note) {
            yield [
                'path' => self::pathFor($note),
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
        return $this->notes->isEmpty();
    }

    private static function pathFor(Note $note): string
    {
        $path = (string) $note->path;

        return str_ends_with($path, '.md') ? $path : $path.'.md';
    }
}
