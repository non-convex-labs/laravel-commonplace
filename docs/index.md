# laravel-commonplace

A personal markdown knowledge vault for Laravel. You get wikilinks, version history, semantic search, and an MCP server for Claude Code.

> Status: pre-1.0. APIs may shift between minor versions.

## Install

```bash
composer require non-convex-labs/laravel-commonplace
php artisan vendor:publish --tag=commonplace-config
php artisan migrate
```

Add the `HasCommonplaceNotes` trait to whichever model owns notes. Usually that's `App\Models\User`:

```php
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;

class User extends Authenticatable
{
    use HasCommonplaceNotes;
}
```

Set the embedding driver in your `.env` (see [Embedding drivers](embedding-drivers.md)), then verify the install:

```bash
php artisan commonplace:doctor
```

## Start here

- [Design](design.md) — what this package is, who it's for, and how it stacks up against Obsidian, Logseq, Foam, and Dendron.
- [User model](user-model.md) — the required model contract, the optional `CommonplaceUser` interface, and the trait surface.
- [Embedding drivers](embedding-drivers.md) — Voyage, OpenAI, Cohere, Bedrock, null. How to switch and reindex.
- [Vector storage](vector-storage.md) — `in_php_cosine`, `pgvector`, `null`. Pick where embeddings live and how search runs.
- [Markdown rendering](markdown-rendering.md) — the CommonMark extension list, the runtime hook, and how to swap the wikilink resolver.
- [Theming](theming.md) — publish the views, override the CSS custom properties, and drop in your own nav.
- [Auth integration](auth.md) — session, Sanctum SPA, token-based, and public-read mode.

## Reference

- [Artisan commands](commands.md) — `commonplace:doctor` and `commonplace:reindex`. Flags, exit codes, and common invocations.
- [Model relationships](model-relationships.md) — Note, NoteVersion, Link, Share, and Tag schemas, plus the `accessibleBy` visibility model.

## Programmatic usage

- [Services and facade](services.md) — the `Commonplace` service (20 methods), the facade, and related helpers.
- [MCP tools](mcp-tools.md) — 16 MCP tools exposed to Claude Code. Signatures, auth model, and output shapes.
- [HTTP API](http-api.md) — routes and controllers behind the web UI, plus public-read mode endpoints.

## Operations

- [Backup](backup.md) — pluggable destinations, the bundle format, and how to write your own.

## Scenarios

- [Scenarios overview](scenarios/index.md) — narrative behavior specs grouped by who is driving the package (note-taker, AI agent, collaborator, public-visitor, integrator, operator). Use this folder to drive a verification pass against a running install.

## Internal reference

- [AI SDK evaluation](ai-sdk-evaluation.md) — the decision record for keeping the in-repo `EmbeddingProvider` contract.
- [CI/CD and supply chain](cicd-and-supply-chain.md) — GitHub Actions setup and supply-chain defenses.

## Style guides

- [Laravel style guide](styleguides/laravel_styleguide.md) — PHP and Laravel conventions used in this repo.
- [Docs style guide](styleguides/docs_styleguide.md) — how to write and organize docs in this repo.
