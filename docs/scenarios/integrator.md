# Scenarios — Integrator

The developer wiring `laravel-commonplace` into a Laravel application. These scenarios cover install, configuration, publishing assets, swapping drivers, and the extension hooks. None of these run "in production" — they're the first-five-minutes path plus the customization knobs you'll reach for once.

Assumptions:

- Fresh Laravel 13 application. PHP 8.4. Composer available.
- The integrator is logged in as a regular shell user; no special privileges needed beyond writing to the application directory.
- A user model exists at `App\Models\User`.

---

## Install

### S-INT-01 — `composer require non-convex-labs/laravel-commonplace` succeeds on a stock app

**Intent.** No surprise platform requirements. The package's `composer.json` declares everything it needs.

**Preconditions.** PHP `^8.4`, Composer 2.x, Laravel `^13.0`.

**Steps.**
1. `composer require non-convex-labs/laravel-commonplace`.

**Expected.** Composer resolves without conflicts and installs the package and its non-dev dependencies (commonmark, elgigi/commonmark-emoji, laravel/mcp, spatie/laravel-package-tools, symfony/yaml, tempest/highlight).

**Verify with.** `composer show non-convex-labs/laravel-commonplace`.

**Source.** [`composer.json`](../../composer.json).

---

### S-INT-02 — Service provider is auto-discovered

**Intent.** Laravel's package discovery picks up `CommonplaceServiceProvider` from `composer.json`'s `extra.laravel.providers` block. No manual registration in `bootstrap/providers.php` or `config/app.php`.

**Preconditions.** S-INT-01 done.

**Steps.**
1. `php artisan package:discover`.
2. `php artisan route:list | grep commonplace`.

**Expected.**
- Discovery output lists `NonConvexLabs\Commonplace\CommonplaceServiceProvider`.
- Routes list shows the package's auth-gated `commonplace.*` routes (unless `COMMONPLACE_ROUTES_ENABLED=false`).

**Verify with.** Console output.

**Source.** [`composer.json`](../../composer.json) (`extra.laravel.providers`), [`CommonplaceServiceProvider.php`](../../src/CommonplaceServiceProvider.php).

---

### S-INT-03 — `vendor:publish --tag=commonplace-config` publishes `config/commonplace.php`

**Intent.** Config publishing is opt-in. The package reads its defaults from the bundled config until you override them locally.

**Preconditions.** S-INT-02 done.

**Steps.**
1. `php artisan vendor:publish --tag=commonplace-config`.

**Expected.** A new file at `config/commonplace.php` with all the package keys (`user_model`, `routes`, `mcp`, `markdown`, `embedding`, `vector`, `wikilinks`, `backup`).

**Verify with.** `ls config/commonplace.php`.

**Source.** [docs/index.md → Install](../index.md#install), [`CommonplaceServiceProvider`](../../src/CommonplaceServiceProvider.php).

---

### S-INT-04 — `php artisan migrate` creates all package tables

**Intent.** Migrations are loaded from the package directly — no separate publish step needed. Running `migrate` brings up the full schema.

> [!NOTE]
> Validation 2026-05-17: `migrate` alone is currently **insufficient**. The service provider uses Spatie's `hasMigrations([...])` with an explicit list of 6 file names, which works as a `vendor:publish --tag=commonplace-migrations` source but does not auto-load the migrations into `migrate`. Worse, the list is out of sync with `database/migrations/`: 2 of the 8 files on disk (`add_indexes_to_commonplace_links_table`, `normalize_commonplace_notes_visibility`) are missing from the array. Tracked in [#64](https://github.com/non-convex-labs/laravel-commonplace/issues/64).

**Preconditions.** S-INT-01 done. Default DB connection configured.

**Steps.**
1. `php artisan migrate`.

**Expected.** New tables: `commonplace_notes`, `commonplace_note_versions`, `commonplace_tags`, `commonplace_note_tag`, `commonplace_links`, `commonplace_shares`. Schema as in [model-relationships.md](../model-relationships.md).

**Verify with.**
```bash
php artisan tinker
>>> Schema::hasTable('commonplace_notes');   // true
>>> Schema::hasColumn('commonplace_notes', 'embedding_dimensions'); // true
```

**Source.** [`database/migrations/`](../../database/migrations/), [model-relationships.md](../model-relationships.md).

---

### S-INT-05 — `HasCommonplaceNotes` trait wires the owner side

**Intent.** Adding the trait to the User model unlocks `notes()`, `recentNotes()`, `noteVersions()` relations and satisfies the contract the package reads off the owner.

**Preconditions.** S-INT-04 done. `App\Models\User extends Authenticatable`.

**Steps.**
1. Add `use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;` to the User class.
2. Tinker: `auth()->user()->notes()` after creating one note.

**Expected.** Relation returns a `HasMany<Note>` query. `recentNotes()` returns a `Collection<Note>` ordered desc by `updated_at`.

**Verify with.** Tinker output.

**Source.** [user-model.md](../user-model.md), [`HasCommonplaceNotes.php`](../../src/Concerns/HasCommonplaceNotes.php).

---

### S-INT-06 — `commonplace:doctor` reports green on a fresh install

**Intent.** Doctor is the install smoke test. It should pass clean on a vanilla setup before any custom configuration.

**Preconditions.** S-INT-04 done. `COMMONPLACE_EMBEDDING_DRIVER=null` (default) and `COMMONPLACE_VECTOR_DRIVER=in_php_cosine`.

**Steps.**
1. `php artisan commonplace:doctor`.

**Expected.** `[OK]` on every check, or `[SKIP]` for ones that don't apply (e.g. pgvector on SQLite).

**Verify with.** Console output.

**Source.** [commands.md → commonplace:doctor](../commands.md#commonplacedoctor).

---

## Auth wiring

### S-INT-07 — Default `web,auth` middleware works with Breeze/Jetstream/Fortify

**Intent.** Out-of-the-box auth integration: any standard Laravel session-auth scaffold gates the routes correctly.

**Preconditions.** Breeze installed; a registered user.

**Steps.**
1. Sign in. `GET /commonplace`.

**Expected.** 200 OK with the vault index.

**Verify with.** Browser.

**Source.** [auth.md → Session (default)](../auth.md#session-default).

---

### S-INT-08 — Switching to Sanctum SPA requires `EnsureFrontendRequestsAreStateful`

**Intent.** The package's middleware list is a knob, but Sanctum SPA's stateful cookie flow needs Laravel's stateful middleware in front of `auth:sanctum`. The package doesn't add it for you.

**Preconditions.** `COMMONPLACE_ROUTES_MIDDLEWARE=web,auth:sanctum`. Sanctum installed.

**Steps.**
1. Configure SPA cookie domain, CORS, and add `EnsureFrontendRequestsAreStateful` to the package's middleware stack via the env var.
2. From SPA: fetch `/commonplace` with credentials.

**Expected.** The cookie authenticates the session; the route resolves the user.

**Verify with.** Browser DevTools — the cookie flows; the response is 200.

**Source.** [auth.md → Sanctum SPA](../auth.md#sanctum-spa-cookie).

---

### S-INT-09 — MCP enable + Sanctum PAT yields a working agent endpoint

**Intent.** From off to "Claude Code can connect" is two env vars plus a token issue.

**Preconditions.** S-INT-04 done. Sanctum installed.

**Steps.**
1. Set `COMMONPLACE_MCP_ENABLED=true`. Restart server.
2. Issue a PAT: `php artisan tinker` → `$u->createToken('mcp')->plainTextToken`.
3. `claude mcp add commonplace --transport http http://localhost/mcp/commonplace --header "Authorization: Bearer <token>"`.
4. List tools.

**Expected.** Claude Code lists 16 tools.

**Verify with.** `claude mcp list`.

**Source.** [mcp-tools.md → Setup](../mcp-tools.md#setup), [auth.md → MCP](../auth.md#mcp).

---

## Driver swaps

### S-INT-10 — Swap embedding driver from `null` to `voyage`, reindex with `--force`

**Intent.** Switching providers without reindexing produces garbage results. The package surfaces the warning; the integrator runs the reindex.

**Preconditions.** Several notes exist. `COMMONPLACE_EMBEDDING_DRIVER=null` currently.

**Steps.**
1. Set `COMMONPLACE_EMBEDDING_DRIVER=voyage` and `VOYAGE_API_KEY=...`.
2. `php artisan commonplace:doctor` — observe the dimension-drift warning if vectors exist.
3. `php artisan commonplace:reindex --force --sync`.
4. `php artisan commonplace:doctor`.

**Expected.**
- After (3), every note has `embedding_dimensions=1024` (Voyage default) and a non-null `indexed_at`.
- After (4), no drift warning.

**Verify with.**
```php
Note::distinct()->pluck('embedding_dimensions'); // [1024]
Note::whereNull('indexed_at')->count();          // 0
```

**Source.** [embedding-drivers.md](../embedding-drivers.md), [commands.md → commonplace:reindex](../commands.md#commonplacereindex).

---

### S-INT-11 — Swap vector storage from `in_php_cosine` to `pgvector`

**Intent.** The published pgvector migration runs an `ALTER TABLE ... USING embedding::vector(N)` cast. Existing JSON-encoded embeddings cast cleanly. A precheck command catches malformed rows ahead of the lock.

**Preconditions.** Postgres database. `vector` extension installable. Some embedded notes.

**Steps.**
1. `php artisan commonplace:doctor --pgvector-migration-precheck`. Resolve any flagged rows.
2. `php artisan vendor:publish --tag=commonplace-pgvector-migration`.
3. `php artisan migrate`.
4. Set `COMMONPLACE_VECTOR_DRIVER=pgvector`.
5. Verify search.

**Expected.**
- (1) Either reports `0 rows` to fix or lists offending row ids (≤100).
- (3) Adds the `vector` extension, runs the column type cast, completes. The lock is held for the duration; expect minutes on a large table.
- (5) Semantic search still works.

**Verify with.** `psql` — `\d commonplace_notes` shows `embedding vector(N)`.

**Source.** [vector-storage.md → pgvector](../vector-storage.md#pgvector-postgresql--pgvector), [commands.md](../commands.md).

---

### S-INT-12 — `null` embedding + `null` vector driver disables semantic search cleanly

**Intent.** Running without semantic search is a supported mode. The code paths short-circuit; the schema doesn't change.

**Preconditions.** `COMMONPLACE_EMBEDDING_DRIVER=null`, `COMMONPLACE_VECTOR_DRIVER=null`.

**Steps.**
1. `Commonplace::semanticSearch('anything', $alice);`
2. `Commonplace::getSuggestedLinks('any/path', $alice);`
3. `Commonplace::lastSearchWarnings();`

**Expected.** Both calls return empty collections without errors. `lastSearchWarnings()` returns `[]`.

**Verify with.** Tinker.

**Source.** [vector-storage.md → null](../vector-storage.md#null-disabled), [embedding-drivers.md](../embedding-drivers.md).

---

## Extension hooks

### S-INT-13 — Register a CommonMark extension via config

**Intent.** Adding extensions is a config-array append. The package builds the converter once per request and freezes the registry.

**Preconditions.** S-INT-03 done.

**Steps.**
1. Add `League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension::class` to `commonplace.markdown.extensions`.
2. Render a note with an `## H2`.

**Expected.** The rendered HTML has a permalink anchor on the heading.

**Verify with.** Inspect rendered HTML.

**Source.** [markdown-rendering.md → Basic usage](../markdown-rendering.md#basic-usage).

---

### S-INT-14 — Register a runtime extender via `Commonplace::extendMarkdown`

**Intent.** When you need the live `Environment` (custom inline parser, event listener), use the runtime hook from a service provider's `boot()`.

**Preconditions.** S-INT-03 done.

**Steps.**
1. In `AppServiceProvider::boot()`:
```php
use NonConvexLabs\Commonplace\Facades\Commonplace;
use League\CommonMark\Environment\Environment;

Commonplace::extendMarkdown(function (Environment $env): void {
    $env->addInlineParser(new MyMentionParser, priority: 100);
});
```
2. Render a note containing the new syntax.

**Expected.** The custom parser runs. Calling `extendMarkdown()` after the first render throws `LogicException`.

**Verify with.** Render output + intentional second-call probe.

**Source.** [services.md → extendMarkdown](../services.md#markdown-extension-hooks), [markdown-rendering.md → Runtime extension hook](../markdown-rendering.md#runtime-extension-hook).

---

### S-INT-15 — Swap the `WikilinkResolver` to point at a different model

**Intent.** Rebinding the contract redirects `[[wikilinks]]` to anywhere — a docs site, an external wiki, a different model.

**Preconditions.** S-INT-03 done.

**Steps.**
1. Implement `WikilinkResolver::resolve(string $target): ?ResolvedWikilink`.
2. In a service provider's `register()`: `$this->app->bind(WikilinkResolver::class, MyResolver::class);`.
3. Render a note with `[[some-target]]`.

**Expected.** The rendered anchor uses the resolver's returned `href` and `title`. Returning `null` yields the broken-link affordance (`vault-link-broken` class, href = route prefix + raw target).

**Verify with.** Inspect rendered HTML.

**Source.** [markdown-rendering.md → Swapping the wikilink resolver](../markdown-rendering.md#swapping-the-wikilink-resolver).

---

### S-INT-16 — Publish Blade views and override one

**Intent.** Theming the markup. Published views in `resources/views/vendor/commonplace/` win over the bundled ones.

**Preconditions.** S-INT-03 done.

**Steps.**
1. `php artisan vendor:publish --tag=commonplace-views`.
2. Edit `resources/views/vendor/commonplace/notes/show.blade.php` and add a marker comment.
3. `GET /commonplace/<path>`.

**Expected.** The marker is present in the response HTML.

**Verify with.** Browser → view source.

**Source.** [theming.md → Publishing Blade views](../theming.md#publishing-blade-views).

---

### S-INT-17 — Override CSS custom properties without forking the stylesheet

**Intent.** The default CSS uses only `--commonplace-*` custom properties. Override the variables to retheme.

**Preconditions.** S-INT-03 done.

**Steps.**
1. `php artisan vendor:publish --tag=commonplace-css`.
2. In `resources/css/commonplace/commonplace.css`, override `--commonplace-color-accent`.
3. Visit a vault page.

**Expected.** The accent color in rendered styles matches the override.

**Verify with.** DevTools inspector.

**Source.** [theming.md → Publishing the CSS source](../theming.md#publishing-the-css-source).

---

### S-INT-18 — Inject a custom nav via the `commonplace.nav` section

**Intent.** Replace the package's topbar without forking the layout.

**Preconditions.** S-INT-03 done.

**Steps.**
1. Create a view that extends `commonplace::layouts.app` and defines `@section('commonplace.nav')`.
2. Visit a vault page.

**Expected.** The package's topbar is replaced by the section content. Leave the section unset and the default topbar still renders.

**Verify with.** Browser.

**Source.** [theming.md → Injecting your own nav](../theming.md#injecting-your-own-nav).

---

## Custom infrastructure

### S-INT-19 — Add a custom backup destination

**Intent.** Implement `BackupDestination` and bind it; list it alongside the built-ins.

**Preconditions.** S-INT-03 done.

**Steps.**
1. Implement `GcsBackupDestination implements BackupDestination`.
2. In a service provider's `register()`: `$this->app->bind('gcs-snapshot', GcsBackupDestination::class);`.
3. Set `COMMONPLACE_BACKUP_DESTINATIONS=github,gcs-snapshot`.
4. Run `\NonConvexLabs\Commonplace\Jobs\BackupVault::dispatch()`.

**Expected.** Both destinations receive `BackupBundle::push()` in order.

**Verify with.** Inspect both destinations for the bundle.

**Source.** [backup.md → Custom destinations](../backup.md#custom-destinations).

---

### S-INT-20 — Swap the user model via `commonplace.user_model`

**Intent.** A custom user model works if it implements `Authenticatable` and has an integer `id`. The FK column names are hardcoded.

**Preconditions.** Custom `App\Models\Account extends Authenticatable` with integer PK and the `HasCommonplaceNotes` trait.

**Steps.**
1. Set `COMMONPLACE_USER_MODEL=App\\Models\\Account` in `.env`.
2. Create a note as an `Account`.

**Expected.** The note's `user_id` is the Account's `id`. The `owner()` relation resolves to an `Account`, not a `User`.

**Verify with.** Tinker.

**Source.** [user-model.md → What the package expects](../user-model.md#what-the-package-expects).

---

### S-INT-21 — Cross-surface consistency: a write on one surface is visible from the others

**Intent.** The three write surfaces (facade, MCP tool, HTTP) all funnel through `Commonplace::*`. A change made via one is immediately reflected in the others without sync, polling, or cache invalidation.

**Preconditions.** Fresh sandbox. One authenticated user (Alice). MCP enabled. `COMMONPLACE_WIKILINKS_REWRITE_SYNC=true` for the move step.

**Steps.**
1. Facade: `Commonplace::createNote('cross/surface', '# Hello', ['initial'], 'private', $alice);`.
2. MCP `update-note-tool` (Alice's Bearer): `{path: 'cross/surface', tags: ['initial', 'mcp-edit']}`.
3. Web UI: `GET /commonplace/cross` — inspect the folder browser's note row.
4. Web UI: `GET /commonplace/cross/surface` — inspect the note view's tag list.
5. MCP `move-tool`: `{from_path: 'cross/surface', to_path: 'renamed/here'}`.
6. Web UI: `GET /commonplace/renamed/here`.

**Expected.**
- After (2): the tag set on the row is `['initial', 'mcp-edit']`. No version row is added (tag-only update does not change `content_hash`).
- (3): the folder browser shows the note with the new tag list.
- (4): the rendered note view shows both tags.
- (5): the move succeeds; `commonplace_notes.path` is `renamed/here`.
- (6): the renamed path renders 200 with the note's content; the old path 404s.

**Verify with.** Tinker for the DB state; Playwright or curl for the web steps.

**Source.** [services.md](../services.md), [mcp-tools.md](../mcp-tools.md), [http-api.md](../http-api.md).
