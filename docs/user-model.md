# User model

How `laravel-commonplace` attaches to your user model, and what it expects
to find there.

The package needs a user model to own notes and stamp version history.
You wire it up by adding the `HasCommonplaceNotes` trait to whichever
model fills that role (usually `App\Models\User`). The page below covers
the trait surface area, the columns and methods the package reads, and
the optional contract you can implement for stricter typing.

---

## The `HasCommonplaceNotes` trait

Add the trait to your user model:

```php
use NonConvexLabs\Commonplace\Concerns\HasCommonplaceNotes;

class User extends Authenticatable
{
    use HasCommonplaceNotes;
}
```

It exposes three accessors:

| Method | Returns |
|---|---|
| `notes()` | `HasMany<Note>` — all notes owned by the user |
| `recentNotes(int $limit = 10)` | `Collection<Note>` — owned notes ordered by `updated_at` desc |
| `noteVersions()` | `HasMany<NoteVersion>` — versions this user authored (`changed_by`) |

---

## What the package expects

The user model is configurable via `commonplace.user_model` (default
`App\Models\User`). The package expects:

- **`id`** — integer primary key. The FK columns `notes.user_id` and
  `note_versions.changed_by` are hardcoded; a non-`id` primary key
  won't work without forking.
- **`getAuthIdentifier()`** — supplied by Laravel's `Authenticatable`.
  Used to stamp `changed_by` on each version.
- **`name`** — read by the MCP `history` tool when surfacing version
  attribution. Optional but recommended; appears as `null` if missing.
  (The package does not currently read `email`. If your User model
  exposes only `email`, attribution will fall back to `null` until the
  web UI port adds an email-based display path.)

---

## Implementing `CommonplaceUser`

For stricter typing, implement
`NonConvexLabs\Commonplace\Contracts\CommonplaceUser` — the trait
satisfies it structurally:

```php
use NonConvexLabs\Commonplace\Contracts\CommonplaceUser;

class User extends Authenticatable implements CommonplaceUser
{
    use HasCommonplaceNotes;
}
```
