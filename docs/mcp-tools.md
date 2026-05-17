# MCP tools

The package ships an MCP (Model Context Protocol) server. Claude Code, or any MCP-compatible client, can read and write your commonplace book through structured tool calls.

**Source files:**

- [`src/Mcp/CommonplaceMcpServer.php`](../src/Mcp/CommonplaceMcpServer.php)
- [`src/Mcp/Tools/CreateNoteTool.php`](../src/Mcp/Tools/CreateNoteTool.php)
- [`src/Mcp/Tools/ReadNoteTool.php`](../src/Mcp/Tools/ReadNoteTool.php)
- [`src/Mcp/Tools/UpdateNoteTool.php`](../src/Mcp/Tools/UpdateNoteTool.php)
- [`src/Mcp/Tools/EditNoteTool.php`](../src/Mcp/Tools/EditNoteTool.php)
- [`src/Mcp/Tools/DeleteNoteTool.php`](../src/Mcp/Tools/DeleteNoteTool.php)
- [`src/Mcp/Tools/ListTool.php`](../src/Mcp/Tools/ListTool.php)
- [`src/Mcp/Tools/SearchTool.php`](../src/Mcp/Tools/SearchTool.php)
- [`src/Mcp/Tools/SemanticSearchTool.php`](../src/Mcp/Tools/SemanticSearchTool.php)
- [`src/Mcp/Tools/BacklinksTool.php`](../src/Mcp/Tools/BacklinksTool.php)
- [`src/Mcp/Tools/MoveTool.php`](../src/Mcp/Tools/MoveTool.php)
- [`src/Mcp/Tools/HistoryTool.php`](../src/Mcp/Tools/HistoryTool.php)
- [`src/Mcp/Tools/NeighborhoodTool.php`](../src/Mcp/Tools/NeighborhoodTool.php)
- [`src/Mcp/Tools/ShortestPathTool.php`](../src/Mcp/Tools/ShortestPathTool.php)
- [`src/Mcp/Tools/HubNotesTool.php`](../src/Mcp/Tools/HubNotesTool.php)
- [`src/Mcp/Tools/OrphanNotesTool.php`](../src/Mcp/Tools/OrphanNotesTool.php)
- [`src/Mcp/Tools/SuggestedLinksTool.php`](../src/Mcp/Tools/SuggestedLinksTool.php)
- [`routes/mcp.php`](../routes/mcp.php)

## Overview

[MCP](https://modelcontextprotocol.io) is a JSON-RPC protocol. It lets a model invoke server-defined tools, read server-defined resources, and follow server-defined prompts. This package ships an MCP server (`commonplace`, version `0.1.0`, see [`CommonplaceMcpServer.php:28`](../src/Mcp/CommonplaceMcpServer.php#L28)) that exposes sixteen tools. They cover note CRUD, search (substring and semantic), wikilink graph traversal, and version history.

The server is built on `laravel/mcp` and runs over HTTP streamable transport. It mounts at a configurable route prefix (default `mcp/commonplace`) and authenticates the same way the rest of your Laravel app does. Every tool resolves `$request->user()` and delegates to the [`Commonplace`](../src/Services/Commonplace.php) service. That service enforces the [`Note::accessibleBy()`](../src/Models/Note.php#L128) visibility scope on every read and an ownership / share-permission check on every write.

The server's `#[Instructions]` attribute ([`CommonplaceMcpServer.php:30-52`](../src/Mcp/CommonplaceMcpServer.php#L30)) ships a short system prompt to clients describing paths, wikilinks, tags, visibility, and the recommended workflow. Notable hints it gives the model: read a `commonplace-guide` note first, prefer `semantic-search-tool` over `search-tool`, prefer `edit-note-tool` over rewrites, prefer `move-tool` over delete + recreate.

## Setup

MCP is **off by default**. Turn it on via env or the published config:

```dotenv
COMMONPLACE_MCP_ENABLED=true
COMMONPLACE_MCP_PREFIX=mcp/commonplace
```

Config keys live under `commonplace.mcp` in [`config/commonplace.php`](../config/commonplace.php):

```php
'mcp' => [
    'enabled' => (bool) env('COMMONPLACE_MCP_ENABLED', false),
    'prefix' => env('COMMONPLACE_MCP_PREFIX', 'mcp/commonplace'),
    'middleware' => /* parsed from COMMONPLACE_MCP_MIDDLEWARE, default ['auth:sanctum'] */,
],
```

When enabled, the service provider loads [`routes/mcp.php`](../routes/mcp.php). That file registers an `Mcp::web()` endpoint at the configured prefix inside a `Route::middleware(config('commonplace.mcp.middleware'))->group(...)` wrapper. The middleware applies to every route the MCP registrar adds: the JSON-RPC `POST`, the `405 Allow: POST` `GET`/`DELETE` stubs, and any route the registrar grows in future. The default is `auth:sanctum` so the dominant MCP client class (Claude Desktop, Cursor, remote bridges) can present a Bearer token from a non-browser context. See [auth.md → MCP](auth.md#mcp) for browser-SPA, Passport, and OAuth-DCR overrides.

Register the server with Claude Code:

```bash
claude mcp add commonplace --transport http https://your-app.test/mcp/commonplace
```

Swap the URL for your deployed endpoint. The client will then list all sixteen tools below.

## Tools matrix

All tools require an authenticated user. Read-only tools never mutate state. Destructive tools are flagged for clients that respect MCP annotations.

| Tool name | Purpose | Annotation |
|---|---|---|
| `create-note-tool` | Create a new note at a virtual path. | write |
| `read-note-tool` | Read a note's full markdown content. | `IsReadOnly` |
| `update-note-tool` | Replace whole fields on a note. | write |
| `edit-note-tool` | Surgical string replacement in a note. | write |
| `delete-note-tool` | Permanently delete a note (history is preserved). | `IsDestructive` |
| `list-tool` | List notes with optional folder / tag / visibility filters. | `IsReadOnly` |
| `search-tool` | Substring (ILIKE) search across title and content. | `IsReadOnly` |
| `semantic-search-tool` | AI-embedding semantic search. | `IsReadOnly` |
| `backlinks-tool` | List notes that wikilink to a target. | `IsReadOnly` |
| `move-tool` | Rename / move a note; rewrites referring wikilinks. | write |
| `history-tool` | Retrieve version snapshots for a note. | `IsReadOnly` |
| `neighborhood-tool` | Notes within N wikilink hops, grouped by depth. | `IsReadOnly` |
| `shortest-path-tool` | Shortest wikilink chain between two notes. | `IsReadOnly` |
| `hub-notes-tool` | Most-linked notes in your vault. | `IsReadOnly` |
| `orphan-notes-tool` | Notes with no inbound or outbound wikilinks. | `IsReadOnly` |
| `suggested-links-tool` | Embedding-similar notes not yet linked. | `IsReadOnly` |

Every tool wraps a corresponding method on the [`Commonplace`](../src/Services/Commonplace.php) service (see [`services.md`](./services.md) for the underlying API). The HTTP routes documented in [`http-api.md`](./http-api.md) and the MCP tools share identical authorization and side-effect semantics.

## create-note-tool

Create a new markdown note at a virtual path.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Virtual path, e.g. `projects/ncl/roadmap`. |
| `content` | string | yes | Markdown body. May include YAML frontmatter (`title`, `tags`). |
| `tags` | string[] | no | Tag names. Frontmatter `tags` merge with this. |
| `visibility` | string | no | `private` (default) or `public`. Per-user sharing is granted via the `Share` model, not a visibility value. |

**Output:**

```json
{
  "path": "projects/ncl/roadmap",
  "title": "NCL Roadmap",
  "visibility": "private",
  "tags": ["ncl", "roadmap"],
  "created_at": "2026-05-17T12:00:00+00:00"
}
```

Owner is set to the authenticated user. Errors with `AuthorizationException` if the caller can't write at that path.

## read-note-tool

Read a note's full content by path.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Virtual path of the note. |

**Output:**

```json
{
  "path": "projects/ncl/roadmap",
  "title": "NCL Roadmap",
  "content": "# Roadmap\n\nSee [[references/laravel-eloquent]]…",
  "visibility": "private",
  "tags": ["ncl", "roadmap"],
  "updated_at": "2026-05-17T12:00:00+00:00"
}
```

Visibility: the note must be owned by the caller, shared with them, or have `visibility = 'public'`. Otherwise the tool returns `Note not found.` ([`ReadNoteTool.php:42`](../src/Mcp/Tools/ReadNoteTool.php#L42)). The package deliberately does not distinguish "doesn't exist" from "you can't see it" to prevent path enumeration.

## update-note-tool

Replace fields on an existing note. Only fields you provide are touched; omitted fields are left alone ([`UpdateNoteTool.php:24-29`](../src/Mcp/Tools/UpdateNoteTool.php#L24)).

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Current path of the note. |
| `content` | string | no | New markdown body. |
| `tags` | string[] | no | Replaces the existing tag set. |
| `visibility` | string | no | `private` or `public`. |
| `new_path` | string | no | Rename the note. |

**Output:** same shape as `read-note-tool`.

Each update produces a new entry in the note's version history. Use `edit-note-tool` when you only need to change a small region. It costs less context and reduces the chance of clobbering recent edits.

## edit-note-tool

Find-and-replace a single substring inside a note ([`EditNoteTool.php`](../src/Mcp/Tools/EditNoteTool.php)). Modeled on the editor's own Edit tool.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Path of the note to edit. |
| `old_string` | string | yes | Exact text to find. Whitespace-sensitive. |
| `new_string` | string | yes | Replacement. Empty string deletes the match. |
| `replace_all` | bool | no | Default `false`. When `false`, ambiguity is an error. |

**Output:** same shape as `read-note-tool`.

If `old_string` appears more than once and `replace_all` is `false`, the tool returns an error **with the full current note content appended** ([`EditNoteTool.php:49-62`](../src/Mcp/Tools/EditNoteTool.php#L49)). The calling model can then re-plan the edit without an extra `read-note-tool` round trip:

```
old_string appears 2 times in the note.

--- current note content ---
<the full note>
```

## delete-note-tool

Permanently remove a note. Marked `#[IsDestructive(true)]` ([`DeleteNoteTool.php:18`](../src/Mcp/Tools/DeleteNoteTool.php#L18)).

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Virtual path of the note. |

**Output:** a text message, `Note deleted: {path}`.

Version snapshots are retained after deletion (see the [`NoteVersion` section in model-relationships.md](./model-relationships.md#noteversion)) and remain queryable via `history-tool` against the original path. Prefer `move-tool` if the content should stay reachable.

## list-tool

List notes the caller can see, with optional filters. Returns metadata only. Call `read-note-tool` for content.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `folder` | string | no | Prefix filter, e.g. `projects/ncl`. |
| `tag` | string | no | Tag name. |
| `visibility` | string | no | `private` or `public`. |

**Output:**

```json
[
  {
    "path": "projects/ncl/roadmap",
    "title": "NCL Roadmap",
    "visibility": "private",
    "tags": ["ncl", "roadmap"],
    "updated_at": "2026-05-17T12:00:00+00:00"
  }
]
```

Results are scoped through `Note::accessibleBy($user)` ([`Commonplace.php:275`](../src/Services/Commonplace.php#L275)) and ordered by `updated_at` descending.

## search-tool

Substring (`ILIKE`) search across `title` and `content`. Returns at most 20 results, ordered with title hits before body hits.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `query` | string | yes | Minimum 2 characters. Shorter queries return `[]`. |

**Output:**

```json
[
  {
    "path": "references/laravel-eloquent",
    "title": "Laravel Eloquent",
    "excerpt": "Eloquent is the package's ORM…",
    "updated_at": "2026-05-17T12:00:00+00:00"
  }
]
```

The MCP server's instructions tell the model to prefer `semantic-search-tool` and fall back here only for exact keyword matches ([`CommonplaceMcpServer.php:48`](../src/Mcp/CommonplaceMcpServer.php#L48)).

## semantic-search-tool

Embedding-based search across the configured [vector storage](./vector-storage.md). Returns up to 20 results ordered by ascending cosine distance.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `query` | string | yes | Natural-language query. |
| `scope` | enum | no | `mine`, `public`, or `accessible` (default). |

**Output:**

```json
{
  "results": [
    {
      "path": "projects/ncl/roadmap",
      "title": "NCL Roadmap",
      "excerpt": "Quarterly milestones for…",
      "distance": 0.1234,
      "updated_at": "2026-05-17T12:00:00+00:00"
    }
  ]
}
```

When the vector driver emits warnings (e.g. candidate-cap truncation in `in_php_cosine`, dimension mismatches), a top-level `warnings` array is appended ([`SemanticSearchTool.php:49-53`](../src/Mcp/Tools/SemanticSearchTool.php#L49)):

```json
{
  "results": [...],
  "warnings": [
    {"code": "candidates_truncated", "message": "...", "context": {...}}
  ]
}
```

The three scopes map to [`SemanticSearchScope`](../src/Enums/SemanticSearchScope.php): `mine` filters to `user_id = caller`, `public` to `visibility = 'public'`, and `accessible` is the union of mine + public + shared-with-me (same predicate as `Note::accessibleBy()`).

## backlinks-tool

List notes that wikilink to a target via `[[path]]`.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Path of the target note. |

**Output:**

```json
[
  {"path": "projects/source", "title": "Source note"}
]
```

Only backlinks from notes the caller can see are returned. The result set is intersected with `Note::accessibleBy($user)` ([`Commonplace.php:357`](../src/Services/Commonplace.php#L357)). The target note itself must also be visible to the caller or the tool returns `Note not found.`.

## move-tool

Rename or move a note. Preserves version history; queues a job that rewrites `[[wikilinks]]` in every referencing note.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `from_path` | string | yes | Current path. |
| `to_path` | string | yes | New path. Must not collide with an existing note. |

**Output:** same shape as `read-note-tool` (the moved note's new metadata).

Wikilink rewriting runs asynchronously via `UpdateWikilinksJob` ([`src/Jobs/UpdateWikilinksJob.php`](../src/Jobs/UpdateWikilinksJob.php)). The job reads `commonplace_links` rows pointing at the moved note, replaces the literal `target_path` text in each source note's content (preserving any `|alias` suffix), and rewrites the link rows directly. Dispatched via `DB::afterCommit` inside `Commonplace::moveNote`'s transaction so the job never sees a half-applied move. Set `COMMONPLACE_WIKILINKS_REWRITE_SYNC=true` to run inline for tests / CLI tools.

If the queue worker is down, `commonplace:doctor` flags orphaned link rows above a configurable threshold and recommends `commonplace:relink` to re-resolve them. See [commands.md](commands.md). Errors surface as `InvalidArgumentException` (collision, invalid path) or `AuthorizationException` (caller doesn't own the source).

The same rewrite covers the `new_path` parameter on `update-note-tool`. `Commonplace::updateNote` delegates the path mutation to `moveNote` so both entry points dispatch the same job.

Anchor-suffixed wikilinks (`[[a/b#heading]]`) and wikilinks inside fenced code blocks are pre-existing limitations of `WikilinkParser::resolveTarget` / `extractLinks` and are **not** rewritten by the move job.

## history-tool

Return the version snapshots recorded for a note.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Virtual path. Works for deleted notes too. |

**Output:**

```json
[
  {
    "id": 42,
    "content_hash": "ab12cd…",
    "changed_by": "Alice",
    "created_at": "2026-05-17T12:00:00+00:00"
  }
]
```

Versions are append-only and survive deletion (see the [`NoteVersion` section in model-relationships.md](./model-relationships.md#noteversion)), so this tool can recover the last known content of a deleted path.

## neighborhood-tool

Breadth-first traversal of the wikilink graph starting from a note, treating links as undirected.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | Starting note. |
| `max_hops` | number | no | Default 2, capped at 5. |

**Output:**

```json
[
  {"path": "references/laravel-eloquent", "title": "Laravel Eloquent", "depth": 1, "tags": ["reference"]},
  {"path": "projects/ncl/auth", "title": "NCL Auth", "depth": 2, "tags": ["ncl"]}
]
```

The recursive CTE that powers this is PostgreSQL-only. The test suite explicitly skips it on other databases ([`CommonplaceMcpServerTest.php:386-389`](../tests/Feature/Mcp/CommonplaceMcpServerTest.php#L386)).

> [!NOTE]
> Requires PostgreSQL. The CTE uses `ARRAY` and `ANY` operators that aren't portable to SQLite or MySQL.

## shortest-path-tool

Find the shortest chain of wikilinks connecting two notes (undirected, bounded at 10 hops).

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `from_path` | string | yes | Starting note. |
| `to_path` | string | yes | Destination note. |

**Output:**

```json
{
  "connected": true,
  "path": [
    {"path": "projects/a", "title": "A"},
    {"path": "references/shared", "title": "Shared"},
    {"path": "projects/b", "title": "B"}
  ]
}
```

When no path exists within 10 hops:

```json
{"connected": false, "path": []}
```

Both endpoints must be visible to the caller. PostgreSQL-only (same reason as `neighborhood-tool`).

## hub-notes-tool

Rank your most-linked notes by total inbound + outbound wikilinks.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `limit` | number | no | Default 20, capped at 50. |

**Output:**

```json
[
  {
    "path": "projects/ncl",
    "title": "NCL",
    "outgoing_links": 12,
    "incoming_links": 18,
    "total_links": 30
  }
]
```

Scoped to **your own notes only** (`vn.user_id = ?` in the underlying query, [`Commonplace.php:534`](../src/Services/Commonplace.php#L534)). Hub-ness is a property of your personal vault, not the shared graph. PostgreSQL-only.

## orphan-notes-tool

List notes with no inbound and no outbound wikilinks. These are candidates for connection.

**Input:** none.

**Output:**

```json
[
  {
    "path": "journal/2026-03-08",
    "title": "Journal 2026-03-08",
    "tags": ["journal"],
    "updated_at": "2026-05-17T12:00:00+00:00"
  }
]
```

Scoped through `Note::accessibleBy($user)`, so orphans in your view include public / shared notes that happen to be unlinked.

## suggested-links-tool

For a given note, return semantically similar notes that aren't already linked to or from it.

**Input:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `path` | string | yes | The note to find suggestions for. |
| `limit` | number | no | Default 10, capped at 20. |
| `scope` | enum | no | `mine` (default), `public`, or `accessible`. |

**Output:**

```json
{
  "suggestions": [
    {"path": "references/wikilinks", "title": "Wikilinks", "distance": 0.0921}
  ]
}
```

If the vector driver emits warnings, they appear in a top-level `warnings` array (same shape as `semantic-search-tool`).

The **default scope is `mine`**, not `accessible`. This is deliberate ([`SuggestedLinksTool.php:32`](../src/Mcp/Tools/SuggestedLinksTool.php#L32) and `SuggestedLinksTool.php:69`). Suggesting a link to a public note owned by someone else would create a wikilink the model couldn't reliably resolve later if visibility changed. Pass `scope: "accessible"` if you want cross-user suggestions anyway. Requires a working embedding driver and vector backend. Returns `[]` when vectors are disabled ([`Commonplace.php:566`](../src/Services/Commonplace.php#L566)).

## Visibility model

Every tool resolves the caller via Laravel's standard `$request->user()` (configurable via [`commonplace.user_model`](./user-model.md)) and filters through one of two predicates:

- **Read scope — `Note::accessibleBy($user)`** ([`Note.php:128`](../src/Models/Note.php#L128)) — used by `read-note-tool`, `list-tool`, `search-tool`, `backlinks-tool`, `history-tool`, `neighborhood-tool`, `shortest-path-tool`, `orphan-notes-tool`. Returns notes where any of:
  1. `user_id = caller.id` (you own it), or
  2. `visibility = 'public'`, or
  3. There's a row in `commonplace_shares` with `note_id = note.id AND user_id = caller.id`.
- **Write check — `Commonplace::checkAccess($note, $user, $level)`** ([`Commonplace.php:599`](../src/Services/Commonplace.php#L599)) — used by `create-note-tool`, `update-note-tool`, `edit-note-tool`, `delete-note-tool`, `move-tool`. Requires ownership, or a share row with `permission = 'write'` for write-level operations.

Two tools narrow further:

- **`hub-notes-tool`** is scoped to `user_id = caller.id` directly in SQL. Public / shared notes you can read do **not** count toward hub ranking.
- **`semantic-search-tool` and `suggested-links-tool`** take an explicit `scope` parameter (the [`SemanticSearchScope`](../src/Enums/SemanticSearchScope.php) enum: `mine`, `public`, `accessible`) that overrides the default scope per call.

Missing notes and inaccessible notes both surface as `Note not found.` ([`ReadNoteTool.php:42`](../src/Mcp/Tools/ReadNoteTool.php#L42)). This is by design, to prevent enumerating paths the caller cannot see. Write failures use the more specific message `You do not have access to this note.` ([`CommonplaceMcpServerTest.php:230`](../tests/Feature/Mcp/CommonplaceMcpServerTest.php#L230)) since the caller has already proven they know the path.

## Related pages

- [user-model.md](./user-model.md) — how the package resolves the authenticated user the tools act as.
- [services.md](./services.md) — the `Commonplace` service every tool wraps, including the methods listed above.
- [http-api.md](./http-api.md) — the HTTP routes that expose the same surface for browsers / programmatic clients.
- [vector-storage.md](./vector-storage.md) — what powers `semantic-search-tool` and `suggested-links-tool`.
- [model-relationships.md](./model-relationships.md) — the `NoteVersion` schema that backs `history-tool`.
