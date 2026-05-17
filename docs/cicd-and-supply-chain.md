# CI/CD & Supply Chain Security for Laravel Packages

I put this together as a working reference for GitHub Actions on a Laravel 13
package, and for keeping it safe from the 2024-2026 wave of supply-chain
attacks.

Scope: Laravel 13 package (PHP 8.3+), GitHub-hosted, Composer-distributed.

---

## 1. The Minimum Viable CI Surface

A serious Laravel package ships with **four workflows**. This is what
`spatie/package-skeleton-laravel` uses, and most well-maintained packages
follow the same shape:

| Workflow | Purpose | When it runs |
|---|---|---|
| `run-tests.yml` | Matrix test against PHP × Laravel × stability | push + PR |
| `phpstan.yml` | Static analysis | push + PR |
| `fix-php-code-style-issues.yml` | Auto-format with Pint, commit fix | push |
| `dependabot-auto-merge.yml` | Auto-merge safe Dependabot PRs | dependabot PRs |

You also need a `.github/dependabot.yml` config to actually generate the
update PRs.

That's the floor. Coverage uploads, release-drafter, security scanners, and
changelog automation are nice-to-haves on top.

---

## 2. Test Matrix Design

### The three matrix axes

For a Laravel package, you almost always want to sweep three dimensions:

- **PHP version** — every minor your `composer.json` says you support.
  For Laravel 13: `8.3`, `8.4`, `8.5`.
- **Laravel version** — every minor your package supports. Laravel 13.x is
  the typical floor. Add `12.*` if you want backward support.
- **Composer stability** — `prefer-lowest` and `prefer-stable`. This catches
  the classic "works on my machine, breaks on a clean install" bug where
  your code accidentally relies on a feature added in a later patch of a
  transitive dependency.

### Pair Laravel ↔ Testbench via `include`

Orchestra Testbench tracks Laravel one-for-one. You can't just sweep Laravel
versions independently. Each Laravel major needs the matching Testbench
major. Use `matrix.include` to bind them:

```yaml
matrix:
  laravel: [13.*, 12.*]
  include:
    - laravel: 13.*
      testbench: 11.*
    - laravel: 12.*
      testbench: 10.*
```

Then in your install step:

```yaml
composer require "laravel/framework:${{ matrix.laravel }}" \
                 "orchestra/testbench:${{ matrix.testbench }}" \
                 --no-interaction --no-update
composer update --${{ matrix.stability }} --prefer-dist --no-interaction
```

### OS matrix

Always run on `ubuntu-latest`. Add `windows-latest` if your package touches
the filesystem, paths, or processes. Spatie's skeleton runs both. I'd skip
macOS unless you have a specific reason; it doubles cost for little extra
signal.

### `fail-fast`

Set `fail-fast: true` so a single failure cancels the rest of the matrix
quickly. It's cheap. The only reason to flip it is when you specifically
want to see which combos fail together.

### `concurrency` cancellation

```yaml
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true
```

This cancels in-flight runs when you push a new commit to the same branch.
Saves CI minutes and stops you from waiting on results that no longer
matter.

---

## 3. The Reference `run-tests.yml`

Below is the current Spatie package skeleton's workflow. Treat it as a
known-good starting point. PHP 8.5 / Laravel 13 / Testbench 11.

```yaml
name: run-tests

on:
  push:
    paths:
      - '**.php'
      - '.github/workflows/run-tests.yml'
      - 'phpunit.xml.dist'
      - 'composer.json'
      - 'composer.lock'
  pull_request:
    paths:
      - '**.php'
      - '.github/workflows/run-tests.yml'
      - 'phpunit.xml.dist'
      - 'composer.json'
      - 'composer.lock'

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  test:
    runs-on: ${{ matrix.os }}
    timeout-minutes: 5
    strategy:
      fail-fast: true
      matrix:
        os: [ubuntu-latest, windows-latest]
        php: [8.5, 8.4, 8.3]
        laravel: [13.*, 12.*]
        stability: [prefer-lowest, prefer-stable]
        include:
          - laravel: 13.*
            testbench: 11.*
          - laravel: 12.*
            testbench: 10.*

    name: P${{ matrix.php }} - L${{ matrix.laravel }} - ${{ matrix.stability }} - ${{ matrix.os }}

    steps:
      - name: Checkout code
        uses: actions/checkout@v6

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv, imagick, fileinfo
          coverage: none

      - name: Setup problem matchers
        run: |
          echo "::add-matcher::${{ runner.tool_cache }}/php.json"
          echo "::add-matcher::${{ runner.tool_cache }}/phpunit.json"

      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
          composer update --${{ matrix.stability }} --prefer-dist --no-interaction

      - name: List Installed Dependencies
        run: composer show -D

      - name: Execute tests
        run: vendor/bin/pest --ci
```

### Things worth copying from this

- **Path filters on triggers** — the workflow only runs when something
  test-relevant changes. Saves CI minutes.
- **`timeout-minutes: 5`** on every job — a runaway job doesn't burn an
  hour of free-tier budget.
- **Problem matchers** — surfaces PHP/PHPUnit errors as GitHub annotations
  inline on the PR.
- **`composer show -D`** as an explicit step — when something breaks in the
  matrix, the logs already show the exact resolved versions.
- **`coverage: none`** when you don't need it — avoids installing Xdebug
  and slowing tests 5–10×.

### Pint (auto-format on push)

```yaml
name: Fix PHP code style issues
on:
  push:
    paths: ['**.php']
permissions:
  contents: write
jobs:
  php-code-styling:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@v6
        with:
          ref: ${{ github.head_ref }}
      - uses: aglipanci/laravel-pint-action@2.6
      - uses: stefanzweifel/git-auto-commit-action@v7
        with:
          commit_message: Fix styling
```

This one is "lazy" — it fixes style and force-pushes a commit. Two real
concerns:

1. **It writes back to the branch.** That's why `permissions: contents:
   write` is scoped to this single workflow, not the whole repo.
2. **It can race with humans pushing.** Acceptable on push to feature
   branches; do not run this on `main` after a merge.

If you'd rather **block** instead of auto-fix, swap to `pint --test` in the
test workflow and let CI fail.

### PHPStan

```yaml
name: PHPStan
on:
  push:
    paths: ['**.php', 'phpstan.neon.dist', '.github/workflows/phpstan.yml']
jobs:
  phpstan:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@v6
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: none
      - uses: ramsey/composer-install@v4
      - run: ./vendor/bin/phpstan --error-format=github
```

`--error-format=github` makes errors appear as inline PR annotations.

### Dependabot config + auto-merge

```yaml
# .github/dependabot.yml
version: 2
updates:
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule: { interval: "weekly" }
    labels: ["dependencies"]
    cooldown: { default-days: 1 }
  - package-ecosystem: "composer"
    directory: "/"
    schedule: { interval: "weekly" }
    labels: ["dependencies"]
    cooldown: { default-days: 1 }
```

Pay attention to **`cooldown: default-days: 1`**. That's a 24h delay between
a package release and Dependabot opening a PR for it. It's your first line
of defence against publish-and-snipe attacks (think Shai-Hulud, see §5).
Malicious versions usually get yanked within hours of detection. A one-day
cooldown lets the community catch the bad versions before you auto-merge
them.

```yaml
# .github/workflows/dependabot-auto-merge.yml
name: dependabot-auto-merge
on: pull_request_target
permissions:
  pull-requests: write
  contents: write
jobs:
  dependabot:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    if: ${{ github.actor == 'dependabot[bot]' }}
    steps:
      - id: metadata
        uses: dependabot/fetch-metadata@v3.1.0
        with: { github-token: "${{ secrets.GITHUB_TOKEN }}" }
      - if: steps.metadata.outputs.update-type == 'version-update:semver-minor'
        run: gh pr merge --auto --merge "$PR_URL"
        env: { PR_URL: ${{ github.event.pull_request.html_url }}, GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }} }
      - if: steps.metadata.outputs.update-type == 'version-update:semver-patch'
        run: gh pr merge --auto --merge "$PR_URL"
        env: { PR_URL: ${{ github.event.pull_request.html_url }}, GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }} }
```

This only auto-merges **minor and patch** updates. Never major bumps. And
only after `--auto` flag conditions are met (status checks passing, branch
up-to-date), so the test matrix has to go green first.

---

## 4. CI/CD Antipatterns

| Antipattern | Why it bites | Fix |
|---|---|---|
| Pinning third-party actions to a `@v3` tag | Tag can be force-pushed to malicious commit (this is exactly what tj-actions did, §5) | Pin to a full 40-char commit SHA, comment the human-readable version next to it |
| Granting `permissions:` at workflow root | Every job gets write tokens it doesn't need | Default to `permissions: contents: read` at the top; widen per-job only where needed |
| Using `pull_request_target` without `if:` actor check | Attacker PR runs with write tokens to your repo | Always gate with `if: github.actor == 'trusted-bot'` or never check out PR HEAD |
| Hard-coded secrets in YAML | Leaked on first log dump | Always `secrets.*`; mask custom values with `::add-mask::` |
| Echoing env vars containing secrets | Shows up in logs, defeats masking | Never `echo $SECRET`; never `set -x` in a step with secrets |
| `actions/checkout` with `persist-credentials: true` (default) on third-party-action workflows | Composite action can exfiltrate the GitHub token | Set `persist-credentials: false` unless the workflow itself pushes to git |
| No `timeout-minutes` on jobs | Stuck job burns billable minutes | Always set `timeout-minutes` (5–10 for tests, 30 for builds is plenty) |
| No `concurrency` group | New pushes don't cancel old runs; you wait on stale work | `concurrency: { group: ..., cancel-in-progress: true }` |
| Running CI on `*` triggers (every branch, every push) | Wasted budget | Path filters on `on:` + `pull_request` instead of `push` for feature branches |
| Caching `vendor/` across jobs | Hides "fresh install breaks" bugs; corrupted cache poisons every run | Cache the Composer global cache (`~/.composer/cache`), not `vendor/`; let `composer install` rebuild |
| Mutating release tags after publish | Consumers pinned to a tag silently get new code (this is how trivy-action got hit in 2026) | Never force-push tags. If you must republish, cut a new patch version |
| Auto-merging major-version Dependabot PRs | Major bumps are breaking changes by definition | Only auto-merge `semver-minor` and `semver-patch` |
| No Dependabot cooldown | You install brand-new compromised versions before anyone notices | `cooldown: default-days: 1` (or more) |
| Running tests against production secrets | Exfiltration risk + flaky tests | Use sandbox credentials; rotate if any test ever touches prod |
| Long-lived PATs as `secrets` | When (not if) they leak, they're persistent | Prefer GitHub App tokens with short TTLs or OIDC federation to cloud providers |
| Self-hosted runners on public repos | Forks can run arbitrary code on your hardware | Use GitHub-hosted runners for public repos, or self-hosted with strict ACL |
| Workflows that publish releases on every tag push without manual approval | A compromised maintainer account auto-publishes | Require environment protection rule with manual approval for `release` env |

---

## 5. Supply Chain Attacks 2024–2026 — What They Teach Us

Five incidents that should shape your CI/CD posture. None are hypothetical.
All are post-incident reports.

### XZ Utils backdoor — CVE-2024-3094 (March 2024)

A maintainer identity ("Jia Tan") spent **2+ years** building trust on the
`xz-utils` project and eventually became co-maintainer. They shipped a
backdoor in v5.6.0 via a malicious M4 macro in the build system that
injected obfuscated object code into `liblzma`. Andres Freund discovered
it by noticing a 500ms benchmark regression on SSH login. Pure luck.

**Lessons:**
- SBOMs, SLSA, and Sigstore would all have shown "Jia Tan signed it"
  cleanly. They don't solve **trust** problems, only **artifact** problems.
- Maintainer burnout is a security risk. A lone overworked maintainer is
  a target for a "helpful" newcomer offering to share the load.
- The build system is part of your attack surface, not just the source. The
  XZ payload was hidden in tarballs and never visible in the Git tree.
- **For your package:** don't ship build steps that pull from anywhere
  except Packagist; pin every build tool; never let a new maintainer
  push to `main` without review for the first N PRs.

### Polyfill.io (June 2024)

A widely-used CDN-hosted JS shim (`cdn.polyfill.io`) on ~100,000 sites was
sold in Feb 2024 to a Chinese company called Funnull. By June they were
serving malware to mobile users. Cloudflare and Fastly auto-rewrote
requests to safe mirrors. That's the only reason damage was limited.

**Lessons:**
- A `<script src="">` to a third-party domain is a **permanent trust
  delegation**. Whoever owns that domain owns your users.
- **For your package:** if you ship frontend assets (Blade includes JS/CSS),
  serve from the host app's own origin or via SRI hashes. Never `<script
  src="https://some-cdn/..."> without `integrity="sha384-..."`.

**Lessons (transfer directly to Composer/Packagist):**
- Package lifecycle scripts (`postinstall`, `preinstall`, Composer's
  `post-install-cmd`) run with full user privileges. They are the
  primary attack vector. **Composer offers `--no-scripts`** — use it in
  CI for production-style installs unless you specifically need scripts.
- Long-lived tokens stored on disk (`~/.npmrc`, `auth.json`) are gold to
  worms. Use **OIDC federation** or **short-TTL tokens** wherever your CI
  provider supports it.
- Worms are fast. Manual review can't keep up with thousand-package
  cascades. **Cooldown periods on Dependabot are not optional in 2026.**
- If you're a package maintainer, **enable 2FA** on Packagist and
  GitHub; rotate any token that touched a compromised dev machine.

### Trivy-action force-push (March 2026)

Attackers compromised **75 of 76** version tags on `trivy-action` via
force-push and exfiltrated secrets from every pipeline that ran a Trivy
scan. The stolen credentials cascaded into PyPI compromises, including a
backdoor in `LiteLLM`.

**Lessons:**
- Same SHA-pinning lesson as `tj-actions`, twelve months later. The
  industry is *still* not pinning to SHAs.
- Security scanners are a privileged target precisely because they run
  on everyone's CI with broad read access.
- **For your package:** SHA-pin even the "trusted" actions. `actions/*`
  and `github/*` are arguably safer (GitHub owns the org), but pinning
  costs nothing and protects against future credential compromise.

---

## 6. Composer / Packagist Specifics

PHP has its own supply-chain story, and it's been hardening steadily over
2024-2025:

### `composer audit`
- Run it as a CI gate. It checks installed packages against the Packagist
  Security Advisory API.
- Add a job:
  ```yaml
  - run: composer audit --no-dev
  ```
- Use `--abandoned=report` (vs. fail) if you don't want abandoned-package
  warnings to block CI.

### Composer 2.9 automatic blocking (Nov 2025)
- Composer 2.9 **automatically blocks updates to packages with known
  security advisories by default**.
- Make sure CI uses Composer 2.9+. Older versions will happily install
  vulnerable packages.

### Packagist Transparency Log (in progress)
- Funded by the Sovereign Tech Agency. It'll make security-relevant events
  on Packagist publicly auditable (similar to Certificate Transparency).
- Not actionable today, but worth tracking. It'll be the first
  cryptographically auditable supply-chain log in the PHP ecosystem.

### `composer install --no-scripts` in CI
- Mirror the npm `--ignore-scripts` recommendation. Most package
  `post-install-cmd` hooks aren't needed in CI test runs, and opting out
  shrinks your attack surface.
- Only re-enable for steps that genuinely need them (e.g. building
  Filament assets, publishing migrations).

### `composer.lock` discipline
- For an **application**: commit `composer.lock`, install with
  `composer install` (not `update`) in CI/prod. Reproducible builds.
- For a **package**: do **not** commit `composer.lock`. You want CI to
  resolve afresh against your declared constraints. That's exactly what
  the matrix `composer update --prefer-lowest/--prefer-stable` step
  tests.

### Signature / provenance (the gap)
- Composer doesn't yet have npm-style package signatures. The Packagist
  Transparency Log is the planned mitigation.
- For now: trust + Dependabot cooldown + `composer audit` is the best
  combination available.

---

## 7. Hardening Checklist (for any Laravel package)

Workflow hygiene:
- [ ] Every `uses:` pinned to a **40-char SHA**, with version comment
- [ ] `permissions:` block at workflow root, defaulting to `contents: read`
- [ ] Per-job `permissions:` widening only where needed (and minimal)
- [ ] `timeout-minutes` on every job
- [ ] `concurrency` group with `cancel-in-progress: true`
- [ ] Path filters on `on:` triggers
- [ ] No `pull_request_target` unless absolutely required, and gated on
      `if: github.actor == ...`
- [ ] `persist-credentials: false` on `actions/checkout` unless the
      workflow pushes to git
- [ ] Self-hosted runners not exposed to public-fork CI

Dependency hygiene:
- [ ] Dependabot enabled for **both** `composer` and `github-actions`
- [ ] `cooldown: default-days: 1` (or higher) on every Dependabot ecosystem
- [ ] Auto-merge limited to `semver-minor` and `semver-patch`
- [ ] `composer audit` runs in CI and fails the build on advisory hits
- [ ] CI uses Composer 2.9+ (so auto-block is active)
- [ ] No long-lived PATs in repository secrets; prefer GitHub App tokens
      or OIDC

Release hygiene:
- [ ] Releases are tagged and never force-pushed
- [ ] Release publishing workflow uses a protected environment with
      manual approval
- [ ] 2FA enabled on Packagist + GitHub for every maintainer
- [ ] CODEOWNERS file gating sensitive paths (`.github/`, `composer.json`,
      service providers)

Frontend asset hygiene (if applicable):
- [ ] No `<script src="">` to third-party CDNs without SRI
- [ ] npm/yarn dependencies (if any) covered by Dependabot
- [ ] `npm ci --ignore-scripts` in CI

Incident readiness:
- [ ] You can answer "which versions of which actions did this workflow run
      on 2026-03-14?" — i.e. SHA pins make incident audit possible
- [ ] You know how to rotate a compromised Packagist token in < 10 minutes
- [ ] You know how to yank a malicious release from Packagist
