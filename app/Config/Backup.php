<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuration for the local database backup command (`php spark db:backup`).
 *
 * Settings are overridable through the environment using the CodeIgniter
 * BaseConfig convention, e.g. in `.env`:
 *
 *     backup.backupDir = "C:\Backups\ospos"
 *     backup.dumpExecutable = "C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe"
 *     backup.clientExecutable = "C:\Program Files\MariaDB 10.6\bin\mariadb.exe"
 *     backup.replicaDir = "D:\Backups\ospos"
 *     backup.retentionCount = 5
 *
 * Never put the database password here - it is read from the Database config
 * and handed to the dump/restore process through a temporary option file that
 * is deleted immediately after the process finishes.
 */
class Backup extends BaseConfig
{
    /**
     * Directory where completed dumps are written.
     *
     * Defaults to writable/backups (ignored by git). Relative values resolve
     * under the writable directory.
     */
    public string $backupDir = WRITEPATH . 'backups';

    /**
     * Absolute path to the MariaDB/MySQL dump executable.
     *
     * Empty means: look up `mariadb-dump`, then `mysqldump`, on PATH.
     * Production Windows installs often keep the binary outside PATH, e.g.
     * `C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe` - set it here.
     */
    public string $dumpExecutable = '';

    /**
     * Absolute path to the MariaDB/MySQL client executable used for restore
     * verification (creating/dropping the scratch database and restoring into
     * it).
     *
     * Empty means: look for `mariadb`, then `mysql`, next to the configured
     * dump executable, then on PATH.
     */
    public string $clientExecutable = '';

    /**
     * Optional off-device replication destination. Replication is DISABLED when
     * this is empty, which is the default.
     *
     * When set, `php spark db:backup --replicate` copies each verified backup
     * into this directory. Point it at another physical device, a network share
     * (UNC path) or a synced external location so a single local failure cannot
     * destroy both copies. Relative values resolve under the writable
     * directory. The destination must differ from the backup directory.
     *
     * Credentials for a network share are NOT configured here - the operating
     * system must already have access to the share.
     */
    public string $replicaDir = '';

    /**
     * How many of the newest valid backups to keep when `db:backup --prune`
     * runs, applied independently to the local backup directory and (when
     * configured) the replica directory.
     *
     * 0 (the default) disables pruning. Only files matching the application
     * backup naming rule (`<database>_YYYY-MM-DD_HHMMSS_<hex>.sql`) are ever
     * removed from exactly the configured directories, never subdirectories or
     * arbitrary files, and the newest valid backup is always kept.
     */
    public int $retentionCount = 0;
}
