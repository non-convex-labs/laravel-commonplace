# Laravel Style, Best Practices & Antipatterns

A reference distilled from current (2025–2026) community guidance, framework
docs, and package-development guides. Scope: Laravel 13 packages and
applications (PHP 8.3+, ideally 8.4).

Treat this as a checklist when designing or reviewing code, not a contract.

---

## 1. Code Style

### Standards
- Follow **PSR-1 / PSR-12** as the floor. The Laravel preset is PSR-12 plus a
  handful of opinionated Laravel rules.
- Use **Laravel Pint** as the single source of style truth. Don't hand-format;
  let Pint own it. Wire it into CI as `pint --test`.
- Pint presets: `laravel` (default), `per`, `psr12`, `symfony`, `empty`.
  The default `laravel` preset is the right choice unless there's a written
  reason to deviate.
- Pair with a static analyzer (PHPStan / Larastan) at level 5+ — even modest
  levels catch real bugs.

### Naming
- `camelCase` for variables, methods, and "string-like things that aren't
  public-facing".
- `snake_case` for config keys, database columns, and route names (Laravel
  convention).
- `PascalCase` for classes. Models singular (`Note`, not `Notes`).
- Verb-first method names: `renderMarkdown()`, not `markdownRendering()`.
- Boolean methods read as questions: `isPublished()`, `hasTag()`.

### File layout
- One class per file; namespace mirrors directory.
- `declare(strict_types=1);` at the top of every PHP file.
- Type-hint everything you can — parameters, return types, properties.

---

## 2. Laravel 13 — What's New & Relevant

Laravel 13 shipped 2026-03-17 at Laracon EU as a deliberately small upgrade
with **zero breaking changes** from 12. Key additions worth designing around
when rewriting:

### Laravel AI SDK (first-party, stable in 13)
- Unified API for text generation, tool-calling agents, **embeddings**,
  audio, images, and **vector-store integrations**.
- Provider-agnostic — swap OpenAI / Anthropic / Voyage / local without
  rewriting call sites.
- **Before rolling your own `EmbeddingProvider` contract + driver pattern,
  check whether the AI SDK already covers the use case.** It probably does,
  and you inherit the SDK's testing helpers, fakes, and observability.
- For this repo specifically, the check was done in #34 — see
  [`ai-sdk-evaluation.md`](./ai-sdk-evaluation.md). Decision: stay on
  the in-repo contract pending per-driver-knob coverage in a future
  SDK release.

### Native vector queries & semantic search
- First-class support for vector queries, embedding workflows, and similarity
  search.
- Documented integration with **PostgreSQL + pgvector** — embeddings can be
  generated directly from strings via the AI SDK and stored/queried through
  Eloquent.
- If you previously hand-rolled `WHERE embedding <-> ? < threshold` raw SQL,
  the new APIs are likely cleaner.

### JSON:API resources (first-party)
- Built-in support for JSON:API-compliant responses: resource serialization,
  relationship inclusion, sparse fieldsets, links, compliant headers.
- Prefer over hand-built `JsonResource` classes when you want
  spec-conformant APIs.

### PHP Attributes for models / jobs / commands
- Native `#[Attribute]` syntax now available as an alternative to class
  property declarations (`$fillable`, `$casts`, `$tries`, signature, etc.).
- **Fully optional, fully backward-compatible.** Don't migrate existing
  declarations for the sake of it, but consider attributes for new code if
  your team prefers the syntax.

### Enhanced CSRF: `PreventRequestForgery`
- Formalizes request-forgery protection middleware with origin-aware
  verification, on top of existing token-based CSRF.
- No action required for typical setups, but be aware if you have custom
  CSRF handling.

### Smaller quality-of-life changes
- `Http::pool()` now defaults to **concurrency of 2** (true parallel
  requests).
- `Str` factories **auto-reset between tests** — eliminates a class of
  state-leak bugs.
- MySQL `DELETE…JOIN` now respects `ORDER BY` and `LIMIT`.

### Provider registration (carried over from Laravel 11+)
- User-defined providers live in `bootstrap/providers.php` (not
  `config/app.php`).
- Packages still register via Composer auto-discovery
  (`extra.laravel.providers` in `composer.json`) — no change required from
  package authors.

### Requirements
- **PHP 8.3 minimum.** PHP 8.4 strongly recommended (property hooks, asymmetric
  visibility, lazy objects — all useful in domain code).

---

## 3. Architecture & Code Organization

### Single Responsibility & thin controllers
- Controllers orchestrate; they don't contain business logic. Push work down
  into Services, Actions, or domain classes.
- A class with one reason to change is easier to test, refactor, and replace.
- Keep controller methods small — ideally < 20 lines. If you can't, the work
  belongs somewhere else.

### Use the framework's seams
- Bind services in `ServiceProvider::register()` (or `packageRegistered()`
  for Spatie-style packages). Reserve `boot` / `packageBooted` for wiring
  that depends on other providers being registered (routes, observers,
  gates, view composers).
- Don't reinvent: prefer Laravel's queues, events, listeners, policies,
  jobs, gates, validators, form requests, notifications, Eloquent relations,
  and (now) the AI SDK over custom equivalents.
- Convention over configuration: when Laravel has a documented way to do
  something, follow it. The cost of bucking convention is paid by every
  future reader.

### Facades vs Dependency Injection
- **Inject** when a class has clear collaborators, when you want to swap
  implementations in tests, or when the dependency is non-trivial.
  Constructor injection > method injection > facade for anything with state
  or a contract.
- **Facades** are fine for terse access to framework services in
  controllers, Blade views, or small helpers. They're testable
  (`Cache::shouldReceive(...)`) but they hide the dependency graph.
- **Rule of thumb for package code:** services and drivers a consumer might
  want to swap should be resolved from the container (not facades) so they
  can be rebound. Internal Laravel facades (`Storage`, `Cache`, `Log`,
  `Bus`, `Queue`) are OK inside a package — they're already replaceable via
  Laravel's own bindings.

### Control flow
- Avoid `else`. Prefer early returns and guard clauses. Long `if/else`
  ladders almost always read better as a series of early returns or a
  `match`.
- Avoid nested conditionals more than 2 deep. Extract the inner branch into
  a named method.

---

## 4. Eloquent & Database

### The N+1 problem (the most common Laravel performance bug)
- Eager-load known relationships with `with(['author', 'tags'])`.
- For collections you already have, use `$collection->load('relation')`.
- For automatic prevention during dev:
  ```php
  Model::preventLazyLoading(! app()->isProduction());
  ```
  Throw on lazy loads in non-prod so the bug surfaces before staging.
- For models almost always loaded with the same relation, set
  `protected $with = [...]` on the model — but use sparingly; it's global
  and easy to over-fetch.
- Use `withCount('relation')` instead of loading + counting.
- Constrain eager loads: `with(['comments:id,post_id,body'])` — load only
  the columns you need.

### Query hygiene
- Prefer Eloquent and Query Builder over raw SQL. When you must use raw
  SQL, use parameter bindings (`DB::select('...', [$id])`), never string
  interpolation.
- Avoid `Model::all()` on any table that can grow. Always paginate or
  chunk (`->chunkById(500, fn ($rows) => ...)`) for batch work.
- Add DB indexes for any column you filter, join, or order by — including
  foreign keys. `$table->foreignId()->constrained()` adds the FK
  constraint but you may still want a composite index for hot queries.
- Use `select(['id', 'name'])` to project only the columns you need; don't
  `SELECT *` from API endpoints.
- Use `exists()` rather than `count() > 0`; use `doesntExist()` rather
  than `count() === 0`.
- For bulk writes, prefer `upsert()` / `insert()` over a loop of `save()`.
- For vector/embedding columns, use Laravel 13's native vector query APIs
  rather than raw `<->` operators where possible.

### Migrations
- One migration per logical change. Don't bundle table-creates with data
  backfills.
- Use descriptive, dated filenames. Prefix package tables with the package
  short name to avoid colliding with the host app.
- Avoid `Schema::hasTable` / `hasColumn` guards unless truly needed — they
  hide intent and let migrations silently no-op.

---

## 5. Security

- **Never** trust `auth()->user()` alone for authorization. Use Policies
  and `$this->authorize(...)` (or `Gate::allows`). Authentication ≠
  authorization.
- Don't return raw Eloquent models from API endpoints — fields like
  `password`, internal timestamps, or soft-delete flags leak. Use API
  Resources (`JsonResource` or Laravel 13's JSON:API resources) and
  explicitly project what goes out.
- Keep secrets in `.env`. Read them only through `config()` — **never**
  `env()` outside `config/*.php` files, because `env()` returns `null`
  once configs are cached (`php artisan config:cache`).
- Always validate inbound data with Form Requests or
  `$request->validate()`. Validate by allowlist (the fields you accept),
  not blocklist.
- Use `bcrypt` / `Hash::make` for passwords; never roll crypto.
- CSRF: keep `PreventRequestForgery` (Laravel 13's enhanced middleware)
  on for all state-changing web requests; SameSite=Lax cookies by default.
- Mass-assignment protection: use `$fillable` (allowlist) over
  `$guarded = []`. The latter trusts the entire HTTP request.
- Laravel does not "take care of security" automatically. Auth scaffolding
  doesn't authorize; CSRF doesn't validate input; `bcrypt` doesn't store
  tokens. Each layer is opt-in.

---

## 6. Jobs & Queues

- **Make jobs small and single-purpose.** A job that does one thing is
  easy to retry, observe, and reason about.
- **Make jobs idempotent.** They WILL run twice — on retry, on crash
  recovery, on accidental double-dispatch. Tools:
  - `ShouldBeUnique` interface + `uniqueId()` for dispatch-time dedupe.
  - DB unique constraints / `upsert()` for write-time dedupe.
  - Conditional updates ("set status only if it's still pending").
  - External-API idempotency keys for outbound calls.
- **Set explicit retry limits & backoff:**
  - `public int $tries = 5;`
  - `public function backoff(): array { return [10, 30, 120]; }` —
    exponential backoff with jitter prevents thundering herds against
    external APIs.
  - `public function retryUntil(): \DateTime` for time-based caps.
- **Isolate workloads** onto dedicated queues
  (`->onQueue('embeddings')`, `->onQueue('backups')`) so a slow workload
  never starves an urgent one. Give each its own supervisor / worker pool.
- **Persist failures.** Keep `failed_jobs` enabled; log enough context in
  `failed()` to reproduce.
- **Never dispatch from inside a DB transaction** without
  `->afterCommit()` — the job can pick up rows that haven't committed
  yet.
- **Measure** p95/p99 job runtimes. Long-tail jobs are where queues
  silently break SLAs.
- **Use Horizon** for any non-trivial Redis-backed queue setup — it
  gives you metrics, supervisor management, and a UI for free.

---

## 7. Testing

### Frameworks
- PHPUnit and Pest are both fine. Pest is built on PHPUnit, so PHPUnit
  configuration applies to either. Don't mix both in one suite — pick one.
- For packages, use **Orchestra Testbench** to boot a minimal Laravel app
  inside the test process. It lets you test service providers, routes,
  Eloquent, and Blade rendering exactly as a host app would experience
  them.

### Package test setup
- Implement the three lifecycle hooks on your `TestCase`:
  - `getPackageProviders($app)` — register your service provider(s).
  - `getEnvironmentSetUp($app)` — set DB connection, config overrides.
  - `setUp()` — run migrations (`loadLaravelMigrations()`,
    `artisan('migrate')`), seed fixtures.
- Use SQLite in-memory (`:memory:`) for fast suites where SQL dialect
  doesn't matter. Where you depend on Postgres-only features (pgvector,
  JSONB ops, etc.), run a real Postgres in CI for those specific tests.
- Wrap each test in a DB transaction (`RefreshDatabase` or
  `DatabaseTransactions`) so tests don't leak state.

### What to test
- **Unit:** pure logic — parsers, formatters, value objects. Fast, no
  Laravel boot needed when feasible.
- **Feature:** HTTP routes, controllers, console commands — exercise via
  `$this->get(...)`, `$this->postJson(...)`, `$this->artisan(...)`.
- **Integration:** jobs end-to-end with a real queue driver
  (`Queue::fake()` for assertions on dispatch; `sync` driver for actual
  run).
- **Don't mock what you own** unless the seam is genuinely valuable.
  Prefer test doubles only at I/O boundaries (HTTP, filesystem, paid
  external services). A null driver / fake driver class in your own code
  is usually a better seam than runtime mocking.
- Take advantage of Laravel 13's auto-reset `Str` factories — one fewer
  source of cross-test pollution.

### CI gates worth keeping
- `pint --test` (style)
- `phpunit` (or `pest`)
- A static analyzer (PHPStan / Larastan)
- Composer dependency audit (`composer audit`)

---

## 8. Package Development

### Service provider shape
- Extend `Illuminate\Support\ServiceProvider` (or, for a more declarative
  API, Spatie's `PackageServiceProvider` from
  `spatie/laravel-package-tools`).
- Two phases:
  - `register()` / `packageRegistered()` — container bindings only.
    Don't touch other services here; they may not be registered yet.
  - `boot()` / `packageBooted()` — wiring: routes, views, migrations,
    observers, gates, view composers, scheduled commands.
- Bind interfaces to implementations in `register()`. Use a `match` on a
  config value to pick a driver — the standard "driver pattern":
  ```php
  $this->app->bind(SomeContract::class, function () {
      return match (config('your-package.driver')) {
          'a' => $this->app->make(DriverA::class),
          'b' => $this->app->make(DriverB::class),
      };
  });
  ```
  (But: in Laravel 13, check whether a first-party SDK — AI SDK, vector
  search, etc. — already provides the abstraction you'd otherwise hand-roll.)

### Auto-discovery
- Declare your provider (and any facades) in `composer.json` under
  `extra.laravel.providers` so Laravel auto-registers on install. No
  manual `bootstrap/providers.php` edits required from the consumer.

### Publishable resources
- Config: publishable under a single tag, with a sensible default in the
  package so the consumer can run with no published config.
- Migrations: publishable, or load directly via `loadMigrationsFrom()`.
  Loading directly is friendlier for upgrades; publishing is friendlier
  for consumers who want to edit them.
- Views: publishable under a namespace
  (`view('your-package::path.to.view')`).
- Routes: load from `routes/web.php` / `routes/api.php` inside your
  package. Gate optional route groups behind a config flag so consumers
  can opt in.

### API surface
- Keep the public API small. Everything you export becomes a maintenance
  burden across major versions.
- Use interfaces / contracts at the boundary; concrete classes are
  implementation details.
- Don't export traits as part of the public API unless you really mean it
  — they're hard to deprecate.
- Document the minimum supported PHP and Laravel versions in the README,
  and gate Composer's `require` accordingly.

### Versioning & releases
- SemVer strictly. A removed public method is a major version bump even
  if you "didn't think anyone was using it."
- Tag releases. Don't expect consumers to track `dev-main`.
- Maintain a `CHANGELOG.md` — `Keep a Changelog` format works well.

---

## 9. Common Antipatterns to Avoid

| Antipattern | Why it hurts | Fix |
|---|---|---|
| Business logic in controllers | Untestable, can't reuse from CLI/job | Push into Services or Actions |
| `Model::all()` in production paths | Memory blow-up on growth | Paginate or `chunkById()` |
| Lazy-loaded relations in loops | N+1 → death by 1000 queries | `with()` / `load()` / `preventLazyLoading()` |
| Returning Eloquent models from APIs | Leaks fields (`remember_token`, etc.) | API Resources / JSON:API resources |
| `env()` outside config files | Returns `null` after `config:cache` | Read via `config()` |
| `$guarded = []` everywhere | Mass-assignment of any field | Use `$fillable` allowlist |
| Calling `auth()->user()->id` for authz | No policy = no authorization | Policies + `authorize()` |
| Raw SQL with string interpolation | SQL injection | Bindings / Query Builder |
| Job logic that isn't idempotent | Double-charges, duplicate writes on retry | Dedupe keys, upserts |
| Catch-all `try { } catch (\Throwable)` swallowing | Silent failures | Catch specific exceptions; rethrow or log+report |
| Logic in Blade (`@php` blocks with conditionals) | Hard to test, view becomes a controller | View composers or precomputed view-models |
| Helpers/facades inside services | Tight coupling, hard to swap in tests | Inject the dependency |
| One giant service provider doing 12 things | Hard to follow boot order | Split by concern; or use `spatie/laravel-package-tools` |
| Dispatching jobs inside a transaction | Race: worker reads pre-commit state | `->afterCommit()` |
| Long-lived singletons holding request data | Cross-request leaks | Resolve per-request, or never store request state |
| Reinventing queues/events/policies | More code to maintain, less battle-tested | Use the framework primitive |
| Hand-rolling embedding/AI driver abstractions | Maintenance burden, no fakes | Use Laravel 13 AI SDK |
| Hand-rolling vector-search raw SQL | Easy to get wrong, hard to test | Use Laravel 13 native vector queries |
| Skipping migrations, editing schema by hand | No reproducibility, no rollback | Always go through migrations |

---

## 10. Quick-Reference Checklist

Before opening a PR, mentally walk through:

- [ ] `pint` clean
- [ ] Tests green
- [ ] Static analyzer clean
- [ ] No `env()` calls outside `config/`
- [ ] No new facades inside services/drivers (inject instead)
- [ ] Any new Eloquent loop has `with()` or a `load()`
- [ ] New jobs declare `$tries` and `backoff()`, are idempotent
- [ ] New endpoints use Form Requests + API Resources, not raw model dumps
- [ ] New tables have indexes on the columns you'll filter/join on
- [ ] New container bindings live in `register()` / `packageRegistered()`
- [ ] New optional features sit behind a config flag
- [ ] Checked whether a Laravel 13 first-party feature (AI SDK, vector
      queries, JSON:API resources) already solves the problem before
      hand-rolling
- [ ] Public API additions are intentional (you'll support them across the
      next major version)

---

## Sources

### Laravel 13
- [Laravel 13 Release Notes](https://laravel.com/docs/13.x/releases)
- [Laravel 13: What's New, What Changed, and How to Upgrade from Laravel 12 — Edwin Savarimuthu / Medium](https://medium.com/@ed.sav/laravel-13-whats-new-what-changed-and-how-to-upgrade-from-laravel-12-dcd964024504)
- [Laravel 13 New Features (March 2026)](https://pola5h.github.io/blog/laravel-13-new-features/)
- [Laravel 13 (2026) Release: New Features and Upgrade Guide — PHP Everyday](https://www.phpeveryday.com/articles/laravel-13-2026-release-new-features-and-upgrade-guide/)
- [Laravel 13 Service Providers Docs](https://laravel.com/docs/13.x/providers)
- [Laravel 13 Package Development Docs](https://laravel.com/docs/13.x/packages)
- [Laravel 13 Facades Docs](https://laravel.com/docs/13.x/facades)
- [Laravel 13 Pint Docs](https://laravel.com/docs/13.x/pint)

### Style & best practices
- [Laravel Best Practices (alexeymezenin) — GitHub](https://github.com/alexeymezenin/laravel-best-practices)
- [Spatie Laravel & PHP Guidelines](https://spatie.be/guidelines/laravel-php)
- [Laravel Coding Guidelines (mindtwo)](https://www.mindtwo.com/guidelines/coding/laravel)
- [19 Laravel Best Practices — ButterCMS](https://buttercms.com/blog/laravel-best-practices/)
- [Laravel 11 Best Practices for 2026 — shayanmemonsaqlaini.com](https://shayanmemonsaqlaini.com/blog/laravel-11-best-practices-scalable-web-applications-2026)

### Antipatterns
- [Common Anti-patterns in Laravel Development — Binarcode](https://www.binarcode.com/blog/common-antipaterns-laravel-development)
- [Convention over Configuration: Anti-Patterns in Laravel — PHP Architect](https://www.phparch.com/article/2023-08-artisan/)

### Eloquent & performance
- [Eloquent Performance: 4 Examples of N+1 — Laravel News](https://laravel-news.com/laravel-n1-query-problems)
- [Handling N+1 in Laravel — OneUptime](https://oneuptime.com/blog/post/2026-02-02-laravel-n-plus-one-queries/view)

### Package development & testing
- [Simplifying Service Providers in Laravel Packages — Freek Van der Herten](https://freek.dev/1886-simplifying-service-providers-in-laravel-packages)
- [Service Providers — LaravelPackage.com](https://www.laravelpackage.com/03-service-providers/)
- [Testing Laravel Packages — LaravelPackage.com](https://www.laravelpackage.com/04-testing/)
- [Test Laravel Packages with PestPHP — David Carr](https://dcblog.dev/test-laravel-packages-with-pestphp)

### Facades vs DI
- [Facades vs Dependency Injection — Chimeremze Ejimadu / Medium](https://medium.com/@prevailexcellent/laravel-facades-vs-dependency-injection-whats-the-difference-and-when-to-use-each-539be13808a9)

### Jobs & queues
- [Laravel Queue Design, Retries, Backoff & Observability — Prateeksha](https://prateeksha.com/blog/queues-that-dont-fail-laravel-queue-design-retries-backoff-observability)
- [Laravel Queues & Horizon at Scale (2026)](https://itmarkerz.co.in/blog/laravel-queues-horizon-at-scale-2026-idempotency-retries-and-enterprise-job-pipelines)
- [Idempotency in Laravel 12 — Asfia Aiman / Medium](https://medium.com/@aiman.asfia/idempotency-in-laravel-12-2025-the-complete-guide-that-will-save-you-from-double-charges-3-am-0135d93f6dea)
