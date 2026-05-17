# HTTP API

Reference for the routes and controllers `laravel-commonplace` registers.

**Source files:**

- [`routes/web.php`](../routes/web.php) — route definitions
- [`src/CommonplaceServiceProvider.php`](../src/CommonplaceServiceProvider.php) — route loading
- [`src/Http/Controllers/AssetController.php`](../src/Http/Controllers/AssetController.php)
- [`src/Http/Controllers/NoteController.php`](../src/Http/Controllers/NoteController.php)
- [`src/Http/Controllers/GraphController.php`](../src/Http/Controllers/GraphController.php)
- [`src/Http/Controllers/SearchController.php`](../src/Http/Controllers/SearchController.php)
- [`src/Http/Controllers/PublicNoteController.php`](../src/Http/Controllers/PublicNoteController.php)
- [`config/commonplace.php`](../config/commonplace.php) — route prefix / middleware config

## Overview

The package loads its routes from `routes/web.php` via Spatie's package
tools (`->hasRoute('web')` in [`CommonplaceServiceProvider`](../src/CommonplaceServiceProvider.php#L37)).
Three route groups are registered:

1. **Assets** — `web` middleware, no auth. Serves the bundled CSS/JS.
2. **Public-read** — opt-in, `web` middleware. Exposes notes with
   `visibility = 'public'` to unauthenticated visitors. See
   [auth.md](auth.md#public-read-mode).
3. **Authenticated vault** — `web,auth` middleware by default. Owns the
   browse / show / create / edit / search / graph surface.

The route loader is gated behind `commonplace.routes.enabled`; setting
`COMMONPLACE_ROUTES_ENABLED=false` makes the service provider return
before registering any of them ([`routes/web.php:12`](../routes/web.php#L12)).

> [!NOTE]
> The MCP transport routes (`routes/mcp.php`) are registered separately
> when `commonplace.mcp.enabled` is true. They're documented in
> [mcp-tools.md](mcp-tools.md), not here.

## Route prefix and middleware

Two env vars control where the routes mount and what guards them:

```dotenv
# URL prefix, default 'commonplace'
COMMONPLACE_ROUTES_PREFIX=commonplace

# Middleware stack on the authenticated group (comma-separated)
COMMONPLACE_ROUTES_MIDDLEWARE=web,auth
```

Both are read in [`config/commonplace.php`](../config/commonplace.php#L33-L44).
For Sanctum SPA, bearer tokens, or other guards see [auth.md](auth.md).

The public-read group has its own toggle and middleware list — see
[Public-read routes](#public-read-routes) below.

## All routes

Every named route is prefixed with `commonplace.` (or `commonplace.public.`
for the public-read group). `{path}` is a catch-all matching any depth
of slashes ([`->where('path', '.*')`](../routes/web.php#L59)).

| Method | Path                                  | Name                              | Auth   | Purpose                                  |
|--------|---------------------------------------|-----------------------------------|--------|------------------------------------------|
| GET    | `/assets/commonplace.css`             | `commonplace.asset.css`           | none   | Bundled stylesheet (see [theming](theming.md)) |
| GET    | `/assets/commonplace.js`              | `commonplace.asset.js`            | none   | Bundled JS                               |
| GET    | `/`                                   | `commonplace.index`               | auth   | Vault root (HTML)                        |
| GET    | `/create`                             | `commonplace.create`              | auth   | New-note form (HTML)                     |
| POST   | `/`                                   | `commonplace.store`               | auth   | Create note                              |
| GET    | `/graph`                              | `commonplace.graph`               | auth   | Graph view (HTML)                        |
| GET    | `/api/graph`                          | `commonplace.graph.api`           | auth   | Graph JSON                               |
| GET    | `/search`                             | `commonplace.search`              | auth   | Search results page (HTML)               |
| GET    | `/api/search`                         | `commonplace.search.api`          | auth   | Search JSON (for autocomplete)           |
| GET    | `/api/neighborhood/{path}`            | `commonplace.neighborhood`        | auth   | N-hop graph neighborhood (JSON)          |
| GET    | `/api/suggested-links/{path}`         | `commonplace.suggested-links`     | auth   | Suggested wikilink targets (JSON)        |
| GET    | `/raw/{path}`                         | `commonplace.showRaw`             | auth   | Note source with header (plain text)     |
| GET    | `/download/{path}`                    | `commonplace.downloadRaw`         | auth   | Same as showRaw, as a `.md` attachment   |
| GET    | `/edit/{path}`                        | `commonplace.edit`                | auth   | Edit form (HTML)                         |
| PUT    | `/{path}`                             | `commonplace.update`              | auth   | Update note                              |
| DELETE | `/{path}`                             | `commonplace.destroy`             | auth   | Delete note                              |
| GET    | `/{path}`                             | `commonplace.show`                | auth   | Note view (HTML, also handles `journal/*` and folder fallback) |
| GET    | `/public/{path}`                      | `commonplace.public.show`         | none   | Public-read HTML (when enabled)          |
| GET    | `/public/raw/{path}`                  | `commonplace.public.showRaw`      | none   | Public-read plain text (when enabled)    |

Counts: 2 asset routes, 15 authenticated routes, 2 public-read routes.
Source: [`routes/web.php`](../routes/web.php).

## AssetController

Serves the bundled CSS and JS from `resources/css/commonplace/` and
`resources/js/commonplace/` ([`AssetController.php:14,30`](../src/Http/Controllers/AssetController.php#L14)).
Both responses set `Cache-Control: public, max-age=3600`. A 404 is
returned if the source file is missing.

Consumers who want to override the CSS publish it via the
`commonplace-css` tag — see [theming.md](theming.md#publishing-the-css-source).

## NoteController

Owns the vault browse/show/edit surface. Most actions take a `{path}`
parameter — the note's slash-delimited path within the vault, e.g.
`projects/2026/launch-plan`. Authorization is enforced by the
`Note::accessibleBy()` scope; `ModelNotFoundException` returns 404,
`AuthorizationException` returns 403.

All read/edit actions render Blade views from `resources/views/`
(publishable via `commonplace-views` — see [theming.md](theming.md#publishing-blade-views)).

### `GET /{path}` fallback chain

[`NoteController::show()`](../src/Http/Controllers/NoteController.php#L41) handles three cases
in order:

1. If a note exists at `{path}` and the user can read it, render it.
2. If the note is missing and `{path}` is `journal` or `journal/*`,
   render the journal calendar for the requested `?year`/`?month`/`?date`.
3. Otherwise treat `{path}` as a folder and render the folder browser.

This is why the package only registers one catch-all `GET {path}`
route rather than separate `show`/`browse`/`journal` routes.

### `POST /` (store) — validation

From [`NoteController::store()`](../src/Http/Controllers/NoteController.php#L117-L124):

```php
$validated = $request->validate([
    'path'       => ['required', 'string'],
    'content'    => ['required', 'string'],
    'tags'       => ['sometimes', 'string'],         // comma-separated
    'visibility' => ['sometimes', 'string', 'in:private,shared,public'],
]);
```

`tags` is parsed by splitting on commas and trimming. Default
`visibility` is `private`.

### `PUT /{path}` (update) — validation

From [`NoteController::update()`](../src/Http/Controllers/NoteController.php#L158-L165):

```php
$validated = $request->validate([
    'content'    => ['sometimes', 'string'],
    'tags'       => ['sometimes', 'string'],
    'visibility' => ['sometimes', 'string', 'in:private,shared,public'],
    'new_path'   => ['sometimes', 'string'],          // rename
]);
```

Only fields that are present in the request are forwarded to
`Commonplace::updateNote()`. Pass `new_path` to rename the note (the
redirect follows to the new path).

## GraphController

Backs the graph view (HTML) and two JSON endpoints used by it.

### `GET /api/graph` response

Every note the user can access becomes a node; every link with both
ends inside that set becomes an edge ([`GraphController::graphApi()`](../src/Http/Controllers/GraphController.php#L29)).

```json
{
  "nodes": [
    {
      "id": "projects/launch",
      "title": "Launch plan",
      "folder": "projects",
      "tags": ["alpha", "q2"],
      "updated_at": "2026-05-17T12:00:00+00:00"
    }
  ],
  "edges": [
    { "source": "projects/launch", "target": "people/sam" }
  ]
}
```

### `GET /api/neighborhood/{path}` response

Returns notes within N hops of `{path}` (default 2, clamped to 1–5
via the `?hops=` query parameter, [`GraphController.php:64`](../src/Http/Controllers/GraphController.php#L64)).

```json
{
  "path": "projects/launch",
  "max_hops": 2,
  "neighbors": [ /* shape defined by Commonplace::getNeighborhood() */ ]
}
```

## SearchController

Owns full-text and semantic search.

### `GET /search` (HTML)

Query params:

- `q` — search string (trimmed).
- `semantic=1` — toggle semantic (vector) search; default is full-text.

See [embedding-drivers.md](embedding-drivers.md) and
[vector-storage.md](vector-storage.md) for what powers semantic mode.

### `GET /api/search` response

Used by the in-page search autocomplete. Returns `[]` when `q` is empty
or shorter than 2 characters ([`SearchController.php:46`](../src/Http/Controllers/SearchController.php#L46)):

```json
[
  {
    "path": "projects/launch",
    "title": "Launch plan",
    "excerpt": "Outline for the Q2 product launch...",
    "url": "https://example.test/commonplace/projects/launch",
    "updated_at": "2026-05-17T12:00:00+00:00",
    "tags": ["alpha", "q2"]
  }
]
```

Full-text only — `searchApi` does not honor a `semantic` flag.

### `GET /api/suggested-links/{path}` response

Suggests notes that could be wikilinked from `{path}`. `?limit` defaults
to 10 ([`SearchController.php:69`](../src/Http/Controllers/SearchController.php#L69)). The body is the array returned by
`Commonplace::getSuggestedLinks()` — see the service for the exact
shape.

## Public-read routes

Opt-in route group that exposes notes with `visibility = 'public'` to
unauthenticated visitors. Enable with:

```dotenv
COMMONPLACE_PUBLIC_ROUTES_ENABLED=true
# Optional, default is `web`:
COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE=web
```

See [auth.md → Public-read mode](auth.md#public-read-mode) for the
full setup. [`PublicNoteController`](../src/Http/Controllers/PublicNoteController.php)
registers `GET /public/{path}` (HTML) and `GET /public/raw/{path}`
(`text/plain`) under the configured prefix.

Notes with any other visibility (`private`, `shared`) return **404, not
403** ([`PublicNoteController.php:62`](../src/Http/Controllers/PublicNoteController.php#L62))
so unauthenticated visitors can't enumerate the private vault by probing
paths.

The public group is registered **before** the authenticated group in
[`routes/web.php`](../routes/web.php#L24-L41) so `/{prefix}/public/...`
matches `commonplace.public.*` rather than being caught by the
authenticated `{path}` catch-all and 302'd to login.

## Related

- [Auth integration](auth.md) — middleware stacks, Sanctum, public-read setup
- [MCP tools](mcp-tools.md) — the other integration surface, registered separately
- [Theming](theming.md) — how to publish and override views / CSS served via `AssetController`
- [User model contract](user-model.md) — what the authenticated user must implement
