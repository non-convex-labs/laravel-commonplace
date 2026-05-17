# Scenarios — Operator

Whoever runs `laravel-commonplace` after install. Diagnoses health, recovers from queue outages, rebuilds embeddings after driver swaps, ships backups, and notices when something drifted.

Assumptions:

- The operator has shell access to the host running `php artisan` or to a deployed environment that runs scheduled jobs.
- Migrations have been applied. At least one user and one note exist.
- A queue worker is running, except where a scenario calls out its absence.

---

## Doctor

### S-OPS-01 — `commonplace:doctor` runs read-only and reports per-check status

**Intent.** The smoke test command. Never mutates state. Emits one line per check with `[OK]`, `[WARN]`, `[FAIL]`, or `[SKIP]`.

**Preconditions.** A configured install.

**Steps.**
1. `php artisan commonplace:doctor`.

**Expected.**
- Exit code 0 by default (even on failures, unless `--exit-code` is set).
- Every check is named and reported.
- A recommendations footer lists fixes for non-`OK` checks.

**Verify with.** Console output.

**Source.** [commands.md → commonplace:doctor](../commands.md#commonplacedoctor).

---

### S-OPS-02 — `--exit-code` propagates failure into CI

**Intent.** CI gates can fail builds on misconfiguration without parsing stdout. Casual runs stay green.

**Preconditions.** A check that fails (e.g. dimension drift introduced by a driver swap with no reindex).

**Steps.**
1. `php artisan commonplace:doctor --exit-code; echo "exit=$?"`.

**Expected.** Exit code 1 if any check `fail`s. 0 if all OK or only WARN/SKIP.

**Verify with.** Shell capture of `$?`.

**Source.** [commands.md → commonplace:doctor → Flags](../commands.md#flags).

---

### S-OPS-03 — Doctor flags dimension drift after a driver swap

**Intent.** Switching embedding model leaves the existing rows with the wrong `embedding_dimensions`. Doctor catches that before semantic search starts returning weird results.

**Preconditions.** Notes embedded under one driver (e.g. Voyage, 1024-dim). Switch to OpenAI 3-large (3072-dim) without reindexing.

**Steps.**
1. Change driver in `.env`.
2. `php artisan commonplace:doctor`.

**Expected.** A `[FAIL]` or `[WARN]` for the dimension-drift check, with the recommendation to run `commonplace:reindex --force`.

**Verify with.** Console output.

**Source.** [vector-storage.md → Dimension mismatches](../vector-storage.md#dimension-mismatches), [commands.md](../commands.md).

---

### S-OPS-04 — Doctor flags candidate-cap headroom for `in_php_cosine`

**Intent.** When the indexed-note count approaches `COMMONPLACE_INPHP_HARD_MAX_CANDIDATES`, doctor surfaces that you should consider `pgvector`.

**Preconditions.** `in_php_cosine` driver. Note count near the hard cap.

**Steps.**
1. `php artisan commonplace:doctor`.

**Expected.** A `[WARN]` for the candidate-cap headroom check with the recommendation to migrate to pgvector.

**Verify with.** Console output.

**Source.** [vector-storage.md → in_php_cosine](../vector-storage.md#in_php_cosine-default), [commands.md → commonplace:doctor](../commands.md#commonplacedoctor).

---

### S-OPS-05 — Doctor flags orphaned wikilinks

**Intent.** Orphan link rows accumulate when `UpdateWikilinksJob` failed silently. Doctor watches the count against `commonplace.wikilinks.orphan_warn_threshold` (default 50).

**Preconditions.** A queue outage during which moves happened, producing >50 rows with `target_note_id IS NULL` where the link is recoverable.

**Steps.**
1. `php artisan commonplace:doctor`.

**Expected.** A `[WARN]` recommending `commonplace:relink`.

**Verify with.** Console output.

**Source.** [commands.md → commonplace:doctor](../commands.md#commonplacedoctor).

---

### S-OPS-06 — Doctor flags MCP auth misconfiguration

**Intent.** With MCP enabled, an empty middleware list ships an unauthenticated transport. Doctor blocks that.

**Preconditions.** `COMMONPLACE_MCP_ENABLED=true` and `COMMONPLACE_MCP_MIDDLEWARE=""`.

**Steps.**
1. `php artisan commonplace:doctor`.

**Expected.** `[FAIL]` with a recommendation pointing at the auth doc.

**Verify with.** Console output.

**Source.** [auth.md → Doctor](../auth.md#doctor).

---

### S-OPS-07 — Doctor flags `auth:sanctum` without Sanctum installed

**Intent.** Catch the case where the default middleware references Sanctum but the package isn't installed.

**Preconditions.** Default middleware. Remove `laravel/sanctum` from composer.

**Steps.**
1. `php artisan commonplace:doctor`.

**Expected.** `[FAIL]` recommending `composer require laravel/sanctum`.

**Verify with.** Console output.

**Source.** [auth.md → Doctor](../auth.md#doctor).

---

### S-OPS-08 — `--pgvector-migration-precheck` lists offending rows ahead of the lock

**Intent.** Before running the destructive `ALTER TABLE ... USING embedding::vector(N)` (which holds `ACCESS EXCLUSIVE` for the row scan), the operator can preview which rows would crash the cast.

**Preconditions.** Some rows have malformed `embedding` payloads (legacy JSON not in `[...]` shape).

**Steps.**
1. `php artisan commonplace:doctor --pgvector-migration-precheck`.

**Expected.** Lists up to 100 offending row ids. Skips the standard checks. Exit code 1 with `--exit-code` if any are found.

**Verify with.** Console output, `psql` validation against the listed ids.

**Source.** [commands.md → Pre/post conditions](../commands.md#prepost-conditions), [vector-storage.md → pgvector](../vector-storage.md#pgvector-postgresql--pgvector).

---

## Reindex

### S-OPS-09 — Default reindex skips rows where `indexed_at > updated_at`

**Intent.** Without `--force`, the job only re-embeds notes whose `indexed_at` is null or stale relative to `updated_at`. Cheap to run periodically.

> [!NOTE]
> Validation 2026-05-17: the actual predicate is **`indexed_at IS NULL AND updated_at < now() - 60min`** — a 60-minute cooldown on `updated_at`, not a comparison to `indexed_at`. Default reindex won't pick up notes created or edited in the last hour. The cooldown is configurable via `commonplace.reindex.cooldown_minutes` but isn't documented. Tracked in [#65](https://github.com/non-convex-labs/laravel-commonplace/issues/65); once docs are fixed, update this scenario's Intent and Expected to match.

**Preconditions.** A mix of notes — some never indexed, some indexed and unchanged, some changed since last index.

**Steps.**
1. `php artisan commonplace:reindex`.
2. Inspect `commonplace_notes.indexed_at` distribution.

**Expected.** Only the `indexed_at IS NULL OR updated_at > indexed_at` rows are re-embedded. Other rows untouched.

**Verify with.**
```sql
SELECT COUNT(*) FROM commonplace_notes WHERE indexed_at IS NULL;
-- 0 after a successful reindex
```

**Source.** [commands.md → commonplace:reindex](../commands.md#commonplacereindex).

---

### S-OPS-10 — `--force` clears `indexed_at` globally before dispatching

**Intent.** Required after switching embedding driver or model. Re-embeds everything.

**Preconditions.** Some notes indexed under a stale dimension.

**Steps.**
1. `php artisan commonplace:reindex --force`.

**Expected.**
- An `UPDATE commonplace_notes SET indexed_at = NULL` runs before the job dispatches.
- After completion, every row has a fresh `indexed_at` and a non-stale `embedding_dimensions`.

**Verify with.**
```sql
SELECT DISTINCT embedding_dimensions FROM commonplace_notes; -- one value
```

**Source.** [commands.md → commonplace:reindex](../commands.md#commonplacereindex), [embedding-drivers.md](../embedding-drivers.md).

---

### S-OPS-11 — `--sync` runs inline without a queue worker

**Intent.** For dev machines, CLI scripts, or test harnesses that don't run a worker.

**Preconditions.** No queue worker.

**Steps.**
1. `php artisan commonplace:reindex --force --sync`.

**Expected.** The job runs in the current process and blocks until done. After it returns, all notes have non-null `indexed_at`.

**Verify with.** Tinker after the command exits.

**Source.** [commands.md → commonplace:reindex](../commands.md#commonplacereindex).

---

### S-OPS-12 — Queued reindex without a worker leaves the job stuck in queue

**Intent.** Default behavior dispatches to the configured queue. Doctor does **not** flag a missing worker — the operator owns that.

**Preconditions.** No queue worker running. Default queue driver (e.g. `database`).

**Steps.**
1. `php artisan commonplace:reindex`.
2. Inspect the queue.

**Expected.** Command exits 0 (the dispatch succeeded). The `jobs` table has a `ReindexNotes` row that won't be processed until a worker runs.

**Verify with.** `SELECT COUNT(*) FROM jobs;`.

**Source.** [commands.md → Pre/post conditions](../commands.md#prepost-conditions-1).

---

## Relink

### S-OPS-13 — `commonplace:relink` re-resolves `target_note_id` for orphan rows

**Intent.** Recovery path for the symptom that doctor reports: orphan link rows after a missed `UpdateWikilinksJob`.

**Preconditions.** Several `commonplace_links` rows with `target_note_id IS NULL` where the target now exists at the recorded `target_path` (or by title/basename).

**Steps.**
1. `php artisan commonplace:relink`.
2. Inspect `commonplace_links`.

**Expected.** Rows whose `target_path` resolves via `WikilinkParser::resolveTarget` now have their `target_note_id` set. Rows that still don't resolve (target doesn't exist) remain orphaned.

**Verify with.**
```sql
SELECT COUNT(*) FROM commonplace_links WHERE target_note_id IS NULL;
-- expected to drop after the relink pass
```

**Source.** [commands.md → commonplace:relink](../commands.md#commonplacerelink).

---

### S-OPS-14 — `commonplace:relink --exit-code` fails CI if any orphan remains

**Intent.** Useful in a pipeline that asserts the graph is intact after a deploy.

**Preconditions.** Some orphan rows that genuinely don't resolve (target note never existed).

**Steps.**
1. `php artisan commonplace:relink --exit-code; echo "exit=$?"`.

**Expected.** Exit code 1 if at least one row is still unresolved.

**Verify with.** Shell capture of `$?`.

**Source.** [commands.md → commonplace:relink](../commands.md#commonplacerelink).

---

### S-OPS-15 — Relink doesn't repair mis-resolved (non-null) targets

**Intent.** Documented blind spot. `relink` only fills `NULL` targets. A row whose `target_note_id` is non-null but points at the wrong note (e.g. resolveTarget fell through to a trailing-segment match) is **not** corrected.

**Preconditions.** A `commonplace_links` row with a non-null but wrong `target_note_id`.

**Steps.**
1. `php artisan commonplace:relink`.

**Expected.** The wrong row is untouched.

**Verify with.** Inspect the row before and after.

**Source.** [commands.md → commonplace:relink → Pre/post conditions](../commands.md#prepost-conditions-2).

---

## Backups

### S-OPS-16 — Single GitHub destination pushes one bundle per scheduled run

**Intent.** The simplest backup setup: one env var names the destination, two more provide the credentials.

**Preconditions.** GitHub repo and PAT set.

**Steps.**
1. Set `COMMONPLACE_BACKUP_DESTINATIONS=github` plus `COMMONPLACE_GITHUB_BACKUP_REPO=org/repo` and `COMMONPLACE_GITHUB_BACKUP_TOKEN=ghp_...`.
2. `php artisan tinker` → `\NonConvexLabs\Commonplace\Jobs\BackupVault::dispatchSync();`.
3. Check the GitHub repo.

**Expected.** A commit lands in the repo with one `.md` file per note (at the note's `path`) plus a `manifest.json` at the bundle root with `version: "1.0"`, `note_count`, and per-file `checksum: sha256:...`.

**Verify with.** GitHub repo browser; inspect `manifest.json`.

**Source.** [backup.md → Configuration](../backup.md#configuration), [backup.md → Bundle format](../backup.md#bundle-format-schema-v10).

---

### S-OPS-17 — Fan-out: GitHub + filesystem disk receive the same bundle

**Intent.** One job, multiple destinations. Order matters: if one fails, the rest are skipped and the job retries.

**Preconditions.** Both destinations configured.

**Steps.**
1. Set `COMMONPLACE_BACKUP_DESTINATIONS=github,filesystem.local-backup` plus disk and path.
2. Dispatch `BackupVault`.

**Expected.** Both destinations receive an identical bundle. If GitHub fails first, the disk doesn't receive the bundle for that run; the job retries on the standard 30s/120s/300s backoff (5 tries total).

**Verify with.** Inspect both destinations for matching `manifest.json` checksums.

**Source.** [backup.md → Configuration](../backup.md#configuration).

---

### S-OPS-18 — Custom destination drops in via container binding

**Intent.** Implement `BackupDestination`, bind it under a name, list the name in `COMMONPLACE_BACKUP_DESTINATIONS`. No package fork required.

**Preconditions.** Custom `GcsBackupDestination` bound to `gcs-snapshot`.

**Steps.**
1. `COMMONPLACE_BACKUP_DESTINATIONS=github,gcs-snapshot`. Dispatch.

**Expected.** Both destinations receive `push(BackupBundle $bundle)`.

**Verify with.** Inspect both destinations.

**Source.** [backup.md → Custom destinations](../backup.md#custom-destinations).

---

### S-OPS-19 — Legacy `BackupToGitHub` job still ships to GitHub directly

**Intent.** Kept for back-compat. Ignores the destinations list. Use `BackupVault` for new code.

**Preconditions.** Both jobs available. GitHub credentials set.

**Steps.**
1. Dispatch `\NonConvexLabs\Commonplace\Jobs\BackupToGitHub::dispatchSync()`.

**Expected.** The bundle reaches GitHub regardless of `COMMONPLACE_BACKUP_DESTINATIONS`.

**Verify with.** Inspect repo.

**Source.** [backup.md → Legacy job](../backup.md#legacy-job).

---

## Routine operations

### S-OPS-20 — Schedule periodic doctor + reindex + backup

**Intent.** Three commands cover the regular operational loop. Doctor surfaces drift; reindex catches up; backup ships the corpus.

**Preconditions.** Laravel scheduler running.

**Steps.**
1. In `routes/console.php` (or `app/Console/Kernel.php`):
```php
Schedule::command('commonplace:doctor --exit-code')->dailyAt('01:00');
Schedule::command('commonplace:reindex')->hourly();
Schedule::job(new \NonConvexLabs\Commonplace\Jobs\BackupVault)->dailyAt('02:00');
```

**Expected.** Each task runs on its cadence. Doctor's `--exit-code` surfaces failures into the scheduler's failure reporting.

**Verify with.** `php artisan schedule:list`; log inspection.

**Source.** [commands.md](../commands.md), [backup.md](../backup.md).

---

### S-OPS-21 — Octane: re-clear markdown extender registry per request

**Intent.** Under Octane, the markdown converter is built once per worker process. Tests use `Commonplace::clearMarkdownExtenders()` to reset between tests; the same hook is what an Octane integration would call between requests if you've registered runtime extenders that need to vary per request.

**Preconditions.** Octane installed. Runtime extenders registered.

**Steps.**
1. Hook `Octane::tick(fn() => Commonplace::clearMarkdownExtenders())` (or equivalent per-request callback) in `OctaneServiceProvider`.

**Expected.** Renderer rebuilds the environment each request. Repeated `extendMarkdown()` calls don't accumulate.

**Verify with.** Octane logs; memory steady-state.

**Source.** [services.md → Markdown extension hooks](../services.md#markdown-extension-hooks), [markdown-rendering.md → Runtime extension hook](../markdown-rendering.md#runtime-extension-hook).

---

### S-OPS-22 — Cold start of a queue worker after an outage processes queued moves and reindexes

**Intent.** After a queue outage, dispatched jobs sit in the queue. Once a worker comes back, they process and the graph + embeddings catch up. `commonplace:relink` is the recovery for anything `UpdateWikilinksJob` missed before it was dispatched (the dispatch happens via `DB::afterCommit`).

**Preconditions.** Queue had been down. Notes were created and moved during the outage.

**Steps.**
1. Restart the queue worker.
2. Watch the queue drain.
3. Run `php artisan commonplace:doctor`.

**Expected.**
- Queued jobs process. Pending `ReindexNotes` and `UpdateWikilinksJob` runs finish.
- Doctor's orphan-wikilinks check is below threshold; if not, run `commonplace:relink`.
- Doctor's dimension-drift check is OK.

**Verify with.** Console output of doctor.

**Source.** [services.md → moveNote](../services.md#movenote), [commands.md → commonplace:relink](../commands.md#commonplacerelink).

---

### S-OPS-23 — Voyage smoke test: install → reindex → semantic search returns sensible ranking

**Intent.** End-to-end confidence that a fresh install with the default `voyage` embedding driver actually produces vectors that rank semantically. Catches misconfigured API keys, dimension mismatches, and the cooldown footgun in one shot.

**Preconditions.** Fresh sandbox. `COMMONPLACE_EMBEDDING_DRIVER=voyage`, `VOYAGE_API_KEY=...`, `COMMONPLACE_VECTOR_DRIVER=in_php_cosine`. At least one user.

**Steps.**
1. `php artisan commonplace:doctor` — confirm `[OK]` on driver wiring.
2. Tinker: create three thematically distinct notes (e.g. project plan, cooking recipe, agile retro).
3. `php artisan commonplace:reindex --force --sync` (the `--force` is required to bypass the [60-min cooldown](#s-ops-09--default-reindex-skips-rows-where-indexed_at--updated_at) for just-created notes).
4. Tinker: `Commonplace::semanticSearch('quarterly product milestones', $user, SemanticSearchScope::Mine);`.
5. Tinker: `Commonplace::semanticSearch('bread baking technique', $user, SemanticSearchScope::Mine);`.

**Expected.**
- (1) Doctor's `Embedding provider` line reads `VoyageEmbeddingProvider (dimensions=1024)`. No `[FAIL]`s.
- (3) Reindex output: `Cleared indexed_at on N note(s).` then `Reindex completed (sync).`. Every row has a non-null `indexed_at` and `embedding_dimensions=1024`.
- (4) The project-plan note ranks first; the recipe ranks last (highest cosine distance).
- (5) The recipe ranks first; the project plan ranks last.

**Verify with.** Console output of doctor + tinker assertions on `embedding_dimensions` and `distance` values from the search results.

**Source.** [embedding-drivers.md → Voyage](../embedding-drivers.md#voyage), [commands.md → commonplace:reindex](../commands.md#commonplacereindex), [services.md → semanticSearch](../services.md#semanticsearch).
