# Scenarios — AI agent (MCP client)

An MCP-compatible client — Claude Code, Cursor, Zed, or a custom JSON-RPC consumer — acting on behalf of a Laravel-authenticated user. Drives the package through the 16 MCP tools defined in [mcp-tools.md](../mcp-tools.md). Every tool resolves `$request->user()` and delegates to the same `Commonplace` service the note-taker uses, so the underlying behaviors mirror [note-taker.md](note-taker.md); this file focuses on the **MCP-specific** surface: tool wiring, JSON shapes, the workflow hints, and the `auth:sanctum` posture by default.

Assumptions for the scenarios below:

- `COMMONPLACE_MCP_ENABLED=true`, default prefix `mcp/commonplace`.
- `COMMONPLACE_MCP_MIDDLEWARE=auth:sanctum` (the default).
- Alice has a Sanctum personal-access token. The client sends `Authorization: Bearer <token>`.
- Queue worker running (for `move-tool` wikilink rewrites).

Where a scenario depends on Postgres-only graph queries (`neighborhood-tool`, `shortest-path-tool`, `hub-notes-tool`), it says so up front.

---

## Server discovery

### S-AI-01 — `tools/list` returns sixteen tools

**Intent.** A connected client discovers exactly the documented tool surface and the recommended workflow hints baked into the server.

> [!NOTE]
> Validation 2026-05-17: `tools/list` currently returns 15 of the 16 registered tools — `suggested-links-tool` is missing from the listing. Direct `tools/call` against it still works, so the tool is wired and dispatching, just invisible to discovery. Tracked in [#66](https://github.com/non-convex-labs/laravel-commonplace/issues/66).

**Preconditions.** MCP enabled. Client connected and authenticated.

**Steps.**
1. JSON-RPC `tools/list`.
2. JSON-RPC `prompts/list` and `resources/list` (if implemented).

**Expected.**
- 16 tools, names match the [mcp-tools matrix](../mcp-tools.md#tools-matrix).
- Read-only tools carry the `IsReadOnly` annotation. `delete-note-tool` carries `IsDestructive`.
- The server's `#[Instructions]` are returned in the server info or initial handshake, including hints: read a `commonplace-guide` note first; prefer `semantic-search-tool` over `search-tool`; prefer `edit-note-tool` over rewrites; prefer `move-tool` over delete + recreate.

**Verify with.** Hand-craft a JSON-RPC call to `tools/list` against the prefix; assert names and annotations.

**Source.** [mcp-tools.md → Tools matrix](../mcp-tools.md#tools-matrix), [`CommonplaceMcpServer.php:28`](../src/Mcp/CommonplaceMcpServer.php#L28).

---

### S-AI-02 — Disabled MCP returns 404, not an empty tool list

**Intent.** With MCP off, the routes aren't registered at all. There's no leaky discovery endpoint.

**Preconditions.** `COMMONPLACE_MCP_ENABLED=false`.

**Steps.**
1. `POST /mcp/commonplace` with any JSON-RPC envelope.

**Expected.** 404 from the framework, not a 405 from the MCP registrar.

**Verify with.** `curl -X POST` and assert status code.

**Source.** [mcp-tools.md → Setup](../mcp-tools.md#setup), `routes/mcp.php`.

---

## Authentication

### S-AI-03 — Sanctum Bearer token authenticates the JSON-RPC POST

**Intent.** A token-bearing client authenticates cleanly without CSRF or session-cookie roundtrips. Every tool call resolves `$request->user()`.

**Preconditions.** Alice has a Sanctum PAT. `auth:sanctum` in middleware.

**Steps.**
1. POST the JSON-RPC tool call with `Authorization: Bearer <token>`.

**Expected.**
- 200 with a normal tool response.
- The note created/read is scoped to Alice's `user_id`.

**Verify with.** `claude mcp add commonplace --transport http <url> --header "Authorization: Bearer <token>"` then call a tool; assert ownership.

**Source.** [auth.md → MCP](../auth.md#mcp).

---

### S-AI-04 — Unauthenticated request is rejected before any tool dispatches

**Intent.** Tool-level `$request->user()` fail-closed checks are defense in depth; the auth boundary is the middleware group. Missing credentials don't reach the tool.

**Preconditions.** `auth:sanctum` middleware.

**Steps.**
1. POST to the JSON-RPC endpoint without `Authorization`.

**Expected.** 401, not "Note not found." from a tool.

**Verify with.** `curl` without header.

**Source.** [auth.md → MCP](../auth.md#mcp).

---

### S-AI-05 — Doctor flags a misconfigured MCP auth stack

**Intent.** `commonplace:doctor` blocks deploys where MCP is enabled but middleware is empty, or where `auth:sanctum` is configured but Sanctum isn't installed.

**Preconditions.** MCP enabled.

**Steps.**
1. Unset `COMMONPLACE_MCP_MIDDLEWARE` to empty string.
2. Run `php artisan commonplace:doctor`.
3. Restore `auth:sanctum` but remove `laravel/sanctum` from composer.
4. Re-run doctor.

**Expected.** Doctor fails on both configurations with actionable recommendations. With `--exit-code`, the exit code is 1.

**Verify with.** Run the command; inspect stdout for `[FAIL]` markers.

**Source.** [commands.md → commonplace:doctor](../commands.md#commonplacedoctor), [auth.md → Doctor](../auth.md#doctor).

---

## Note CRUD via tools

### S-AI-06 — `create-note-tool` writes a note owned by the caller

**Intent.** Tool calls mirror `Commonplace::createNote()` exactly. Owner is the authenticated MCP user.

**Preconditions.** Alice authenticated.

**Steps.**
1. Call `create-note-tool` with `{path: 'projects/ncl/roadmap', content: '...', tags: ['ncl','roadmap'], visibility: 'private'}`.

**Expected.** Same as [S-NOTE-01](note-taker.md#s-note-01--create-a-private-note-from-the-web-ui) but driven over JSON-RPC. Response JSON shape per [mcp-tools.md → create-note-tool](../mcp-tools.md#create-note-tool).

**Verify with.** Inspect the tool response and the resulting DB row.

**Source.** [mcp-tools.md → create-note-tool](../mcp-tools.md#create-note-tool).

---

### S-AI-07 — `read-note-tool` returns full content; absent or inaccessible notes both say "Note not found."

**Intent.** Path enumeration is not possible. The tool collapses "doesn't exist" and "you can't see it" into one error string.

> [!NOTE]
> Validation 2026-05-17: the implementation **distinguishes the two cases** — inaccessible returns `"You do not have access to this note."`, missing returns `"Note not found."`. That contradicts the security promise in `docs/mcp-tools.md:138`. Tracked in [#67](https://github.com/non-convex-labs/laravel-commonplace/issues/67).

**Preconditions.** A note Bob owns privately at `private/bob-notes`.

**Steps.**
1. Call `read-note-tool` as Alice with `path: 'private/bob-notes'`.
2. Call as Alice with `path: 'never-existed'`.

**Expected.** Both responses return the error text `Note not found.`. Same wording, same structure.

**Verify with.** Inspect tool error response.

**Source.** [mcp-tools.md → read-note-tool](../mcp-tools.md#read-note-tool), [`ReadNoteTool.php:42`](../src/Mcp/Tools/ReadNoteTool.php#L42).

---

### S-AI-08 — `update-note-tool` only touches provided fields

**Intent.** Omitting a field leaves it alone. Useful for models that want to update tags without re-sending the body.

**Preconditions.** A note with content and tags.

**Steps.**
1. Call `update-note-tool` with `{path, tags: ['new']}` only.

**Expected.** The note's `tags` change to `['new']`. Content, visibility, path are unchanged. One new `NoteVersion` is **not** written when only tags change (versions track content).

**Verify with.** DB inspection.

**Source.** [mcp-tools.md → update-note-tool](../mcp-tools.md#update-note-tool), [`UpdateNoteTool.php:24-29`](../src/Mcp/Tools/UpdateNoteTool.php#L24).

---

### S-AI-09 — `edit-note-tool` on ambiguous match returns the error with full content appended

**Intent.** When the model's `old_string` matches more than once and `replace_all=false`, the tool returns an error that *includes the full current content of the note*, so the model can re-plan without an extra `read-note-tool` call.

**Preconditions.** A note containing `TODO` twice.

**Steps.**
1. Call `edit-note-tool` with `{path, old_string: 'TODO', new_string: 'DONE', replace_all: false}`.

**Expected.** Error text:
```
old_string appears 2 times in the note.

--- current note content ---
<the full note>
```
No mutation. No version row added.

**Verify with.** Inspect the tool error response.

**Source.** [mcp-tools.md → edit-note-tool](../mcp-tools.md#edit-note-tool), [`EditNoteTool.php:49-62`](../src/Mcp/Tools/EditNoteTool.php#L49).

---

### S-AI-10 — `delete-note-tool` is flagged destructive and preserves history

**Intent.** Clients that respect MCP annotations can show a confirmation prompt before delete. The version history survives so `history-tool` still works.

**Preconditions.** A note with prior versions.

**Steps.**
1. Inspect the tool metadata.
2. Call `delete-note-tool` against the path.
3. Call `history-tool` against the same path.

**Expected.**
- (1) The tool exposes the `IsDestructive` annotation.
- (2) Response: `Note deleted: <path>`. Row removed.
- (3) Returns the version snapshots, including the final pre-delete one.

**Verify with.** Tool calls + DB inspection.

**Source.** [mcp-tools.md → delete-note-tool](../mcp-tools.md#delete-note-tool), [`DeleteNoteTool.php:18`](../src/Mcp/Tools/DeleteNoteTool.php#L18).

---

## Discovery via tools

### S-AI-11 — `list-tool` returns metadata only, ordered by `updated_at DESC`

**Intent.** Metadata-only listings are cheap. Clients fetch content per-note via `read-note-tool`.

**Preconditions.** Several accessible notes.

**Steps.**
1. Call `list-tool` with no filters.
2. Call with `folder: 'projects/ncl'`.
3. Call with `tag: 'roadmap'`.
4. Call with `visibility: 'public'`.

**Expected.** Each call returns the filtered set scoped through `accessibleBy`, ordered newest-update-first, with the shape `{path, title, visibility, tags, updated_at}`.

**Verify with.** Inspect tool response.

**Source.** [mcp-tools.md → list-tool](../mcp-tools.md#list-tool).

---

### S-AI-12 — `search-tool` is title-hit-first, capped at 20, ignores <2-char queries

**Intent.** Substring search. The MCP server's instructions tell the model to prefer `semantic-search-tool` and fall back here only for exact-keyword matches.

**Preconditions.** Several notes with the query term in titles and bodies.

**Steps.**
1. Call `search-tool` with a 1-char query.
2. Call with a real query.

**Expected.**
- (1) `[]`.
- (2) Up to 20 results, title hits first, `{path, title, excerpt, updated_at}`.

**Verify with.** Inspect response.

**Source.** [mcp-tools.md → search-tool](../mcp-tools.md#search-tool).

---

### S-AI-13 — `semantic-search-tool` emits a top-level `warnings` array when the driver advises one

**Intent.** Cap truncation, dimension mismatches, and other driver warnings reach the client so the agent can adapt.

**Preconditions.** `in_php_cosine` driver. More notes than `COMMONPLACE_INPHP_HARD_MAX_CANDIDATES` so the hard-cap warning fires.

**Steps.**
1. Call `semantic-search-tool` with any query.

**Expected.** Response shape:
```json
{
  "results": [...],
  "warnings": [
    {"code": "hard_cap_truncated", "message": "...", "context": {...}}
  ]
}
```
`pgvector` and `null` never emit warnings.

**Verify with.** Inspect response shape.

**Source.** [mcp-tools.md → semantic-search-tool](../mcp-tools.md#semantic-search-tool), [`SemanticSearchTool.php:49-53`](../src/Mcp/Tools/SemanticSearchTool.php#L49).

---

### S-AI-14 — `semantic-search-tool` scope enum: mine / public / accessible

**Intent.** Per-call scope overrides the default. Default is `accessible` (the same union `Note::accessibleBy()` enforces).

**Preconditions.** Embedding driver enabled. A mix of own / public / shared / others'-private notes.

**Steps.**
1. Call with `scope: 'mine'`.
2. Call with `scope: 'public'`.
3. Call with `scope: 'accessible'`.

**Expected.** Each call's results stay within the named scope. `mine` excludes shared. `public` excludes private-shared-with-me. `accessible` returns all three categories.

**Verify with.** Inspect results' `path` values against known ownership.

**Source.** [mcp-tools.md → semantic-search-tool](../mcp-tools.md#semantic-search-tool), [`SemanticSearchScope`](../src/Enums/SemanticSearchScope.php).

---

## Graph via tools

### S-AI-15 — `backlinks-tool` requires target visibility

**Intent.** If the agent can't see the target, the tool returns `Note not found.` — same enumeration defense as `read-note-tool`.

> [!NOTE]
> Validation 2026-05-17: the implementation returns `[]` (empty list, `isError: false`) for an inaccessible target, and `"Note not found."` for a missing one. Same enumeration leak as S-AI-07. Tracked in [#67](https://github.com/non-convex-labs/laravel-commonplace/issues/67).

**Preconditions.** A private note Bob owns.

**Steps.**
1. As Alice, call `backlinks-tool` with Bob's path.

**Expected.** `Note not found.`. No leakage of whether the note has backlinks.

**Verify with.** Inspect response.

**Source.** [mcp-tools.md → backlinks-tool](../mcp-tools.md#backlinks-tool).

---

### S-AI-16 — `move-tool` rewrites referring wikilinks via the async job

**Intent.** Move is atomic at the source; wikilink rewrites in *referring* notes happen via `UpdateWikilinksJob` dispatched on `DB::afterCommit`. Queue worker required.

**Preconditions.** Source note exists; one referring note holds `[[old/path]]`. Queue worker running.

**Steps.**
1. Call `move-tool` with `{from_path: 'old/path', to_path: 'new/path'}`.
2. Wait for the queue.
3. Read the referring note.

**Expected.**
- The moved note's row has new `path`.
- The referring note's content has `[[new/path]]` (the rewrite preserves the alias suffix if present).
- The `commonplace_links` row's `target_path` is updated.

**Verify with.** Inspect DB and content.

**Source.** [mcp-tools.md → move-tool](../mcp-tools.md#move-tool), [services.md → moveNote](../services.md#movenote).

---

### S-AI-17 — `move-tool` does not rewrite wikilinks inside fenced code or with anchor suffixes

**Intent.** Two pre-existing limitations of `WikilinkParser::extractLinks`. The agent should know not to expect rewrites in those positions.

**Preconditions.** Two referring notes — one with the wikilink in a fenced code block, one with `[[old/path#heading]]`.

**Steps.**
1. Call `move-tool` to rename `old/path` → `new/path`.
2. Wait for the queue.
3. Inspect both referring notes.

**Expected.** Neither referring note's content is rewritten. The `[[old/path]]` text remains in the code fence and in the anchor-suffixed link.

**Verify with.** Inspect content.

**Source.** [mcp-tools.md → move-tool](../mcp-tools.md#move-tool) (limitations paragraph).

---

### S-AI-18 — `history-tool` works against deleted paths

**Intent.** Versions outlive the live note. The agent can reach back into the history of a deleted path.

**Preconditions.** A deleted note with several versions.

**Steps.**
1. Call `history-tool` with the deleted path.

**Expected.** Array of `{id, content_hash, changed_by, created_at}`, including the final pre-delete snapshot.

**Verify with.** Inspect tool response.

**Source.** [mcp-tools.md → history-tool](../mcp-tools.md#history-tool).

---

### S-AI-19 — `neighborhood-tool` is undirected and clamped to 5 hops

**Intent.** Breadth-first traversal of the wikilink graph treating links as undirected, default 2 hops, max 5. Postgres-only.

**Preconditions.** Postgres. A wikilink graph.

**Steps.**
1. Call `neighborhood-tool` with `{path, max_hops: 2}`.
2. Call with `max_hops: 99` (over the cap).

**Expected.**
- (1) `{path, title, depth, tags}` entries within 2 hops. Start excluded. Only visible notes.
- (2) Clamped to 5 hops. The cap is enforced server-side.

**Verify with.** Compare result depths against expected graph distances.

**Source.** [mcp-tools.md → neighborhood-tool](../mcp-tools.md#neighborhood-tool).

---

### S-AI-20 — `shortest-path-tool` bounds at 10 hops and returns `connected: false` when no path

**Intent.** Two-endpoint search. Caps at 10 hops; disconnected returns an explicit `connected: false` rather than null.

**Preconditions.** Postgres. Disconnected pair of notes.

**Steps.**
1. Call `shortest-path-tool` with the two paths.

**Expected.** `{"connected": false, "path": []}`. Caller doesn't have to disambiguate null from empty.

**Verify with.** Inspect response.

**Source.** [mcp-tools.md → shortest-path-tool](../mcp-tools.md#shortest-path-tool).

---

### S-AI-21 — `hub-notes-tool` ranks owned notes only

**Intent.** Hub-ness is a personal-vault metric. Public and shared notes that the caller can read don't count toward their hubs.

**Preconditions.** Postgres. Alice owns several linked notes; one well-linked public note from Bob is visible to her.

**Steps.**
1. As Alice, call `hub-notes-tool`.

**Expected.** Returns Alice-owned notes only. Bob's public note is absent regardless of its link counts.

**Verify with.** Inspect returned `path` values.

**Source.** [mcp-tools.md → hub-notes-tool](../mcp-tools.md#hub-notes-tool), [`Commonplace.php:534`](../src/Services/Commonplace.php#L534).

---

### S-AI-22 — `orphan-notes-tool` is scoped through `accessibleBy`

**Intent.** Orphans in the caller's view include public/shared notes that happen to be unlinked. Unlike hub-notes, this isn't owner-only.

**Preconditions.** Alice owns two orphans. Bob's public note is also linkless.

**Steps.**
1. As Alice, call `orphan-notes-tool`.

**Expected.** Returns Alice's two orphans plus Bob's public orphan.

**Verify with.** Inspect returned paths.

**Source.** [mcp-tools.md → orphan-notes-tool](../mcp-tools.md#orphan-notes-tool).

---

### S-AI-23 — `suggested-links-tool` defaults to `mine` scope and excludes already-linked notes

**Intent.** Default is `mine`, not `accessible`, so the agent doesn't propose links to notes whose visibility could change later and leave the link broken.

**Preconditions.** Embedding + vector driver enabled. A source note plus several semantically similar notes; one is shared with Alice.

**Steps.**
1. Call `suggested-links-tool` with `{path}` only.
2. Call with `{path, scope: 'accessible'}`.

**Expected.**
- (1) Returns Alice-owned candidates only; the shared note is excluded.
- (2) Returns Alice-owned + accessible candidates (the shared note can appear).
- Both exclude the source note and any already-linked notes.

**Verify with.** Inspect returned paths; cross-check against `commonplace_links` from the source.

**Source.** [mcp-tools.md → suggested-links-tool](../mcp-tools.md#suggested-links-tool), [`SuggestedLinksTool.php:32`](../src/Mcp/Tools/SuggestedLinksTool.php#L32).

---

## Cross-cutting

### S-AI-24 — Write failures use a different error string than read failures

**Intent.** A `Note not found.` from a read tool doesn't tell you whether the path exists; a write tool's `You do not have access to this note.` does. The asymmetry is deliberate — the caller has already proven they know the path on a write.

**Preconditions.** Bob owns a private note. Alice tries to update it.

**Steps.**
1. As Alice, call `update-note-tool` with Bob's path.

**Expected.** Error text contains `You do not have access to this note.`. Not `Note not found.`.

**Verify with.** Inspect error.

**Source.** [mcp-tools.md → Visibility model](../mcp-tools.md#visibility-model), [`CommonplaceMcpServerTest.php:230`](../tests/Feature/Mcp/CommonplaceMcpServerTest.php#L230).

---

### S-AI-25 — Tool errors are JSON-RPC `error` responses, not 500s

**Intent.** Domain errors (collision, ambiguous edit, missing note, auth failure inside a write check) surface as structured tool errors. The transport stays 200 with an error payload.

**Preconditions.** Any error-producing call (e.g. move into occupied path).

**Steps.**
1. Call `move-tool` into a path that already exists.

**Expected.** JSON-RPC response with an `error` object, not a 500.

**Verify with.** Inspect response status + body.

**Source.** `laravel/mcp` transport behavior; observed in the tests at `tests/Feature/Mcp/`.

---

### S-AI-26 — Tool calls respect `COMMONPLACE_WIKILINKS_REWRITE_SYNC` for queue-less consumers

**Intent.** For CLI bots or test harnesses without a queue worker, set `COMMONPLACE_WIKILINKS_REWRITE_SYNC=true` so `move-tool` (and `update-note-tool` with `new_path`) finishes the rewrites inline.

**Preconditions.** No queue worker. Sync flag enabled.

**Steps.**
1. Call `move-tool`.
2. Immediately read the referring note.

**Expected.** Wikilinks in the referring note are already rewritten before the tool response returns.

**Verify with.** Same as [S-AI-16](#s-ai-16--move-tool-rewrites-referring-wikilinks-via-the-async-job) but without the wait step.

**Source.** [services.md → moveNote](../services.md#movenote).
