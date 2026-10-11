<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuration for the local database backup command (`php spark db:backup`).
 *
 * Both settings are overridable through the environment using the CodeIgniter
 * BaseConfig convention, e.g. in `.env`:
 *
 *     backup.backupDir = "C:\Backups\ospos"
 *     backup.dumpExecutable = "C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe"
 *
 * Never put the database password here - it is read from the Database config
 * and handed to the dump process through a temporary option file that is
 * deleted immediately after the dump.
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
}
