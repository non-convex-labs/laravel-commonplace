# Contributing

Thanks for considering a contribution. This file covers the human workflow:
how to set up, how to run the required checks, how PRs are scoped, and how to
disclose AI assistance.

Technical conventions (code style, antipatterns, MCP-security rules) live in
[`CLAUDE.md`](CLAUDE.md) and [`AGENTS.md`](AGENTS.md) so your AI tooling
picks them up automatically. Read them once if you're working through a
human review.

## Before you start

- **Bug reports** — open a GitHub issue with the Laravel and PHP version,
  a minimal reproduction, expected vs. actual behavior, and any relevant
  driver config (embedding driver, vector backend, MCP transport).
- **Failing test as a contribution** — a PR that only adds a failing test
  pinning down a bug is a welcome contribution. You don't have to ship the
  fix to start the conversation.
- **Larger changes** — open an issue first. Pre-1.0 means scope and API
  shape are still settling; a short discussion saves a long rebase.
- **Questions / ideas** — use GitHub Discussions, not the issue tracker.

## Local development

```bash
git clone https://github.com/non-convex-labs/laravel-commonplace
cd laravel-commonplace
composer install
```

The package tests boot a minimal Laravel app via Orchestra Testbench against
SQLite `:memory:`. No host app needed. Postgres-only paths (pgvector, the
recursive-CTE neighborhood query) run against a real Postgres in CI on
their own jobs.

## The required checks

All three must be green before requesting review. CI gates on them.

```bash
composer test                    # PHPUnit 12 + Testbench
vendor/bin/pint --test           # Laravel Pint, `laravel` preset
vendor/bin/phpstan analyse       # PHPStan level 5 on src/
```

If Pint wants to reformat, run `vendor/bin/pint` and commit the result —
don't argue with it. If PHPStan reports a new error, fix it; don't append
to [`phpstan-baseline.neon`](phpstan-baseline.neon).

For embedding-driver changes, also run the live-API smoke test described
in [`RELEASING.md`](RELEASING.md) before requesting review. CI's
`smoke-tests` workflow is manual-dispatch and reviewer-gated.

## Pull-request hygiene

- **One logical change per PR.** Two unrelated fixes are two PRs.
- **Branch from `main`.** Name it `fix/<issue#>-short-description` or
  `feat/<issue#>-short-description`. Reference the issue in the PR body
  with `Fixes #123` or `Refs #123`.
- **Rebase, don't merge.** Keep history linear.
- **Include a test** for any bug fix or new behavior. The
  `php-test-validator` agent audits for hollow assertions, TODO
  placeholders, and `assertTrue(true)` — assume it will run.
- **Update [`CHANGELOG.md`](CHANGELOG.md)** under `[Unreleased]` in the
  existing voice. Tag BREAKING changes. The entry should let a reader
  learn the what / why / impact without clicking through to the PR.
- **Don't bump versions or edit release tags** in PRs — that's a
  maintainer step (see [`RELEASING.md`](RELEASING.md)).

If a PR is closed, accept the maintainer's decision and engage on the next
one. Closure is not a verdict on the contributor.

## AI-assisted contributions

AI-assisted work is welcome. **Understanding the code you submit is not
optional** — if you can't explain why a line is there, don't submit it.
Unreviewed AI-slop will be closed.

Disclose AI assistance in three places when applicable. This pattern
mirrors [aaddrick/claude-desktop-debian](https://github.com/aaddrick/claude-desktop-debian);
it gives reviewers context without turning attribution into ceremony.

### Commits

Add a trailer (this is what Claude Code emits by default):

```
Co-Authored-By: Claude <noreply@anthropic.com>
```

### Issues and PR comments

A simple one-liner footer:

```
---
Written by Claude Opus 4.7 via [Claude Code](https://claude.ai/code)
```

Substitute the actual model name and tool you're using.

### PR descriptions

A full attribution block at the bottom:

```
---
Generated with [Claude Code](https://claude.ai/code)
Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
Claude: <what AI did>
Human: <what human did>
```

The model name goes in the Name field (e.g. `Claude Opus 4.7`,
`Claude Sonnet 4.6`). The `Claude:` and `Human:` lines are where the
signal lives — concrete, specific, sanity-checkable against the diff.
Reviewers calibrate scrutiny accordingly. This is a context signal,
not a quality signal.

Substitute the relevant tool name if you're using something other than
Claude Code (Cursor, Copilot, Aider, etc.). The shape stays the same.

## Security issues

Don't open a public issue for a security report. Email
**aaddrick@gmail.com** or open a private GitHub Security Advisory at
https://github.com/non-convex-labs/laravel-commonplace/security/advisories/new.

The MCP envelope, the HTTP read surface, and the embedding-driver
exception path have all had landed redaction fixes
([#115](https://github.com/non-convex-labs/laravel-commonplace/issues/115),
[#118](https://github.com/non-convex-labs/laravel-commonplace/issues/118),
[#123](https://github.com/non-convex-labs/laravel-commonplace/issues/123),
[#132](https://github.com/non-convex-labs/laravel-commonplace/issues/132)).
A regression there is worth a private report.

## See also

- [`CLAUDE.md`](CLAUDE.md) — agent-loaded conventions (Claude Code).
- [`AGENTS.md`](AGENTS.md) — agent-loaded conventions (vendor-neutral).
- [`docs/styleguides/laravel_styleguide.md`](docs/styleguides/laravel_styleguide.md)
  — PHP / Laravel 13 conventions and the pre-PR checklist.
- [`docs/styleguides/docs_styleguide.md`](docs/styleguides/docs_styleguide.md)
  — docs structure and prose conventions.
- [`RELEASING.md`](RELEASING.md) — maintainer-side release workflow.
