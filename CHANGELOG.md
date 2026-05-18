# Changelog

All notable changes to `non-convex-labs/laravel-commonplace` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See [RELEASING.md](RELEASING.md) for the tag-and-publish workflow.

## [Unreleased]

### Changed

- **BREAKING (HTTP API).** Authenticated read routes no longer return `403` on inaccessible-but-existing notes. The catch-all show route `GET /{prefix}/{path}` falls through to the folder browser (HTTP 200), and `/{prefix}/raw/{path}`, `/{prefix}/download/{path}`, `/{prefix}/history/{path}`, `/{prefix}/graph/neighborhood/{path}`, and `/{prefix}/suggested-links/{path}` all `404` in both the inaccessible and missing cases. The collapse closes a status-code enumeration channel an authenticated caller could otherwise use to probe foreign paths. Write surfaces (`edit`, `update`, `destroy`) still return `403`. Consumers that scripted against `403` to detect access denial should use the share API instead. ([#123](https://github.com/non-convex-labs/laravel-commonplace/pull/127))
- `AssetController::css` now emits `Cache-Control: no-store` when `APP_DEBUG=true`, so iterating on a published CSS override doesn't fight a 1-hour cache. Production keeps `public, max-age=3600`. JS responses are unchanged. ([#121](https://github.com/non-convex-labs/laravel-commonplace/pull/125))
- `AssetController::css` now serves the consumer's published override at `resources/css/commonplace/commonplace.css` when present, falling back to the bundled copy otherwise. The bundled file's header carries a note that the override is a hard pin — package CSS upgrades require `--force` re-publish or deleting the override. ([#116](https://github.com/non-convex-labs/laravel-commonplace/pull/120))
- MCP tool errors that bubble up as `QueryException` now redact to `Database error: SQLSTATE[<code>]` instead of leaking DB host/port/database, the parameterized SQL trace, or PDO's `DETAIL:` row data. Operators still see the full exception via `report()`. ([#115](https://github.com/non-convex-labs/laravel-commonplace/pull/119))
- MCP tool exceptions now ride a JSON-RPC `result.isError` envelope at HTTP 200 instead of leaking as a bare 500. Protocol-level errors (parse, unknown method) still propagate as JSON-RPC `error` responses. ([#110](https://github.com/non-convex-labs/laravel-commonplace/pull/114))

### Fixed

- The `COMMONPLACE_*_MIDDLEWARE` env parser now preserves the comma inside parameterized middleware tokens, so `COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE="web,throttle:30,1"` parses as a single `throttle:30,1` token. Consumers on the old published config need `php artisan vendor:publish --tag=commonplace-config --force` to pick up the fix. ([#108](https://github.com/non-convex-labs/laravel-commonplace/pull/113))
- Postgres `getNeighborhood` / `getShortestPath` recursive CTEs cast the seed `note_id` to `bigint`, so the join no longer fails with `operator does not exist: bigint = text`. ([#109](https://github.com/non-convex-labs/laravel-commonplace/pull/112))

### Documentation

- The "Visibility scope" cross-cutting invariant in `docs/scenarios/index.md` is rewritten to describe each surface's actual canonicalisation — MCP and public-read both collapse missing/inaccessible into `Note not found.`, the authenticated read routes now collapse too (per #123 above), and write routes intentionally retain `403`. The invariant also acknowledges a residual timing side channel as out of scope for the threat model. ([#117](https://github.com/non-convex-labs/laravel-commonplace/pull/124), [#123](https://github.com/non-convex-labs/laravel-commonplace/pull/127))
- `tests/Feature/Http/AssetControllerTest.php` JS-override negative test now asserts the bundled `CP_JS_BUNDLED_MARKER` sentinel positively, so an empty body can't slip past the absence check. ([#122](https://github.com/non-convex-labs/laravel-commonplace/pull/126))

## [v0.1.0] — 2025

Initial public release. See git history for the full feature surface and the `docs/scenarios/index.md` validation log for the spec-vs-implementation baseline.

[Unreleased]: https://github.com/non-convex-labs/laravel-commonplace/compare/v0.1.0...HEAD
[v0.1.0]: https://github.com/non-convex-labs/laravel-commonplace/releases/tag/v0.1.0
