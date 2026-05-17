# Scenarios — Public-read visitor

An unauthenticated reader who hits the optional public-read route group at `{prefix}/public/{path}`. Sees only notes with `visibility=public`. Everything else — listings, search, graph, editing, version history — stays behind the authenticated routes.

Assumptions:

- `COMMONPLACE_PUBLIC_ROUTES_ENABLED=true`, default middleware `web` (no auth).
- Alice owns `public/handbook` (`visibility=public`) and `projects/launch` (`visibility=private`).
- No session, no Sanctum token, no cookie.

---

## Happy path

### S-PUB-01 — `GET /commonplace/public/{path}` renders public notes

**Intent.** Public visitors get an HTML view of any `visibility=public` note, with the same Blade layout the authenticated views use.

> [!NOTE]
> Validation 2026-05-17: the response renders 200 with the note's content, but the surrounding chrome is **the authenticated owner's template** — Edit/Delete/Download buttons, a nav bar pointing at auth-gated routes, and a "View markdown" link that points at the wrong (auth-gated) URL. Tracked in [#68](https://github.com/non-convex-labs/laravel-commonplace/issues/68); see also [S-PUB-01b](#s-pub-01b--public-template-does-not-expose-authenticated-only-chrome) below for the precise template requirements.

**Preconditions.** `public/handbook` is public.

**Steps.**
1. `GET /commonplace/public/public/handbook`.

**Expected.**
- 200 OK.
- HTML response rendering the note's content via the package's CommonMark pipeline.
- No private-vault affordances (no edit link, no header search, no graph view) — those are behind the authenticated layout.

**Verify with.** `curl -i` and inspect status + rendered markup.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes), [auth.md → Public-read mode](../auth.md#public-read-mode).

---

### S-PUB-02 — `GET /commonplace/public/raw/{path}` returns plain text

**Intent.** Mirrors the authenticated `/raw/{path}` endpoint but without auth. Useful for syndication, search engines, scrape-friendly mirrors.

**Preconditions.** `public/handbook` is public.

**Steps.**
1. `GET /commonplace/public/raw/public/handbook`.

**Expected.**
- 200 OK with `Content-Type: text/plain; charset=utf-8`.
- The body is the raw markdown source (frontmatter included).

**Verify with.** `curl -I` to confirm headers, `curl` to inspect body.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes), `PublicNoteController`.

---

### S-PUB-01b — Public template does not expose authenticated-only chrome

**Intent.** The public-read view is for unauthenticated readers. Affordances that require login (Edit, Delete, Download, the top-nav links to Search / Graph / New / Notes-index) should not appear on a public page. The "View markdown" link must point at the public-raw URL, not the auth-gated one.

**Preconditions.** Public-read enabled. `public/handbook` is public.

**Steps.**
1. Unauthenticated `GET /commonplace/public/public/handbook`.
2. Parse the response HTML.

**Expected.**
- No `<a>` to `/commonplace/edit/*`.
- No `<button>` or `<form>` performing `DELETE` against the note.
- No `<a>` to `/commonplace/raw/*` (the auth-gated raw route).
- If a "View markdown" affordance is present, it points at `/commonplace/public/raw/{path}` (the public-raw route, which returns 200 without auth).
- No `<a>` to `/commonplace/search`, `/commonplace/graph`, `/commonplace/create`, or `/commonplace` (the authenticated index).
- The breadcrumb (if rendered) shows the note's actual path, not a broken stem.

**Verify with.** `curl` + assertion on absent hrefs; or Playwright `evaluate` over `document.querySelectorAll('a, button')` filtering by href / action.

**Source.** [#68](https://github.com/non-convex-labs/laravel-commonplace/issues/68) for the gap; [auth.md → Public-read mode](../auth.md#public-read-mode) for the documented surface contract.

---

## Privacy boundary

### S-PUB-03 — Private notes return 404, not 403

**Intent.** Returning 403 would confirm a note exists at that path. The package returns 404 to deny path enumeration.

**Preconditions.** `projects/launch` is private. No share row for an anonymous caller (impossible anyway — Share requires a user_id).

**Steps.**
1. `GET /commonplace/public/projects/launch`.
2. `GET /commonplace/public/never-existed`.

**Expected.** Both return 404. Same status, same shape, no information leak.

**Verify with.** `curl -i` both URLs.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes), [`PublicNoteController.php:62`](../src/Http/Controllers/PublicNoteController.php#L62), [auth.md → Public-read mode](../auth.md#public-read-mode).

---

### S-PUB-04 — Public-read does not expose listing, search, or graph

**Intent.** Read-by-known-path is intentionally the only operation. There is no way to enumerate which paths exist.

> [!NOTE]
> Validation 2026-05-17: `/{prefix}/public/` (trailing slash, empty path) currently falls through to the **authenticated catch-all** and redirects to `/login` (302), rather than 404ing under the public group. Related to [#61](https://github.com/non-convex-labs/laravel-commonplace/issues/61) — the public group doesn't match an empty `{path}`. Other endpoints in this scenario (`/public/search`, `/public/graph`) correctly return 404.

**Preconditions.** Public-read enabled.

**Steps.**
1. `GET /commonplace/public/` (root).
2. `GET /commonplace/public/search?q=anything`.
3. `GET /commonplace/public/graph`.
4. `GET /commonplace/public/api/graph`.

**Expected.** Each returns 404 (these routes simply aren't registered for the public group).

**Verify with.** `curl -i` each URL.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes).

---

### S-PUB-05 — Public-read does not expose write, edit, delete, move

**Intent.** Mutation paths are entirely absent from the public route group.

**Preconditions.** Public-read enabled.

**Steps.**
1. `POST /commonplace/public/`.
2. `PUT /commonplace/public/public/handbook`.
3. `DELETE /commonplace/public/public/handbook`.

**Expected.** Each returns 405 or 404 (depending on Laravel's method matcher behavior with the unregistered verbs).

**Verify with.** `curl -i` each.

**Source.** [http-api.md → All routes](../http-api.md#all-routes).

---

## Opt-in toggle

### S-PUB-06 — With `COMMONPLACE_PUBLIC_ROUTES_ENABLED=false`, the public group isn't registered

**Intent.** Public-read is opt-in. With the toggle off, all `/public/*` URLs return 404 from the framework.

**Preconditions.** `COMMONPLACE_PUBLIC_ROUTES_ENABLED=false`. `public/handbook` still has `visibility=public`.

**Steps.**
1. `GET /commonplace/public/public/handbook`.

**Expected.** 404.

**Verify with.** `curl -i`.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes), `config/commonplace.php`.

---

### S-PUB-07 — Custom public middleware applies to both public routes

**Intent.** `COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE` controls the stack on the public group. Useful for rate-limiting or geoblocking.

**Preconditions.** `COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE=web,throttle:30,1`.

**Steps.**
1. Hit `GET /commonplace/public/public/handbook` 31 times in quick succession.

**Expected.** The first 30 return 200; the 31st returns 429.

**Verify with.** Shell loop + `curl`.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes), [auth.md → Public-read mode](../auth.md#public-read-mode).

---

## Route precedence

### S-PUB-08 — `/public/*` does not collide with the authenticated catch-all

**Intent.** The public group is registered **before** the authenticated catch-all (`GET /{path}`), so a public URL doesn't get caught by the auth layer and redirected to login.

**Preconditions.** Public-read enabled. Auth guard set.

**Steps.**
1. Unauthenticated `GET /commonplace/public/public/handbook`.

**Expected.** 200 OK rendering the public note. **Not** a 302 to `/login`.

**Verify with.** `curl -i` without cookies; confirm no `Location: /login` header.

**Source.** [http-api.md → Public-read routes](../http-api.md#public-read-routes), [`routes/web.php:24-41`](../routes/web.php#L24-L41).

---

### S-PUB-09 — A note named `public/something-else` resolves under the auth group too

**Intent.** The literal first path segment `public` is overloaded: it's both the public-read route prefix segment **and** a legal folder name. When authenticated, the regular `GET /commonplace/public/something-else` should resolve through the auth catch-all (note view / folder fallback), not the public group.

**Preconditions.** Authenticated as Alice. Note exists at `public/handbook` (`visibility=private`, for this scenario, to highlight the precedence).

**Steps.**
1. As Alice, authenticated: `GET /commonplace/public/handbook`.

**Expected.** ... actually a known ambiguity. The public group's `/public/{path}` matches first; visiting `/commonplace/public/handbook` will hit `PublicNoteController` even when authenticated, and 404 if the note isn't `visibility=public`. The authenticated path to a note literally named `public/handbook` is `/commonplace/public/handbook` — same URL — so the public group precedence shadows the authenticated route for any folder named `public`.

> [!NOTE]
> This is a real edge worth verifying against running code. The docs imply route precedence is settled but don't call out that `public/` as a vault folder name conflicts with the public-read prefix. If your vault uses `public/` as a folder for `visibility=public` notes, the behavior is consistent (the public group handles it). If you use `public/` as a folder for *private* notes, those notes won't render under the authenticated routes via that URL.

**Verify with.** Try both — same URL, with and without auth — and confirm the 404 vs 200 outcomes.

**Source.** [http-api.md → All routes](../http-api.md#all-routes).
