# User model

Add the `HasCommonplaceNotes` trait to whichever model owns notes (usually `App\Models\User`).

```php
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;

class User extends Authenticatable
{
    use HasCommonplaceNotes;
}
```

The package reads ownership off this model when creating notes, scoping [semantic search](vector-storage.md), and stamping version history.

## Trait surface

The trait exposes three relations:

| Method | Returns |
|---|---|
| `notes()` | `HasMany<Note>` — all notes owned by the user |
| `recentNotes(int $limit = 10)` | `Collection<Note>` — owned notes ordered by `updated_at` desc |
| `noteVersions()` | `HasMany<NoteVersion>` — versions this user authored (`changed_by`) |

## What the package expects

The user model is configurable via `commonplace.user_model` (default `App\Models\User`):

```php
// config/commonplace.php
'user_model' => env('COMMONPLACE_USER_MODEL', 'App\\Models\\User'),
```

The package reads three things off whatever model you point it at:

- **`id`** — integer primary key. The FK columns `notes.user_id` and `note_versions.changed_by` are hardcoded.
- **`getAuthIdentifier()`** — supplied by Laravel's [`Authenticatable`](https://laravel.com/docs/contracts#method-authenticatable). Used to stamp ownership on notes and `changed_by` on each version.
- **`name`** — read by the MCP `history` tool to attribute versions. Optional; falls back to `null` if missing.

> [!WARNING]
> A non-`id` primary key won't work without forking. The FK column names are hardcoded in the migrations and queries.

## Implementing `CommonplaceUser`

Implement the contract when you want stricter typing in code that accepts a user:

```php
use NonConvexLabs\Commonplace\Contracts\CommonplaceUser;

class User extends Authenticatable implements CommonplaceUser
{
    use HasCommonplaceNotes;
}
```

The trait satisfies the contract structurally — adding `implements CommonplaceUser` is purely a type-hint affordance.

## Related

- [Authentication](auth.md) — how the package resolves the current user from a request
- [Embedding drivers](embedding-drivers.md) — per-user scope is applied before embeddings are queried
- [Vector storage](vector-storage.md) — `user_id` flows into the scoped search SQL
