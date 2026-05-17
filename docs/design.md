# Design

Here's why this package exists, who I built it for, and what it isn't trying to be.

## What this is

A personal markdown knowledge vault delivered as a Laravel package. Notes are Eloquent models in your existing database. They're gated by your existing auth, queryable from your existing service layer, and optionally exposed over an MCP server to AI clients.

## Who it's for

Reach for it if you:

- **Already have a Laravel app** and want notes living inside it. Same DB, same users, same deployment. You don't have to host or sync a second silo.
- **Want a database-backed vault, not a folder of files.** Notes are rows in Postgres / MySQL / SQLite alongside the rest of your app's data. They're SQL-queryable, transactional, and joinable. Version history is automatic. Moving a note rewrites every wikilink that pointed at the old path. Backup is your existing database backup. No file watcher, no sync conflicts, no "vault opened in another window" races.
- **Want Laravel's auth, not bespoke auth.** Whatever guard your app already runs gates the web UI, the HTTP API, and the MCP transport. That includes Breeze / Jetstream / Fortify session, Sanctum SPA cookie, Sanctum personal access tokens, and Passport bearer tokens. See [auth.md](auth.md).
- **Want real multi-user, not single-vault.** Notes `belongsTo` a user. Visibility is `private` or `public`, with per-user grants via a `Share` model. A single `Note::accessibleBy($user)` scope filters every read across the HTTP API, MCP tools, and direct service calls. No entry point can leak another user's note.
- **Want an MCP-compatible AI client reading and writing your vault** through a structured tool surface. There are 16 MCP tools covering CRUD, search, semantic search, and wikilink-graph traversal, all scoped to the authenticated user. Works with Claude Code, Pi, Cursor, Zed, and other MCP clients. See [mcp-tools.md](mcp-tools.md).
- **Want semantic search you control.** Pluggable embedding drivers (Voyage / OpenAI / Cohere / Bedrock / null) and pluggable vector storage (in-PHP cosine on any database, or pgvector on PostgreSQL). See [embedding-drivers.md](embedding-drivers.md) and [vector-storage.md](vector-storage.md).

## Skip it if

- You want a polished cross-platform desktop client with offline sync, a plugin marketplace, or a community of theme authors. That's Obsidian's lane and I'm not trying to compete.
- Your vault has to remain portable as a folder of `.md` files for editing in other tools. Exporting works (see [backup.md](backup.md)), but the canonical store is the database, not the disk.

## How it compares

| | Obsidian / Logseq | Foam / Dendron | laravel-commonplace |
|---|---|---|---|
| Storage | Folder of `.md` files | Folder of `.md` files | Rows in your app DB |
| Auth | App-level / file-system | File-system | Laravel guards |
| Multi-user | Single-vault | Single-vault | Owner + per-user shares |
| Sync | App-specific service | Git / file sync | DB backup |
| AI access | Plugin or scrape files | Plugin or scrape files | MCP server, scoped to user |
| Editor | Native desktop / mobile app | VS Code | Browser UI in your Laravel app |
| Plugin ecosystem | Large | Small | None — extend via your Laravel app |

The mental model I keep coming back to: Obsidian is "an app for your knowledge." This is "knowledge as part of your app."

## Feature highlights

- **Database-backed** — notes are rows in Postgres / MySQL / SQLite, not files on disk. They're SQL-queryable, transactional, and joinable with the rest of your app.
- **Laravel-native auth** — same guard covers web, HTTP API, and MCP. Multi-user owner + share model, single `accessibleBy` scope enforced everywhere.
- **MCP server** — off by default. One env flag exposes 16 tools to any MCP client, each scoped to the authenticated user.
- **Wikilinks** — `[[double-bracket]]` parsing, backlinks, move-rewrites-references, and graph queries (neighborhood, shortest path, hubs, orphans, suggested links).
- **Semantic search** — Voyage / OpenAI / Cohere / Bedrock embeddings; in-PHP or pgvector storage.
- **Version history** — every write snapshots; delete keeps a final version.
- **Markdown rendering** — CommonMark + emoji + syntax highlighting (Tempest). Swappable wikilink resolver. See [markdown-rendering.md](markdown-rendering.md).
- **Bundled UI** — self-contained Blade layout, CSS custom properties for theming, slot for your app's nav. See [theming.md](theming.md).
- **Public-read mode** — expose only `visibility = 'public'` notes to anonymous visitors.
- **Backup** — pluggable destinations, streaming bundle format. See [backup.md](backup.md).
