# laravel-commonplace

[![Latest Version](https://img.shields.io/packagist/v/non-convex-labs/laravel-commonplace.svg)](https://packagist.org/packages/non-convex-labs/laravel-commonplace)
[![Tests](https://github.com/non-convex-labs/laravel-commonplace/actions/workflows/run-tests.yml/badge.svg)](https://github.com/non-convex-labs/laravel-commonplace/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/non-convex-labs/laravel-commonplace/actions/workflows/phpstan.yml/badge.svg)](https://github.com/non-convex-labs/laravel-commonplace/actions/workflows/phpstan.yml)
[![License](https://img.shields.io/packagist/l/non-convex-labs/laravel-commonplace.svg)](LICENSE)

A database-backed personal knowledge vault for Laravel. You get wikilinks, version history, semantic search, and an MCP server so Claude Code, Cursor, and Zed can read and write your notes. Same database as your app, same auth, same backup.

## Quick look

Notes are Eloquent models. The `Commonplace` facade owns the writes, the wikilink graph, and search:

```php
use NonConvexLabs\Commonplace\Facades\Commonplace;

Commonplace::createNote(
    path: 'meetings/q2-planning',
    content: 'Discussed [[roadmap-2026]] and [[hiring-plan]].',
    tags: ['meeting'],
    visibility: 'private',
    owner: $user,
);

Commonplace::getBacklinks('roadmap-2026', $user);
Commonplace::semanticSearch('q2 planning topics', $user);
```

Set `COMMONPLACE_MCP_ENABLED=true` and the same vault is reachable to any MCP-compatible AI client, scoped to the authenticated user.

## Install

```bash
composer require non-convex-labs/laravel-commonplace
```

Full setup lives in [docs/](docs/index.md). That covers migrations, trait wiring, embedding driver, and MCP transport.

## Why a database vault, not a folder of `.md` files?

Most knowledge-vault tools are standalone apps backed by a folder of files. Sync, plugins, and auth get bolted on after the fact. This package flips that around. Notes are rows in your existing Laravel database. They're queryable by SQL, transactional, joinable, and gated by whichever guard your app already runs. You don't host a second silo, you don't run a file watcher, you don't manage separate API keys, and you don't hit "vault opened in another window" races.

See [docs/design.md](docs/design.md) for the full comparison against Obsidian, Logseq, Foam, and Dendron, plus the tradeoffs that come with it.

## Status

Pre-1.0. APIs may shift between minor versions until 1.0 ships.

## License

MIT. See [LICENSE](LICENSE).
