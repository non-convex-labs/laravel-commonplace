# laravel-commonplace

A personal markdown knowledge vault for Laravel — wikilinks, version history, semantic search, and an MCP server for Claude Code.

> Status: pre-1.0. APIs may shift between minor versions.

## Install

```bash
composer require non-convex-labs/laravel-commonplace
php artisan vendor:publish --tag=commonplace-config
php artisan migrate
```

Add the `HasCommonplaceNotes` trait to whichever model owns notes (usually `App\Models\User`):

```php
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;

class User extends Authenticatable
{
    use HasCommonplaceNotes;
}
```

Set the embedding driver in your `.env` (see [Embedding drivers](embedding-drivers.md)) and verify the install:

```bash
php artisan commonplace:doctor
```

## Start here

- [User model](user-model.md) — required model contract, optional `CommonplaceUser` interface, trait surface.
- [Embedding drivers](embedding-drivers.md) — Voyage, OpenAI, Cohere, Bedrock, null. How to switch and reindex.
- [Vector storage](vector-storage.md) — `in_php_cosine`, `pgvector`, `null`. Pick where embeddings live and how search runs.
- [Markdown rendering](markdown-rendering.md) — CommonMark extension list, runtime hook, swapping the wikilink resolver.
- [Theming](theming.md) — publishing views, overriding the CSS custom properties, injecting your own nav.
- [Auth integration](auth.md) — session, Sanctum SPA, token-based, public-read mode.

## Reference

- [Artisan commands](commands.md) — `commonplace:doctor`, `commonplace:reindex`. Flags, exit codes, common invocations.
- [Model relationships](model-relationships.md) — Note, NoteVersion, Link, Share, Tag schemas and the `accessibleBy` visibility model.

## Programmatic usage

- [Services and facade](services.md) — the `Commonplace` service (20 methods), facade, related helpers.
- [MCP tools](mcp-tools.md) — 16 MCP tools exposed to Claude Code: signatures, auth model, output shapes.
- [HTTP API](http-api.md) — routes and controllers behind the web UI; public-read mode endpoints.

## Operations

- [Backup](backup.md) — pluggable destinations, bundle format, writing your own.

## Internal reference

- [AI SDK evaluation](ai-sdk-evaluation.md) — decision record for keeping the in-repo `EmbeddingProvider` contract.
- [CI/CD and supply chain](cicd-and-supply-chain.md) — GitHub Actions setup and supply-chain defenses.

## Style guides

- [Laravel style guide](styleguides/laravel_styleguide.md) — PHP / Laravel conventions used in this repo.
- [Docs style guide](styleguides/docs_styleguide.md) — how to write and organize docs in this repo.
