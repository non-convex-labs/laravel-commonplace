# Backup

Pluggable destinations, one bundle, fan-out delivery.

Commonplace ships a backup job that snapshots your vault and pushes it to one
or more destinations. The destinations are pluggable behind a single contract,
so you can ship to GitHub, a Laravel filesystem disk, or your own custom
target (S3, GCS, whatever) by listing it in an env var. Each run builds one
`BackupBundle` and delivers it to every destination in order.

## Configuration

Configure one or more destinations via `COMMONPLACE_BACKUP_DESTINATIONS`
(comma-separated):

```dotenv
# Single GitHub destination
COMMONPLACE_BACKUP_DESTINATIONS=github
COMMONPLACE_GITHUB_BACKUP_REPO=your-org/your-vault
COMMONPLACE_GITHUB_BACKUP_TOKEN=ghp_...

# Or fan-out to GitHub + a filesystem disk in one job
COMMONPLACE_BACKUP_DESTINATIONS=github,filesystem.local-backup
COMMONPLACE_BACKUP_FS_LOCAL_DISK=s3-prod
COMMONPLACE_BACKUP_FS_LOCAL_PATH=vault-backups
```

Schedule `\NonConvexLabs\Commonplace\Jobs\BackupVault::dispatch()` from your
app's scheduler. The job builds a single `BackupBundle` and pushes it
sequentially to every destination. If one fails, subsequent destinations are
skipped and the job retries (5 tries, 30s/120s/300s backoff).

## Bundle format (schema v1.0)

Each destination receives the same payload:

- One markdown file per note at the note's `path` (`.md` appended if missing).
- A `manifest.json` at the bundle root:

```json
{
  "version": "1.0",
  "generated_at": "2026-05-17T08:21:14+00:00",
  "note_count": 42,
  "notes": [
    {"path": "notes/foo.md", "title": "Foo", "checksum": "sha256:..."},
    ...
  ]
}
```

## Custom destinations

Implement `NonConvexLabs\Commonplace\Contracts\BackupDestination` and bind it
in your service provider:

```php
use NonConvexLabs\Commonplace\Backup\BackupBundle;
use NonConvexLabs\Commonplace\Contracts\BackupDestination;

class GcsBackupDestination implements BackupDestination
{
    public function push(BackupBundle $bundle): void
    {
        // Use $bundle->files() (one .md per note) and
        // $bundle->manifestJson() to write to your target.
    }
}

// In a service provider's register():
$this->app->bind('gcs-snapshot', GcsBackupDestination::class);
```

Then list it in `COMMONPLACE_BACKUP_DESTINATIONS=github,gcs-snapshot`.

## Legacy job

The legacy `BackupToGitHub` job is preserved for back-compat. It dispatches the
GitHub destination directly without consulting the `destinations` list. Prefer
`BackupVault` for new code.
