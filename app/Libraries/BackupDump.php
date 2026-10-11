<?php

namespace App\Libraries;

use Config\Backup;
use Config\Database;
use RuntimeException;

/**
 * Creates a verified local SQL dump of the configured default database.
 *
 * The database connection details come from `Config\Database::$default`; the
 * password reaches mariadb-dump/mysqldump through a temporary
 * `--defaults-extra-file` option file, never through the process command line.
 * Output is written to a `.part` file first and only renamed to the final
 * filename after the dump passes basic verification (exit code 0 and non-empty
 * output), so an interrupted or failed dump never looks like a completed
 * backup. A SHA-256 checksum of the finished file is included in the report.
 *
 * This is dump-level verification only - it does NOT prove the dump can be
 * restored. The external process is injectable so tests never touch a real
 * database. Password handling: the temporary option file is removed in a
 * finally block and the password is redacted from any reported error text.
 */
final class BackupDump
{
    /**
     * @var callable(array<int, string>, string): array{exitCode: int, stderr: string}
     */
    private $processRunner;

    private ?Backup $config;
    private ?Database $dbConfig;
    private ?string $environment;

    /**
     * @param callable(array<int, string>, string): array{exitCode: int, stderr: string}|null $processRunner
     */
    public function __construct(
        ?Backup $config = null,
        ?Database $dbConfig = null,
        ?callable $processRunner = null,
        ?string $environment = null,
    ) {
        $this->config        = $config;
        $this->dbConfig      = $dbConfig;
        $this->environment   = $environment;
        $this->processRunner = $processRunner ?? $this->defaultProcessRunner(...);
    }

    /**
     * Runs the dump and returns the completed-backup report.
     *
     * @return array{path: string, size: int, sha256: string, timestamp: string}
     *
     * @throws RuntimeException on any failure; partial output and the
     *                          temporary credentials file are removed first
     */
    public function execute(): array
    {
        $connection = $this->defaultConnection();
        $backup     = $this->backupConfig();

        $partial    = null;
        $optionFile = null;

        try {
            $this->assertNotTestingEnvironment();
            $this->assertNotTestDatabase($connection['database']);

            $executable = $this->resolveDumpExecutable($backup->dumpExecutable);
            $dumpDir    = $this->dumpDirectory($backup->backupDir);

            $timestamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(2));
            $finalPath = $dumpDir . DIRECTORY_SEPARATOR . $this->safeDatabaseName($connection['database']) . '_' . $timestamp . '.sql';
            $partial   = $finalPath . '.part';

            $optionFile = $this->writeOptionFile((string) $connection['password']);

            $result = ($this->processRunner)(
                $this->dumpCommand($executable, $connection, $optionFile, (string) $connection['database']),
                $partial,
            );

            if ($result['exitCode'] !== 0) {
                throw new RuntimeException(
                    'Dump process failed (exit ' . $result['exitCode'] . '): '
                    . $this->redact($result['stderr'], (string) $connection['password']),
                );
            }

            if (! is_file($partial)) {
                throw new RuntimeException('Dump produced no output file.');
            }

            if (filesize($partial) === 0) {
                throw new RuntimeException('Dump produced an empty file - refusing to treat it as a backup.');
            }

            // Complete the backup only now that the dump succeeded and has data.
            if (! @rename($partial, $finalPath)) {
                throw new RuntimeException('Could not move the completed dump into place: ' . $finalPath);
            }

            return [
                'path'      => $finalPath,
                'size'      => filesize($finalPath),
                'sha256'    => hash_file('sha256', $finalPath),
                'timestamp' => $timestamp,
            ];
        } finally {
            if ($optionFile !== null && is_file($optionFile)) {
                @unlink($optionFile);
            }

            if ($partial !== null && is_file($partial)) {
                @unlink($partial);
            }
        }
    }

    private function backupConfig(): Backup
    {
        return $this->config ?? config(Backup::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConnection(): array
    {
        $db = $this->dbConfig ?? config(Database::class);

        return (array) $db->{'default'};
    }

    private function assertNotTestingEnvironment(): void
    {
        $environment = $this->environment ?? ENVIRONMENT;

        if ($environment === 'testing') {
            throw new RuntimeException('Refusing to create a backup while the environment is "testing".');
        }
    }

    private function assertNotTestDatabase(string $databaseName): void
    {
        if (preg_match('/test/i', $databaseName) === 1) {
            throw new RuntimeException('Refusing to back up database "' . $databaseName . '" - the name looks like a test database.');
        }
    }

    private function resolveDumpExecutable(string $configured): string
    {
        if ($configured !== '') {
            if (! is_file($configured)) {
                throw new RuntimeException('Configured dump executable not found: ' . $configured);
            }

            return $configured;
        }

        foreach (['mariadb-dump', 'mysqldump'] as $candidate) {
            $found = $this->findOnPath($candidate);

            if ($found !== '') {
                return $found;
            }
        }

        throw new RuntimeException('Could not find mariadb-dump or mysqldump on PATH. Set backup.dumpExecutable in .env, e.g. C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe.');
    }

    private function findOnPath(string $executable): string
    {
        $path = getenv('PATH');

        if (! is_string($path) || $path === '') {
            return '';
        }

        $separator  = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        $extensions = DIRECTORY_SEPARATOR === '\\' ? ['.exe', ''] : [''];

        foreach (explode($separator, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $executable . $extension;

                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function dumpDirectory(string $configured): string
    {
        $dir = $configured === '' ? WRITEPATH . 'backups' : $configured;

        if (! $this->isAbsolutePath($dir)) {
            $dir = WRITEPATH . ltrim($dir, '/\\');
        }

        $dir = rtrim($dir, '/\\');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create backup directory: ' . $dir);
        }

        return $dir;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function writeOptionFile(string $password): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'db-backup-' . bin2hex(random_bytes(8)) . '.cnf';

        if (@file_put_contents($path, "[client]\npassword=" . $password . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not create the temporary credentials file for the dump.');
        }

        $this->lockDownOptionFile($path);

        return $path;
    }

    private function lockDownOptionFile(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Best-effort on Windows: drop inherited ACLs so only the current
            // user can read the file. Failure is non-fatal - the file is
            // deleted immediately after the dump either way.
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

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    private function dumpCommand(string $executable, array $connection, string $optionFile, string $databaseName): array
    {
        $command = [$executable, '--defaults-extra-file=' . $optionFile];

        if (isset($connection['hostname']) && $connection['hostname'] !== '') {
            $command[] = '--host=' . $connection['hostname'];
        }

        if (isset($connection['port']) && $connection['port'] !== '' && (int) $connection['port'] !== 0) {
            $command[] = '--port=' . $connection['port'];
        }

        if (isset($connection['username']) && $connection['username'] !== '') {
            $command[] = '--user=' . $connection['username'];
        }

        $command[] = '--single-transaction';
        $command[] = '--databases';
        $command[] = $databaseName;

        return $command;
    }

    private function safeDatabaseName(string $databaseName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '-', $databaseName) ?? '';

        return $safe === '' ? 'database' : $safe;
    }

    private function redact(string $text, string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, '[REDACTED]', $text);
    }

    /**
     * Runs the dump with stdout redirected straight into the `.part` file.
     *
     * @param array<int, string> $command
     *
     * @return array{exitCode: int, stderr: string}
     */
    private function defaultProcessRunner(): callable
    {
        return static function (array $command, string $stdoutFile): array {
            $pipes   = [];
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $stdoutFile, 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the dump process.');
            }

            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            return [
                'exitCode' => proc_close($process),
                'stderr'   => $stderr === false ? '' : $stderr,
            ];
        };
    }
}
