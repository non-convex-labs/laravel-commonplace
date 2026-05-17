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
1. `Share::where(['note_id' => $note->id, 'user_id' => $bob->id])->delete();`
2. As Bob: `Commonplace::readNote('references/shared-doc', $bob);`.

**Expected.** Step 2 throws `ModelNotFoundException`.

**Verify with.** Tinker.

**Source.** [model-relationships.md → Share](../model-relationships.md#share).

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

**Intent.** Share management is the owner's prerogative. There is no UI or service method that lets a recipient sub-share what they've been given.

> [!NOTE]
> Validation 2026-05-17: the package today has **no first-class API** for granting or revoking shares — not in the `Commonplace` service, not in `routes/web.php`, not in `src/Mcp/Tools/`. Adopters must `Share::create([...])` directly. That makes this scenario trivially "pass" (there's nothing exposed for a non-owner to call) but masks a real gap: an owner also has no first-class API to *grant* a share. Tracked in [#63](https://github.com/non-convex-labs/laravel-commonplace/issues/63).

**Preconditions.** `references/shared-doc` shared with Bob.

**Steps.**
1. As Bob, attempt to write a `Share` row for a third user against the same note.

**Expected.** The package exposes no API for this. The model is plain Eloquent so a determined caller can `Share::create(...)` directly — that's outside the service surface and outside the package's protection. The HTTP / MCP surface offers no endpoint.

**Verify with.** Grep for share endpoints in `routes/web.php` and `src/Mcp/Tools/` — none exist.

**Source.** [http-api.md → All routes](../http-api.md#all-routes), [mcp-tools.md → Tools matrix](../mcp-tools.md#tools-matrix).

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

**Preconditions.** Bob has 2 own notes, 1 share, 1 visible public note.

**Steps.**
1. As Bob: `GET /commonplace`.

**Expected.** Four entries in the recent list. No distinction between owned and shared in the listing (a future enhancement could surface this; today it's flat).

**Verify with.** Browser.

**Source.** [http-api.md → NoteController](../http-api.md#notecontroller).

---

### S-COL-15 — Edit link is absent for shared notes the caller can't write

**Intent.** The edit form is gated behind the same write check. The link / button isn't rendered for a non-owner.

**Preconditions.** `references/shared-doc` shared with Bob.

**Steps.**
1. As Bob: `GET /commonplace/references/shared-doc`.

**Expected.** The view renders the note. No edit affordance is shown to non-owners. A direct `GET /commonplace/edit/references/shared-doc` returns 403 (`AuthorizationException`).

**Verify with.** Browser inspection + direct URL probe.

**Source.** [http-api.md → NoteController](../http-api.md#notecontroller).
