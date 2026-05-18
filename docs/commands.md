# Artisan commands

The package ships three Artisan commands. You get one for diagnosing vector search, one for rebuilding embeddings, and one for recovering wikilink graph drift.

**Source files:**

- [`src/Console/DoctorCommand.php`](../src/Console/DoctorCommand.php) — `commonplace:doctor`
- [`src/Console/ReindexCommand.php`](../src/Console/ReindexCommand.php) — `commonplace:reindex`
- [`src/Console/RelinkCommand.php`](../src/Console/RelinkCommand.php) — `commonplace:relink`
- [`src/CommonplaceServiceProvider.php`](../src/CommonplaceServiceProvider.php) — registration, via Spatie Package Tools `hasCommand(...)`

## Overview

| Command | When to use |
|---|---|
| `commonplace:doctor` | Before opening a bug report, after deploying, after switching embedding driver or vector backend, and as a CI step (`--exit-code`). |
| `commonplace:reindex` | After switching embedding driver, after changing model dimensions, or to backfill notes created while the queue was down. |
| `commonplace:relink` | After a queue outage during which `UpdateWikilinksJob` failed; doctor's "Orphaned wikilinks" warning recommends it. |

Doctor never mutates state. Reindex with `--force` clears `indexed_at` on every note, and it's the only way to recover from embedding dimension drift that doctor surfaces.

## `commonplace:doctor`

Diagnoses your install across four areas: vector search (driver wiring, schema, pgvector extension, dimension drift, candidate-cap headroom), multi-user posture, wikilink-graph integrity (orphaned link rows from a missed `UpdateWikilinksJob` — recommends [`commonplace:relink`](#commonplacerelink) above `commonplace.wikilinks.orphan_warn_threshold`, default 50), and MCP transport auth (fails when MCP is enabled but middleware is empty, or references `auth:sanctum` without Sanctum installed). Defined at [`src/Console/DoctorCommand.php`](../src/Console/DoctorCommand.php) — each check method is named `check*`.

**Signature:**

```
commonplace:doctor
    {--exit-code : Return a non-zero exit code if any check fails}
    {--live : Exercise the embedding provider with a real embedQuery call (burns paid API quota; off by default)}
    {--pgvector-migration-precheck : Scan commonplace_notes.embedding for rows that would break the pgvector ALTER and exit}
```

### Flags

| Flag | Type | Default | Purpose |
|---|---|---|---|
| `--exit-code` | bool | `false` | Return exit code 1 when any check has `fail` status. Without this, doctor always returns 0 so casual runs don't break CI. |
| `--live` | bool | `false` | Exercise the configured embedding provider with a real `embedQuery('doctor probe')` call. Verifies API key / quota / network on top of the config-only checks. Off by default because routine doctor runs would otherwise burn paid quota. Can also be enabled globally via `COMMONPLACE_DOCTOR_PROBE_EMBEDDING=true` (config key `commonplace.doctor.probe_embedding_provider`). |
| `--pgvector-migration-precheck` | bool | `false` | Skip the standard checks; instead scan `commonplace_notes.embedding` for rows whose value would crash the `ALTER ... USING embedding::vector(N)` cast. Lists up to 100 offending row ids. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | All checks passed, or failures present but `--exit-code` not set. |
| `1` | At least one check failed and `--exit-code` is set. Also returned by `--pgvector-migration-precheck` when offending rows are found and `--exit-code` is set, or when the schema query itself fails. |

### Common invocations

Run it as a smoke test after install or after editing `.env`:

```bash
php artisan commonplace:doctor
```

Use it in CI to fail the build on a misconfiguration:

```bash
php artisan commonplace:doctor --exit-code
```

Before you apply the published pgvector migration, sanity-check that no legacy JSON-encoded embeddings will trip the type cast:

```bash
php artisan commonplace:doctor --pgvector-migration-precheck
```

### Pre/post conditions

- Read-only. Safe to run repeatedly, in production, and against a live database.
- Requires a working database connection. It resolves `EmbeddingProvider` and `VectorSearchDriver` from the container — container binding errors surface as failed checks rather than uncaught exceptions.
- Emits `[OK]`, `[WARN]`, `[FAIL]`, `[SKIP]` markers followed by a recommendations footer for every non-`ok` check.

## `commonplace:reindex`

Walks all notes through the configured embedding provider via the `ReindexNotes` job. Defined at [`src/Console/ReindexCommand.php:13-15`](../src/Console/ReindexCommand.php).

**Signature:**

```
commonplace:reindex
    {--force : Clear indexed_at on every note before reindexing — required after switching embedding driver or model}
    {--sync : Run the reindex inline instead of dispatching to the queue}
```

### Flags

| Flag | Type | Default | Purpose |
|---|---|---|---|
| `--force` | bool | `false` | `UPDATE commonplace_notes SET indexed_at = NULL` on every row, then skips the cooldown filter — the job sees every note as a candidate. Required after switching driver or model. Without it, the default scope is `(indexed_at IS NULL OR indexed_at < updated_at) AND updated_at < now - cooldown`. |
| `--sync` | bool | `false` | Dispatch via `dispatchSync()` so the job runs inline in the current process. Without it, the job is queued and a worker must be running. |

### What gets picked up by default

Without `--force`, the job only embeds notes that:

- Have never been indexed (`indexed_at IS NULL`) **or** have an embedding older than the content (`indexed_at < updated_at`), **and**
- Were last touched at least `commonplace.reindex.cooldown_minutes` ago (default 60, env `COMMONPLACE_REINDEX_COOLDOWN`).

The cooldown is a save-debounce. A note created or edited within the cooldown window is skipped until it settles, so a burst of saves doesn't burn one embedding request per keystroke. If you need to embed a just-created note immediately, use `--force` or wait out the cooldown.

> [!NOTE]
> The default scope catches both never-indexed notes (`indexed_at IS NULL`) and notes whose embedding has fallen behind the content (`indexed_at < updated_at`), subject to the cooldown. So an edit after first index *is* picked up on the next reindex run, once the cooldown window passes.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | The job was dispatched (queued or sync). The command does not surface job-level failures back as a non-zero exit. |

### Common invocations

After you switch the embedding driver from `null` to `openai` (or between providers), force a full re-embed:

```bash
php artisan commonplace:reindex --force
```

Run it inline against a small dev vault without spinning up a worker:

```bash
php artisan commonplace:reindex --force --sync
```

Catch up on notes created while no queue worker was running (no `--force` — only stale rows are embedded):

```bash
php artisan commonplace:reindex
```

### Pre/post conditions

- `--force` writes to `commonplace_notes.indexed_at` (sets to `NULL`). Safe to run repeatedly; idempotent on repeated invocations once the job completes.
- Default (queued) mode requires a running queue worker. Without one, the dispatched job sits in the queue until a worker picks it up — `commonplace:doctor` does not flag this.
- `--sync` blocks until the job finishes. The job batches notes through `EmbeddingProvider::embedBatch()`; with a remote provider, large vaults can take minutes.
- Writes embeddings to `commonplace_notes.embedding` and `commonplace_notes.embedding_dimensions`, plus sets `indexed_at`. See [vector-storage.md](vector-storage.md) for the storage shape per driver.

## `commonplace:relink`

Re-resolves `commonplace_links` rows whose `target_note_id` is `NULL` against the current note set, via `WikilinkParser::resolveTarget`. Defined at [`src/Console/RelinkCommand.php`](../src/Console/RelinkCommand.php).

Orphaned link rows are the symptom that `UpdateWikilinksJob` (the move-rewrites-wikilinks job) failed silently. The typical cause is a queue worker that wasn't running when a note got moved. `commonplace:doctor` watches the count via `commonplace.wikilinks.orphan_warn_threshold` (default 50) and recommends this command above threshold.

**Signature:**

```
commonplace:relink
    {--exit-code : Return a non-zero exit code if any orphan remains after the run}
```

### Flags

| Flag | Type | Default | Purpose |
|---|---|---|---|
| `--exit-code` | bool | `false` | Return exit code 1 when at least one row is still unresolved after the pass (the target note genuinely doesn't exist). Without this, the command always returns 0. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Walked every orphan row. Some may still be unresolved if their target note doesn't exist. |
| `1` | At least one orphan remained and `--exit-code` was set. |

### Pre/post conditions

- Idempotent. Rows that already have a non-null `target_note_id` are skipped.
- **Blind spot:** only repairs `NULL` targets. Mis-resolved links — where `target_note_id` is non-null but points at the wrong note because `resolveTarget` fell through to its trailing-segment match — are not detected. A `--verify` mode for that case is tracked separately.

## Related

- [embedding-drivers.md](embedding-drivers.md) — choosing a driver and the configuration each one expects
- [vector-storage.md](vector-storage.md) — how embeddings are stored, dimension drift, and pgvector migration
- [backup.md](backup.md) — backup/restore workflow (separate command surface; not part of this package's Artisan layer)
