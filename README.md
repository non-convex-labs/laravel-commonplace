# laravel-commonplace

A personal markdown knowledge vault for Laravel — wikilinks, version history, semantic search, and an MCP server for Claude Code.

> Status: pre-1.0. APIs may shift between minor versions.

## Requirements

- PHP 8.4+
- Laravel 13+

## Installation

```bash
composer require non-convex-labs/laravel-commonplace
php artisan vendor:publish --tag=commonplace-config
php artisan migrate
```

Add the `HasCommonplaceNotes` trait to whichever model owns notes (typically `App\Models\User`):

```php
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;

class User extends Authenticatable
{
    use HasCommonplaceNotes;
}
```

The trait provides three accessors:

| Method | Returns |
|---|---|
| `notes()` | `HasMany<Note>` — all notes owned by the user |
| `recentNotes(int $limit = 10)` | `Collection<Note>` — owned notes ordered by `updated_at` desc |
| `noteVersions()` | `HasMany<NoteVersion>` — versions this user authored (`changed_by`) |

Set the embedding driver in your `.env` (see [Embedding drivers](docs/embedding-drivers.md)) and run the diagnostic to verify the install:

```bash
php artisan commonplace:doctor
```

## Documentation

- [User model contract](docs/user-model.md) — required model contract, optional `CommonplaceUser` interface, trait surface.
- [Embedding drivers](docs/embedding-drivers.md) — Voyage, OpenAI, Cohere, Bedrock, null. How to switch and reindex.
- [Theming](docs/theming.md) — publishing views, overriding the CSS custom properties, injecting your own nav.
- [Markdown rendering](docs/markdown-rendering.md) — CommonMark extension list, runtime hook, swapping the wikilink resolver.
- [Auth integration](docs/auth.md) — session, Sanctum SPA, token-based, public-read mode.
- [Backup](docs/backup.md) — pluggable destinations, bundle format, writing your own.
- [Vector storage](docs/vector-storage.md) — `in_php_cosine`, `pgvector`, `null`.

### Internal reference

- [Laravel style and best practices](docs/laravel-style-and-best-practices.md)
- [CI/CD and supply chain](docs/cicd-and-supply-chain.md)
- [AI SDK evaluation](docs/ai-sdk-evaluation.md)

## License

MIT
