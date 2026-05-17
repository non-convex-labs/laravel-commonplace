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

**Variables** — none required. The CI workflow uses the package defaults
from `config/commonplace.php` (e.g. `text-embedding-3-small` for OpenAI,
`us-east-1` for Bedrock). To override a model or dimension in CI, edit
`.github/workflows/smoke-tests.yml` directly; passing empty strings through
`vars.X` is unsafe because Laravel's `env()` returns the empty value rather
than the default.

### Running the smoke test (locally)

Useful when iterating on a driver change before pushing. Copy the template,
fill in the keys for the driver(s) you care about, source it, and run
phpunit:

```bash
cp .env.smoke.example .env.smoke
$EDITOR .env.smoke
set -a && source .env.smoke && set +a
vendor/bin/phpunit --group=smoke
```

`.env.smoke` is gitignored. Each driver's smoke test self-skips unless its
credentials are present, so you can run them one at a time. To include
Bedrock, set `COMMONPLACE_SMOKE_TEST_BEDROCK=1` in the env file and make
sure your default AWS credential chain and `AWS_BEDROCK_REGION` are wired
up.

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
