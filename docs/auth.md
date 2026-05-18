# Auth integration

How `laravel-commonplace` plugs into the auth guard you already have.

The package's routes ship behind the `web,auth` middleware stack by
default. A vanilla Breeze/Jetstream/Fortify install works out of the
box. If you're running Sanctum SPA, bearer tokens, or you want to
expose a read-only public view of selected notes, you can point the
routes at a different guard with one env var. The sections below walk
through each setup.

The single knob is `COMMONPLACE_ROUTES_MIDDLEWARE`. It's a
comma-separated list of middleware applied to the package's
authenticated routes.

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
on a different subdomain. Check Laravel's Sanctum docs for the details.

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
with `visibility != 'public'` return 404 instead of 403, so an attacker
can't enumerate the private vault by probing paths. Editing, listing,
and search aren't exposed via the public group. Those stay behind the
authenticated routes.

### Renaming the public segment

The public group is registered before the authenticated catch-all, so the
public segment **shadows any vault folder of the same name**. With the
default setup, a note at vault path `public/handbook` is reachable through
the public group (or 404s under it if `visibility != public`) instead of
through the authenticated `{prefix}/{path}` show route. The same applies if
you rename the segment — picking `share` shadows a folder called `share`.

Set `COMMONPLACE_PUBLIC_ROUTES_PREFIX` to a full path that doesn't collide
with any of your vault's top-level folders:

```dotenv
COMMONPLACE_PUBLIC_ROUTES_PREFIX=commonplace/share
```

The value overrides the entire public-group prefix (not just the trailing
segment), so you can also publish at a different root — e.g.
`COMMONPLACE_PUBLIC_ROUTES_PREFIX=p` to drop the `commonplace/` prefix from
public URLs. Rerun `php artisan route:cache` after changing.

Pair this with the [user model contract](user-model.md) for the
`getAuthIdentifier()` and `name` requirements on the authenticated
side.

---

## MCP

The MCP transport (`routes/mcp.php`) runs under its own middleware
stack, **not** the `COMMONPLACE_ROUTES_MIDDLEWARE` setting that gates
the HTTP routes. The MCP knob is `COMMONPLACE_MCP_MIDDLEWARE`.

### Default: `auth:sanctum`

```dotenv
COMMONPLACE_MCP_MIDDLEWARE=auth:sanctum
```

Most MCP clients today — Claude Desktop, Cursor, Zed, Pi, and remote
MCP bridges — send `Authorization: Bearer <token>` from a non-browser
context. Sanctum's guard accepts a personal access token in that
header and resolves `$request->user()` cleanly. A `web,auth` stack
would 419-CSRF the JSON-RPC POST, and a `Bearer`-token client would
302 to `/login` against the session guard.

The middleware is applied as a route **group**, so it covers every
route the MCP registrar adds. That includes POST plus the
`405 Allow: POST` GET and DELETE stubs, and any future route the
registrar grows (e.g. SSE GET). Chaining onto the returned POST `Route`
would leave the 405s unauthenticated.

Issue a Sanctum PAT to your user and configure the client:

```bash
claude mcp add commonplace --transport http https://your-app.test/mcp/commonplace --header "Authorization: Bearer <token>"
```

### Browser-resident MCP clients (SPA cookie auth)

If your MCP client runs in the browser and authenticates via Sanctum
session cookies, `auth:sanctum` on its own is **insufficient**.
Sanctum's stateful guard needs the `EnsureFrontendRequestsAreStateful`
middleware before `auth:sanctum` to switch from token resolution to
session resolution. Cookie auth also re-introduces CSRF requirements
on `POST`, so add `web` if you're routing through Laravel's session.

```dotenv
COMMONPLACE_MCP_MIDDLEWARE=web,auth:sanctum
```

This is the same shape as the SPA cookie config for the HTTP routes
above. Same guard, same caveat. Most MCP usage today is bearer-token
from a desktop client, which is why the default doesn't include `web`.

### Passport

If your app uses Passport, point the MCP guard at it:

```dotenv
COMMONPLACE_MCP_MIDDLEWARE=auth:api
```

Make sure the `api` guard is wired to Passport in `config/auth.php`.

### OAuth-DCR via `laravel/mcp`

`laravel/mcp`'s `Registrar::oauthRoutes()` registers the metadata
endpoints (`/.well-known/oauth-protected-resource`,
`/.well-known/oauth-authorization-server`) and the dynamic-client
registration endpoint. This package doesn't wire that path yet. If you
need OAuth-DCR, call `Mcp::oauthRoutes(...)` in your own service
provider's `boot()` and document the scopes you support.

### Doctor

`commonplace:doctor` validates the MCP middleware stack when MCP is
enabled:

- Fails if `commonplace.mcp.middleware` is empty (the transport would
  ship unauthenticated; tool-level `$request->user()` fail-closed is
  defense in depth, not the auth boundary).
- Fails if the stack references `auth:sanctum` but the
  `Laravel\Sanctum\Sanctum` class isn't loaded (you removed the
  default but didn't install Sanctum). Recommendation:
  `composer require laravel/sanctum`.
- Silent when MCP is disabled.
