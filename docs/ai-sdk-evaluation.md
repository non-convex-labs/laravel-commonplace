# Laravel AI SDK — migration evaluation

**Status:** Decision recorded 2026-05-17. Closes #34.
**Decision:** **Stay.** Keep the in-repo `EmbeddingProvider` contract +
per-vendor driver pattern. Revisit when (and if) a first-party
Laravel AI SDK ships an embeddings API that we can adapt without
losing per-driver knobs.

## Context

`docs/laravel-style-and-best-practices.md` flags:

> Before rolling your own EmbeddingProvider contract + driver pattern,
> check whether the AI SDK already covers the use case.

In #29 we extended the in-repo contract rather than migrating to a
first-party SDK, because the migration would be a breaking config
change for every Voyage user already in production. This document is
the deliberate check the style guide asked for.

## What we looked at

- `vendor/laravel/framework/src/Illuminate/*` — no `AI`, `Embeddings`,
  or `VectorStore` module in the bundled framework at the time of
  this evaluation. The closest adjacency is `Illuminate/JsonSchema`,
  which is request/response schema validation — not AI.
- `composer require laravel/ai` / `prism-php/prism` — neither is in
  `composer.json` or in the vendor tree. Both are third-party today;
  there is no first-party Anthropic / OpenAI client packaged with
  Laravel 13 here.
- The existing in-repo drivers (`Voyage`, `OpenAI`, `Cohere`,
  `Bedrock`, `Null`) each rely on per-vendor knobs that any
  abstraction would have to carry through:
    - Voyage: `input_type`, `output_dimension`.
    - OpenAI v3: `dimensions` server-side truncation (validated in #32).
    - Cohere v3: `input_type` split between indexing vs querying.
    - Bedrock: AWS credential chain, region, `normalize` flag,
      `Aws\CommandPool` concurrency (#31).
  These aren't surface-level options — they materially affect retrieval
  quality and storage column sizing.

## Adapter-pattern prototype (not landed)

A `LaravelAiSdkEmbeddingProvider` that adapts a Laravel AI SDK driver
to our `EmbeddingProvider` contract would look like:

```php
final class LaravelAiSdkEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly \Illuminate\AI\EmbeddingsContract $sdk,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public function embed(string $text): array
    {
        return $this->sdk->driver($this->driverName())->embed($model: $this->model, input: $text);
    }
    // ...
}
```

We did **not** land this because the `Illuminate\AI\EmbeddingsContract`
namespace does not exist in the installed Laravel 13 vendor tree (see
"What we looked at"). The shape is sketched here for the next time
this is reviewed.

## What we'd lose if we migrated to a generic SDK shape today

| Driver | Knob today | Generic SDK risk |
|---|---|---|
| Voyage | `input_type` (query vs document) | Likely flattened; retrieval quality drop measurable. |
| OpenAI | `dimensions` truncation + v3-only allowlist (#32) | SDK might forward `dimensions` blindly to ada-002 → API error. |
| Cohere | Separate `index_input_type` / `query_input_type` defaults | SDK likely exposes one `input_type` — single-value loses the query/index split. |
| Bedrock | `Aws\CommandPool` concurrency, `normalize`, native AWS credential chain | SDK likely wraps the AWS SDK and hides `CommandPool`; concurrency knob lost. |
| Storage | `dimensions()` self-report drives column sizing (`pgvector`) | If the SDK doesn't report column-sized dimensions per driver, schema drift produces silent vector corruption (the bug class #32 specifically prevents). |

## Vector-store helpers

Laravel 13 ships `Illuminate\Database` query builders that include
`pgvector` operators (we use this in `PgvectorDriver`). That part is
**already** first-party and we already use it. The piece we hand-roll
(`Drivers\Vector\InPhpCosineDriver`, `Drivers\Vector\PgvectorDriver`,
`Drivers\Vector\NullDriver`) is just a `VectorStorage`/`VectorSearch`
contract on top — a swappable backend selection, not an embeddings
client. No migration value there.

## Decision

**Stay.** Keep the in-repo contract. Maintain incrementally.

Triggers that would flip this:

1. A first-party Laravel AI / Embeddings module ships that exposes
   per-driver knobs we currently use (notably Cohere's input_type
   split and OpenAI v3-only dimensions). Re-run this evaluation.
2. We discover that our maintenance cost across the 5 drivers
   exceeds the cost of carrying a per-driver-knob shim on top of an
   SDK — i.e. when we add a 6th or 7th provider.
3. A specific feature we want (batched async, fakes for tests,
   observability) becomes meaningfully easier under an SDK than to
   add to the in-repo contract.

## Migration shape (for the next reviewer)

If a future evaluation flips the decision to **migrate**:

- `config/commonplace.php`: replace `embedding.driver` with a pointer
  into the SDK config (e.g. `embedding.sdk_driver` → SDK's own
  driver map). Per-vendor blocks (`embedding.voyage`,
  `embedding.openai`, ...) stay; they're either passed through to the
  SDK as driver-specific config or get aliased.
- Upgrade path for existing users: keep both code paths gated behind a
  feature flag for one minor version (`embedding.use_sdk = false` by
  default). Validation of equivalence: a doctor command that compares
  the in-repo provider's `dimensions()` against the SDK's reported
  dimensions for the same model — must match exactly to avoid silent
  pgvector column drift.
- Tests: keep the existing per-driver test files; their assertions
  about request shape (POST body, dimensions parameter) translate to
  SDK assertions if the SDK has its own fake (otherwise the tests
  themselves migrate to the SDK's fake API).

## Action items

- [x] Decision recorded.
- [ ] Add a calendar reminder to re-evaluate when Laravel 14 ships, or
      when we add a 6th embedding provider — whichever comes first.
- [ ] Link this doc from `docs/laravel-style-and-best-practices.md`
      as the answer to the "did you check the AI SDK?" prompt.
