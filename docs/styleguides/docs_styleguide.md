# Docs Style Guide

How docs in this repo are organized and written. Synthesized from a survey of Spatie (medialibrary, permission, backup), Filament, Livewire, laravel/docs, and earendil-works/pi.

## Structure

- **Flat `docs/`**, kebab-case filenames, no numeric prefixes (`embedding-drivers.md`, not `01-embedding-drivers.md`). Order belongs in a nav config, not filenames.
- One landing page: **`docs/index.md`** (or `_index.md` if a Jigsaw-style site generator is later adopted). Acts as both the GitHub-browsable entry point and the docs-site home.
- Subdirectories only when a topic grows past ~5 sub-pages. Promote a single file to `topic/_index.md` + `topic/{subpage}.md` rather than letting one file balloon past ~20 kB.
- `docs/images/` for screenshots and diagrams. Never scatter `.png`s next to `.md`s.
- `docs/styleguides/` for meta-docs about how to write docs.
- Auxiliary files (`CHANGELOG.md`, `UPGRADE.md`, `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE.md`, `.github/`) stay at the **repo root** so GitHub auto-detects them. If the docs site needs them, alias — don't move.

## Nav config

Once `docs/` crosses ~10 files, introduce a Mintlify-style `docs.json` (see `pi-docs.json` in this folder for a reference). Group pages into a small number of sections — six is a useful upper bound:

1. **Start here** — index, quickstart, install, core feature pages
2. **Customization** — extension points, theming, custom drivers
3. **Reference** — file formats, schemas, API contracts
4. **Programmatic Usage** — SDK, CLI, integration modes (if relevant)
5. **Platform Setup** — OS-specific notes, environment quirks
6. **Development** — local setup, project structure, debugging

Include a `redirects` block from day one — plan renames before doing them.

## Page anatomy

Three skeletons recur across high-quality projects. Pick one when starting a page.

### Landing page (`index.md`)

```
<one-sentence elevator pitch>
<2–4 code blocks showing the most common usages, one sentence each>
## Start here     -> bullet list, each item links to a page with a one-line hook
## Customization  -> ditto
## Reference      -> ditto
```

No prereqs, no requirements table, no architecture. The landing page exists to make the reader think "yes, this is what I want" within 15 seconds.

### Feature page

```
<one paragraph: what this feature is and when it runs>
[Optional: one-sentence link to an underlying library]
## Basic usage              -> minimal happy-path example
## <Orthogonal toggle 1>    -> one short section per option, one code block each
## <Orthogonal toggle 2>
## Advanced / escape hatch  -> for users who outgrow the defaults
```

Each `##` is independent — a reader should be able to skim to one section and use it without reading the rest. Open every section with one declarative sentence, then a code block.

### Reference / internals page

```
**Source files:** bullet list of GitHub links to relevant source
## Overview     -> 2–3 paragraphs of context
## <Mechanic>   -> for each non-trivial mechanic, prose + diagram (only when state transitions need one)
## <Data type>  -> for each contract, the TypeScript/PHP interface inline
```

Reference pages can be long (15–35 kB is fine). They serve repeat readers, not first-timers.

### Platform/setup page

```
<one paragraph: the constraint and how to resolve it>
<one config block or command>
[Optional: a "custom path" or "alternative" section]
```

Length: 350 bytes to 3 kB is normal. **Do not pad short topics.** A page can be five sentences if that's all the topic needs.

## Content rules

1. **Open every page with one declarative sentence, then a code block.** No "In this guide we will explore…" preamble.
2. **Use Laravel's nouns as Laravel uses them.** Link to laravel.com on first introduction; never re-teach Gates, policies, disks, migrations.
3. **Method signatures live inside examples**, not in a separate API list. The fluent chain *is* the API.
4. **For a feature with N config options, show 2–3 common ones and link to the published config** for the rest.
5. **Real-world domain nouns over `foo`/`bar`.** This package is a "commonplace book" — use notes, references, citations, vaults. Mixing `User` and `App\Foo` in the same page breaks comprehension.
6. **Defaults first, then the override.** "By default X happens. If you want Y, call `nonQueued()`."
7. **Rationale lives on a dedicated best-practices page**, not sprinkled through feature pages.
8. **Warnings in alert blocks** (`> [!NOTE]`, `> [!WARNING]`, `> [!TIP]`), not paragraphs.
9. **Tone: imperative, second-person, present tense.** "Add the trait." Not "users may wish to consider."
10. **Upgrade guides are bullet diffs by major version**, never narrative. Bonus: tag each change with "Likelihood Of Impact: High/Medium/Low".
11. **Testing helpers get their own page** under an `advanced-usage/` (or similar) section. Users hit testing pain second only to install pain.
12. **Cross-link liberally.** Every page should link to 3–5 others; `index.md` should link to every page in `docs/`.

## Useful patterns to steal

- **Comparison tables for near-synonyms.** When a feature has overlapping siblings (e.g. multiple embedding drivers, vector backends), a `| feature | A | B | C |` table beats three prose paragraphs.
- **"Source files" block at the top of internals pages** — bulleted GitHub links to the actual code. Don't bury source references in prose.
- **"Design Principles" or "What this package intentionally doesn't do" section** on the landing or usage page. Sets expectations; channels deep philosophy out of feature docs.
- **`> [Tool] can help you build this. Ask it.`** as a pull-quote on SDK/extension pages. Reflexive, specific to AI-tooling adjacent projects.
- **"Next steps" footer** on getting-started pages with 3–5 outbound links.
- **`redirects` block in the nav config** before the first rename, not after.

## Antipatterns

- **Duplicating quick-start in three places** (README + `installation.md` + `getting-started.md`). README is pitch + one-liner install + link. Real install lives in one canonical page.
- **No entry point in `docs/`** — without `index.md`, GitHub renders an alphabetical file list.
- **Numbered prefixes** (`01-introduction.md`). They rot the moment you insert a section. Use a nav config.
- **Mid-page TOC inconsistency.** Either every page has one or none do. Pi gets this wrong; don't copy that part.
- **One file past ~30 kB that isn't a reference/API page.** Promote to a subdirectory. Pi's 97 kB `extensions.md` is the cautionary example.
- **Inline "this changed in v10" annotations** scattered through current docs. Version notes belong in `UPGRADE.md` or `CHANGELOG.md`.
- **Free-form FAQ prose.** Use `troubleshooting.md` with `## <error message>` → `### Fix` → code. Users land via search.
- **Code blocks without a "when to use this" sentence above them.** Turns docs into a CLI dump.
- **`foo`/`bar` in end-to-end recipes.** Placeholders in basic-usage are tolerable; in walkthroughs they kill comprehension.
- **Mixing how and why in the same paragraph.** Separate pages, separate concerns.
- **Re-pasting the full config block on every page that touches it.** Show it once in install; excerpt subsections elsewhere.
- **Pages that are 90% caveats.** Anchor every warning to a concrete code example.
- **Hiding `CONTRIBUTING.md`/`SECURITY.md` under `docs/`** — loses GitHub's auto-detection.

## Page-size honesty

Page length should track topic depth, not editorial consistency. Reference distribution from earendil-works/pi:

| Size | When |
|---|---|
| <500 B | Single config snippet + 2 sentences. `shell-aliases.md`. |
| 1.5–3 kB | Platform notes, install variants |
| 3–8 kB | Standard feature pages |
| 10–17 kB | Major feature pages |
| 28–35 kB | Reference / API surface for integrators |
| >35 kB | Smell. Promote to a subdirectory. |

## What stays in README vs. moves to docs/

| In README | In `docs/` |
|---|---|
| Elevator pitch (1–3 sentences) | Full prose docs |
| Install one-liner (`composer require ...`) | Complete install + config walkthrough |
| Link to docs/index.md | Everything else |
| Badges (CI, version, license) | — |
| License + author + sponsor links | — |

Once you have `docs/`, do not duplicate install steps in README. Link out.

## References

- Survey of Spatie / Filament / Livewire / laravel-docs / Pi conventions: see commit history on this file
- Pi's `docs.json` as an example nav config: [`pi-docs.json`](pi-docs.json) in this folder
- Spatie permission's best-practices model: <https://github.com/spatie/laravel-permission/blob/main/docs/best-practices/roles-vs-permissions.md>
- Laravel framework upgrade-guide format: <https://github.com/laravel/docs/blob/13.x/upgrade.md>
- Pi as a flat-docs exemplar: <https://github.com/earendil-works/pi/tree/main/packages/coding-agent/docs>
