# OSPOS Database Backup & Recovery Runbook

Operational guide for the local verified backup system built into `php spark db:backup`.

> **Read this first.** This document separates two things that must never be
> confused: **backup verification** (automated, safe, runs every day) and
> **actual disaster recovery** (manual, careful, rarely needed). The `db:backup`
> command **verifies** backups. It is **not** a restore command and can never
> drop or overwrite the production database. Restoring after a disaster is a
> deliberate operator procedure, described in [Disaster recovery](#3-disaster-recovery).

---

## 1. What the backup system does

`php spark db:backup` creates a verified SQL dump of the configured default
database and never touches the database it dumps from beyond reading it.

The dump is written to `writable/backups/` (or `backup.backupDir`). Output is
first written to a `<name>.part` file and only renamed to its final name after
the dump exits 0 with non-empty output — an interrupted dump can never look
like a completed backup. The database password is handed to the dump/restore
tools through a temporary option file and **never appears on the process
command line**.

### Verification stages

| Marker | Meaning |
| --- | --- |
| `DUMP VERIFIED` | The SQL file exists, is non-empty and its SHA-256 was recorded. **Does not** prove restorability. |
| `RESTORE VERIFIED` | The dump was loaded into a freshly created scratch database (`ospos_backup_verify_<timestamp>_<random>`), sanity-checked, and the scratch database was dropped. Only ever on a scratch database — never the production one. |
| `REPLICA VERIFIED` | The verified backup was copied to `backup.replicaDir` and the copy re-checked (size + SHA-256 match the source). |
| `RETENTION APPLIED` | Backups older than `backup.retentionCount` were pruned (local and replica, independently). |
| `RETENTION DRY-RUN` | `--prune --dry-run` preview: nothing was deleted. |

Exit codes: `0` every requested stage succeeded; `1` a stage failed (the output
names it: `Backup failed`, `Restore verification failed`, `Replication
failed`, `Retention failed`, `Backup lock unavailable`); `7` the command was
given arguments it does not accept.

### Recommended daily invocation

```sh
php spark db:backup --verify-restore --replicate --prune
```

This produces a dump, proves it can be restored (scratch restore), copies it
off-device, and prunes old backups. Any failure exits non-zero.

### Safety properties

- **Verification cannot restore into / drop / modify the production database.**
  The only write target of the verify stage is a generated scratch database on
  the same server; scratch names always match `ospos_backup_verify_*` and are
  dropped after the run.
- **Replication is copy-only.** A failed replica never deletes or alters the
  local backup; a different existing replica is never overwritten (the command
  fails instead); the copy is verified before and after the atomic rename.
- **Retention cannot delete arbitrary files.** Only files matching the backup
  naming rule (`<database>_YYYY-MM-DD_HHMMSS_<hex>.sql`) inside exactly the
  configured backup/replica directories are ever removed — never
  subdirectories, symlinks, `.env`, or `.part` files. The newest valid backup
  is always kept, and local/replica retention are independent.
- **Concurrent runs are excluded.** A second `db:backup` while one is running
  fails fast (bounded wait) instead of racing dump/replica `.part` files or
  retention. The lock is released automatically if the process dies.

## 2. Configuration (`.env`)

```ini
backup.backupDir           = 'C:\Backups\ospos'         # default: writable/backups
backup.dumpExecutable      = 'C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe'
backup.clientExecutable    = 'C:\Program Files\MariaDB 10.6\bin\mariadb.exe'
backup.replicaDir          = 'D:\Backups\ospos'         # off-device copy target
backup.retentionCount      = 5                          # keep N newest (0 = off)
```

- `backup.dumpExecutable` / `backup.clientExecutable`: empty means search PATH
  for `mariadb-dump`/`mysqldump` and `mariadb`/`mysql`. On Windows the MariaDB
  binaries usually live outside PATH under `C:\Program Files\MariaDB <n.n>\bin\`
  — set the absolute paths there (paths with spaces are fine).
- `backup.replicaDir`: another physical device, a UNC network share
  (`\\fileserver\ospos-backups`) or a synced external location. **Credentials
  for a share are never stored in the config** — the OS account running the
  backup must already have access.
- `backup.retentionCount` : applied independently to local and replica
  directories. Test with `--prune --dry-run` first.

## 3. Disaster recovery

**Backup verification (`--verify-restore`) proves a dump can be restored into a
scratch database. It is not a recovery procedure.** Never point a restore at
the production database without the full operator checklist below. There is no
automated "restore" command — that is deliberate, so a mistyped command or a
compromised script cannot destroy a live database.

### 3.1 Stop application writes

- Put the point of sale into maintenance/kiosk-only mode, or take it offline.
  No receipts, receiving, inventory or customer changes while restoring.
- Keep the application **stopped** until cutover is complete.

### 3.2 Locate the latest suitable verified backup

Prefer, in order:

1. A **RESTORE VERIFIED + REPLICA VERIFIED** backup (daily recommended run).
2. A **REPLICA VERIFIED** backup (off-device copy, re-checked by size+SHA-256).
3. Any **DUMP VERIFIED** backup as a last resort.

Pick the newest one that passed the verification level you need. Choose the
replica copy when the local disk is damaged.

### 3.3 Independently verify the chosen file's checksum

```sh
certutil -hashfile "C:\Backups\ospos\ospos_2026-10-12_021000_3f2a.sql" SHA256
# on Linux/macOS: sha256sum <file>
```

Compare it against the SHA-256 printed at backup time (the command prints it
for the local backup and the replica). If they differ, **do not use the file** —
pick another backup or go back to the latest known-good dump.

### 3.4 Provision an empty recovery/staging database

Create a fresh, empty database on the (recovery) server, e.g.
`ospos_recovery_20261012`. The dump contains no `CREATE DATABASE`/`USE`
statements, so it can be loaded into any name without touching the source.

### 3.5 Restore into recovery/staging first

Use the MariaDB client directly (the same binary family as
`backup.clientExecutable`):

```sh
"C:\Program Files\MariaDB 10.6\bin\mariadb.exe" --defaults-extra-file="<option-file>" ospos_recovery_20261012 < "C:\Backups\ospos\ospos_2026-10-12_021000_3f2a.sql"
```

The option file is a temporary `[client]` file containing `user`/`password` for
a **restore-specific** account (least privilege, never the app admin account),
kept out of the command line and deleted immediately after. See
[Credentials handling](#39-credentials-and-secrets-handling).

### 3.6 Validate critical tables and business data

On the staging database, check — with the application's own terminology:

- row counts and latest dates on high-value tables (sales/receipts, inventory,
  items, customers, giftcards, suppliers, employees);
- the most recent transaction matches the last known receipt number;
- totals of a known day reconcile to the last daily report;
- the application can log in against the staging database (read-only check).

Only proceed to cutover when the staging check passes. If it does not, a newer
dump was probably made after the failure window — go back to 3.2 with the
timeline in mind.

### 3.7 Preserve the current production database before cutover

Before replacing anything, make a point-in-time copy of the **current**
production database (even if it is damaged or suspect) so nothing is lost:

```sh
# dumps the current production data to a timestamped file you keep out of the way
"C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe" --defaults-extra-file="<option-file>" --single-transaction ospos > "C:\Before-Cutover\ospos_precutover_20261012.sql"
```

### 3.8 Controlled cutover and rollback

Choose the cutover that matches your deployment, and record everything:

- **Option A (rename, recommended when downtime is acceptable):** stop the app
  completely, `RENAME TABLE`-free approach — rename the production database to
  `ospos_old_<date>`, rename the validated staging database to `ospos`, restart
  the app, verify a test sale/scan. **Rollback:** rename back in reverse order;
  the pre-cutover dump from 3.7 is the final fallback.
- **Option B (load into production):** restore into the production database
  name only after 3.4–3.6 passed and 3.7 is done. Keep the staging database and
  the pre-cutover dump until the restored system has been stable for a business
  day.

The app's DB prefix is `ospos_` (see `database.default.DBPrefix`); keep it
consistent in whatever database name you cut over to.

### 3.9 Credentials and secrets handling

- Connection details and the password live in the application `.env`; the app
  never puts the password on a process command line.
- For operator restores, create a **dedicated restore account** (e.g.
  `ospos_restore`) with restore privileges on the staging database only, and
  put its credentials in a temporary option file that you delete immediately
  after use. Never reuse the production password in scripts or logs.
- Never store credentials in Task Scheduler actions, PowerShell runners, batch
  files, or anywhere in the repository.
- After cutover, rotate/revoke the restore account.

### 3.10 MariaDB client on Windows

- Set `backup.clientExecutable` to the absolute path of the client binary
  (`mariadb.exe` or `mysql.exe`) — usually
  `C:\Program Files\MariaDB 10.6\bin\mariadb.exe`. The dump tool used must be
  the matching `mariadb-dump.exe`/`mysqldump.exe` family.
- Windows cannot redirect PostgreSQL-style here-strings; use `<` (cmd.exe /
  PowerShell) or `--execute="source ..."` with files. `cmd /c` is fine for a
  restore you run yourself; the backup system itself never shells out.
- If the server is remote, add `--host`/`--port` explicitly.

### 3.11 What if only the off-device replica survives?

- The replica was verified (size + SHA-256) at replication time, but still
  re-verify the file's SHA-256 on the recovery machine (3.3) and restore it
  into the staging database exactly as above.
- The dump has no `CREATE DATABASE`/`USE`, so it restores into a fresh
  database name regardless of the machine/setup.
- If the replica directory is on a network share, ensure the recovery host has
  read access (the backup system itself does not store share credentials).

## 4. Unattended operation (Windows Task Scheduler)

An example `schtasks` entry that runs the recommended daily backup and reports
failure:

```sh
schtasks /Create /TN "OSPOS Database Backup" ^
  /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"C:\path\to\ospos\tools\backup-run.ps1\" -PhpPath \"C:\Program Files\PHP\8.2\php.exe\"" ^
  /SC DAILY /ST 02:00 /RU SYSTEM /RL HIGHEST /F
```

- The checked-in helper `tools/backup-run.ps1` resolves the project directory
  automatically (paths with spaces are fine), accepts `-PhpPath` and
  `-SparkArgs`, and **exits with the Spark exit code** so Task Scheduler shows
  the run as failed when any verification stage fails.
- Configure the OS account to reach `backup.replicaDir` (if it is a network
  share) — credentials are not stored anywhere in the runner or config.
- Test the scheduled task manually once (`schtasks /Run /TN "OSPOS Database
  Backup"`), confirm the local and replica files appear and the final exit code
  is 0, then monitor the task history.

See `tools/backup-run.ps1` header for the full parameters.

## 5. Daily operations checklist

- The scheduled run completed with exit code 0 (Task Scheduler history).
- A new `DUMP VERIFIED` file exists in `backup.backupDir` and a matching
  `REPLICA VERIFIED` file exists in `backup.replicaDir`.
- Spot-check `RESTORE VERIFIED` output at least weekly (it is included in the
  recommended daily invocation).
- Watch the retention lines: old backups are being pruned and the newest is
  retained.
- Keep a printed/offline copy of the current verification path, sha256 and
  this runbook somewhere outside the building/office.