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

Last full pass: **2026-05-17**, against `main` (commit `2d3b300`), Laravel 13.9 + SQLite + `in_php_cosine` + Voyage (`voyage-3.5`, 1024 dim).

| Scenario | Issue | Status |
|---|---|---|
| [S-NOTE-04](note-taker.md#s-note-04--update-writes-a-noteversion-and-clears-indexed_at) | [#62](https://github.com/non-convex-labs/laravel-commonplace/issues/62) | Spec ambiguous: does `createNote` write a `NoteVersion`? |
| [S-AI-07](ai-agent.md#s-ai-07--read-note-tool-returns-full-content-absent-or-inaccessible-notes-both-say-note-not-found) | [#67](https://github.com/non-convex-labs/laravel-commonplace/issues/67) | Error-message differential leaks path existence |
| [S-AI-15](ai-agent.md#s-ai-15--backlinks-tool-requires-target-visibility) | [#67](https://github.com/non-convex-labs/laravel-commonplace/issues/67) | `backlinks-tool` returns `[]` for inaccessible target (silent leak) |
| [S-PUB-01](public-visitor.md#s-pub-01--get-commonplacepublicpath-renders-public-notes) | [#68](https://github.com/non-convex-labs/laravel-commonplace/issues/68) | Public template renders authenticated owner chrome |
| [S-PUB-04](public-visitor.md#s-pub-04--public-read-does-not-expose-listing-search-or-graph) | [#61](https://github.com/non-convex-labs/laravel-commonplace/issues/61) | `/{prefix}/public/` route precedence ambiguity |
| [S-INT-04](integrator.md#s-int-04--php-artisan-migrate-creates-all-package-tables) | [#64](https://github.com/non-convex-labs/laravel-commonplace/issues/64) | `migrate` alone is insufficient; `vendor:publish --tag=commonplace-migrations` required; 2 migrations missing |
| [S-OPS-09](operator.md#s-ops-09--default-reindex-skips-rows-where-indexed_at--updated_at) | [#65](https://github.com/non-convex-labs/laravel-commonplace/issues/65) | 60-minute cooldown undocumented; description doesn't match actual SQL |
| [S-COL-08](collaborator.md#s-col-08--collaborator-cannot-grant-or-revoke-shares-on-a-note-they-dont-own) | [#63](https://github.com/non-convex-labs/laravel-commonplace/issues/63) | No first-class API for granting/revoking shares (gap, not bug) |
| [S-NOTE-WEB-HISTORY](note-taker.md#s-note-29--view-version-history-via-the-web-ui) | [#69](https://github.com/non-convex-labs/laravel-commonplace/issues/69) | No web-UI view for note history; only service + MCP expose it |

When a fix lands, the linked scenario gets the `> [!NOTE]` annotation removed and this row drops from the table.
