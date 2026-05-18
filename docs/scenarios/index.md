# Scenarios

Narrative behavior specs for `laravel-commonplace`, grouped by who is doing the thing.

Each file in this folder describes how one kind of caller — a human note-taker, an AI agent over MCP, a shared collaborator, an unauthenticated visitor, the developer installing the package, the operator running it — uses the package end-to-end. Scenarios pin down **intended** behavior. The reference docs ([services.md](../services.md), [mcp-tools.md](../mcp-tools.md), [http-api.md](../http-api.md), [commands.md](../commands.md), etc.) describe **what exists**. These describe **what the package promises to do**.

Use this folder to:

- Drive a verification pass against a running install — work through a persona's scenarios, tick off the ones that actually behave as written, flag the ones that don't.
- Catch missing-from-docs behavior early — if you can't write the verify-with step, the doc probably owes a reader more.
- Anchor design discussion when adding a new feature — the new behavior gets a scenario before it gets a method.

## Personas

| File | Persona | Acts via | Auth model |
|---|---|---|---|
| [note-taker.md](note-taker.md) | The vault owner. Reads, writes, organizes, and searches their own notes. | Web UI ([http-api.md](../http-api.md)) + facade ([services.md](../services.md)) | `web,auth` session |
| [ai-agent.md](ai-agent.md) | Claude Code, Cursor, Zed, or another MCP client acting on behalf of a user. | The 16 MCP tools ([mcp-tools.md](../mcp-tools.md)) | `auth:sanctum` Bearer (default) |
| [collaborator.md](collaborator.md) | A second user the owner has granted access to specific notes via `Share`. | Same surface as the note-taker, scoped to what's shared / public. | Same as note-taker |
| [public-visitor.md](public-visitor.md) | An unauthenticated reader. | `GET /{prefix}/public/{path}` only ([auth.md](../auth.md#public-read-mode)). | none |
| [integrator.md](integrator.md) | The developer wiring this package into a Laravel app. | `composer require`, `vendor:publish`, container bindings, env. | n/a (install-time) |
| [operator.md](operator.md) | Whoever runs the system after install. | Artisan commands ([commands.md](../commands.md)), queue worker, scheduler. | shell access |

Personas can overlap (the integrator is often also the operator and note-taker). The split is about *what surface they're driving*, not *who they are*.

## Scenario format

Every scenario in this folder follows the same shape:

```markdown
### S-PFX-NN — Short title

**Intent.** One sentence: what the persona is trying to accomplish and why it matters.

**Preconditions.** Bulleted setup the system must be in before the scenario runs.

**Steps.** Numbered list of the actions the persona takes.

**Expected.** Bulleted observable outcomes — what the persona sees, what the database holds, what side effects fire.

**Verify with.** A concrete artifact a reviewer can run: a tinker snippet, an HTTP request, a CLI invocation, a SQL query.

**Source.** Links to the docs and source files that own this behavior.
```

The `PFX` is a stable per-persona prefix:

| Persona | Prefix |
|---|---|
| note-taker | `NOTE` |
| ai-agent | `AI` |
| collaborator | `COL` |
| public-visitor | `PUB` |
| integrator | `INT` |
| operator | `OPS` |

`NN` is a zero-padded sequence within the file. IDs are stable once published — append new scenarios at the end of the file rather than renumbering.

## Cross-cutting invariants

These show up across personas. They're the rules every scenario implicitly relies on.

- **Visibility scope.** Every read goes through `Note::accessibleBy($user)` (owned OR `visibility=public` OR present in `commonplace_shares`). See [model-relationships.md → Visibility model](../model-relationships.md#visibility-model-how-accessibleby-works). The enumeration defense — for a given path, the response shape is indistinguishable whether the note is missing or just inaccessible to the caller — now holds on every read surface: the MCP read tools and the public-read route both canonicalise to `Note not found.` ([mcp-tools.md → Visibility model](../mcp-tools.md#visibility-model)); the **authenticated web surface** collapses too — `GET /{prefix}/{path}` falls through to the folder browser (HTTP 200) for both inaccessible and missing paths, while `GET /{prefix}/raw/{path}`, `GET /{prefix}/download/{path}`, `GET /{prefix}/history/{path}`, `GET /{prefix}/graph/neighborhood/{path}`, and `GET /{prefix}/suggested-links/{path}` all 404 for both. Note the scoping: the breadcrumbs and `<h1>` in the show route's folder-fallback reflect the URL path, so two *different* paths produce different responses — only an attacker probing the *same* path on both sides sees identical bodies, which is the realistic threat model. The service layer remains informative — `Note::accessibleBy()` callers see typed `AuthorizationException` vs `ModelNotFoundException` because in-process callers are trusted. Write surfaces (`edit`, `update`, `destroy`) still return 403 on inaccessible by design (per S-AI-26: write callers have already proven path knowledge, so the more specific error is more useful). A residual timing side channel exists — the inaccessible branch runs an extra row-hit + `checkAccess` before falling through — and is out of scope for the package's threat model; closing it would require constant-time path normalisation that is not implemented.
- **Write check.** Mutations go through `Commonplace::checkAccess()`. Owner-only operations (delete, move, share grants) ignore `permission=write` shares. See [services.md](../services.md).
- **Frontmatter wins.** YAML frontmatter (`title`, `visibility`, `tags`) overrides the explicit arguments passed to `createNote` / `updateNote`. See [services.md → createNote](../services.md#createnote).
- **Path / line-ending normalization.** Paths normalize `\` → `/`. Content normalizes `\r\n` / `\r` → `\n` before storage.
- **Versioning.** Every content change writes a `NoteVersion` row (immutable, append-only). Deletion writes one final snapshot. Version history survives note deletion. See [model-relationships.md → NoteVersion](../model-relationships.md#noteversion).
- **Async wikilink rewrites.** `moveNote` and `updateNote(..., new_path)` dispatch `UpdateWikilinksJob` via `DB::afterCommit`. Queue worker must be running, or the rewrites land late. `commonplace:relink` is the recovery path for orphaned link rows. See [services.md → moveNote](../services.md#movenote).
- **Embedding dimension drift.** Switching embedding driver or model without `commonplace:reindex --force` produces dimension mismatches. `in_php_cosine` skips mismatched rows with a warning; `pgvector` errors on insert. See [vector-storage.md → Dimension mismatches](../vector-storage.md#dimension-mismatches).
- **Postgres-only graph queries.** `getNeighborhood`, `getShortestPath`, `getHubNotes` use recursive CTEs + `ARRAY[]` syntax. They don't run on SQLite or MySQL. See [services.md → Graph queries](../services.md#graph-queries).
- **Doctor never mutates.** `commonplace:doctor` is read-only. `commonplace:reindex --force` is the only path to recover from dimension drift. See [commands.md](../commands.md).

When a scenario depends on one of these invariants, it links here instead of restating it.

## How to use this folder during verification

1. Pick the persona whose surface you want to validate (e.g. `ai-agent.md` if you're testing the MCP integration).
2. Spin up an environment that satisfies the persona's preconditions (an authenticated user; MCP enabled; etc.).
3. Walk each scenario top to bottom. Run the **Verify with** step. Tick the ones that pass.
4. For any failure, capture the diff between **Expected** and observed in an issue. Reference the scenario ID.
5. When you find a behavior the docs claim but no scenario covers, add a scenario before opening the issue. The scenario file is part of the spec.

When adding a new feature, write the scenarios first, then the docs, then the implementation. Scenarios drift fastest when they're written last.

## Validation log

Scenarios in this folder are the **spec** — they describe what the package should do. When validation against a running install finds a divergence, the scenario stays as-is and the divergence is tracked in a GitHub issue. The table below links scenarios with known gaps to the issues that own the fix.

Last full pass: **2026-05-18**, re-run against `main` (commit `1647e52`) after the #108 / #109 / #110 fixes landed; Laravel 13.9 + Voyage (`voyage-3.5`, 1024 dim). Sandbox lives at [commonplace-sandbox](https://github.com/non-convex-labs/commonplace-sandbox) and now runs under **flavor subfolders** so we can exercise the env-flip and Postgres-only scenarios alongside the default config without disturbing it.

| Sandbox flavor | Stack | Scenarios covered | Server |
|---|---|---|---|
| `main/` | SQLite + `in_php_cosine` + Voyage + MCP `auth:sanctum` + public-read on | All baseline: `scenarios-note-taker.php`, `scenarios-web.py`, `scenarios-mcp.py`, `scenarios-sharing-public.py`, `scenarios-gaps.php` (S-NOTE-19/31/32, S-COL-02/04/08/12/16-18, S-AI-06/22, S-INT-04/05/21), `scenarios-gaps2.py` (S-NOTE-01/29/30, S-AI-03/17/18/25/26, S-COL-14/15, S-PUB-01b/05/08/09). Operator scenarios S-OPS-01/02/09/10/12/13/14/26 spot-verified via artisan. | :8123 |
| `mcp-disabled/` | `COMMONPLACE_MCP_ENABLED=false` | S-AI-02 — `POST /mcp/commonplace` returns 404 (not 405). | ephemeral :8124 |
| `mcp-misconfig/` | `COMMONPLACE_MCP_MIDDLEWARE=""` | S-AI-05 / S-OPS-06 — doctor `[FAIL]` on MCP middleware empty; `--exit-code` exits 1. | n/a (CLI only) |
| `public-disabled/` | `COMMONPLACE_PUBLIC_ROUTES_ENABLED=false` | S-PUB-06 — `/commonplace/public/*` returns 404 from the framework. | ephemeral :8124 |
| `public-throttled/` | `COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE="web,throttle:30,1"` (canonical two-arg form, parsed via `MiddlewareList::parse` after [#108](https://github.com/non-convex-labs/laravel-commonplace/issues/108)) | S-PUB-07 — 30× 200, 31st 429. Confirmed against both the old workaround (`throttle:30`) and the canonical `throttle:30,1` form after re-publishing `config/commonplace.php`. | ephemeral :8124 |
| `public-prefix-share/` | `COMMONPLACE_PUBLIC_ROUTES_PREFIX=commonplace/share` | S-PUB-10 — public group moves to `/share`; the auth catch-all now reaches notes under `public/` for the owner. | ephemeral :8124 |
| `sanctum-removed/` | `composer remove laravel/sanctum` | S-OPS-07 — doctor `[FAIL]` "auth:sanctum in stack but Laravel\\Sanctum\\Sanctum class is not loaded". | n/a |
| `postgres/` | Postgres 17 + pgvector 0.8.2 (`podman run docker.io/pgvector/pgvector:pg17`); `COMMONPLACE_VECTOR_DRIVER=pgvector` | S-NOTE-18a/b/c/d / S-AI-19 / S-AI-20 / S-AI-21 / S-COL-11 — all graph queries execute without exception after [#109](https://github.com/non-convex-labs/laravel-commonplace/issues/109). S-AI-25 — a deliberately-triggered `QueryException` (unique-constraint violation) now returns HTTP 200 with `{result.isError:true}` instead of a bare 500, confirming [#110](https://github.com/non-convex-labs/laravel-commonplace/issues/110). Doctor reports all 13 checks `[OK]`. | ephemeral :8125 |
| `integrator-extensions/` | Published views + CSS; custom `AppServiceProvider` with extender bindings | S-INT-13 (`HeadingPermalinkExtension` in config — `heading-permalink` class lands in HTML), S-INT-14 (`Commonplace::extendMarkdown` callback runs — `[MENTION:alice]` appears), S-INT-15 (rebound `WikilinkResolver` — wikilinks render with `href="/docs/..."`), S-INT-16 (HTML-comment marker survives the override), S-INT-18 (custom `@section('commonplace.nav')` replaces topbar). S-INT-17 partial — the published CSS at `resources/css/commonplace/commonplace.css` accepts the override but the `AssetController` (`src/Http/Controllers/AssetController.php:14`) serves CSS from the **package's** `__DIR__/../../../resources/css/...`, so the published file only takes effect if the consumer builds and serves it themselves; the spec wording is misleading on this point. S-INT-19 — custom `BackupDestination` bound under a name and invoked via `Bus::dispatchSync(new BackupVault())`: `RecordingDestination::push()` wrote a marker file with the seeded bundle's note count. S-INT-20 (custom user model) — deferred; requires a fresh schema. | ephemeral :8126 |

Playwright walk-through of Alice + Bob against `main/` confirmed S-NOTE-01 (create form → owned by caller), S-NOTE-20a/22/23/24/29/30, S-PUB-01b, and S-COL-14/15a/15b visually.

_Open divergences from this pass:_

- [#118](https://github.com/non-convex-labs/laravel-commonplace/issues/118) → S-AI-25 (info disclosure, broader scope, *partially resolved*) — `LostConnectionException` and bare `PDOException` (including `DeadlockException`) are now redacted to generic strings ("Database connection lost." / "Database error.") so the sibling DB exception classes the #115 `QueryException` branch missed no longer leak. Open: non-DB Throwables (`ErrorException` with file paths, HTTP client exceptions with internal URLs, `LockTimeoutException` with cache keys) still pass `getMessage()` through verbatim — closing this requires an allowlist-sanitiser design that opts tool-thrown user-facing messages in.

The three issues flagged in the original 2026-05-18 pass (#108 / #109 / #110) are all closed; see below.

Not exercised this pass: Voyage fault injection (S-OPS-24/25 — needs `Http::fake` in a feature test), backup to real GitHub (S-OPS-16/17/19), Octane (S-OPS-21 — Octane not installed), S-INT-20 (custom user model — deferred).

### Closed since the 2026-05-18 multi-flavor pass

- [#108](https://github.com/non-convex-labs/laravel-commonplace/issues/108) → S-PUB-07, S-AI-05 / S-OPS-06, and any consumer using parameterized middleware — `MiddlewareList::parse` now preserves param commas so `COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE="web,throttle:30,1"` parses as a single token. Same parser covers `COMMONPLACE_ROUTES_MIDDLEWARE` and `COMMONPLACE_MCP_MIDDLEWARE`. Consumers on the old published config need `php artisan vendor:publish --tag=commonplace-config --force` to pick up the fix.
- [#109](https://github.com/non-convex-labs/laravel-commonplace/issues/109) → S-NOTE-18a/b/c, S-AI-19, S-AI-20 — Postgres `getNeighborhood` / `getShortestPath` recursive CTEs now cast the seed `note_id` to `bigint`, so the join no longer fails with `operator does not exist: bigint = text`.
- [#110](https://github.com/non-convex-labs/laravel-commonplace/issues/110) → S-AI-25 — tool `Throwable`s are now caught at the MCP transport boundary and wrapped as a JSON-RPC `result` with `isError:true`. HTTP stays 200 on tool-level failures instead of leaking as a bare 500.
- [#115](https://github.com/non-convex-labs/laravel-commonplace/issues/115) → S-AI-25 (info disclosure) — the MCP envelope now collapses `QueryException` to `Database error: SQLSTATE[<code>]`, stripping the DB host/port/database segments, the parameterized SQL trace, and PDO's `DETAIL:` row data. Operators still see the full exception via `report()`. Follow-up [#118](https://github.com/non-convex-labs/laravel-commonplace/issues/118) tracks the broader allowlist sanitiser for non-`QueryException` Throwables.
- [#116](https://github.com/non-convex-labs/laravel-commonplace/issues/116) → S-INT-17 (asset routing) — `AssetController::css` now serves the published override at `resources/css/commonplace/commonplace.css` when one is present, falling back to the bundled copy otherwise. The published stub carries a header warning that the file pins the consumer to the version they published — `--force` re-publishes to pick up bundled upgrades. `js()` stays bundled-only by design (no `commonplace-js` publish tag exists; introducing an override path for an unversioned asset would create a hidden script-injection extension point).
- [#121](https://github.com/non-convex-labs/laravel-commonplace/issues/121) → theming iteration loop — `AssetController::css` now emits `Cache-Control: no-store` whenever `APP_DEBUG=true` (any environment — local dev, a staging deploy chasing a prod-shaped bug). Production keeps the `public, max-age=3600` policy. `js()` is unaffected (bundled-only, no override path). Followup from #116.
- [#122](https://github.com/non-convex-labs/laravel-commonplace/issues/122) → JS test now asserts the bundled `commonplace.js` header marker positively (`Commonplace — knowledge graph renderer`), so an empty or whitespace-only response can't pass the negative-only assertion. Followup from #116.
- [#117](https://github.com/non-convex-labs/laravel-commonplace/issues/117) → cross-cutting "Visibility scope" invariant — doc-only carve-out. The invariant now explicitly states that the canonicalisation to "Note not found." is uniform on MCP read tools and the public-read route, while the authenticated web surface returns 403 for inaccessible existing notes and falls through to a 200 folder browser for non-existent paths. S-NOTE-03 and the `GET /{path}` fallback chain in `http-api.md` updated to match. Superseded by [#123](https://github.com/non-convex-labs/laravel-commonplace/issues/123) — the behavior-side fix collapses the asymmetry across the whole authenticated read surface.
- [#123](https://github.com/non-convex-labs/laravel-commonplace/issues/123) → "Visibility scope" enumeration leak (behavior side) — `NoteController::show` now falls through to the folder browser on `AuthorizationException` (same response shape as a missing path). `showRaw`, `downloadRaw`, `history`, `historyVersion`, `GraphController::neighborhood`, and `SearchController::suggestedLinks` all 404 for both inaccessible and missing. Write surfaces (`edit`, `update`, `destroy`) keep their 403 by design (S-AI-26). The invariant in this file and the matching docs in `http-api.md` / `scenarios/note-taker.md` / `scenarios/collaborator.md` are rewritten to describe the now-uniform read surface. **Note for HTTP API consumers:** scripts that relied on `403` to detect access denial on read routes now see `200`/`404` — use the share API to check access intent.

### Closed in the 2026-05-17 pass

- [#95](https://github.com/non-convex-labs/laravel-commonplace/issues/95) → S-AI-17 — `move-tool` no longer rewrites wikilinks inside code fences ([#103](https://github.com/non-convex-labs/laravel-commonplace/pull/103)).
- [#96](https://github.com/non-convex-labs/laravel-commonplace/issues/96) → S-PUB-04 — bare `/{prefix}/public/` now 404s ([#105](https://github.com/non-convex-labs/laravel-commonplace/pull/105)).
- [#97](https://github.com/non-convex-labs/laravel-commonplace/issues/97) → S-PUB-05, S-PUB-06 — PUT/DELETE on public URL now 405; toggle-off 404s instead of redirecting ([#106](https://github.com/non-convex-labs/laravel-commonplace/pull/106)).
- [#98](https://github.com/non-convex-labs/laravel-commonplace/issues/98) → S-COL-14, S-COL-15 — index lists recent accessible notes; show/edit gated by ownership / write-share ([#107](https://github.com/non-convex-labs/laravel-commonplace/pull/107)).
- [#99](https://github.com/non-convex-labs/laravel-commonplace/issues/99) → S-NOTE-03, S-COL-02, S-INT-21 — two-tier exception model and S-NOTE-20 fallback documented ([#100](https://github.com/non-convex-labs/laravel-commonplace/pull/100)).

### Closed in earlier passes

- [#68](https://github.com/non-convex-labs/laravel-commonplace/issues/68) → S-PUB-01, S-PUB-01b (public chrome) — fixed by [#88](https://github.com/non-convex-labs/laravel-commonplace/pull/88).
- [#63](https://github.com/non-convex-labs/laravel-commonplace/issues/63) → S-COL-08 — `grantShare`/`revokeShare`/`listShares` shipped in [#91](https://github.com/non-convex-labs/laravel-commonplace/pull/91); see [S-COL-16](collaborator.md#s-col-16--grantshare-creates-or-updates-a-share-row-as-the-owner) / [S-COL-17](collaborator.md#s-col-17--revokeshare-deletes-the-row-and-returns-whether-one-was-removed) / [S-COL-18](collaborator.md#s-col-18--listshares-returns-the-owners-share-rows-with-recipient-eager-loaded).
- [#65](https://github.com/non-convex-labs/laravel-commonplace/issues/65) → S-OPS-09 — cooldown documented in [#83](https://github.com/non-convex-labs/laravel-commonplace/pull/83); scenario rewritten to describe actual behavior.
- [#69](https://github.com/non-convex-labs/laravel-commonplace/issues/69) → S-NOTE-29 — web-UI history view shipped in [#92](https://github.com/non-convex-labs/laravel-commonplace/pull/92).
