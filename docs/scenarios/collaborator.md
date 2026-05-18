# Scenarios — Collaborator

A second authenticated user who has been granted access to one or more notes via the `Share` model, or who reads notes the owner has marked `visibility=public`. Drives the package through the same surfaces as the note-taker, but the visibility scope ([model-relationships.md → Visibility model](../model-relationships.md#visibility-model-how-accessibleby-works)) is what makes their experience different.

Assumptions:

- Two authenticated users: `$alice` (owner) and `$bob` (collaborator).
- Alice owns `projects/alpha` (private), `references/shared-doc` (private but shared with Bob), and `public/handbook` (`visibility=public`).
- Where a scenario depends on `permission` semantics: today, only `permission=read` is consumed by `Note::accessibleBy()`. Higher permissions are captured in the column but don't unlock write — see [model-relationships.md → Visibility model](../model-relationships.md#visibility-model-how-accessibleby-works).

---

## Share-based access

### S-COL-01 — Granting a Share gives the recipient read access immediately

**Intent.** A new row in `commonplace_shares` is the only thing the package needs to start returning a note from `accessibleBy` for that user.

**Preconditions.** Alice owns `references/shared-doc` privately.

**Steps.**
1. Alice creates a Share: `Share::create(['note_id' => $note->id, 'user_id' => $bob->id, 'permission' => 'read']);`.
2. As Bob: `Commonplace::readNote('references/shared-doc', $bob);`.

**Expected.**
- Step 2 returns the note. No exception.
- Before step 1, the same call would have thrown `ModelNotFoundException`.

**Verify with.** Tinker as both users.

**Source.** [model-relationships.md → Share](../model-relationships.md#share).

---

### S-COL-02 — Revoking a Share removes access immediately

**Intent.** Delete the row → access disappears on the next query. There is no cache to invalidate.

**Preconditions.** S-COL-01 has run.

**Steps.**
1. `Commonplace::revokeShare('references/shared-doc', $bob, $alice);` (or `Share::where(['note_id' => $note->id, 'user_id' => $bob->id])->delete();`).
2. As Bob: `Commonplace::readNote('references/shared-doc', $bob);`.

**Expected.** Step 2 throws `Illuminate\Auth\Access\AuthorizationException` (\"You do not have access to this note.\"). The note still exists, so the service raises the access-denied class, not `ModelNotFoundException`. The MCP boundary collapses both into `Note not found.` per [S-AI-07](ai-agent.md#s-ai-07--read-note-tool-returns-full-content-absent-or-inaccessible-notes-both-say-note-not-found); the service layer is informative because in-process callers are trusted. See [S-NOTE-03](note-taker.md#s-note-03--read-a-note-requires-visibility-access) for the same two-tier model.

**Verify with.** Tinker — assert the exception class explicitly.

**Source.** [model-relationships.md → Share](../model-relationships.md#share), [services.md → revokeShare](../services.md#revokeshare).

---

### S-COL-03 — Public visibility reaches collaborators without an explicit Share

**Intent.** `visibility=public` is the second clause of `accessibleBy`. No Share row needed.

**Preconditions.** Alice owns `public/handbook` with `visibility=public`. No Share row for Bob.

**Steps.**
1. As Bob: `Commonplace::readNote('public/handbook', $bob);`.
2. As Bob: `Commonplace::listNotes(folder: null, tag: null, visibility: 'public', user: $bob);`.

**Expected.**
- (1) Returns the note.
- (2) Includes `public/handbook`.

**Verify with.** Tinker.

**Source.** [model-relationships.md → Visibility model](../model-relationships.md#visibility-model-how-accessibleby-works).

---

### S-COL-04 — Share has no effect on `inFolder` / `withTag` filters

**Intent.** Scopes compose. `accessibleBy` widens the visible set; `inFolder` and `withTag` narrow it. None of them branch on share status.

**Preconditions.** `references/shared-doc` shared with Bob, tagged `reference`.

**Steps.**
1. `Note::accessibleBy($bob)->withTag('reference')->get();`
2. `Note::accessibleBy($bob)->inFolder('references')->get();`

**Expected.** Both return `[references/shared-doc]` (plus any other Bob-accessible matches).

**Verify with.** Tinker.

**Source.** [model-relationships.md → Scopes](../model-relationships.md#scopes).

---

## Write-side boundary

### S-COL-05 — Collaborator cannot update a shared note

**Intent.** `Commonplace::checkAccess(..., 'write')` requires ownership. The `Share.permission` column exists for future use; today even `permission=write` is captured but ignored.

**Preconditions.** `references/shared-doc` shared with Bob with `permission=read`.

**Steps.**
1. As Bob: `Commonplace::updateNote('references/shared-doc', ['content' => '...'], $bob);`.

**Expected.** Throws `AuthorizationException`. No mutation. No `NoteVersion` written.

**Verify with.** Tinker — assert exception and unchanged content_hash.

**Source.** [services.md → updateNote](../services.md#updatenote), [`Commonplace.php:599`](../src/Services/Commonplace.php#L599).

---

### S-COL-06 — Collaborator cannot delete a shared note

**Intent.** Delete is owner-only. Shares grant read access, not destructive access — even when a future `permission=write` lands.

**Preconditions.** Same as S-COL-05.

**Steps.**
1. As Bob: `Commonplace::deleteNote('references/shared-doc', $bob);`.

**Expected.** Throws `AuthorizationException`. Note row intact.

**Verify with.** Tinker.

**Source.** [services.md → deleteNote](../services.md#deletenote).

---

### S-COL-07 — Collaborator cannot move a shared note

**Intent.** Same owner-only rule as delete. Move would invalidate every referring wikilink, so it stays gated to owner.

**Preconditions.** Same as S-COL-05.

**Steps.**
1. As Bob: `Commonplace::moveNote('references/shared-doc', 'somewhere/else', $bob);`.

**Expected.** Throws `AuthorizationException`. No path change. No `UpdateWikilinksJob` dispatched.

**Verify with.** Tinker; assert path unchanged and no enqueued job.

**Source.** [services.md → moveNote](../services.md#movenote).

---

### S-COL-08 — Collaborator cannot grant or revoke shares on a note they don't own

**Intent.** Share management is the owner's prerogative. A recipient cannot sub-share what they've been given.

**Preconditions.** Alice owns `references/shared-doc`. Bob has a read share on it. A third user `$carol` is authenticated.

**Steps.**
1. As Bob: `Commonplace::grantShare('references/shared-doc', $carol, 'read', $bob);` — pass Bob as the `$owner` argument.
2. As Bob: `Commonplace::revokeShare('references/shared-doc', $bob, $bob);` — try to remove his own share with himself as the asserted owner.

**Expected.** Both calls throw `Illuminate\Auth\Access\AuthorizationException` ("You are not the owner of this note."). The `$owner` parameter on `grantShare` / `revokeShare` / `listShares` is what gates ownership — see [S-COL-16](#s-col-16--grantshare-creates-or-updates-a-share-row-as-the-owner). Callers that omit `$owner` skip the check; that's intended for trusted contexts (artisan commands, fixtures), and is the caller's responsibility to keep off untrusted surfaces.

**Verify with.** Tinker — assert the exception class on both calls.

**Source.** [services.md → grantShare](../services.md#grantshare), [services.md → revokeShare](../services.md#revokeshare).

---

## Discovery boundaries

### S-COL-09 — Lexical and semantic search return shared notes under default scope

**Intent.** Default `searchNotes` and `semanticSearch` scopes use `accessibleBy`, which includes shared-with-me. So shared notes show up in regular search.

**Preconditions.** `references/shared-doc` shared with Bob, containing the query term.

**Steps.**
1. As Bob: `Commonplace::searchNotes('term', $bob);`
2. As Bob: `Commonplace::semanticSearch('related query', $bob, SemanticSearchScope::Accessible);`

**Expected.** Both include `references/shared-doc`.

**Verify with.** Tinker.

**Source.** [services.md → searchNotes](../services.md#searchnotes), [services.md → semanticSearch](../services.md#semanticsearch).

---

### S-COL-10 — `SemanticSearchScope::Mine` excludes shared notes

**Intent.** `Mine` is owner-only. The recipient can't dredge up shared notes by accident under that scope.

**Preconditions.** Same as S-COL-09.

**Steps.**
1. As Bob: `Commonplace::semanticSearch('related query', $bob, SemanticSearchScope::Mine);`

**Expected.** `references/shared-doc` is excluded. Only Bob-owned matches.

**Verify with.** Tinker.

**Source.** [services.md → semanticSearch](../services.md#semanticsearch).

---

### S-COL-11 — `getHubNotes` excludes shared notes for the recipient

**Intent.** Hubs are an owned-vault metric. A note Alice shared with Bob counts toward Alice's hubs (in Alice's vault) and never toward Bob's.

**Preconditions.** `references/shared-doc` shared with Bob, high inbound link count from Bob's own notes.

**Steps.**
1. As Bob: `Commonplace::getHubNotes($bob);`

**Expected.** `references/shared-doc` is absent. Postgres-only.

**Verify with.** Tinker (Postgres connection).

**Source.** [services.md → getHubNotes](../services.md#gethubnotes), [`Commonplace.php:534`](../src/Services/Commonplace.php#L534).

---

### S-COL-12 — `getSuggestedLinks` default scope excludes shared notes as candidates

**Intent.** Suggesting `[[references/shared-doc]]` to Bob would create a link that breaks the moment Alice revokes the share. Default scope `Mine` avoids this.

**Preconditions.** Embedding + vector driver enabled. `references/shared-doc` shared with Bob; semantically similar to one of Bob's notes.

**Steps.**
1. As Bob: `Commonplace::getSuggestedLinks($bob_note_path, $bob);` (default `Mine`).
2. As Bob: `Commonplace::getSuggestedLinks($bob_note_path, $bob, scope: SemanticSearchScope::Accessible);`.

**Expected.**
- (1) `references/shared-doc` is not in the results.
- (2) It may appear.

**Verify with.** Tinker.

**Source.** [services.md → getSuggestedLinks](../services.md#getsuggestedlinks).

---

### S-COL-13 — `getBacklinks` for a shared target only returns backlinks the caller can see

**Intent.** The result set is intersected with `accessibleBy`. A private referring note Alice owns that Bob can't see won't appear, even though it does link to the shared target.

**Preconditions.** `references/shared-doc` shared with Bob. Two notes link to it: one of Alice's private notes, and one of Bob's own.

**Steps.**
1. As Bob: `Commonplace::getBacklinks('references/shared-doc', $bob);`

**Expected.** Returns Bob's note only.

**Verify with.** Tinker.

**Source.** [services.md → getBacklinks](../services.md#getbacklinks).

---

## Web UI

### S-COL-14 — Collaborator's index lists owned + shared + public notes intermixed

**Intent.** The browse view doesn't visually split owned vs shared. The `accessibleBy` scope is applied; ordering is `updated_at DESC` across the union.

> [!NOTE]
> Validation 2026-05-17: Bob's `/commonplace` recent-notes block is **empty** (not just missing shared/public — even Bob's own notes don't render). The folder list does correctly surface every accessible top-level folder (so the page itself isn't broken, only the recent-notes section). The data is reachable via `$bob->recentNotes()` from tinker, so the gap is in the view layer / controller query, not in the service. Tracked in [#98](https://github.com/non-convex-labs/laravel-commonplace/issues/98).

**Preconditions.** Bob has 2 own notes, 1 share, 1 visible public note.

**Steps.**
1. As Bob: `GET /commonplace`.

**Expected.** Four entries in the recent list. No distinction between owned and shared in the listing (a future enhancement could surface this; today it's flat).

**Verify with.** Browser, or `curl` + assertion that the response HTML contains at least one anchor whose `href` ends in each of the four note paths.

**Source.** [http-api.md → NoteController](../http-api.md#notecontroller).

---

### S-COL-15 — Edit link is absent for shared notes the caller can't write

**Intent.** The edit form is gated behind the same write check. The link / button isn't rendered for a non-owner.

> [!NOTE]
> Validation 2026-05-17: the action bar on the note-show view **renders Edit, Delete, View markdown, Download markdown, and History affordances to non-owners**, and the corresponding `GET /commonplace/edit/{path}` route returns 200 with a working textarea + save button. The mutation itself is still blocked because `Commonplace::updateNote()` / `deleteNote()` enforce ownership server-side (and the PUT/DELETE form submission rejects via that path), but the UI layer and the controller routes for the edit/delete forms are not gated. Tracked in [#98](https://github.com/non-convex-labs/laravel-commonplace/issues/98). Defense-in-depth — the spec wants the gate at view + controller + service, not just service.

**Preconditions.** `references/shared-doc` shared with Bob (read only).

**Steps.**
1. As Bob: `GET /commonplace/references/shared-doc`.
2. As Bob: `GET /commonplace/edit/references/shared-doc`.
3. As Bob: `POST /commonplace/references/shared-doc` with `_method=DELETE` and a valid CSRF token.

**Expected.**
- Step 1: the view renders the note. No `<a href=".../commonplace/edit/...">`, no `<form>` carrying `_method=DELETE`. View-markdown and download links may remain (read affordances are fine).
- Step 2: 403 `AuthorizationException`. The edit form is never served to a non-owner.
- Step 3: 403, and `commonplace_notes` row for `references/shared-doc` is unchanged. No `NoteVersion` is written.

**Verify with.** Browser inspection (step 1) + `curl -I` (steps 2–3) + a tinker assertion that the row's `content_hash` is unchanged.

**Source.** [http-api.md → NoteController](../http-api.md#notecontroller), `src/Http/Controllers/NoteController.php` (`edit`, `update`, `destroy` need an ownership policy / gate up front).

---

## Share management API

### S-COL-16 — `grantShare` creates or updates a share row as the owner

**Intent.** First-class API for owners to extend access. Idempotent on `(note_id, user_id)`: a second call with a different `permission` updates the existing row rather than inserting a duplicate.

**Preconditions.** Alice owns `references/shared-doc`. A third user `$carol` is authenticated. Bob's existing share on the note (per S-COL-01) is irrelevant — this scenario tests the grant operation against Carol.

**Steps.**
1. As Alice: `Commonplace::grantShare('references/shared-doc', $carol, 'read', $alice);`.
2. As Alice: `Commonplace::grantShare('references/shared-doc', $carol, 'write', $alice);`.

**Expected.**
- Step 1 returns a `Share` row with `permission=read`, `note_id` = the doc's id, `user_id` = Carol's id.
- Step 2 returns the *same* row (same `id`) with `permission=write`. `commonplace_shares` has exactly one row for `(note, Carol)` afterwards.
- Passing `$permission` outside `['read', 'write']` raises `InvalidArgumentException`.
- Today the write check (`checkAccess(..., 'write')`) still requires ownership — see [S-COL-05](#s-col-05--collaborator-cannot-update-a-shared-note). `permission=write` is captured for future use but doesn't yet unlock writes.

**Verify with.** Tinker, plus `SELECT count(*) FROM commonplace_shares WHERE user_id = ?` against Carol.

**Source.** [services.md → grantShare](../services.md#grantshare).

---

### S-COL-17 — `revokeShare` deletes the row and returns whether one was removed

**Intent.** Symmetric to `grantShare`. Returns a boolean so a caller can distinguish "removed" from "no-op" without an extra query.

**Preconditions.** S-COL-16 has run; Carol has a `write` share on `references/shared-doc`.

**Steps.**
1. As Alice: `Commonplace::revokeShare('references/shared-doc', $carol, $alice);`.
2. As Alice: re-call the same `revokeShare`.

**Expected.**
- Step 1 returns `true`. `commonplace_shares` has zero rows for `(note, Carol)`.
- Step 2 returns `false` (idempotent — no row to delete).
- Carol's subsequent `readNote('references/shared-doc', $carol)` throws `AuthorizationException` per [S-COL-02](#s-col-02--revoking-a-share-removes-access-immediately).

**Verify with.** Tinker — capture and compare the boolean return.

**Source.** [services.md → revokeShare](../services.md#revokeshare).

---

### S-COL-18 — `listShares` returns the owner's share rows with recipient eager-loaded

**Intent.** Owners need to see who currently has access. The returned `Collection<Share>` has `user` (the recipient) eager-loaded so a UI can render names without an N+1.

**Preconditions.** Alice owns `references/shared-doc`. Bob has read; Carol has write.

**Steps.**
1. As Alice: `Commonplace::listShares('references/shared-doc', $alice);`.
2. As Bob (non-owner) with the ownership check enabled: `Commonplace::listShares('references/shared-doc', $bob);`.

**Expected.**
- Step 1 returns a `Collection<Share>` of size 2. Each `Share` has `permission` and the `user` relation already loaded — `$share->user->name` does not trigger a query.
- Step 2 throws `AuthorizationException`. Omitting `$owner` skips the check (for fixture / artisan use); the caller chooses whether to enforce.

**Verify with.** Tinker; assert `DB::getQueryLog()` shows no extra queries when reading `$shares->first()->user->name`.

**Source.** [services.md → listShares](../services.md#listshares).
