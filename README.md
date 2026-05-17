# laravel-commonplace

A personal markdown knowledge vault for Laravel — wikilinks, version history,
semantic search, and an MCP server for Claude Code.

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

Add the `HasCommonplaceNotes` trait to whichever model owns notes
(typically `App\Models\User`):

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

Set the embedding driver in your `.env` (see [Embedding drivers](#embedding-drivers)
below) and run the diagnostic to verify the install:

```bash
php artisan commonplace:doctor
```

### User model contract

The user model is configurable via `commonplace.user_model` (default
`App\Models\User`). The package expects:

- **`id`** — integer primary key. The FK columns `notes.user_id` and
  `note_versions.changed_by` are hardcoded; a non-`id` primary key
  won't work without forking.
- **`getAuthIdentifier()`** — supplied by Laravel's `Authenticatable`.
  Used to stamp `changed_by` on each version.
- **`name`** — read by the MCP `history` tool when surfacing version
  attribution. Optional but recommended; appears as `null` if missing.
  (The package does not currently read `email`. If your User model
  exposes only `email`, attribution will fall back to `null` until the
  web UI port adds an email-based display path.)

For stricter typing, implement
`NonConvexLabs\Commonplace\Contracts\CommonplaceUser` — the trait
satisfies it structurally:

```php
use NonConvexLabs\Commonplace\Contracts\CommonplaceUser;

class User extends Authenticatable implements CommonplaceUser
{
    use HasCommonplaceNotes;
}
```

## Embedding drivers

Pick one driver in `config/commonplace.php` (or via
`COMMONPLACE_EMBEDDING_DRIVER`). Each driver self-reports the dimensionality of
its output vectors, and that dimensionality is what the storage column will be
sized to. **Changing driver or model without re-embedding existing rows
produces garbage results** — always run `php artisan commonplace:reindex --force`
after a switch (the `--force` flag clears `indexed_at` so existing rows are
re-embedded instead of skipped). Add `--sync` to run inline if you don't have
a queue worker.

### Driver matrix

| Driver | Default model | Dimensions | Notes |
|---|---|---|---|
| `voyage` | `voyage-3.5` | 1024 | Default. Cheap and high-quality for English. |
| `openai` | `text-embedding-3-small` | 1536 | Also: `text-embedding-3-large` (3072). The `dimensions` parameter truncates server-side. |
| `cohere` | `embed-english-v3.0` | 1024 | Also: `embed-multilingual-v3.0` (1024). Configurable `input_type` (default `search_document`). |
| `bedrock` | `amazon.titan-embed-text-v2:0` | 1024 | Configurable to 256 / 512 / 1024. Uses your default AWS credential chain. |
| `null` | — | 1024 | Zero vectors. For tests / disabling semantic search. |

### Voyage

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=voyage
VOYAGE_API_KEY=...
VOYAGE_EMBEDDING_MODEL=voyage-3.5
VOYAGE_EMBEDDING_DIMENSIONS=1024
```

### OpenAI

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=openai
OPENAI_API_KEY=...
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIMENSIONS=1536
```

For the larger model:

```dotenv
OPENAI_EMBEDDING_MODEL=text-embedding-3-large
OPENAI_EMBEDDING_DIMENSIONS=3072
```

You can also truncate the vectors below the model's native size by setting
`OPENAI_EMBEDDING_DIMENSIONS` to a smaller value (e.g. `512`). OpenAI applies
the truncation server-side. **This parameter is supported only on
`text-embedding-3-*` models.** If you set `OPENAI_EMBEDDING_DIMENSIONS` while
using a non-v3 model (e.g. `text-embedding-ada-002`), the driver throws a
configuration error before any API call — unset the variable to let the model
use its native size.

### Cohere

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=cohere
COHERE_API_KEY=...
COHERE_EMBEDDING_MODEL=embed-english-v3.0
COHERE_EMBEDDING_DIMENSIONS=1024
```

Cohere v3 distinguishes indexing from querying via `input_type`. The
driver uses two separate values and defaults to the recommended pair:
`search_document` when indexing notes and `search_query` when a user
searches. Only override these if you know what you're doing:

```dotenv
COHERE_EMBEDDING_INDEX_INPUT_TYPE=search_document
COHERE_EMBEDDING_QUERY_INPUT_TYPE=search_query
```

For multilingual content:

```dotenv
COHERE_EMBEDDING_MODEL=embed-multilingual-v3.0
```

### Bedrock (Amazon Titan)

The Bedrock driver depends on `aws/aws-sdk-php`. Install it explicitly:

```bash
composer require aws/aws-sdk-php
```

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=bedrock
AWS_BEDROCK_REGION=us-east-1
BEDROCK_EMBEDDING_MODEL=amazon.titan-embed-text-v2:0
BEDROCK_EMBEDDING_DIMENSIONS=1024
BEDROCK_EMBEDDING_NORMALIZE=true
```

Credentials are resolved through the AWS SDK's default credential chain
(`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`, `~/.aws/credentials`, instance
metadata, etc.). Titan v2 supports `dimensions` values of `256`, `512`, and
`1024`.

## Vector storage

See `config/commonplace.php` for the three storage backends: `in_php_cosine`
(default; portable), `pgvector` (PostgreSQL + pgvector), and `null` (disabled).

## License

MIT
