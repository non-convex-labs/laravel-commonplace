# Vector storage

Pick where embeddings live and how similarity search runs.

```dotenv
COMMONPLACE_VECTOR_DRIVER=in_php_cosine
```

Commonplace ships three drivers behind a single contract: `in_php_cosine` (the portable default), `pgvector` (Postgres + indexed similarity), and `null` (disabled). The [embedding driver](./embedding-drivers.md) produces the vectors; this layer stores them and runs search. Run [`php artisan commonplace:doctor`](./commands.md) to verify the driver is wired up.

## Driver matrix

| Driver | Storage | Search execution | When to use |
|---|---|---|---|
| `in_php_cosine` | JSON in `longText` | Cosine computed in PHP | Default. Any database. Personal / single-user installs. |
| `pgvector` | Typed `vector(N)` column | Indexed similarity in Postgres | PostgreSQL hosts. Larger note sets, multi-user. |
| `null` | Nothing (no-op) | No-op (empty result) | Tests, or any install that doesn't want semantic search. |

## `in_php_cosine` (default)

JSON in a `longText` column, cosine distance computed in PHP. Portable across SQLite, MySQL, MariaDB, and Postgres because the column is just text and the math runs in the application layer.

```dotenv
COMMONPLACE_VECTOR_DRIVER=in_php_cosine
COMMONPLACE_INPHP_MAX_CANDIDATES=2000
COMMONPLACE_INPHP_HARD_MAX_CANDIDATES=20000
```

The driver does a two-pass search. The first pass counts candidates with a non-null embedding and projects only `(id, embedding, embedding_dimensions, updated_at)` for scoring — no tag or owner hydration on thousands of rows it'll throw away. The second pass re-hydrates the top results with eager loads intact.

Two candidate caps gate the scoring pass:

- `max_candidates` (soft cap, default 2000) — above this, the driver logs a warning but still scores every candidate.
- `hard_max_candidates` (hard cap, default 20000) — above this, the driver falls back to the most recently updated `hard_max_candidates` notes and surfaces a `hard_cap_truncated` warning on the search result. Older notes are skipped for that query.

The hard cap is the signal to switch to pgvector. In-PHP cosine is fine for personal vaults; you don't want to be scoring 20,000+ vectors in PHP on every search.

> [!WARNING]
> The driver skips rows where `embedding_dimensions` doesn't match the query vector and reports a `dimension_mismatch_skipped` warning. That usually means you switched embedding provider or model without running `php artisan commonplace:reindex --force`.

## `pgvector` (PostgreSQL + pgvector)

Typed `vector(N)` column with indexed similarity search pushed down to the database. Uses Laravel 13's native vector query builders (`selectVectorDistance`, `orderByVectorDistance`). `N` matches the embedding provider's `dimensions()` at the time you ran the migration.

```dotenv
COMMONPLACE_VECTOR_DRIVER=pgvector
```

Setup is a two-step sequence:

1. Install the pgvector extension on your Postgres host. On Debian / Ubuntu: `apt install postgresql-15-pgvector` (match your Postgres major). On managed Postgres (RDS, Supabase, Neon), enable the `vector` extension in the dashboard.
2. Publish and run the migration that swaps the neutral `longText` column for a typed `vector(N)` column:
   ```bash
   php artisan vendor:publish --tag=commonplace-pgvector-migration
   php artisan migrate
   ```

The published migration runs `CREATE EXTENSION IF NOT EXISTS vector`, nulls out empty-string defaults (`UPDATE ... SET embedding = NULL WHERE embedding = ''`), runs a pre-flight scan for rows whose `embedding` isn't `NULL` and isn't a `[...]` array, then `ALTER TABLE commonplace_notes ALTER COLUMN embedding TYPE vector(N) USING embedding::vector(N)`. Existing in-PHP-cosine embeddings stored as `[0.1,0.2,...]` JSON arrays are byte-identical to pgvector's text input format, so the cast preserves them.

> [!CAUTION]
> The `ALTER TABLE ... USING` statement holds an `ACCESS EXCLUSIVE` lock on `commonplace_notes` for the entire row scan — minutes of blocked reads and writes on a large table. Run it in a low-traffic window.

If the pre-flight scan finds malformed rows it aborts before the `ALTER`, so you can fix them instead of crashing mid-statement and rolling back the lock for nothing. Use the doctor command to see the full list:

```bash
php artisan commonplace:doctor --pgvector-migration-precheck
```

The driver self-gates on first use. If the database isn't Postgres, the extension isn't installed, or the column hasn't been migrated to `vector`, it throws `PgvectorDriverNotReady` with a message pointing at what to fix. The check is memoized per process, so the cost is one query at boot.

Search is pushed down to Postgres, so there's no in-PHP candidate cap and no `lastWarnings()` output — partial results aren't a thing for this driver.

## `null` (disabled)

Storage and search are both no-ops. `store()` writes nothing, `search()` returns an empty collection, `isEnabled()` returns false so callers can short-circuit the semantic-search code path entirely.

```dotenv
COMMONPLACE_VECTOR_DRIVER=null
```

Pair this with the `null` embedding driver in tests, or when you want to run Commonplace as a plain notes app with full-text search only. The schema still has the neutral `longText` embedding column so you can switch a real driver on later without a migration.

## Swapping drivers later

The base create-table migration ships a neutral `longText` `embedding` column ([see the notes table](./model-relationships.md)). That means you can start on `in_php_cosine`, run with it for a while, and switch to `pgvector` later by publishing the pgvector migration and running it. Existing JSON-encoded embeddings cast cleanly.

Switching the other direction (`pgvector` → `in_php_cosine`) is supported by the published migration's `down()`, which runs `ALTER ... TYPE text USING embedding::text`. That preserves the values too.

> [!CAUTION]
> The reverse migration uses the same `ACCESS EXCLUSIVE` lock. Same low-traffic-window rule applies.

## Dimension mismatches

The `embedding_dimensions` column on `commonplace_notes` is a per-row sentinel — written by every driver's `store()` — so the search path can detect stale rows from a previous provider/model and skip them rather than crash.

> [!WARNING]
> Changing the **embedding provider or model** makes existing vectors incompatible. `in_php_cosine` will skip dimension-mismatched rows (and surface a `dimension_mismatch_skipped` warning); `pgvector` will error on insert because `vector(N)` is a fixed width. Run `php artisan commonplace:reindex --force` after any provider/model change so existing rows get re-embedded instead of skipped.
