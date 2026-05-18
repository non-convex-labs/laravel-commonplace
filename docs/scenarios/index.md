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

- **Visibility scope.** Every read goes through `Note::accessibleBy($user)` (owned OR `visibility=public` OR present in `commonplace_shares`). See [model-relationships.md → Visibility model](../model-relationships.md#visibility-model-how-accessibleby-works). Missing notes and inaccessible notes both surface as `Note not found.` to prevent path enumeration ([mcp-tools.md → Visibility model](../mcp-tools.md#visibility-model)).
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

Last full pass: **2026-05-17**, against `main` (commit `bf6d762`), Laravel 13.9 + SQLite + `in_php_cosine` + Voyage (`voyage-3.5`, 1024 dim). Sandbox: [commonplace-sandbox/scripts/](https://github.com/non-convex-labs/commonplace-sandbox/tree/main/scripts) — `scenarios-note-taker.php`, `scenarios-web.py`, `scenarios-mcp.py`, `scenarios-sharing-public.py`.

_No known divergences as of 2026-05-18._

### Closed since the last pass

- [#95](https://github.com/non-convex-labs/laravel-commonplace/issues/95) → S-AI-17 — `move-tool` no longer rewrites wikilinks inside code fences ([#103](https://github.com/non-convex-labs/laravel-commonplace/pull/103)).
- [#96](https://github.com/non-convex-labs/laravel-commonplace/issues/96) → S-PUB-04 — bare `/{prefix}/public/` now 404s ([#105](https://github.com/non-convex-labs/laravel-commonplace/pull/105)).
- [#97](https://github.com/non-convex-labs/laravel-commonplace/issues/97) → S-PUB-05, S-PUB-06 — PUT/DELETE on public URL now 405; toggle-off 404s instead of redirecting ([#106](https://github.com/non-convex-labs/laravel-commonplace/pull/106)).
- [#98](https://github.com/non-convex-labs/laravel-commonplace/issues/98) → S-COL-14, S-COL-15 — index lists recent accessible notes; show/edit gated by ownership / write-share.
- [#99](https://github.com/non-convex-labs/laravel-commonplace/issues/99) → S-NOTE-03, S-COL-02, S-INT-21 — two-tier exception model and S-NOTE-20 fallback documented (closed by [#100](https://github.com/non-convex-labs/laravel-commonplace/pull/100)).

### Closed in the prior pass

- [#68](https://github.com/non-convex-labs/laravel-commonplace/issues/68) → S-PUB-01, S-PUB-01b (public chrome) — fixed by [#88](https://github.com/non-convex-labs/laravel-commonplace/pull/88).
- [#63](https://github.com/non-convex-labs/laravel-commonplace/issues/63) → S-COL-08 — `grantShare`/`revokeShare`/`listShares` shipped in [#91](https://github.com/non-convex-labs/laravel-commonplace/pull/91); see [S-COL-16](collaborator.md#s-col-16--grantshare-creates-or-updates-a-share-row-as-the-owner) / [S-COL-17](collaborator.md#s-col-17--revokeshare-deletes-the-row-and-returns-whether-one-was-removed) / [S-COL-18](collaborator.md#s-col-18--listshares-returns-the-owners-share-rows-with-recipient-eager-loaded).
- [#65](https://github.com/non-convex-labs/laravel-commonplace/issues/65) → S-OPS-09 — cooldown documented in [#83](https://github.com/non-convex-labs/laravel-commonplace/pull/83); scenario rewritten to describe actual behavior.
- [#69](https://github.com/non-convex-labs/laravel-commonplace/issues/69) → S-NOTE-29 — web-UI history view shipped in [#92](https://github.com/non-convex-labs/laravel-commonplace/pull/92).
