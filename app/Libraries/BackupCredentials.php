<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Shares the secure credential handling between the database dump and the
 * scratch-restore verifier.
 *
 * The database password is written to a temporary `--defaults-extra-file`
 * option file so it never appears on a process command line. On Windows the
 * file is ACL-hardened to the current user (best effort, non-fatal); on POSIX
 * it is chmod 0600. The caller deletes the file in a finally block, and error
 * text is scrubbed through redact() so the secret never reaches output.
 */
final class BackupCredentials
{
    /**
     * Writes the password to a new, permission-restricted temporary `[client]`
     * option file and returns its path. The caller must delete it afterwards.
     *
     * @throws RuntimeException when the file cannot be created
     */
    public static function writeOptionFile(string $password): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'db-backup-' . bin2hex(random_bytes(8)) . '.cnf';

        if (@file_put_contents($path, "[client]\npassword=" . $password . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not create the temporary credentials file.');
        }

        self::lockDownOptionFile($path);

        return $path;
    }

    private static function lockDownOptionFile(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Best-effort on Windows: drop inherited ACLs so only the current
            // user can read the file. Failure is non-fatal - the file is
            // deleted immediately after the process either way.
            $user = getenv('USERNAME') ?: get_current_user();

            $pipes   = [];
            $process = @proc_open(
                ['icacls', $path, '/inheritance:r', '/grant:r', $user . ':(R,W)'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (is_resource($process)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }

            return;
        }

        @chmod($path, 0600);
    }

    public static function redact(string $text, string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, '[REDACTED]', $text);
    }
}
