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

Set the embedding driver in your `.env` (see [Embedding drivers](#embedding-drivers)
below) and run the diagnostic to verify the install:

```bash
php artisan commonplace:doctor
```

## Embedding drivers

Pick one driver in `config/commonplace.php` (or via
`COMMONPLACE_EMBEDDING_DRIVER`). Each driver self-reports the dimensionality of
its output vectors, and that dimensionality is what the storage column will be
sized to. **Changing driver or model without re-embedding existing rows
produces garbage results** — always run `php artisan commonplace:reindex` after
a switch.

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
the truncation server-side.

### Cohere

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=cohere
COHERE_API_KEY=...
COHERE_EMBEDDING_MODEL=embed-english-v3.0
COHERE_EMBEDDING_DIMENSIONS=1024
COHERE_EMBEDDING_INPUT_TYPE=search_document
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
