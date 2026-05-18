# Services

The `Commonplace` service is the PHP API for this package. Every controller, MCP tool, and Livewire component routes through it. If you want to create, query, or graph notes from your own code, start here.

**Source files:**

- [`src/Services/Commonplace.php`](../src/Services/Commonplace.php) — the main service (20 public methods + constructor)
- [`src/Facades/Commonplace.php`](../src/Facades/Commonplace.php) — facade accessor (`commonplace` alias)
- [`src/Services/MarkdownRenderer.php`](../src/Services/MarkdownRenderer.php)
- [`src/Services/WikilinkParser.php`](../src/Services/WikilinkParser.php)
- [`src/Services/FrontmatterParser.php`](../src/Services/FrontmatterParser.php)
- [`src/Services/JournalCalendar.php`](../src/Services/JournalCalendar.php)
- [`src/Services/NoteBrowser.php`](../src/Services/NoteBrowser.php)
- [`src/CommonplaceServiceProvider.php`](../src/CommonplaceServiceProvider.php) — container bindings

## Overview

`Commonplace` owns the write path (`createNote`, `updateNote`, `editNote`, `deleteNote`, `moveNote`) and the read/query path (`readNote`, `listNotes`, `searchNotes`, `semanticSearch`, plus the graph queries). Every write method takes an `Authenticatable` and runs it through an internal `checkAccess()` that honors visibility, shares, and ownership.

The service is registered as a singleton in [`CommonplaceServiceProvider::packageRegistered()`](../src/CommonplaceServiceProvider.php) and aliased to the container key `commonplace`. You can reach it three ways.

```php
// 1. Facade (most concise)
use NonConvexLabs\Commonplace\Facades\Commonplace;

$note = Commonplace::readNote('projects/vault-cli', auth()->user());
```

```php
// 2. Constructor injection (preferred inside controllers / jobs)
use NonConvexLabs\Commonplace\Services\Commonplace;

public function __construct(private readonly Commonplace $commonplace) {}

public function show(string $path)
{
    return $this->commonplace->readNote($path, request()->user());
}
```

```php
// 3. Resolve from the container
$commonplace = app(\NonConvexLabs\Commonplace\Services\Commonplace::class);
// or via the alias:
$commonplace = app('commonplace');
```

> [!NOTE]
> Every method that touches a single note throws `Illuminate\Database\Eloquent\ModelNotFoundException` if the path doesn't exist, and `Illuminate\Auth\Access\AuthorizationException` if the user lacks the required permission. See [`auth.md`](./auth.md) for the access model.

The eight HTTP endpoints in [`http-api.md`](./http-api.md) and every MCP tool in [`mcp-tools.md`](./mcp-tools.md) are thin wrappers over the methods below.

## Note CRUD

These five write methods own the note lifecycle. They accept paths in any slash style (`\\` is normalized to `/`) and accept `\r\n` / `\r` line endings (normalized to `\n` before storage).

| Method | Source | Returns |
|---|---|---|
| `createNote` | [Commonplace.php:92](../src/Services/Commonplace.php#L92) | `Note` with `tags`, `owner`, `outgoingLinks` loaded |
| `readNote` | [Commonplace.php:125](../src/Services/Commonplace.php#L125) | `Note` with `tags`, `owner` loaded |
| `updateNote` | [Commonplace.php:136](../src/Services/Commonplace.php#L136) | `Note` with `tags`, `owner`, `outgoingLinks` loaded |
| `editNote` | [Commonplace.php:219](../src/Services/Commonplace.php#L219) | `Note` (delegates to `updateNote`) |
| `deleteNote` | [Commonplace.php:262](../src/Services/Commonplace.php#L262) | `void` (writes a final `NoteVersion`, then deletes) |

### `createNote`

Creates a new note. YAML frontmatter in `$content` (recognized keys: `title`, `visibility`, `tags`) overrides the explicit arguments. The explicit values are just the fallback.

```php
public function createNote(
    string $path,
    string $content,
    array $tags,
    string $visibility,
    Authenticatable $owner,
): Note
```

```php
$note = Commonplace::createNote(
    path: 'projects/vault-cli',
    content: "---\ntitle: Vault CLI\ntags: [tooling, cli]\n---\n\nFirst draft of the [[design-doc]].",
    tags: ['draft'],            // overridden by frontmatter `tags`
    visibility: 'private',
    owner: auth()->user(),
);
```

The note's `content_hash` (sha256) is set and `indexed_at` is left null so the reindex queue picks it up. Wikilinks are extracted and persisted to `commonplace_links` via the internal `syncWikilinks()` step.

The `title` column is derived from frontmatter `title:` if present, otherwise from the basename of `$path` with dashes swapped for spaces and run through `Str::title()` ([Commonplace.php:105](../src/Services/Commonplace.php#L105)). There is no H1 parsing. So `journal/2026-05-16-notes` with no frontmatter becomes `2026 05 16 Notes`. See [model-relationships.md → Note](./model-relationships.md#note) for the column definition.

`updateNote` only rewrites `title` when the new content carries a frontmatter `title:`. Without it, the existing title is preserved — the basename fallback does not re-run on update. So removing a frontmatter `title:` from an existing note leaves the old title in place. Filed as a bug; see [#81](https://github.com/non-convex-labs/laravel-commonplace/issues/81).

`createNote` does **not** write a `NoteVersion` row. The live `Note` row is the original content; `NoteVersion` only captures content that has been *displaced* by an update or removed by a delete (see [model-relationships.md → NoteVersion](./model-relationships.md#noteversion)). Calling `getHistory()` on a never-updated note returns an empty collection — read the live note for the original content.

### `readNote`

Loads a note by path and enforces read access (owner, share with any permission, or `visibility=public`).

```php
public function readNote(string $path, Authenticatable $user): Note
```

```php
$note = Commonplace::readNote('references/clean-architecture', auth()->user());
echo $note->content;
```

### `updateNote`

Updates a note's content, path, visibility, and/or tags. Requires write access (owner or a share with `permission=write`).

```php
public function updateNote(string $path, array $data, Authenticatable $user): Note
```

Accepted keys in `$data`:

| Key | Effect |
|---|---|
| `content` | Replaces content. If the hash changes, a `NoteVersion` is written and `indexed_at` is cleared so the next reindex picks it up. Wikilinks are re-synced. Frontmatter overrides separately-passed `visibility` / `tags`. |
| `new_path` | Renames the note. Delegates to `moveNote` ([Commonplace.php:210-212](../src/Services/Commonplace.php#L210)), so referencing `[[wikilinks]]` get rewritten via the same async job. Owner-level check applies. |
| `visibility` | Sets `private` / `public`. Ignored if `content` frontmatter sets `visibility` (frontmatter wins). |
| `tags` | Replaces the tag set. Ignored if `content` frontmatter sets `tags`. When a replacement removes the last reference to a tag, the tag row in `commonplace_tags` is deleted; orphans don't accumulate. The prune runs in a second statement after the detach — under concurrent writes a tag row may briefly survive a detach if a parallel attach lands on it, or be re-created by `Tag::firstOrCreate` on the next attach. Last-writer-wins; no integrity loss. |

```php
$note = Commonplace::updateNote(
    'projects/vault-cli',
    [
        'content' => "# Vault CLI\n\nShip date moved to Q3.",
        'tags' => ['tooling', 'cli', 'shipping'],
    ],
    auth()->user(),
);
```

### `editNote`

A surgical `str_replace` over a note's content. It wraps `updateNote`, so the same versioning + reindex behavior applies.

```php
public function editNote(
    string $path,
    string $oldString,
    string $newString,
    bool $replaceAll,
    Authenticatable $user,
): Note
```

Throws `InvalidArgumentException` if `$oldString` is empty, identical to `$newString`, missing from the content, or matches more than once with `$replaceAll=false`.

```php
Commonplace::editNote(
    path: 'projects/vault-cli',
    oldString: 'Ship date moved to Q3.',
    newString: 'Shipped 2026-04-12.',
    replaceAll: false,
    user: auth()->user(),
);
```

### `deleteNote`

Soft-final delete. Requires owner. Shares can't delete, even with `write`. Writes one last `NoteVersion` before removing the row, so `getHistory()` keeps working after deletion.

```php
public function deleteNote(string $path, Authenticatable $user): void
```

```php
Commonplace::deleteNote('drafts/old-idea', auth()->user());
```

## Note discovery

Listing and filtering notes the user can see.

### `listNotes`

```php
public function listNotes(
    ?string $folder,
    ?string $tag,
    ?string $visibility,
    Authenticatable $user,
): Collection
```

Returns notes accessible to `$user` (owned, shared, or public), filtered by any combination of folder prefix, tag, and visibility. Sorted by `updated_at DESC`. All three filters accept `null` to skip them.

```php
$recent = Commonplace::listNotes(
    folder: 'projects',
    tag: 'shipping',
    visibility: null,
    user: auth()->user(),
);
```

Source: [Commonplace.php:281](../src/Services/Commonplace.php#L281). The folder filter uses the `inFolder` scope and the tag filter uses `withTag`.

### `searchNotes`

```php
public function searchNotes(string $query, Authenticatable $user): Collection
```

Lexical search across title + content using `ILIKE` on PostgreSQL and `LIKE` elsewhere. Title matches rank above content matches. Results are tie-broken by `updated_at DESC` and capped at 20. Queries shorter than 2 characters return an empty collection.

```php
$results = Commonplace::searchNotes('vault', auth()->user());
```

Source: [Commonplace.php:304](../src/Services/Commonplace.php#L304).

### `semanticSearch`

```php
public function semanticSearch(
    string $query,
    Authenticatable $user,
    SemanticSearchScope $scope = SemanticSearchScope::Accessible,
): Collection
```

Vector similarity search against the configured driver. Returns an empty collection when the vector driver is disabled. Capped at 20 hits.

The `$scope` controls which notes are eligible: `Mine` (owned only), `Public` (`visibility=public` only), or `Accessible` (owned + public + shared-with-me, the default).

```php
use NonConvexLabs\Commonplace\Enums\SemanticSearchScope;

$similar = Commonplace::semanticSearch(
    query: 'how do we handle queue retries?',
    user: auth()->user(),
    scope: SemanticSearchScope::Mine,
);
```

Source: [Commonplace.php:328](../src/Services/Commonplace.php#L328). Scope enum: [`src/Enums/SemanticSearchScope.php`](../src/Enums/SemanticSearchScope.php). See [`vector-storage.md`](./vector-storage.md) and [`embedding-drivers.md`](./embedding-drivers.md) for driver wiring.

### `lastSearchWarnings`

```php
/**
 * @return array<int, array{code: string, message: string, context: array<string, mixed>}>
 */
public function lastSearchWarnings(): array
```

Returns warnings emitted by the vector driver during the immediately-preceding `semanticSearch()` or `getSuggestedLinks()` call. Useful for surfacing cap-truncation, dimension mismatches, or driver-specific advisories to the UI. Empty for drivers that never warn (`pgvector`, `null`).

```php
$results = Commonplace::semanticSearch('queue retries', auth()->user());

foreach (Commonplace::lastSearchWarnings() as $warning) {
    logger()->warning($warning['code'], $warning);
}
```

Source: [Commonplace.php:355](../src/Services/Commonplace.php#L355).

## Graph queries

The wikilink graph is built from `commonplace_links` rows, populated automatically by `createNote` / `updateNote`. These six methods read that graph.

| Method | Source | Returns |
|---|---|---|
| `getBacklinks` | [Commonplace.php:360](../src/Services/Commonplace.php#L360) | `Collection<Note>` |
| `moveNote` | [Commonplace.php:375](../src/Services/Commonplace.php#L375) | `Note` |
| `getNeighborhood` | [Commonplace.php:437](../src/Services/Commonplace.php#L437) | `array` of `{path, title, depth, tags}` |
| `getShortestPath` | [Commonplace.php:493](../src/Services/Commonplace.php#L493) | `array` of `{path, title}` (or `null` if no path) |
| `getHubNotes` | [Commonplace.php:554](../src/Services/Commonplace.php#L554) | `array` of `{path, title, outgoing_links, incoming_links, total_links}` |
| `getOrphanNotes` | [Commonplace.php:583](../src/Services/Commonplace.php#L583) | `Collection<Note>` |
| `getSuggestedLinks` | [Commonplace.php:593](../src/Services/Commonplace.php#L593) | `array` of `{path, title, distance}` |

> [!WARNING]
> `getNeighborhood`, `getShortestPath`, and `getHubNotes` are implemented with PostgreSQL-specific recursive CTEs and `ARRAY[]` syntax. They won't run on MySQL or SQLite.

### `getBacklinks`

```php
public function getBacklinks(string $path, Authenticatable $user): Collection
```

Returns every accessible note whose `[[wikilinks]]` resolve to the given note.

```php
$backlinks = Commonplace::getBacklinks('references/clean-architecture', auth()->user());
```

### `moveNote`

```php
public function moveNote(string $fromPath, string $toPath, Authenticatable $user): Note
```

Owner-only. Throws `InvalidArgumentException` if a note already exists at `$toPath`. The path change runs inside a `DB::transaction`. On commit, [`UpdateWikilinksJob`](../src/Jobs/UpdateWikilinksJob.php) is dispatched ([Commonplace.php:402-410](../src/Services/Commonplace.php#L402)) to rewrite every referencing `[[wikilink]]`. Full path, alias form, and trailing-segment are all handled. Async by default. Set `commonplace.wikilinks.rewrite_sync = true` (env `COMMONPLACE_WIKILINKS_REWRITE_SYNC`) to dispatch via `dispatchSync()` for atomicity in tests or queue-less consumers. If the queue worker is down and a dispatch is lost, [`commonplace:relink`](commands.md#commonplacerelink) re-resolves the orphaned link rows.

```php
Commonplace::moveNote('drafts/idea', 'projects/idea', auth()->user());
```

### `getNeighborhood`

```php
public function getNeighborhood(string $path, int $maxHops, Authenticatable $user): array
```

BFS over the wikilink graph (undirected) from `$path` out to `$maxHops` hops. Each entry has `path`, `title`, `depth` (1..N), and `tags`. The starting note is excluded. Only notes the user can see are included.

```php
$neighborhood = Commonplace::getNeighborhood('topics/laravel', maxHops: 2, user: auth()->user());
// [
//   ['path' => 'topics/eloquent',   'title' => 'Eloquent',   'depth' => 1, 'tags' => [...]],
//   ['path' => 'projects/vault-cli','title' => 'Vault CLI',  'depth' => 2, 'tags' => [...]],
// ]
```

### `getShortestPath`

```php
public function getShortestPath(string $fromPath, string $toPath, Authenticatable $user): ?array
```

Recursive CTE BFS, capped at 10 hops. Returns the ordered path of notes, or `null` if disconnected.

```php
$path = Commonplace::getShortestPath(
    fromPath: 'topics/laravel',
    toPath: 'references/clean-architecture',
    user: auth()->user(),
);
// [['path' => 'topics/laravel', 'title' => 'Laravel'], ..., ['path' => 'references/clean-architecture', 'title' => 'Clean Architecture']]
```

### `getHubNotes`

```php
public function getHubNotes(Authenticatable $user, int $limit = 20): array
```

The user's own notes ranked by `outgoing_links + incoming_links`. Useful for finding central topics in a personal vault.

```php
$hubs = Commonplace::getHubNotes(auth()->user(), limit: 10);
```

### `getOrphanNotes`

```php
public function getOrphanNotes(Authenticatable $user): Collection
```

Notes accessible to `$user` with zero outgoing and zero incoming wikilinks. Sorted by `updated_at DESC`.

```php
$orphans = Commonplace::getOrphanNotes(auth()->user());
```

### `getSuggestedLinks`

```php
public function getSuggestedLinks(
    string $path,
    Authenticatable $user,
    int $limit = 10,
    SemanticSearchScope $scope = SemanticSearchScope::Mine,
): array
```

Vector similarity from the note's embedding to every other accessible note. Already-linked notes (incoming or outgoing) and the source note itself are excluded. Returns `[]` when the vector driver is disabled or the note has no stored embedding yet.

```php
$suggestions = Commonplace::getSuggestedLinks(
    path: 'projects/vault-cli',
    user: auth()->user(),
    limit: 5,
);
// [['path' => 'topics/queues', 'title' => 'Queues', 'distance' => 0.2143], ...]
```

`distance` follows the driver's convention (lower = more similar for cosine). See [`vector-storage.md`](./vector-storage.md).

## Versioning

### `getHistory`

```php
public function getHistory(string $path, Authenticatable $user): Collection
```

Returns the note's version history (newest first), with the `author` relationship eager-loaded on each `NoteVersion`. Works for deleted notes too. It falls back to looking up `note_versions` by `note_path` when no live note exists.

```php
$versions = Commonplace::getHistory('projects/vault-cli', auth()->user());

foreach ($versions as $v) {
    echo "Edited by {$v->author->name} — hash {$v->content_hash}\n";
}
```

Source: [Commonplace.php:416](../src/Services/Commonplace.php#L416). Versions are written automatically by `updateNote` (on content change) and `deleteNote` (final snapshot). See [`model-relationships.md`](./model-relationships.md) for the `NoteVersion` schema.

## Markdown extension hooks

Three methods let your application's service provider register CommonMark extensions without forking the package. The renderer builds its converter once on first use and freezes the extender registry. Register from `boot()`, not per request.

| Method | Source | Purpose |
|---|---|---|
| `extendMarkdown` | [Commonplace.php:58](../src/Services/Commonplace.php#L58) | Register a callback that receives the `Environment` after configured extensions are added. |
| `registeredMarkdownExtenders` | [Commonplace.php:76](../src/Services/Commonplace.php#L76) | `@internal` — used by `MarkdownRenderer`. Freezes the registry. |
| `clearMarkdownExtenders` | [Commonplace.php:86](../src/Services/Commonplace.php#L86) | `@internal` — used by tests and the Octane request lifecycle. |

```php
// In your AppServiceProvider::boot()
use NonConvexLabs\Commonplace\Facades\Commonplace;
use League\CommonMark\Environment\Environment;

Commonplace::extendMarkdown(function (Environment $env) {
    $env->addInlineParser(new MyMentionParser);
});
```

Calling `extendMarkdown()` after the renderer has been built throws `LogicException`. See the rationale in the method's doc block. For the full markdown pipeline, see [`markdown-rendering.md`](./markdown-rendering.md).

## Related services

These five services support `Commonplace` but they're independently useful. Resolve them from the container when you need just one of their capabilities.

### `MarkdownRenderer`

Renders note content (or a raw markdown string) to HTML, with `mermaid` fenced blocks lifted out and re-inserted post-render. Registered as a singleton, so the CommonMark `Environment` is built exactly once per request. The renderer reads the extender list registered via [`Commonplace::extendMarkdown`](#extendmarkdown) and integrates [Tempest Highlight](https://github.com/tempestphp/highlight) for syntax highlighting when `commonplace.markdown.highlight.enabled` is true.

```php
$renderer = app(\NonConvexLabs\Commonplace\Services\MarkdownRenderer::class);
$html = $renderer->renderNote($note->content);     // strips frontmatter first
$html = $renderer->render($rawMarkdown);           // no frontmatter stripping
```

Source: [`src/Services/MarkdownRenderer.php`](../src/Services/MarkdownRenderer.php). See [`markdown-rendering.md`](./markdown-rendering.md).

### `WikilinkParser`

Implements the [`WikilinkResolver`](../src/Contracts/WikilinkResolver.php) contract and is the package default binding for it. It both extracts `[[target]]` and `[[target|display]]` patterns from raw markdown (for DB sync) and resolves a target string to a `Note` (matching by exact path, then case-insensitive title, then basename).

```php
$parser = app(\NonConvexLabs\Commonplace\Services\WikilinkParser::class);
$links = $parser->extractLinks($content);       // [['target' => 'design-doc', 'display' => 'design-doc'], ...]
$note  = $parser->resolveTarget('design-doc');  // ?Note
```

Source: [`src/Services/WikilinkParser.php`](../src/Services/WikilinkParser.php). Rebind `WikilinkResolver::class` in your own provider to point wikilinks at different models or external URLs. See [`CommonplaceServiceProvider.php:87`](../src/CommonplaceServiceProvider.php#L87).

### `FrontmatterParser`

Parses YAML frontmatter from a note. Only three keys are recognized: `title`, `visibility`, `tags`. Everything else is ignored. Returns `['meta' => [...], 'body' => string]`. Malformed YAML degrades gracefully to `['meta' => [], 'body' => $original]`.

```php
$parser = app(\NonConvexLabs\Commonplace\Services\FrontmatterParser::class);
['meta' => $meta, 'body' => $body] = $parser->parse($note->content);
```

Source: [`src/Services/FrontmatterParser.php`](../src/Services/FrontmatterParser.php). The `Commonplace` service uses this internally. The parser's results override the explicit arguments passed to `createNote`/`updateNote`.

### `JournalCalendar`

Builds the data structure backing the `/commonplace/journal` calendar view: a month grid plus per-day note counts for notes under `journal/YYYY-MM-DD-*`. Used by the journal Livewire component but callable standalone for any custom view.

```php
$calendar = app(\NonConvexLabs\Commonplace\Services\JournalCalendar::class);
$data = $calendar->buildMonth(auth()->user(), year: 2026, month: 5, selectedDate: '2026-05-17');
// ['year' => 2026, 'monthName' => 'May', 'calendarDays' => [...], 'selectedNotes' => Collection<Note>, ...]
```

Source: [`src/Services/JournalCalendar.php`](../src/Services/JournalCalendar.php).

### `NoteBrowser`

Folder navigation primitive used by the note browser UI. Given a folder, it returns the immediate child notes plus a `name => count` map of immediate subfolders.

```php
$browser = app(\NonConvexLabs\Commonplace\Services\NoteBrowser::class);
$result = $browser->browse(auth()->user(), 'projects');
// ['notes' => Collection<Note>, 'subfolders' => ['vault-cli' => 4, 'wiki-rev' => 2]]
```

Source: [`src/Services/NoteBrowser.php`](../src/Services/NoteBrowser.php).

## Related reading

- [`mcp-tools.md`](./mcp-tools.md) — every MCP tool maps 1:1 to a method above
- [`http-api.md`](./http-api.md) — the eight REST endpoints, each wrapping a `Commonplace` method
- [`model-relationships.md`](./model-relationships.md) — `Note`, `NoteVersion`, `Link`, `Tag`, `Share` schema and relationships
- [`auth.md`](./auth.md) — visibility, sharing, and the access-check rules `Commonplace` enforces
- [`vector-storage.md`](./vector-storage.md) / [`embedding-drivers.md`](./embedding-drivers.md) — what `semanticSearch` and `getSuggestedLinks` depend on
- [`markdown-rendering.md`](./markdown-rendering.md) — what `extendMarkdown` plugs into
