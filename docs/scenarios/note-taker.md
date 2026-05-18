# Scenarios — Note-taker

The vault owner. Reads, writes, organizes, and searches their own notes. Drives the package primarily through the web UI ([http-api.md](../http-api.md)) and, for power use, the `Commonplace` facade ([services.md](../services.md)). Authenticates via whatever guard the host app already runs — `web,auth` by default ([auth.md](../auth.md)).

The scenarios below assume:

- A vanilla Laravel 13 install with the package installed and migrated.
- One authenticated user, `$alice`, with no notes yet unless otherwise stated.
- `COMMONPLACE_VECTOR_DRIVER=in_php_cosine` and a working embedding driver, unless noted.
- Queue worker running (some scenarios depend on `UpdateWikilinksJob` and the reindex job).

Where a scenario needs Postgres-specific behavior (graph queries, pgvector) it says so up front.

---

## CRUD

### S-NOTE-01 — Create a private note from the web UI

**Intent.** Alice opens the vault and creates her first note. It lands private to her, with tags attached, and is reachable from the index.

**Preconditions.** Alice authenticated. No existing note at `projects/launch`.

**Steps.**
1. `GET /commonplace/create` — returns the new-note form ([NoteController](../http-api.md#notecontroller)).
2. `POST /commonplace` with `path=projects/launch`, `content="# Launch plan\n\nFirst pass."`, `tags="alpha, q2"`, `visibility=private`.
3. `GET /commonplace`.

**Expected.**
- 302 redirect to `/commonplace/projects/launch`.
- One `commonplace_notes` row with `path=projects/launch`, `user_id=alice.id`, `visibility=private`, non-null `content_hash`, `indexed_at=NULL`.
- Two `commonplace_tags` rows (`alpha`, `q2`) with pivot rows attaching them to the note.
- The index lists the note in its recent column.

**Verify with.**
```php
$n = Note::where('path', 'projects/launch')->firstOrFail();
[$n->user_id, $n->visibility->value, $n->indexed_at, $n->tags->pluck('name')->all()];
// [1, 'private', null, ['alpha', 'q2']]
```

**Source.** [http-api.md → POST / store](../http-api.md#post--store--validation), [services.md → createNote](../services.md#createnote).

---

### S-NOTE-02 — Frontmatter overrides explicit arguments

**Intent.** Frontmatter is the source of truth for `title`, `visibility`, `tags`. Explicit args are only the fallback.

**Preconditions.** Alice authenticated.

**Steps.** Call `Commonplace::createNote()` with:

```php
Commonplace::createNote(
    path: 'projects/vault-cli',
    content: "---\ntitle: Vault CLI\nvisibility: public\ntags: [tooling, cli]\n---\n\nFirst draft.",
    tags: ['draft'],
    visibility: 'private',
    owner: $alice,
);
```

**Expected.**
- Stored note has `title="Vault CLI"`, `visibility=public`, tags `["tooling","cli"]`.
- The explicit `["draft"]` and `private` are discarded.
- `content_hash` matches a sha256 of the full content including frontmatter.

**Verify with.**
```php
$n = Note::where('path','projects/vault-cli')->first();
[$n->title, $n->visibility->value, $n->tags->pluck('name')->all()];
// ['Vault CLI', 'public', ['tooling','cli']]
```

**Source.** [services.md → createNote](../services.md#createnote), [FrontmatterParser](../services.md#frontmatterparser).

---

### S-NOTE-03 — Read a note requires visibility access

**Intent.** Alice can read her own note. A non-owner's read is rejected, with the error class depending on the surface: the service layer is informative; the MCP / HTTP boundaries are not.

**Preconditions.** Alice owns `projects/launch`. A second user `$bob` is authenticated.

**Steps.**
1. As Alice: `Commonplace::readNote('projects/launch', $alice)`.
2. As Bob: `Commonplace::readNote('projects/launch', $bob)`.
3. As Bob: `Commonplace::readNote('never-existed', $bob)`.

**Expected.**
- Step 1 returns the `Note` with `tags` and `owner` eager-loaded.
- Step 2 throws `Illuminate\Auth\Access\AuthorizationException` ("You do not have access to this note.") — the service distinguishes "exists but you can't see it" from "doesn't exist" because in-process callers are trusted.
- Step 3 throws `Illuminate\Database\Eloquent\ModelNotFoundException` — different class than step 2.
- The MCP and HTTP surfaces collapse steps 2 and 3 into a single response (`Note not found.` for MCP read tools; 404 for `GET /commonplace/{path}`) so an attacker can't enumerate paths. See [S-AI-07](ai-agent.md#s-ai-07--read-note-tool-returns-full-content-absent-or-inaccessible-notes-both-say-note-not-found) for the MCP boundary.

**Verify with.** Tinker the three calls and assert exception classes. The HTTP equivalent is `GET /commonplace/projects/launch` as each user; the second returns 404 not 403.

**Source.** [services.md → readNote](../services.md#readnote), [mcp-tools.md → Visibility model](../mcp-tools.md#visibility-model).

---

### S-NOTE-04 — Update writes a NoteVersion and clears indexed_at

**Intent.** Editing a note's body produces a new entry in version history, and queues it for re-embedding on the next reindex.

**Preconditions.** Alice owns `projects/launch`. `createNote` does not write a `NoteVersion` (versions track *displaced* content, see [model-relationships.md → NoteVersion](../model-relationships.md#noteversion)), so `commonplace_note_versions` has zero rows for this note going in.

**Steps.**
1. `Commonplace::updateNote('projects/launch', ['content' => "# Launch plan v2\n\nNew direction."], $alice);`

**Expected.**
- One new `commonplace_note_versions` row, with the **prior** content snapshot and `content_hash`.
- The `commonplace_notes` row's `content` and `content_hash` are updated.
- `indexed_at` is reset to `NULL`.
- Wikilinks are re-extracted into `commonplace_links`.

**Verify with.**
```php
$n = Note::where('path','projects/launch')->first();
[$n->versions()->count(), $n->indexed_at]; // [1, null]
```

**Source.** [services.md → updateNote](../services.md#updatenote), [model-relationships.md → NoteVersion](../model-relationships.md#noteversion).

---

### S-NOTE-05 — Surgical edit replaces one string

**Intent.** Small in-place edits don't require sending the whole document. `editNote` does a single string replacement under the same versioning rules as `updateNote`.

**Preconditions.** Note exists at `projects/launch` containing the text `Ship date moved to Q3.` exactly once.

**Steps.**
1. `Commonplace::editNote('projects/launch', 'Ship date moved to Q3.', 'Shipped 2026-04-12.', replaceAll: false, user: $alice);`

**Expected.**
- The note's content has the replacement applied.
- A new `NoteVersion` row is written.
- `indexed_at` reset to `NULL`.

**Verify with.**
```php
Note::where('path','projects/launch')->first()->content;
// contains 'Shipped 2026-04-12.' and not 'Ship date moved to Q3.'
```

**Source.** [services.md → editNote](../services.md#editnote).

---

### S-NOTE-06 — editNote refuses ambiguous matches

**Intent.** `editNote` is whitespace-sensitive and unambiguous-by-default. Two matches with `replaceAll=false` raises an error instead of guessing which to change.

**Preconditions.** Note at `projects/launch` contains the string `TODO` twice.

**Steps.**
1. `Commonplace::editNote('projects/launch', 'TODO', 'DONE', replaceAll: false, user: $alice);`
2. `Commonplace::editNote('projects/launch', 'TODO', 'DONE', replaceAll: true,  user: $alice);`

**Expected.**
- Step 1 throws `InvalidArgumentException`. No version row is added. No content change.
- Step 2 replaces both occurrences and writes one version row.

**Verify with.** Tinker — assert the exception on step 1; assert the content + versions count after step 2.

**Source.** [services.md → editNote](../services.md#editnote), [mcp-tools.md → edit-note-tool](../mcp-tools.md#edit-note-tool).

---

### S-NOTE-07 — Delete writes a final version, then removes the row

**Intent.** Deletion is final for the live row, but history survives. `getHistory` still works against the deleted path.

**Preconditions.** Alice owns `drafts/old-idea` with two `NoteVersion` rows already.

**Steps.**
1. `Commonplace::deleteNote('drafts/old-idea', $alice);`
2. `Commonplace::getHistory('drafts/old-idea', $alice);`

**Expected.**
- The `commonplace_notes` row is gone.
- `commonplace_note_versions` now has three rows for this path (two prior + one final snapshot).
- `getHistory` returns all three, newest first, with `author` eager-loaded.

**Verify with.**
```php
Note::where('path','drafts/old-idea')->exists();           // false
NoteVersion::where('note_path','drafts/old-idea')->count(); // 3
```

**Source.** [services.md → deleteNote](../services.md#deletenote), [services.md → getHistory](../services.md#gethistory).

---

### S-NOTE-08 — Move/rename rewrites referring wikilinks async

**Intent.** Renaming a note doesn't break inbound links. An async job rewrites the `[[wikilink]]` text in every referring note, preserving any `|alias` suffix.

**Preconditions.** Two notes exist: `drafts/idea` (source) and `references/index` containing `Discussed in [[drafts/idea|early idea]].`. Queue worker running. `COMMONPLACE_WIKILINKS_REWRITE_SYNC=false`.

**Steps.**
1. `Commonplace::moveNote('drafts/idea', 'projects/idea', $alice);`
2. Wait for the queue to drain.
3. Read `references/index`.

**Expected.**
- `commonplace_notes` now has `path=projects/idea` (not `drafts/idea`).
- `references/index` content reads `Discussed in [[projects/idea|early idea]].`.
- Corresponding `commonplace_links` row has its `target_path` updated.

**Verify with.**
```php
Note::where('path','projects/idea')->exists();              // true
Str::contains(Note::where('path','references/index')->first()->content, '[[projects/idea|early idea]]'); // true
```

For tests, set `COMMONPLACE_WIKILINKS_REWRITE_SYNC=true` so step 2 isn't needed.

**Source.** [services.md → moveNote](../services.md#movenote), [mcp-tools.md → move-tool](../mcp-tools.md#move-tool).

---

### S-NOTE-09 — Move into an occupied path is rejected

**Intent.** Move never silently clobbers. A collision with an existing note path raises `InvalidArgumentException` before any state changes.

**Preconditions.** Two notes: `drafts/idea` and `projects/idea`.

**Steps.**
1. `Commonplace::moveNote('drafts/idea', 'projects/idea', $alice);`

**Expected.**
- `InvalidArgumentException` raised.
- Neither note's `path` changes.
- No `UpdateWikilinksJob` dispatched.

**Verify with.** Tinker — assert exception; assert both rows still at original paths.

**Source.** [services.md → moveNote](../services.md#movenote).

---

## Discovery

### S-NOTE-10 — List notes with folder + tag filters

**Intent.** The browse view filters by folder prefix, tag, and visibility independently or in combination, ordered newest-edit-first.

**Preconditions.** Three notes owned by Alice:
- `projects/launch` tagged `q2`
- `projects/backlog` tagged `q2`
- `personal/journal` tagged `q2`

**Steps.**
1. `Commonplace::listNotes(folder: 'projects', tag: 'q2', visibility: null, user: $alice);`
2. `Commonplace::listNotes(folder: null, tag: 'q2', visibility: null, user: $alice);`

**Expected.**
- Step 1 returns exactly `[projects/launch, projects/backlog]` ordered by `updated_at DESC`.
- Step 2 returns all three.
- Visibility filter accepts `private` / `public` and `null` to skip.

**Verify with.** Tinker — assert paths in returned collection.

**Source.** [services.md → listNotes](../services.md#listnotes).

---

### S-NOTE-11 — Lexical search ranks title hits above body hits, caps at 20

**Intent.** Substring search returns title hits before body hits, tie-broken by `updated_at DESC`. Capped at 20. Queries shorter than 2 chars return empty.

**Preconditions.** Twenty-one notes owned by Alice, one with `vault` in its title and twenty with `vault` only in their bodies.

**Steps.**
1. `Commonplace::searchNotes('vault', $alice);`
2. `Commonplace::searchNotes('v', $alice);`

**Expected.**
- Step 1 returns 20 notes, with the title-hit first.
- Step 2 returns an empty collection.

**Verify with.** Tinker — assert `count()`, assert first result's path is the title-hit one.

**Source.** [services.md → searchNotes](../services.md#searchnotes), [mcp-tools.md → search-tool](../mcp-tools.md#search-tool).

---

### S-NOTE-12 — Semantic search returns ranked hits with the configured scope

**Intent.** Vector search returns up to 20 hits, ordered by ascending distance. The `scope` argument controls which notes are eligible.

**Preconditions.** Embedding driver enabled (`null` won't work for this scenario — use `voyage` or `openai`). Vector storage `in_php_cosine` or `pgvector`. Several notes embedded.

**Steps.**
1. `Commonplace::semanticSearch('queue retries', $alice, SemanticSearchScope::Accessible);`
2. `Commonplace::semanticSearch('queue retries', $alice, SemanticSearchScope::Mine);`

**Expected.**
- Step 1 returns up to 20 notes the user can see (owned + public + shared), ordered by ascending distance.
- Step 2 returns only Alice-owned notes.
- The `null` vector driver returns an empty collection regardless of input.

**Verify with.** Tinker — assert each result has a non-null embedding and an order consistent with cosine distance.

**Source.** [services.md → semanticSearch](../services.md#semanticsearch), [vector-storage.md](../vector-storage.md).

---

### S-NOTE-13 — `lastSearchWarnings` surfaces driver advisories

**Intent.** After a semantic search, callers can read driver-emitted warnings (candidate-cap truncation, dimension mismatch) for the immediately-preceding call.

**Preconditions.** `in_php_cosine` driver. More than 20,000 notes embedded so the hard cap fires (or at least more than `COMMONPLACE_INPHP_MAX_CANDIDATES`).

**Steps.**
1. `Commonplace::semanticSearch('anything', $alice);`
2. `Commonplace::lastSearchWarnings();`

**Expected.**
- Step 2 returns an array of `{code, message, context}` entries.
- `pgvector` and `null` drivers always return `[]`.

**Verify with.** Tinker; or surface warnings to the UI when the search bar reports partial results.

**Source.** [services.md → lastSearchWarnings](../services.md#lastsearchwarnings).

---

## Graph

### S-NOTE-14 — Creating a note with `[[wikilinks]]` populates `commonplace_links`

**Intent.** Wikilinks are first-class graph edges. `createNote` parses them out and stores one row per link, with `target_note_id` set when the target exists.

**Preconditions.** Note exists at `references/clean-architecture`.

**Steps.**
1. `Commonplace::createNote('projects/vault-cli', 'See [[references/clean-architecture]] and [[design-doc]].', [], 'private', $alice);`

**Expected.**
- Two `commonplace_links` rows from the new note:
  - one with `target_path='references/clean-architecture'`, `target_note_id` set.
  - one with `target_path='design-doc'`, `target_note_id` NULL (dangling — no matching note exists).

**Verify with.**
```php
Link::where('source_note_id', $note->id)->get()->pluck('target_note_id', 'target_path');
// ['references/clean-architecture' => 5, 'design-doc' => null]
```

**Source.** [services.md → createNote](../services.md#createnote), [model-relationships.md → Link](../model-relationships.md#link).

---

### S-NOTE-15 — Backlinks query is scoped to the caller's visibility

**Intent.** `getBacklinks` returns every note that wikilinks to the target — but only those the caller can see.

**Preconditions.**
- Alice owns `references/clean-architecture` and `projects/alpha` (the latter links to the former).
- Bob owns `private/bob-notes` (links to the former) with `visibility=private`.

**Steps.**
1. `Commonplace::getBacklinks('references/clean-architecture', $alice);`
2. `Commonplace::getBacklinks('references/clean-architecture', $bob);`

**Expected.**
- Step 1 returns `[projects/alpha]`. Alice can't see Bob's private note.
- Step 2 returns `[private/bob-notes]`. Bob can't see Alice's note unless it's public or shared with him.

**Verify with.** Tinker — assert returned paths per caller.

**Source.** [services.md → getBacklinks](../services.md#getbacklinks).

---

### S-NOTE-16 — Orphan notes are unlinked in both directions

**Intent.** Orphans are accessible notes with zero outgoing and zero incoming wikilinks — connection candidates.

**Preconditions.** Three notes: A links to B; C is alone.

**Steps.**
1. `Commonplace::getOrphanNotes($alice);`

**Expected.**
- Returns `[C]`. A and B both have at least one link in or out.

**Verify with.** Tinker — assert collection paths.

**Source.** [services.md → getOrphanNotes](../services.md#getorphannotes).

---

### S-NOTE-17 — Suggested links default to `Mine` scope and exclude already-linked notes

**Intent.** Suggestions are vector-similar notes that aren't already linked from or to the source. Default scope is `Mine`, not `Accessible`, to avoid suggesting links the caller can't reliably resolve later.

**Preconditions.** Embedding + vector driver enabled. Alice owns several notes plus one shared-with-her note `friend/shared-note`.

**Steps.**
1. `Commonplace::getSuggestedLinks('projects/vault-cli', $alice);` (default scope, default limit 10)
2. `Commonplace::getSuggestedLinks('projects/vault-cli', $alice, scope: SemanticSearchScope::Accessible);`

**Expected.**
- Step 1 returns only Alice-owned candidates. `friend/shared-note` is excluded.
- Step 2 may include `friend/shared-note`.
- Both results exclude `projects/vault-cli` itself and any already-linked notes.
- The `null` vector driver returns `[]`.

**Verify with.** Tinker — assert returned `path` values and confirm none are already in `commonplace_links` for this source.

**Source.** [services.md → getSuggestedLinks](../services.md#getsuggestedlinks), [mcp-tools.md → suggested-links-tool](../mcp-tools.md#suggested-links-tool).

---

### S-NOTE-18 — Postgres-only: neighborhood, shortest path, hub notes

**Intent.** Three graph queries (`getNeighborhood`, `getShortestPath`, `getHubNotes`) use Postgres recursive CTEs + array operators. They don't run on SQLite or MySQL.

**Preconditions.** Postgres connection. A graph of notes connected by `[[wikilinks]]`.

**Steps.**
1. `Commonplace::getNeighborhood('topics/laravel', maxHops: 2, user: $alice);`
2. `Commonplace::getShortestPath('topics/laravel', 'references/clean-architecture', $alice);`
3. `Commonplace::getHubNotes($alice, limit: 10);`

**Expected.**
- (1) returns `{path, title, depth, tags}` entries for every visible note within 2 hops, excluding the start. Undirected traversal.
- (2) returns an ordered array of `{path, title}` (or `null` if disconnected within 10 hops).
- (3) returns Alice-owned notes only (not shared / public), ranked by `outgoing + incoming` link counts.

**Verify with.** Run each call against a seeded graph; assert ordering and depth values.

**Source.** [services.md → Graph queries](../services.md#graph-queries).

---

## Versioning

### S-NOTE-19 — Version history works after delete

**Intent.** `getHistory` returns versions even when the live note row is gone. Useful for recovering the last known content of a deleted path.

**Preconditions.** Alice deleted `drafts/old-idea` (S-NOTE-07). Three version rows exist for the path.

**Steps.**
1. `Commonplace::getHistory('drafts/old-idea', $alice);`

**Expected.**
- Returns three `NoteVersion` rows newest-first.
- Each has `author` eager-loaded (the user who wrote it, or `null` if that user was deleted).

**Verify with.** Tinker.

**Source.** [services.md → getHistory](../services.md#gethistory), [model-relationships.md → NoteVersion](../model-relationships.md#noteversion).

---

## Web UI

### S-NOTE-20 — `GET /commonplace/{path}` falls through to journal and folder browser

**Intent.** A single catch-all `GET /{path}` route serves three things in order: a note view, the journal calendar for `journal/*`, or a folder browser for anything else.

**Preconditions.**
- A note at `projects/launch`.
- No note at `journal` or `projects` (the folder paths).
- Today is 2026-05-17.

**Steps.**
1. `GET /commonplace/projects/launch`.
2. `GET /commonplace/journal?year=2026&month=5&date=2026-05-17`.
3. `GET /commonplace/projects`.

**Expected.**
- (1) renders the note view (HTML).
- (2) renders the journal calendar with day counts from notes under `journal/YYYY-MM-DD-*`.
- (3) renders the folder browser listing notes under `projects/` plus immediate subfolders.

**Verify with.** Browser, or HTTP client asserting on the rendered template.

**Source.** [http-api.md → GET /{path} fallback chain](../http-api.md#get-path-fallback-chain), [services.md → JournalCalendar](../services.md#journalcalendar), [services.md → NoteBrowser](../services.md#notebrowser).

---

### S-NOTE-21 — Raw view and download share content but differ on Content-Disposition

**Intent.** Two ways to get the raw markdown out — inline for copy-paste, or as an attachment for save-to-disk.

**Preconditions.** A note at `projects/launch`.

**Steps.**
1. `GET /commonplace/raw/projects/launch`.
2. `GET /commonplace/download/projects/launch`.

**Expected.**
- (1) returns `text/plain; charset=utf-8` with the raw content (header included).
- (2) returns the same content with `Content-Disposition: attachment; filename="projects/launch.md"` (or equivalent).

**Verify with.** `curl -I` each URL.

**Source.** [http-api.md](../http-api.md), `NoteController::showRaw` / `downloadRaw`.

---

### S-NOTE-22 — Graph view + `/api/graph` JSON

**Intent.** A force-directed graph view shows every accessible note as a node and every link with both ends in that set as an edge. The JSON endpoint feeds the view.

**Preconditions.** A handful of linked notes owned by Alice plus one private note from Bob.

**Steps.**
1. `GET /commonplace/api/graph` as Alice.
2. `GET /commonplace/graph` as Alice.

**Expected.**
- (1) returns `{nodes: [...], edges: [...]}` covering only Alice-accessible notes. Bob's private note is absent.
- (2) renders the graph view that consumes this JSON.

**Verify with.** Hit the JSON endpoint, assert node count equals accessible note count.

**Source.** [http-api.md → GraphController](../http-api.md#graphcontroller).

---

### S-NOTE-23 — In-page search autocomplete uses `/api/search`

**Intent.** The header search bar's typeahead returns lightweight result envelopes. Full-text only; no semantic toggle.

**Preconditions.** Several notes seeded.

**Steps.**
1. `GET /commonplace/api/search?q=vault`.
2. `GET /commonplace/api/search?q=v`.

**Expected.**
- (1) returns up to 20 `{path, title, excerpt, url, updated_at, tags}` objects.
- (2) returns `[]` (short query).

**Verify with.** `curl` and assert JSON shape.

**Source.** [http-api.md → SearchController](../http-api.md#searchcontroller).

---

## Markdown

### S-NOTE-24 — Wikilink resolver fallback shows a broken-link affordance

**Intent.** A `[[target]]` that doesn't resolve renders as a styled broken-link anchor pointing at the canonical create URL, so the reader can promote it to a real note.

**Preconditions.** Note containing `[[nonexistent]]`. Default `WikilinkParser` resolver. Route prefix `commonplace`.

**Steps.**
1. Render the note (HTML).

**Expected.**
- The `[[nonexistent]]` text becomes `<a class="vault-link vault-link-broken" href="/commonplace/nonexistent">nonexistent</a>` (or similar) — the broken class is set, the href falls back to the route prefix joined to the raw target.

**Verify with.** Inspect rendered HTML.

**Source.** [markdown-rendering.md → Swapping the wikilink resolver](../markdown-rendering.md#swapping-the-wikilink-resolver).

---

### S-NOTE-25 — Mermaid fences round-trip past the CommonMark pipeline

**Intent.** Mermaid fenced blocks are lifted out before rendering and re-inserted afterward, so CommonMark doesn't strip or mangle them.

**Preconditions.** Note containing a ```` ```mermaid ```` fenced block.

**Steps.**
1. Render the note.

**Expected.**
- The mermaid block survives the render and is present in the output HTML for a client-side renderer to pick up.
- Surrounding markdown is rendered normally.

**Verify with.** Assert the rendered HTML contains the literal mermaid source inside a `<pre class="mermaid">` (or equivalent) wrapper.

**Source.** [services.md → MarkdownRenderer](../services.md#markdownrenderer).

---

## Edges and failure modes

### S-NOTE-26 — `XSS hardening: <script>` in user content is stripped

**Intent.** The CommonMark `DisallowedRawHtmlExtension` plus `allow_unsafe_links => false` keep `<script>` and `javascript:` URLs out of the rendered output.

**Preconditions.** Note containing `<script>alert(1)</script>` and `[click](javascript:alert(1))`.

**Steps.**
1. Render.

**Expected.**
- `<script>...</script>` is removed from the output.
- The `javascript:` link's `href` is dropped.

**Verify with.** Assert neither pattern is present in the rendered HTML.

**Source.** [markdown-rendering.md → XSS hardening](../markdown-rendering.md#xss-hardening).

---

### S-NOTE-27 — Path normalization on create

**Intent.** Inbound paths normalize Windows-style slashes to forward slashes before storage. The stored representation is canonical.

**Preconditions.** Alice authenticated.

**Steps.**
1. `Commonplace::createNote('projects\\windows-style\\note', '...', [], 'private', $alice);`

**Expected.**
- The row's `path` is `projects/windows-style/note`.

**Verify with.** Tinker.

**Source.** [services.md → Note CRUD](../services.md#note-crud).

---

### S-NOTE-28 — Line-ending normalization on store

**Intent.** Content with `\r\n` or `\r` line endings is normalized to `\n` before storage, so hashes are stable across platforms.

**Preconditions.** Alice authenticated.

**Steps.**
1. `Commonplace::createNote('a', "line1\r\nline2\r\n", [], 'private', $alice);`

**Expected.**
- Stored `content` equals `"line1\nline2\n"`.
- `content_hash` matches `hash('sha256', "line1\nline2\n")`.

**Verify with.** Tinker — read the row, assert content and hash.

**Source.** [services.md → Note CRUD](../services.md#note-crud).

---

### S-NOTE-29 — View version history via the web UI

**Intent.** A note-taker editing in the browser can inspect prior revisions: who changed it, when, and what the content looked like at the time. The same data the MCP `history-tool` exposes is reachable from the note view. The web view lives at `/commonplace/history/{path}` (route name `commonplace.history`) with per-revision detail at `/commonplace/history/{path}/{version}` (`commonplace.historyVersion`).

**Preconditions.** Alice owns `projects/launch` and has updated it at least twice (so the history is non-trivial). At least one update has a `changed_by` user other than Alice would be ideal but not required.

**Steps.**
1. `GET /commonplace/projects/launch` — render the note.
2. Click a "History" affordance on the action bar.
3. The history page renders.
4. Click a revision row.
5. The revision detail renders (read-only).

**Expected.**
- Step 2: a "History" link appears in the action bar alongside Edit / View markdown / Download markdown / Delete.
- Step 3: a table or list of `commonplace_note_versions` rows for this path, newest-first, with `created_at`, `changed_by.name` (or `null`/`(deleted user)` as a fallback), and a short-form `content_hash`.
- The history page also works for **deleted** notes (the `/commonplace/{path}` show route 404s, but `/commonplace/history/{path}` should still render the captured snapshots — paralleling `Commonplace::getHistory`'s deleted-note fallback).
- Step 5: the revision's stored markdown rendered through the same CommonMark pipeline as the live note view. No edit controls; no delete; no commit-to-restore.

**Verify with.** Browser, after the fix lands.

**Source.** [services.md → getHistory](../services.md#gethistory), [mcp-tools.md → history-tool](../mcp-tools.md#history-tool), [model-relationships.md → NoteVersion](../model-relationships.md#noteversion).

---

### S-NOTE-30 — Note-view UI links resolve to the raw and download endpoints

**Intent.** The action bar on the authenticated note view has "View markdown" and "Download markdown" affordances. Their `href`s must resolve to the endpoints [S-NOTE-21](#s-note-21--raw-view-and-download-share-content-but-differ-on-content-disposition) documents, and clicking each must deliver the documented response. The endpoint contract and the link contract are asserted separately because either can drift without the other noticing — see [#68](https://github.com/non-convex-labs/laravel-commonplace/issues/68) for the canonical failure mode.

**Preconditions.** Alice authenticated, viewing her own note at `projects/launch`.

**Steps.**
1. Render `GET /commonplace/projects/launch` (the note-show page).
2. Inspect the rendered HTML for the two action-bar links.
3. Follow each `href`.

**Expected.**
- Step 2: a "View markdown" anchor with `href="/commonplace/raw/projects/launch"` (the `commonplace.showRaw` route) appears in the action bar.
- Step 2: a "Download markdown" anchor with `href="/commonplace/download/projects/launch"` (the `commonplace.downloadRaw` route) appears in the action bar.
- Step 3: following each `href` delivers the response S-NOTE-21 documents — `text/plain` for raw, `Content-Disposition: attachment` for download.

**Verify with.** Render the view and assert on the two `href` attributes; then `curl -I` each rendered URL.

**Source.** [http-api.md](../http-api.md), `resources/views/show.blade.php`, `NoteController::showRaw` / `downloadRaw`.

---

### S-NOTE-31 — Deleting a note prunes orphaned tags

**Intent.** Tags exist as their own rows in `commonplace_tags`. When the last note carrying a tag is deleted, that tag row is removed — the tag dropdown / index doesn't carry around stale "ghost" labels that don't apply to any visible note.

**Preconditions.** Alice owns two notes:
- `personal/notes-A` tagged `solo` and `shared-tag`.
- `personal/notes-B` tagged `shared-tag`.

So `commonplace_tags` has rows for `solo` and `shared-tag`. The `note_tag` pivot has three rows.

**Steps.**
1. `Commonplace::deleteNote('personal/notes-A', $alice);`.
2. Inspect `commonplace_tags` and `commonplace_note_tag`.
3. `Commonplace::deleteNote('personal/notes-B', $alice);`.
4. Inspect again.

**Expected.**
- After (1): the `solo` tag row is **removed** (no remaining notes have it). The `shared-tag` row is kept (B still uses it). Pivot rows for A are gone.
- After (3): the `shared-tag` row is also removed (now orphaned).
- The pruning runs in the same transaction as the delete — there's no `commonplace:prune-tags` cleanup command because none is needed.

**Verify with.** Tinker:
```php
NonConvexLabs\Commonplace\Models\Tag::pluck('name')->sort()->values()->all();
```

**Source.** [services.md → deleteNote](../services.md#deletenote), `Commonplace::deleteNote()` (tag-prune block).

---

### S-NOTE-32 — Updating tags prunes any tag the change orphaned

**Intent.** Same invariant applies to `updateNote` (and the MCP `update-note-tool`). Removing the last reference to a tag — by re-tagging the note that held it — drops the tag row.

**Preconditions.** Alice owns one note `personal/notes-A` tagged `solo`. No other note carries `solo`.

**Steps.**
1. `Commonplace::updateNote('personal/notes-A', ['tags' => ['replacement']], $alice);`.

**Expected.**
- After (1): `commonplace_tags` has a row for `replacement` and **no** row for `solo`. Pivot reflects the new association.
- If a different note had also been tagged `solo`, the row would have been kept. The prune is `whereDoesntHave('notes')`-scoped.

**Verify with.** Tinker:
```php
NonConvexLabs\Commonplace\Models\Tag::pluck('name')->sort()->values()->all();
```

**Source.** [services.md → updateNote](../services.md#updatenote), `Commonplace::syncTags()`.
