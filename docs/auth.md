# Auth integration

How `laravel-commonplace` plugs into the auth guard you already have.

The package's routes ship behind the `web,auth` middleware stack by
default, so a vanilla Breeze/Jetstream/Fortify install works out of the
box. If you're running Sanctum SPA, bearer tokens, or want to expose
a read-only public view of selected notes, you can point the routes at
a different guard with one env var. The sections below walk through
each setup.

The single knob is `COMMONPLACE_ROUTES_MIDDLEWARE`, a comma-separated
list of middleware to apply to the package's authenticated routes.

---

## Session (default)

Nothing to configure. Sign in with `App\Http\Controllers\Auth\*` (Breeze,
Jetstream, Fortify) and hit `/commonplace`.

```dotenv
COMMONPLACE_ROUTES_MIDDLEWARE=web,auth
```

---

## Sanctum SPA (cookie)

Sanctum SPA flow uses cookie auth backed by stateful API requests. Add
`auth:sanctum` after `web` so requests with the Sanctum session cookie
authenticate cleanly:

```dotenv
COMMONPLACE_ROUTES_MIDDLEWARE=web,auth:sanctum
```

You'll also want `EnsureFrontendRequestsAreStateful` if your SPA lives
on a different subdomain. See Laravel's Sanctum docs for the details.

---

## Token-based (Sanctum personal access tokens / Passport)

For API consumers using bearer tokens:

```dotenv
COMMONPLACE_ROUTES_MIDDLEWARE=auth:api
```

Sanctum users can use `auth:sanctum` instead. Both Sanctum personal
access tokens and Passport map to the standard `auth:<guard>`
middleware, so the same pattern covers both.

---

## Public-read mode

You can expose only notes with `visibility = 'public'` to unauthenticated
visitors at `{prefix}/public/{path}`:

```dotenv
COMMONPLACE_PUBLIC_ROUTES_ENABLED=true
# Optional — default is `web` (no auth):
COMMONPLACE_PUBLIC_ROUTES_MIDDLEWARE=web
```

The public route group registers `GET {prefix}/public/{path}` (rendered
HTML) and `GET {prefix}/public/raw/{path}` (plain-text source). Notes
with `visibility != 'public'` 404 rather than 403, so an attacker can't
enumerate the private vault by probing paths. Editing, listing, and
search are not exposed via the public group; those stay behind the
authenticated routes.

Combine this with the [user model contract](user-model.md) for the
`getAuthIdentifier()` and `name` requirements on the authenticated
side.
