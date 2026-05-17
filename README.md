# laravel-commonplace

A personal markdown knowledge vault, as a Laravel package. Notes live in your app's database — not flat files — with wikilinks, version history, semantic search, and an MCP server for AI clients.

> Status: pre-1.0. APIs may shift between minor versions.

## Why this and not Obsidian / Logseq / Foam / Dendron?

Most knowledge-vault tools are standalone apps backed by a folder of `.md` files, with their own sync layer, plugin runtime, and auth bolted on top. This isn't an app and there is no folder — notes are rows in your Laravel database.

Reach for it if you:

- **Already have a Laravel app** and want notes living inside it (same DB, same users, same deployment). No second silo to host or sync.
- **Want a database-backed vault, not a folder of files.** Notes are rows in Postgres / MySQL / SQLite alongside the rest of your app's data — SQL-queryable, transactional, joinable. Version history is automatic; moving a note rewrites every wikilink that pointed at the old path (queued by default, sync via `COMMONPLACE_WIKILINKS_REWRITE_SYNC=true`); backup is your existing database backup. No file watcher, no sync conflicts, no "vault opened in another window" races.
- **Want auth to be Laravel's, not bespoke.** Whatever guard your app already runs — Breeze / Jetstream / Fortify session, Sanctum SPA cookie, Sanctum personal access tokens, Passport bearer tokens — gates the web UI, the HTTP API, and the MCP transport. `COMMONPLACE_ROUTES_MIDDLEWARE` wires the HTTP routes; `COMMONPLACE_MCP_MIDDLEWARE` (default `auth:sanctum`, tuned for Bearer-token MCP clients) wires the MCP transport. No bolt-on auth, no separate API keys to mint and rotate.
- **Want real multi-user, not single-vault.** Notes `belongsTo` a user. Visibility is `private` or `public`, with per-user grants via a `Share` model. A single `Note::accessibleBy($user)` scope filters every read across the HTTP API, MCP tools, and direct service calls — one source of truth for "which notes is this user allowed to see", so no entry point can leak someone else's note. Public-read mode exposes only `public` notes; private paths 404 (not 403) so the vault can't be enumerated.
- **Want an MCP-compatible AI client reading and writing your vault** through a structured tool surface — 16 MCP tools covering CRUD, search, semantic search, and wikilink-graph traversal (backlinks, N-hop neighborhood, shortest path, hubs, orphans, suggested links), all scoped to the authenticated user. Works with Claude Code, Pi, Cursor, Zed, and other MCP clients.
- **Want semantic search you control.** Pluggable embedding drivers (Voyage / OpenAI / Cohere / Bedrock / null) and pluggable vector storage (in-PHP cosine on any database, or pgvector on PostgreSQL).

Skip it if you want a polished cross-platform desktop client with offline sync, a plugin marketplace, or a community of theme authors. That's Obsidian's lane and this isn't trying to compete.

## Highlights

- **Database-backed** — notes are rows in Postgres / MySQL / SQLite, not files on disk. SQL-queryable, transactional, joinable with the rest of your app.
- **Laravel-native auth** — same guard (session, Sanctum SPA, Sanctum/Passport tokens) covers web, HTTP API, and MCP. Multi-user owner + share access model, single `accessibleBy` scope enforced everywhere.
- **MCP server** — off by default; one env flag exposes 16 tools to any MCP client (Claude Code, Pi, Cursor, Zed, …), each scoped to the authenticated user.
- **Wikilinks** — `[[double-bracket]]` parsing, backlinks, move-rewrites-references, graph queries.
- **Semantic search** — Voyage / OpenAI / Cohere / Bedrock embeddings; in-PHP or pgvector storage.
- **Version history** — every write snapshots; delete keeps a final version.
- **Markdown rendering** — CommonMark + emoji + syntax highlighting (Tempest). Swappable wikilink resolver.
- **Bundled UI** — self-contained Blade layout, CSS custom properties for theming, slot for your app's nav.
- **Public-read mode** — expose only `visibility = 'public'` notes to anonymous visitors.
- **Backup** — pluggable destinations, streaming bundle format.

## Requirements

- PHP 8.4+
- Laravel 13+

## Install

```bash
composer require non-convex-labs/laravel-commonplace
php artisan vendor:publish --tag=commonplace-config
php artisan migrate
php artisan commonplace:doctor
```

Add the `HasCommonplaceNotes` trait to whichever model owns notes (usually `App\Models\User`):

```php
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;

class User extends Authenticatable
{
    use HasCommonplaceNotes;
}
```

See [docs/](docs/index.md) for setup, configuration, and full documentation.

## License

MIT
