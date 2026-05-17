# Artisan commands

The package ships two Artisan commands for diagnosing vector search and rebuilding embeddings.

**Source files:**

- [`src/Console/DoctorCommand.php`](../src/Console/DoctorCommand.php) — `commonplace:doctor`
- [`src/Console/ReindexCommand.php`](../src/Console/ReindexCommand.php) — `commonplace:reindex`
- [`src/CommonplaceServiceProvider.php`](../src/CommonplaceServiceProvider.php) — registration (lines 46–47, via Spatie Package Tools `hasCommand(...)`)

## Overview

| Command | When to use |
|---|---|
| `commonplace:doctor` | Before opening a bug report, after deploying, after switching embedding driver or vector backend, and as a CI step (`--exit-code`). |
| `commonplace:reindex` | After switching embedding driver, after changing model dimensions, or to backfill notes created while the queue was down. |

Doctor never mutates state. Reindex with `--force` clears `indexed_at` on every note and is the only way to recover from embedding dimension drift surfaced by doctor.

## `commonplace:doctor`

Diagnoses the vector search configuration: driver wiring, schema, pgvector extension, dimension drift, candidate-cap headroom, and multi-user posture. Defined at [`src/Console/DoctorCommand.php:16-18`](../src/Console/DoctorCommand.php).

**Signature:**

```
commonplace:doctor
    {--exit-code : Return a non-zero exit code if any check fails}
    {--pgvector-migration-precheck : Scan commonplace_notes.embedding for rows that would break the pgvector ALTER and exit}
```

### Flags

| Flag | Type | Default | Purpose |
|---|---|---|---|
| `--exit-code` | bool | `false` | Return exit code 1 when any check has `fail` status. Without this, doctor always returns 0 so casual runs don't break CI. |
| `--pgvector-migration-precheck` | bool | `false` | Skip the standard checks; instead scan `commonplace_notes.embedding` for rows whose value would crash the `ALTER ... USING embedding::vector(N)` cast. Lists up to 100 offending row ids. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | All checks passed, or failures present but `--exit-code` not set. |
| `1` | At least one check failed and `--exit-code` is set. Also returned by `--pgvector-migration-precheck` when offending rows are found and `--exit-code` is set, or when the schema query itself fails. |

### Common invocations

Run as a smoke test after install or after editing `.env`:

```bash
php artisan commonplace:doctor
```

Use in CI to fail the build on a misconfiguration:

```bash
php artisan commonplace:doctor --exit-code
```

Before applying the published pgvector migration, sanity-check that no legacy JSON-encoded embeddings will trip the type cast:

```bash
php artisan commonplace:doctor --pgvector-migration-precheck
```

### Pre/post conditions

- Read-only. Safe to run repeatedly, in production, and against a live database.
- Requires a working database connection. Resolves `EmbeddingProvider` and `VectorSearchDriver` from the container — container binding errors surface as failed checks rather than uncaught exceptions.
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
| `--force` | bool | `false` | `UPDATE commonplace_notes SET indexed_at = NULL` before dispatching, so every note is re-embedded. Required after switching driver or model — without it, the job only embeds rows where `indexed_at IS NULL OR updated_at > indexed_at`. |
| `--sync` | bool | `false` | Dispatch via `dispatchSync()` so the job runs inline in the current process. Without it, the job is queued and a worker must be running. |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | The job was dispatched (queued or sync). The command does not surface job-level failures back as a non-zero exit. |

### Common invocations

After switching the embedding driver from `null` to `openai` (or between providers), force a full re-embed:

```bash
php artisan commonplace:reindex --force
```

Run inline against a small dev vault without spinning up a worker:

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

## Related

- [embedding-drivers.md](embedding-drivers.md) — choosing a driver and the configuration each one expects
- [vector-storage.md](vector-storage.md) — how embeddings are stored, dimension drift, and pgvector migration
- [backup.md](backup.md) — backup/restore workflow (separate command surface; not part of this package's Artisan layer)
