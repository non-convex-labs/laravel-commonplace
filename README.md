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
BEDROCK_EMBEDDING_CONCURRENCY=2
```

Credentials are resolved through the AWS SDK's default credential chain
(`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`, `~/.aws/credentials`, instance
metadata, etc.). Titan v2 supports `dimensions` values of `256`, `512`, and
`1024`.

**Bedrock has no batch-embeddings endpoint.** `embedBatch()` fans out
concurrent `InvokeModel` calls via `Aws\CommandPool` capped by
`BEDROCK_EMBEDDING_CONCURRENCY` (default `2`). Reindexes are still
slower than HTTP-batch providers like Voyage / OpenAI. Tune throughput
with two knobs together:

- `BEDROCK_EMBEDDING_CONCURRENCY` — peak in-flight `InvokeModel` calls
  per reindex batch.
- `COMMONPLACE_REINDEX_BATCH_DELAY` — pause (seconds) between batches.

Sustained RPM is roughly `concurrency * (60 / avg_latency_seconds)` —
at Titan v2's typical ~200ms latency, the default `concurrency=2`
sustains ~600 RPM if every batch is full. Stay under your account's
per-model Bedrock quota; otherwise the SDK's exponential backoff
kicks in and the whole batch fails (`embedBatch` is all-or-nothing
today). A cold AWS account is often capped well below 100 RPM — start
at the default and raise only after confirming headroom in CloudWatch.

## Markdown rendering

The markdown pipeline is built from a configurable list of CommonMark
extensions plus an optional runtime hook. Defaults ship in
`config/commonplace.php` under `markdown.extensions`:

```php
'extensions' => [
    League\CommonMark\Extension\Table\TableExtension::class,
    League\CommonMark\Extension\Autolink\AutolinkExtension::class,
    League\CommonMark\Extension\Strikethrough\StrikethroughExtension::class,
    League\CommonMark\Extension\TaskList\TaskListExtension::class,
    League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension::class,
    League\CommonMark\Extension\Footnote\FootnoteExtension::class,
    League\CommonMark\Extension\SmartPunct\SmartPunctExtension::class,
    ElGigi\CommonMarkEmoji\EmojiExtension::class,
    NonConvexLabs\Commonplace\Markdown\Wikilink\WikilinkExtension::class,
],
```

### Order and precedence

Extensions are registered in array order. Within CommonMark, extensions
registered later can override parsers / renderers registered earlier —
so put narrower / more specific extensions at the end of the list.
Runtime extenders registered via `Commonplace::extendMarkdown()` (below)
run AFTER the config list, so they always win on conflicts.

### Adding a parameterised extension

Entries can be either class strings (resolved through the container) or
already-constructed `ExtensionInterface` instances. Use the instance
form for extensions that take constructor args:

```php
'extensions' => [
    // ... defaults ...
    new League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension(),
    new League\CommonMark\Extension\ExternalLink\ExternalLinkExtension(),
],
```

### Removing `DisallowedRawHtmlExtension` is an XSS regression

Without it, raw `<script>` tags pass through to the output. Keep it
unless you have your own sanitizer downstream.

### Runtime extension hook

For custom inline parsers, renderers, or event listeners that don't fit
the class-string / instance config form, register a callback from your
service provider's `boot()` method:

```php
use Illuminate\Support\ServiceProvider;
use League\CommonMark\Environment\Environment;
use NonConvexLabs\Commonplace\Facades\Commonplace;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Commonplace::extendMarkdown(function (Environment $env): void {
            // Add your own inline parser, renderer, or event listener.
            $env->addInlineParser(new MyAdmonitionInlineParser, priority: 100);
        });
    }
}
```

**Register at boot time only.** Calling `extendMarkdown` per-request
under Octane / queue workers will accumulate callbacks across requests
and leak memory.

### Swapping the wikilink resolver

`[[wikilink]]` syntax is implemented as a CommonMark extension that
delegates target resolution to a swappable `WikilinkResolver`. The
default (`Services\WikilinkParser`) resolves against the `Note` model.
Bind your own implementation to point wikilinks elsewhere:

```php
use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;
use NonConvexLabs\Commonplace\Markdown\Wikilink\ResolvedWikilink;

class WikiResolver implements WikilinkResolver
{
    public function resolve(string $target): ?ResolvedWikilink
    {
        // Look the target up in your own model, an external API, etc.
        return new ResolvedWikilink(
            href: route('docs.show', ['slug' => Str::slug($target)]),
            title: $target,
        );
    }
}

// In your service provider's register():
$this->app->bind(WikilinkResolver::class, WikiResolver::class);
```

Returning `null` produces a broken-link `<a>` with class
`vault-link-broken` (uses `commonplace.routes.prefix` as the fallback
href base).

## Auth integration

The default middleware stack on the package's routes is `web,auth`. Set
`COMMONPLACE_ROUTES_MIDDLEWARE` (comma-separated) to point the routes at
a different guard.

### Session (default)

Nothing to configure. Sign in with `App\Http\Controllers\Auth\*` (Breeze,
Jetstream, Fortify) and hit `/commonplace`.

```dotenv
COMMONPLACE_ROUTES_MIDDLEWARE=web,auth
```

### Sanctum SPA (cookie)

Sanctum SPA flow uses cookie auth backed by stateful API requests.
Add `auth:sanctum` after `web` so requests with the Sanctum session
cookie authenticate cleanly:

```dotenv
COMMONPLACE_ROUTES_MIDDLEWARE=web,auth:sanctum
```

You'll also want `EnsureFrontendRequestsAreStateful` if your SPA lives
on a different subdomain — see Laravel's Sanctum docs.

### Token-based (Sanctum personal access tokens / Passport)

For API consumers using bearer tokens:

```dotenv
COMMONPLACE_ROUTES_MIDDLEWARE=auth:api
```

(Sanctum users can use `auth:sanctum`; both Sanctum personal access
tokens and Passport map to the standard `auth:<guard>` middleware.)

### Public-read mode

Expose only notes with `visibility = 'public'` to unauthenticated
visitors at `{prefix}/public/{path}`:

```dotenv
COMMONPLACE_PUBLIC_ROUTES_ENABLED=true
# Optional — default is `web` (no auth):
COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE=web
```

The public route group registers `GET {prefix}/public/{path}` (rendered
HTML) and `GET {prefix}/public/raw/{path}` (plain-text source). Notes
with `visibility != 'public'` 404 — not 403 — so an attacker can't
enumerate the private vault. Editing, listing, and search are not
exposed via the public group; those stay behind the authenticated
routes.

Combine with the user-model `getAuthIdentifier()` / `name` contract
documented under [Installation](#installation).

## Backup

Backups are pluggable. Configure one or more destinations via
`COMMONPLACE_BACKUP_DESTINATIONS` (comma-separated):

```dotenv
# Single GitHub destination
COMMONPLACE_BACKUP_DESTINATIONS=github
COMMONPLACE_GITHUB_BACKUP_REPO=your-org/your-vault
COMMONPLACE_GITHUB_BACKUP_TOKEN=ghp_...

# Or fan-out to GitHub + a filesystem disk in one job
COMMONPLACE_BACKUP_DESTINATIONS=github,filesystem.local-backup
COMMONPLACE_BACKUP_FS_LOCAL_DISK=s3-prod
COMMONPLACE_BACKUP_FS_LOCAL_PATH=vault-backups
```

Schedule `\NonConvexLabs\Commonplace\Jobs\BackupVault::dispatch()` from
your app's scheduler. The job builds a single `BackupBundle` and pushes
it sequentially to every destination — if one fails, subsequent
destinations are skipped and the job retries (5 tries, 30s/120s/300s
backoff).

### Bundle format (schema v1.0)

Each destination receives the same payload:

- One markdown file per note at the note's `path` (`.md` appended if
  missing).
- A `manifest.json` at the bundle root:

```json
{
  "version": "1.0",
  "generated_at": "2026-05-17T08:21:14+00:00",
  "note_count": 42,
  "notes": [
    {"path": "notes/foo.md", "title": "Foo", "checksum": "sha256:..."},
    ...
  ]
}
```

### Custom destinations

Implement `NonConvexLabs\Commonplace\Contracts\BackupDestination` and
bind it in your service provider:

```php
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;

class GcsBackupDestination implements BackupDestination
{
    public function push(BackupBundle $bundle): void
    {
        // Use $bundle->files() (one .md per note) and
        // $bundle->manifestJson() to write to your target.
    }
}

// In a service provider's register():
$this->app->bind('gcs-snapshot', GcsBackupDestination::class);
```

Then list it in `COMMONPLACE_BACKUP_DESTINATIONS=github,gcs-snapshot`.

The legacy `BackupToGitHub` job is preserved for back-compat — it
dispatches the GitHub destination directly without consulting the
`destinations` list. Prefer `BackupVault` for new code.

## Vector storage

See `config/commonplace.php` for the three storage backends: `in_php_cosine`
(default; portable), `pgvector` (PostgreSQL + pgvector), and `null` (disabled).

## License

MIT
