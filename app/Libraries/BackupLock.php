<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Cross-process advisory lock for `db:backup` runs.
 *
 * PHP's flock() works on both POSIX and Windows: it is an advisory lock tied to
 * an open file handle, and the operating system releases it automatically when
 * the holding process exits (including crashes), so a crashed run never leaves
 * a stale lock blocking future runs. The lock file itself lives under the
 * writable directory, is deterministic across invocations and simply stays
 * behind once created (it is ignored by git and never matches the backup
 * naming rule used by retention).
 *
 * Acquisition is atomic (LOCK_EX|LOCK_NB) and bounded: when another process
 * holds the lock, acquire() keeps probing for up to waitSeconds and then
 * throws, so an overlapping second invocation fails clearly instead of racing
 * the first one over the dump `.part` file, the scratch restore database, the
 * replica `.part` file or retention. release() is idempotent and must be
 * called in a finally block.
 */
final class BackupLock
{
    /**
     * @resource|null the open lock file handle while the lock is held
     */
    private $handle;

    public function __construct(
        private string $lockFile = '',
        private float $waitSeconds = 0.0,
    ) {
    }

    /**
     * The deterministic lock file location.
     */
    public function lockFile(): string
    {
        return $this->lockFile === '' ? WRITEPATH . 'db-backup.lock' : $this->lockFile;
    }

    /**
     * Acquires the lock atomically, probing for up to waitSeconds.
     *
     * @throws RuntimeException when the lock cannot be taken before the
     *                          bounded wait expires (another backup process is
     *                          active) or the lock file cannot be opened
     */
    public function acquire(): bool
    {
        $file = $this->lockFile();
        $dir  = dirname($file);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the backup lock directory: ' . $dir);
        }

        $handle = @fopen($file, 'cb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the backup lock file: ' . $file);
        }

        $deadline = microtime(true) + $this->waitSeconds;

        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;

                return true;
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new RuntimeException('Another backup process is already running (lock: ' . $file . '). Wait for it to finish or investigate a possibly hung process.');
            }

            usleep(200000);
        }
    }

    /**
     * Releases the lock. Idempotent; call in a finally block.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        @flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
