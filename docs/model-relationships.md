# Model relationships

The Eloquent models the package ships with, the tables behind them, and how they fit together.

**Source files:**

- [`src/Models/Note.php`](../src/Models/Note.php)
- [`src/Models/NoteVersion.php`](../src/Models/NoteVersion.php)
- [`src/Models/Link.php`](../src/Models/Link.php)
- [`src/Models/Share.php`](../src/Models/Share.php)
- [`src/Models/Tag.php`](../src/Models/Tag.php)
- [`src/Concerns/HasCommonplaceNotes.php`](../src/Concerns/HasCommonplaceNotes.php)
- [`database/migrations/`](../database/migrations/)

## Overview

`Note` is the aggregate root. Each note `belongsTo` an owner (your configured user model) and `hasMany` `NoteVersion` rows that snapshot prior `content`. A note `hasMany` outgoing `Link` rows for the wikilinks it emits, and `hasMany` incoming `Link` rows for the wikilinks pointing at it. Links carry both `target_path` (the literal `[[wikilink]]` text) and a nullable `target_note_id` that gets filled in when the target note exists. A note `hasMany` `Share` rows that grant a per-user permission to a private note, and `belongsToMany` `Tag` through the `commonplace_note_tag` pivot. The owner side of the graph lives on your user model via the [`HasCommonplaceNotes` trait](user-model.md).

## Note

- **Table:** `commonplace_notes` ([migration](../database/migrations/2026_03_08_000002_create_commonplace_notes_table.php))
- **Primary key:** `id` (auto-increment)
- **Route key:** `path` (`Note::getRouteKeyName()`, [`Note.php:93-96`](../src/Models/Note.php#L93))

### Columns

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | Surrogate key. |
| `path` | string, unique | Vault-relative path; doubles as route key. |
| `title` | string | Display title. On `createNote`, resolved from YAML frontmatter `title:` if present, else from the basename with dashes swapped for spaces and run through `Str::title()` (e.g. `journal/2026-05-16-notes` → `2026 05 16 Notes`). No H1 parsing happens. See [`createNote`](services.md#createnote). |
| `content` | longText | Raw markdown body. |
| `content_hash` | string | Stable hash of `content`; drives version dedup. |
| `visibility` | string, default `private` | `private` or `public`; consumed by `accessibleBy`. |
| `indexed_at` | timestamp, nullable | Set after the [embedding driver](embedding-drivers.md) stores a vector. |
| `user_id` | FK → user model | Owner; cascades on user delete. |
| `embedding` | longText, nullable | Driver-owned vector payload; hidden from arrays/JSON. |
| `embedding_dimensions` | unsigned int, indexed | Per-row dimensions sentinel for drift detection. |
| `timestamps` | — | `created_at` / `updated_at`. |

### Relationships

| Method | Returns | What |
|---|---|---|
| `owner()` | `BelongsTo` user model | Resolved via `config('commonplace.user_model')` ([`Note.php:98`](../src/Models/Note.php#L98)). |
| `versions()` | `HasMany<NoteVersion>` | Append-only history rows. |
| `tags()` | `BelongsToMany<Tag>` | Through `commonplace_note_tag`. |
| `outgoingLinks()` | `HasMany<Link>` | Links where this note is `source_note_id`. |
| `incomingLinks()` | `HasMany<Link>` | Links where this note is `target_note_id`. |
| `shares()` | `HasMany<Share>` | Per-user grants on a private note. |

### Scopes

| Scope | Purpose | Example |
|---|---|---|
| `accessibleBy(Authenticatable $user)` | Owned OR `public` OR shared with `$user`. | `Note::accessibleBy($user)->get()` |
| `inFolder(string $folder)` | `path LIKE 'folder/%'` with `%`/`_` escaped ([`Note.php:139-144`](../src/Models/Note.php#L139)). | `Note::inFolder('projects')` |
| `withTag(string $name)` | Has a tag with `name = $name`. | `Note::withTag('todo')` |
| `needsReindexing(int $cooldownMinutes = 60)` | `indexed_at IS NULL` and `updated_at` older than the cooldown. | `Note::needsReindexing(60)->get()` |

### Casts, accessors, mutators

- `indexed_at` casts to `datetime`. `visibility` casts to the [`Visibility`](../src/Enums/Visibility.php) backed enum with cases `Private` (`'private'`) and `Public` (`'public'`) ([`Note.php:42-47`](../src/Models/Note.php#L42)). Per-user grants on private notes happen via the [`Share`](#share) model. There's no third visibility value.
- `embedding` is a **read-only** accessor that delegates to the bound `VectorStorage`'s `parse()` ([`Note.php:70-91`](../src/Models/Note.php#L70)). The write path is `$storage->store($note->id, $vector)` — see [vector storage](vector-storage.md). The accessor swallows driver-resolution failures and returns `null` (logged once) so `toArray()` and queue payloads never blow up.
- `embedding` and `embedding_dimensions` are listed in `$hidden`, so they never leak into `toArray()` / `toJson()`. `embedding` is also not in `$fillable`.

```php
use NonConvexLabs\Commonplace\Models\Note;

$note = Note::where('path', 'projects/alpha.md')->firstOrFail();

$note->load(['versions', 'outgoingLinks.targetNote', 'tags']);

$note->tags()->attach(Tag::firstOrCreate(['name' => 'philosophy']));
```

## NoteVersion

- **Table:** `commonplace_note_versions` ([migration](../database/migrations/2026_03_08_000003_create_commonplace_note_versions_table.php))
- **Primary key:** `id`
- **Timestamps:** `created_at` only; `UPDATED_AT` is disabled ([`NoteVersion.php:16`](../src/Models/NoteVersion.php#L16)). Rows are immutable history.

A `NoteVersion` row represents **displaced** content — a state that was once live on the `Note` row but has since been overwritten by an update or removed by a delete. Versions are not snapshots of every state: `createNote` does not write one, since the live `Note` row already is the original content. `updateNote` writes a row when `content_hash` changes (capturing the prior content), and `deleteNote` writes one final row before removing the live note. To reconstruct the full timeline for a path, read the current `Note` content alongside `note->versions()` newest-first; for deleted notes the versions persist via `note_path` after `note_id` becomes `null`.

### Columns

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | Surrogate key. |
| `note_id` | FK → notes, nullable | `nullOnDelete` so history survives note deletion. |
| `note_path` | string | Path captured at write time (note path can change later). |
| `content` | longText | Snapshot of the note body. |
| `content_hash` | string | Same hash scheme as `Note.content_hash`. |
| `changed_by` | FK → user model, nullable | `nullOnDelete` so versions survive user deletion. |
| `created_at` | timestamp, nullable | When the snapshot was taken. |

### Relationships

| Method | Returns | What |
|---|---|---|
| `note()` | `BelongsTo<Note>` | Parent note; may be `null` after the note is deleted. |
| `author()` | `BelongsTo` user model | Whoever authored this revision; may be `null`. |

```php
$note->versions()
    ->latest('created_at')
    ->with('author')
    ->get();
```

## Link

- **Table:** `commonplace_links` ([migration](../database/migrations/2026_03_08_000006_create_commonplace_links_table.php), [index migration](../database/migrations/2026_05_16_000001_add_indexes_to_commonplace_links_table.php))
- **Primary key:** `id`

### Columns

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | Surrogate key. |
| `source_note_id` | FK → notes | The note that contains the wikilink. Cascade on delete; indexed. |
| `target_path` | string | The literal `[[wikilink target]]` text. |
| `target_note_id` | FK → notes, nullable | Resolved note when the target exists; `nullOnDelete`; indexed. |
| `timestamps` | — | `created_at` / `updated_at`. |

A `target_path` with a `null` `target_note_id` is the dangling-link case. The wikilink exists on disk but no note matches it yet.

### Relationships

| Method | Returns | What |
|---|---|---|
| `sourceNote()` | `BelongsTo<Note>` | The note the link was parsed from. |
| `targetNote()` | `BelongsTo<Note>` | The resolved destination, if any. |

```php
$dangling = Link::whereNull('target_note_id')
    ->with('sourceNote')
    ->get();
```

## Share

- **Table:** `commonplace_shares` ([migration](../database/migrations/2026_03_08_000007_create_commonplace_shares_table.php))
- **Primary key:** `id`
- **Timestamps:** `created_at` only ([`Share.php:17`](../src/Models/Share.php#L17)). A share is created or revoked, never edited.

### Columns

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | Surrogate key. |
| `note_id` | FK → notes | Cascade on delete. |
| `user_id` | FK → user model | The recipient; cascade on delete. |
| `permission` | string, default `read` | Currently only `read` is consumed by `accessibleBy`. |
| `created_at` | timestamp, nullable | When the share was granted. |

A unique index on `(note_id, user_id)` enforces one share row per (note, user) pair.

### Relationships

| Method | Returns | What |
|---|---|---|
| `note()` | `BelongsTo<Note>` | The shared note. |
| `user()` | `BelongsTo` user model | The recipient. |

```php
Share::firstOrCreate(
    ['note_id' => $note->id, 'user_id' => $teammate->id],
    ['permission' => 'read'],
);
```

## Tag

- **Table:** `commonplace_tags` ([migration](../database/migrations/2026_03_08_000004_create_commonplace_tags_table.php))
- **Pivot:** `commonplace_note_tag` ([migration](../database/migrations/2026_03_08_000005_create_commonplace_note_tag_table.php)) with unique `(note_id, tag_id)`; both FKs cascade on delete.
- **Primary key:** `id`

### Columns

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | Surrogate key. |
| `name` | string, unique | Tag name (no `#` prefix in storage). |
| `timestamps` | — | `created_at` / `updated_at`. |

### Relationships

| Method | Returns | What |
|---|---|---|
| `notes()` | `BelongsToMany<Note>` | All notes wearing this tag. |

```php
Tag::firstOrCreate(['name' => 'todo'])
    ->notes()
    ->orderByDesc('updated_at')
    ->get();
```

## Visibility model: how `accessibleBy` works

`Note::accessibleBy($user)` is the single source of truth for "which notes is this user allowed to read". It compiles to one `WHERE` group with three OR'd conditions ([`Note.php:128-137`](../src/Models/Note.php#L128)):

1. `user_id = $user->getAuthIdentifier()` — the user owns the note.
2. `visibility = 'public'` — anyone can read it.
3. `EXISTS` a `Share` row joining `note_id` to a row with `user_id = $user->getAuthIdentifier()` — the note is privately shared with this user.

```sql
WHERE (
    user_id = ?
    OR visibility = 'public'
    OR EXISTS (
        SELECT 1 FROM commonplace_shares
        WHERE commonplace_shares.note_id = commonplace_notes.id
          AND commonplace_shares.user_id = ?
    )
)
```

The scope reads the user id via `Authenticatable::getAuthIdentifier()`, so any model implementing the Laravel contract works. You don't need `HasCommonplaceNotes`. Pair this scope with `inFolder` or `withTag` to narrow further:

```php
Note::accessibleBy($user)->withTag('philosophy')->get();
```

> [!NOTE]
> `permission` on `Share` is captured but `accessibleBy` doesn't branch on it yet. Every share currently grants read access. Higher permissions are a future extension point.

## Related

- [User model](user-model.md) — the owner side: `HasCommonplaceNotes`, `notes()`, `recentNotes()`, `noteVersions()`.
- [Services](services.md) — the write path through `NoteWriter`, `LinkResolver`, and friends that produce these rows.
- [MCP tools](mcp-tools.md) — the tools that read and mutate notes, versions, links, shares, and tags over the MCP surface.
- [Vector storage](vector-storage.md) — how `embedding` and `embedding_dimensions` are populated and read.
- [Embedding drivers](embedding-drivers.md) — what writes `indexed_at`.
