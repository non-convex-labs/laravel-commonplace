# Releasing

This package follows semantic versioning. Releases are git tags on `main`;
Packagist picks them up automatically via the GitHub webhook.

## Pre-release checklist

1. **Default test suite is green.**

    ```bash
    composer test
    ```

2. **Static analysis is clean.**

    ```bash
    vendor/bin/phpstan analyse
    ```

3. **Live-API smoke test for embedding drivers.** See below — required for
   any release that touches `src/Drivers/Embedding/**`, `src/Contracts/EmbeddingProvider.php`,
   or any driver wiring in `CommonplaceServiceProvider`.

4. **CHANGELOG / release notes drafted.** Cover behavior changes, new config
   keys, and any required migrations or reindex steps.

## Live-API smoke test

The unit tests for every embedding driver use `Http::fake()` (Voyage, OpenAI,
Cohere) or `Aws\MockHandler` (Bedrock). Payload shape, authentication, and
response ordering are inferred from each provider's documentation — there is
no CI step that exercises a real API.

Before tagging a release, run the smoke test once per driver you have
credentials for. A failed smoke test blocks the release.

### Running the smoke test (CI — preferred)

GitHub Actions hosts the canonical smoke run. The `smoke-tests` workflow is
manual-dispatch only and runs in the `release-smoke` environment, which
requires reviewer approval and is restricted to the `main` branch and `v*`
tags.

1. Go to **Actions → smoke-tests → Run workflow**.
2. Pick the driver to exercise (`all`, or just one). Submit.
3. Approve the pending deployment when prompted.
4. Watch the run; a green `smoke` job means the drivers are healthy.

The workflow expects the following entries in the `release-smoke` environment
(Settings → Environments → release-smoke):

**Secrets** — one per driver you want to exercise; tests self-skip when the
matching key is absent.

| Name | Notes |
| --- | --- |
| `VOYAGE_API_KEY` | Voyage AI key |
| `OPENAI_API_KEY` | OpenAI key |
| `COHERE_API_KEY` | Cohere key |
| `AWS_ACCESS_KEY_ID` | Bedrock (or use OIDC; see below) |
| `AWS_SECRET_ACCESS_KEY` | Bedrock |

**Variables** — only set the ones you want to override; defaults live in
`config/commonplace.php`.

| Name | Default |
| --- | --- |
| `AWS_BEDROCK_REGION` | `us-east-1` |
| `VOYAGE_EMBEDDING_MODEL` | `voyage-3.5` |
| `VOYAGE_EMBEDDING_DIMENSIONS` | `1024` |
| `OPENAI_EMBEDDING_MODEL` | `text-embedding-3-small` |
| `OPENAI_EMBEDDING_DIMENSIONS` | `1536` |
| `COHERE_EMBEDDING_MODEL` | `embed-english-v3.0` |
| `COHERE_EMBEDDING_DIMENSIONS` | `1024` |
| `BEDROCK_EMBEDDING_MODEL` | `amazon.titan-embed-text-v2:0` |
| `BEDROCK_EMBEDDING_DIMENSIONS` | `1024` |

### Running the smoke test (locally)

Useful when iterating on a driver change before pushing. Same env vars, run
directly:

```bash
COMMONPLACE_SMOKE_TEST=1 \
VOYAGE_API_KEY=... \
OPENAI_API_KEY=... \
COHERE_API_KEY=... \
vendor/bin/phpunit --group=smoke
```

Each driver's smoke test self-skips unless its credentials are present, so
you can run them one at a time. To include Bedrock, set
`COMMONPLACE_SMOKE_TEST_BEDROCK=1` and make sure your default AWS credential
chain and `AWS_BEDROCK_REGION` are wired up.

### What "passing" means

For each configured driver the test calls `embed()`, `embedQuery()`, and
`embedBatch()` with small inputs and asserts:

- the response is an array of floats,
- its length equals `dimensions()`,
- the vector is not all zeros (which would indicate the Null driver leaked
  in or the provider returned a placeholder).

This catches the most likely "tests pass but the driver is broken" failure
modes: model deprecation, an auth header rename, a response-schema change,
or a dimension mismatch between config and the configured model.

## Tagging the release

```bash
git tag -s v0.x.y -m "v0.x.y"
git push origin v0.x.y
```

Packagist updates within a minute or two; verify the new version is listed
at https://packagist.org/packages/non-convex-labs/laravel-commonplace.
