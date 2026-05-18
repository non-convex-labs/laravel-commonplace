# AGENTS.md

<!--
  This file is read by AI tools that support the agents.md vendor-neutral
  standard. The content below is duplicated in CLAUDE.md (read by Claude
  Code) so that contributors using either receive the same instructions
  without needing to cross-reference. Keep CLAUDE.md and AGENTS.md
  byte-identical below the H1 title — if you edit one, edit the other.
-->

Project-level instructions for AI coding assistants working on
`non-convex-labs/laravel-commonplace`.

## Required reading (mandatory before non-trivial work)

These are the project's authoritative documents. **Read them before you
write code or open an issue or PR.** If anything below conflicts with
them, they win.

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — human contributor workflow,
  setup, the checks that must be green, PR scope, AI-attribution policy,
  security disclosure, and the issue/PR-body tripwire.
- [`docs/styleguides/laravel_styleguide.md`](docs/styleguides/laravel_styleguide.md)
  — PHP / Laravel 13 conventions, antipatterns, the canonical pre-PR
  checklist.
- [`docs/styleguides/docs_styleguide.md`](docs/styleguides/docs_styleguide.md)
  — how docs in this repo are structured and written.

This file is a fast reference for the highest-leverage rules. The
style guides and CONTRIBUTING.md are the source of truth.

## What this package is

A database-backed personal knowledge vault for Laravel: notes as Eloquent
models, a wikilink graph, semantic search, and an MCP server that exposes
the same vault to Claude Code, Cursor, and Zed under the host app's auth.
Pre-1.0; breaking changes are documented in [`CHANGELOG.md`](CHANGELOG.md).

Read [`README.md`](README.md) and [`docs/index.md`](docs/index.md) for the
elevator pitch and entry points before generating code.

## Code style — patterns

- **Pint owns formatting.** Run `vendor/bin/pint` before committing. CI gates
  on `vendor/bin/pint --test`. Preset is `laravel` (see [`pint.json`](pint.json)).
- **`declare(strict_types=1);` at the top of every PHP file.** Type-hint
  every parameter, return type, and property.
- **PHPStan level 5** on `src/`. CI gates on `vendor/bin/phpstan analyse`. New
  errors must be fixed, not appended to [`phpstan-baseline.neon`](phpstan-baseline.neon).
- **PHP 8.4+, Laravel 13.** Use 8.4 features (property hooks, asymmetric
  visibility) where they make domain code clearer.
- **Naming:** `camelCase` for variables/methods, `PascalCase` for classes,
  `snake_case` for config keys / DB columns / route names. Models singular
  (`Note`, not `Notes`). Boolean methods read as questions (`isPublished()`,
  `hasTag()`).
- **Early returns and guard clauses.** Avoid `else`; avoid nesting more than
  two deep.

## Code style — anti-patterns

- **Don't call `env()` outside `config/*.php`.** It returns `null` after
  `php artisan config:cache`. Read config through `config()`.
- **Don't return raw Eloquent models from HTTP endpoints.** Use API
  Resources or Laravel 13 JSON:API resources and project explicitly.
- **Don't lazy-load relations in loops.** Use `with(['relation'])` /
  `->load('relation')` / `withCount()`. `Model::preventLazyLoading()` is
  enabled in non-prod for a reason.
- **Don't use `$guarded = []`.** Use a `$fillable` allowlist.
- **Don't write raw SQL with string interpolation.** Use bindings or the
  query builder. For vector queries prefer Laravel 13's native vector APIs.
- **Don't hand-format.** If Pint would change a line, let it.
- **Don't append to the PHPStan baseline.** Fix the type error.

## MCP security — read this before touching `src/Mcp/` or any tool handler

The MCP surface is the most security-sensitive code in the repo. Several
landed fixes ([#115](https://github.com/non-convex-labs/laravel-commonplace/issues/115),
[#118](https://github.com/non-convex-labs/laravel-commonplace/issues/118),
[#123](https://github.com/non-convex-labs/laravel-commonplace/issues/123),
[#132](https://github.com/non-convex-labs/laravel-commonplace/issues/132))
closed exception-message leaks. **Don't regress them.**

### Where the redaction lives

- **Envelope sanitiser:** [`src/Mcp/CommonplaceMcpServer.php`](src/Mcp/CommonplaceMcpServer.php),
  method `publicMessageFor(Throwable $throwable): string` (around line 199).
  This is the single chokepoint every tool exception passes through. If you
  add a new branch (e.g. for a new vendor SDK exception class), add it here
  and only here.
- **Marker interface:** [`src/Exceptions/PublicMessage.php`](src/Exceptions/PublicMessage.php).
  Empty marker (`interface PublicMessage {}`) — implementing it opts an
  exception into `getMessage()` passing through to the wire.
- **Existing `PublicMessage` implementations** (use these as templates for
  new ones — they all compose message text from package-controlled
  allowlists, never from operator-environment data):
  [`BackupDestinationUnavailable`](src/Exceptions/BackupDestinationUnavailable.php),
  [`BackupDestinationNotConfigured`](src/Exceptions/BackupDestinationNotConfigured.php),
  [`EmbeddingProviderUnavailable`](src/Exceptions/EmbeddingProviderUnavailable.php),
  [`EmbeddingProviderNotConfigured`](src/Exceptions/EmbeddingProviderNotConfigured.php),
  [`MarkdownRendererConfigError`](src/Exceptions/MarkdownRendererConfigError.php),
  [`PartialBatchEmbeddingException`](src/Exceptions/PartialBatchEmbeddingException.php),
  [`PgvectorDriverNotReady`](src/Exceptions/PgvectorDriverNotReady.php).

### The rules

- **The MCP envelope is fail-close.** Unmarked `Throwable`s escaping a tool
  handler collapse to the fixed string `"The tool failed to complete the
  request."` — `getMessage()` never crosses the wire by default.
- **Opt a new exception class into wire-visible messages by implementing
  `PublicMessage`.** Only do this when the message text is composed from
  package-controlled allowlists — never from `$response->body()`, filesystem
  paths, SQL fragments, model class names, cache keys, bearer tokens, or
  any other operator-environment data. The interface docblock at
  [`src/Exceptions/PublicMessage.php`](src/Exceptions/PublicMessage.php) is
  the authoritative spec; read it before implementing.
- **DB-stack exceptions** (`QueryException`, `PDOException`,
  `DeadlockException`, `LostConnectionException`) have their own redaction
  branch in `publicMessageFor()` that preserves `SQLSTATE[<code>]`. That
  branch wins even for `PublicMessage`-implementing PDO subclasses. Leave
  it alone.
- **The authenticated HTTP read surface returns a uniform shape** for
  inaccessible-vs-missing notes (read endpoints `404` in both cases; the
  catch-all show route falls through to the folder browser at 200). Don't
  introduce `403` on a read endpoint — it's a probing channel. Write
  surfaces (`edit`, `update`, `destroy`) still `403`.
- **Use Policies for authorization.** `auth()->user()` proves authentication,
  never authorization.

When in doubt, fail closed and add a `Log::warning('mcp.envelope.redacted',
['class' => ..., 'tool' => ...])` breadcrumb so the next reviewer can grep
for what to mark next.

## Architecture

- **Thin controllers.** Push work into `src/Services/`, drivers, or jobs.
- **Bind services in `CommonplaceServiceProvider::register()`.** Use `boot()`
  only for wiring (routes, observers, migrations). Drivers are selected by a
  `match` on a config value — see the existing embedding-driver pattern in
  [`src/Drivers/`](src/Drivers/) and [`docs/embedding-drivers.md`](docs/embedding-drivers.md).
- **Inject collaborators; don't reach for facades inside services or
  drivers.** Internal Laravel facades (`Cache`, `Log`, `Storage`) are fine.
- **Public API is small and intentional.** Anything exported is a SemVer
  obligation through the next major. New surface needs a written reason.
- **New optional features sit behind a config flag.**

## Tests

- **PHPUnit 12 + Orchestra Testbench.** SQLite `:memory:` for the default
  suite. Postgres-only features (pgvector recursive CTEs, etc.) run against
  a real Postgres in CI on their own paths.
- **`composer test`** before opening a PR. Static analysis: `vendor/bin/phpstan analyse`.
- **Tests must assert real behavior.** No `assertTrue(true)`,
  no commented-out assertions, no TODO placeholders. We audit for this in
  review.
- **Don't mock what you own.** Prefer a null/fake driver class over runtime
  mocking. Mock at I/O boundaries only (HTTP, filesystem, paid external
  APIs).
- **New jobs declare `$tries` and `backoff()` and are idempotent.** They
  WILL run twice.

## Docs

- **Open every page with one declarative sentence, then a code block.** No
  "In this guide we will…" preamble.
- **Flat `docs/`, kebab-case filenames, no numeric prefixes.** Order belongs
  in nav config.
- **Real domain nouns over `foo`/`bar`.** This is a "commonplace book" —
  use notes, vaults, references, citations.
- **CHANGELOG entries are concrete.** A reader should be able to learn the
  what, the why, and the impact-on-callers from one entry without clicking
  through to the PR. Match the existing voice in [`CHANGELOG.md`](CHANGELOG.md).

## Attribution for AI-assisted PRs

If a PR was meaningfully AI-assisted, disclose it. Three places,
escalating detail. Substitute your tool's name if it's not Claude Code;
the shape stays the same.

**Commit trailer** (what Claude Code emits by default):

```
Co-Authored-By: Claude <noreply@anthropic.com>
```

**Issues and PR comments** — one-liner footer:

```
---
Written by Claude Opus 4.7 via [Claude Code](https://claude.ai/code)
```

**PR descriptions** — full block:

```
---
Generated with [Claude Code](https://claude.ai/code)
Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
Claude: <what AI did>
Human: <what human did>
```

Put the model name in the Name field. The `Claude:` and `Human:` lines
carry the signal — concrete, specific, sanity-checkable against the diff.
This is a context signal, not a quality signal. Full policy in
[`CONTRIBUTING.md`](CONTRIBUTING.md).

## What's reserved for humans

There is no `good first issue` reserved-for-humans policy here yet. If
one lands, it goes in this section. Until then: pre-1.0 means scope and
breaking changes still move week-to-week — coordinate in the issue
before a large PR.
