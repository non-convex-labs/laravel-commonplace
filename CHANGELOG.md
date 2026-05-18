# Changelog

All notable changes to `non-convex-labs/laravel-commonplace` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See [RELEASING.md](RELEASING.md) for the tag-and-publish workflow.

## [Unreleased]

## [v0.2.0] — 2026-05-18

### Added

- Note version history view in the UI. ([#69](https://github.com/non-convex-labs/laravel-commonplace/pull/92))
- Share service methods: `grant`, `revoke`, `list`. ([#63](https://github.com/non-convex-labs/laravel-commonplace/pull/91))
- Voyage embedding driver: 429 retry, partial-batch handling, doctor probe. ([#86](https://github.com/non-convex-labs/laravel-commonplace/pull/93))
- Pint auto-fix workflow on push to `main`. ([#52](https://github.com/non-convex-labs/laravel-commonplace/pull/82))

### Changed

- MCP tool errors travel via JSON-RPC `result.isError` envelope at HTTP 200 instead of bare 500s. ([#110](https://github.com/non-convex-labs/laravel-commonplace/pull/114))
- MCP `tools/list` now returns all 16 tools (raised default page size). ([#75](https://github.com/non-convex-labs/laravel-commonplace/pull/75))
- Curated `PublicMessage` exceptions for embedding providers and the GitHub backup destination — wire-visible operator hints without response-body leaks. ([#132](https://github.com/non-convex-labs/laravel-commonplace/pull/133))
- `AssetController::css` emits `Cache-Control: no-store` when `APP_DEBUG=true`, and serves the consumer's published override when present. ([#121](https://github.com/non-convex-labs/laravel-commonplace/pull/125), [#116](https://github.com/non-convex-labs/laravel-commonplace/pull/120))
- Reindex default scope picks up notes with stale embeddings. ([#85](https://github.com/non-convex-labs/laravel-commonplace/pull/90))
- Public-route prefix is configurable. ([#61](https://github.com/non-convex-labs/laravel-commonplace/pull/89))
- `updateNote` regenerates the title from basename when the frontmatter title is removed. ([#81](https://github.com/non-convex-labs/laravel-commonplace/pull/94))

### Fixed

- `COMMONPLACE_*_MIDDLEWARE` env parser preserves commas inside parameterized middleware tokens (`throttle:30,1`). ([#108](https://github.com/non-convex-labs/laravel-commonplace/pull/113))
- Postgres recursive CTEs cast seed `note_id` to `bigint`. ([#109](https://github.com/non-convex-labs/laravel-commonplace/pull/112))
- Wikilink move-rewrite skips occurrences inside code fences and inline code. ([#95](https://github.com/non-convex-labs/laravel-commonplace/pull/103))
- Tags prune on note delete and tag-replace. ([#71](https://github.com/non-convex-labs/laravel-commonplace/pull/87))
- All package migrations auto-load — `php artisan migrate` Just Works. ([#77](https://github.com/non-convex-labs/laravel-commonplace/pull/77))
- Public-read view drops auth-only chrome. ([#68](https://github.com/non-convex-labs/laravel-commonplace/pull/88))
- `NoteVersion` contract: stores displaced content; `createNote` writes none. ([#78](https://github.com/non-convex-labs/laravel-commonplace/pull/78))
- `AssetControllerTest` asserts the bundled JS marker positively. ([#122](https://github.com/non-convex-labs/laravel-commonplace/pull/126))
- Two real bugs surfaced and fixed during a PHPStan baseline refresh. ([#101](https://github.com/non-convex-labs/laravel-commonplace/pull/101))

### Security

- **BREAKING (MCP envelope).** Fail-close — unhandled `Throwable`s from MCP tool handlers collapse to a fixed string. Opt classes in via the new `PublicMessage` marker. ([#118](https://github.com/non-convex-labs/laravel-commonplace/pull/131))
- **BREAKING (HTTP API).** Authenticated read routes `404` instead of `403` on inaccessible notes — closes a status-code enumeration channel. Write surfaces still `403`. ([#117](https://github.com/non-convex-labs/laravel-commonplace/pull/124), [#123](https://github.com/non-convex-labs/laravel-commonplace/pull/127))
- MCP DB-stack exceptions (`QueryException`, `PDOException`, `DeadlockException`, `LostConnectionException`) redact to `SQLSTATE[<code>]`. ([#115](https://github.com/non-convex-labs/laravel-commonplace/pull/119), [#118](https://github.com/non-convex-labs/laravel-commonplace/pull/130))
- MCP read tools collapse inaccessible into `"Note not found."`. ([#76](https://github.com/non-convex-labs/laravel-commonplace/pull/76))
- Public route prefix sealed against method-override and toggle leaks. ([#97](https://github.com/non-convex-labs/laravel-commonplace/pull/106))
- Public route prefix sealed against the auth catch-all. ([#96](https://github.com/non-convex-labs/laravel-commonplace/pull/105))
- UI gates non-owner actions at both controller and view. ([#98](https://github.com/non-convex-labs/laravel-commonplace/pull/107))

## [v0.1.0] — 2026-05-17

Initial public release. See git history for the full feature surface and the `docs/scenarios/index.md` validation log for the spec-vs-implementation baseline.

[Unreleased]: https://github.com/non-convex-labs/laravel-commonplace/compare/v0.2.0...HEAD
[v0.2.0]: https://github.com/non-convex-labs/laravel-commonplace/compare/v0.1.0...v0.2.0
[v0.1.0]: https://github.com/non-convex-labs/laravel-commonplace/releases/tag/v0.1.0
