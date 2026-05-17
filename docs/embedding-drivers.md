# Embedding drivers

Pick a provider, set the env vars, run a reindex.

Commonplace ships with five embedding drivers behind a single contract. You
pick one in `config/commonplace.php` (or via `COMMONPLACE_EMBEDDING_DRIVER`),
and the rest of the package adapts. Each driver self-reports the dimensionality
of its output vectors, and that number is what the storage column gets sized to.

**Changing driver or model without re-embedding existing rows produces garbage
results.** Always run `php artisan commonplace:reindex --force` after a switch.
The `--force` flag clears `indexed_at` so existing rows are re-embedded instead
of skipped. Add `--sync` to run inline if you don't have a queue worker.

## Driver matrix

| Driver | Default model | Dimensions | Notes |
|---|---|---|---|
| `voyage` | `voyage-3.5` | 1024 | Default. Cheap and high-quality for English. |
| `openai` | `text-embedding-3-small` | 1536 | Also: `text-embedding-3-large` (3072). The `dimensions` parameter truncates server-side. |
| `cohere` | `embed-english-v3.0` | 1024 | Also: `embed-multilingual-v3.0` (1024). Configurable `input_type` (default `search_document`). |
| `bedrock` | `amazon.titan-embed-text-v2:0` | 1024 | Configurable to 256 / 512 / 1024. Uses your default AWS credential chain. |
| `null` | — | 1024 | Zero vectors. For tests / disabling semantic search. |

## Voyage

This is the default driver. Cheap, fast, good English quality.

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=voyage
VOYAGE_API_KEY=...
VOYAGE_EMBEDDING_MODEL=voyage-3.5
VOYAGE_EMBEDDING_DIMENSIONS=1024
```

## OpenAI

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

**This parameter is supported only on `text-embedding-3-*` models.** If you set
`OPENAI_EMBEDDING_DIMENSIONS` while using a non-v3 model (e.g.
`text-embedding-ada-002`), the driver throws a configuration error before any
API call. Unset the variable to let the model use its native size.

## Cohere

```dotenv
COMMONPLACE_EMBEDDING_DRIVER=cohere
COHERE_API_KEY=...
COHERE_EMBEDDING_MODEL=embed-english-v3.0
COHERE_EMBEDDING_DIMENSIONS=1024
```

Cohere v3 distinguishes indexing from querying via `input_type`. The driver
uses two separate values and defaults to the recommended pair: `search_document`
when indexing notes and `search_query` when a user searches. Only override these
if you know what you're doing:

```dotenv
COHERE_EMBEDDING_INDEX_INPUT_TYPE=search_document
COHERE_EMBEDDING_QUERY_INPUT_TYPE=search_query
```

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

### Throughput tuning

**Bedrock has no batch-embeddings endpoint.** `embedBatch()` fans out concurrent
`InvokeModel` calls via `Aws\CommandPool` capped by
`BEDROCK_EMBEDDING_CONCURRENCY` (default `2`). Reindexes are still slower than
HTTP-batch providers like Voyage / OpenAI. Tune throughput with two knobs
together:

- `BEDROCK_EMBEDDING_CONCURRENCY` — peak in-flight `InvokeModel` calls per
  reindex batch.
- `COMMONPLACE_REINDEX_BATCH_DELAY` — pause (seconds) between batches.

Sustained RPM is roughly `concurrency * (60 / avg_latency_seconds)`. At Titan
v2's typical ~200ms latency, the default `concurrency=2` sustains ~600 RPM if
every batch is full.

Stay under your account's per-model Bedrock quota. Otherwise the SDK's
exponential backoff kicks in and the whole batch fails (`embedBatch` is
all-or-nothing today). A cold AWS account is often capped well below 100 RPM.
Start at the default and raise only after confirming headroom in CloudWatch.
