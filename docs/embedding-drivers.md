# Embedding drivers

Pick a provider, set its API key, and the rest of the package adapts. Each driver reports the dimensionality of its output vectors. That number sizes the storage column.

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=voyage
VOYAGE_API_KEY=...
```

> [!WARNING]
> Changing driver or model without re-embedding existing rows produces garbage results. Always run `php artisan commonplace:reindex --force` after a switch. `--force` clears `indexed_at` so existing rows are re-embedded instead of skipped. See [commands.md](./commands.md) for the full reindex flow.

The vectors this layer produces flow into [vector storage](./vector-storage.md). That's where they live and where similarity search runs against them.

## Basic usage

Set the driver in `config/commonplace.php` (or via `COMMONPLACE_EMBEDDING_DRIVER`) and provide the driver-specific knobs below. Available drivers: `voyage`, `openai`, `cohere`, `bedrock`, `null`.

| Driver | Default model | Dimensions | Notes |
|---|---|---|---|
| `voyage` | `voyage-3.5` | 1024 | Default. Cheap and high-quality for English. |
| `openai` | `text-embedding-3-small` | 1536 | Also `text-embedding-3-large` (3072). The `dimensions` parameter truncates server-side. |
| `cohere` | `embed-english-v3.0` | 1024 | Also `embed-multilingual-v3.0` (1024). Configurable `input_type` per call site. |
| `bedrock` | `amazon.titan-embed-text-v2:0` | 1024 | Configurable to 256 / 512 / 1024. Uses your default AWS credential chain. |
| `null` | — | 1024 | Zero vectors. For tests / disabling semantic search. |

All drivers implement [`EmbeddingProvider`](../src/Contracts/EmbeddingProvider.php) and are wired through the container. Bind your own to swap providers in tests. See [services.md](./services.md) for the binding points.

## Voyage

The default driver. Cheap, fast, good English quality.

```dotenv
VOYAGE_API_KEY=...
VOYAGE_EMBEDDING_MODEL=voyage-3.5
VOYAGE_EMBEDDING_DIMENSIONS=1024
```

### Rate limits and failure modes

The driver does not retry 429s. Any failed HTTP call — rate limit, timeout, 5xx — throws `RuntimeException` immediately. `embedBatch()` splits inputs into chunks of 128; a failure mid-iteration aborts the rest of the call and discards every chunk that already succeeded. The caller receives nothing. Unlike the [Bedrock driver](#bedrock), Voyage does not surface a `PartialBatchEmbeddingException` — `embedBatch()` fails atomically and there is no partial-progress signal.

Queue-level retry catches the next attempt: `ReindexNotes` carries `#[Tries(3)]` and `#[Backoff([10, 30, 120])]`. That retries the *entire batch* and is not 429-aware — a 500 looks the same as a rate-limit hit.

> [!WARNING]
> `php artisan commonplace:doctor` checks config and calls `dimensions()`, but never hits the Voyage API. `dimensions()` reads from config and never exercises the API key, so an invalid or missing key passes the doctor check and only surfaces on the first real embed. The same is true for a quota-exhausted account.

The only knob is `COMMONPLACE_REINDEX_BATCH_DELAY` (default `25`), a flat pause between batches in the reindex job. There is no token-per-minute awareness. On a free-tier or low-quota account, lower `COMMONPLACE_REINDEX_BATCH_SIZE` to shrink the unit of retry — a queue retry redoes the entire job-level batch, so smaller batches mean less re-embedding work on the retry — and raise `COMMONPLACE_REINDEX_BATCH_DELAY` to keep average RPM under your limit.

## OpenAI

```dotenv
OPENAI_API_KEY=...
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIMENSIONS=1536
```

For the larger model, switch the model and let it use its native size:

```dotenv
OPENAI_EMBEDDING_MODEL=text-embedding-3-large
OPENAI_EMBEDDING_DIMENSIONS=3072
```

`OPENAI_EMBEDDING_DIMENSIONS` truncates the vector below the model's native size when set lower (e.g. `512`). OpenAI applies the truncation server-side.

> [!WARNING]
> The `dimensions` parameter is supported only on `text-embedding-3-*` models. Setting `OPENAI_EMBEDDING_DIMENSIONS` while using a non-v3 model (e.g. `text-embedding-ada-002`) throws a configuration error before any API call. Unset the variable to let the model use its native size.

## Cohere

```dotenv
COHERE_API_KEY=...
COHERE_EMBEDDING_MODEL=embed-english-v3.0
COHERE_EMBEDDING_DIMENSIONS=1024
```

Cohere v3 distinguishes indexing from querying via `input_type`. The driver uses the recommended pair by default: `search_document` when indexing notes, `search_query` when a user searches. Override only if you know what you're doing. See [`config/commonplace.php`](../config/commonplace.php) for the `index_input_type` / `query_input_type` keys.

For multilingual content:

```dotenv
COHERE_EMBEDDING_MODEL=embed-multilingual-v3.0
```

## Bedrock (Amazon Titan)

The Bedrock driver depends on `aws/aws-sdk-php`. Install it explicitly:

```bash
composer require aws/aws-sdk-php
```

```dotenv
AWS_BEDROCK_REGION=us-east-1
BEDROCK_EMBEDDING_MODEL=amazon.titan-embed-text-v2:0
BEDROCK_EMBEDDING_DIMENSIONS=1024
```

Credentials are resolved through the AWS SDK's default credential chain (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`, `~/.aws/credentials`, instance metadata, etc.). Titan v2 supports `dimensions` values of `256`, `512`, and `1024`. Two further knobs (`BEDROCK_EMBEDDING_NORMALIZE`, `BEDROCK_EMBEDDING_CONCURRENCY`) live in [`config/commonplace.php`](../config/commonplace.php).

### Throughput tuning

Bedrock has no batch-embeddings endpoint. `embedBatch()` fans out concurrent `InvokeModel` calls via `Aws\CommandPool` capped by `BEDROCK_EMBEDDING_CONCURRENCY` (default `2`). Reindexes are still slower than HTTP-batch providers like Voyage or OpenAI. Tune throughput with two knobs together:

- `BEDROCK_EMBEDDING_CONCURRENCY` — peak in-flight `InvokeModel` calls per reindex batch.
- `COMMONPLACE_REINDEX_BATCH_DELAY` — pause (seconds) between batches.

Sustained RPM is roughly `concurrency * (60 / avg_latency_seconds)`. At Titan v2's typical ~200ms latency, the default `concurrency=2` sustains ~600 RPM if every batch is full.

> [!WARNING]
> Stay under your account's per-model Bedrock quota. If you exceed it, the SDK's exponential backoff kicks in and the first failure aborts the rest of the batch. The driver surfaces what already succeeded via `PartialBatchEmbeddingException` so callers can checkpoint and retry only the remainder. But a cold AWS account is often capped well below 100 RPM. Start at the default and raise only after confirming headroom in CloudWatch.

## Null

Zero vectors. Use for tests, or for installations that want to disable semantic search entirely.

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=null
COMMONPLACE_NULL_DIMENSIONS=1024
```

Pair with the `null` vector storage driver if you want search disabled end-to-end. See [vector-storage.md](./vector-storage.md).

## Reindexing after a switch

Every driver swap (and every model change within a driver) requires a reindex. The command lives in [commands.md](./commands.md). The short form:

```bash
php artisan commonplace:reindex --force
```

`--force` clears `indexed_at` on every note so existing rows are re-embedded instead of skipped. Add `--sync` to run inline if you don't have a queue worker. The job batches notes through `embedBatch()` and writes results to the configured [vector storage](./vector-storage.md) driver. Tune throughput via the `reindex` block in [`config/commonplace.php`](../config/commonplace.php) (`batch_size`, `batch_delay_seconds`, `cooldown_minutes`).
