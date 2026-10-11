<?php

namespace App\Libraries;

use CodeIgniter\Database\Config as DatabaseConfig;
use Config\Backup;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Restore-verifies a completed SQL dump by loading it into a dedicated scratch
 * database created on the same configured database server, running sanity and
 * integrity checks against that scratch database only, and then dropping it.
 *
 * The scratch database name is generated internally - never accepted from the
 * command line - for example `ospos_backup_verify_20261011_184500_a1b2c3d4`.
 * It must match a strict naming pattern before any CREATE/RESTORE/DROP is
 * attempted, and DROP DATABASE is only ever issued for the exact database
 * created by this run. The configured/default database is never used as a
 * restore target and is never modified.
 *
 * Credentials reuse BackupCredentials: the password is written to a temporary
 * `--defaults-extra-file` option file (ACL-hardened, deleted in a finally
 * block) and never appears on any process command line or in reported error
 * text. The client process is started with an argument array via proc_open (no
 * shell), and the SQL dump is fed to the client through stdin so the file path
 * never appears in the arguments either.
 *
 * The external process runner and the sanity checker are injectable so tests
 * never touch a real database server.
 */
final class BackupRestoreVerify
{
    private const SCRATCH_PREFIX      = 'ospos_backup_verify_';
    private const SCRATCH_PATTERN     = '/^ospos_backup_verify_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/';
    private const PROTECTED_DATABASES = ['ospos', 'ospos_test', 'tests'];

    /**
     * @var callable(array<int, string>, ?string, ?string): array{exitCode: int, stderr: string}
     */
    private $processRunner;

    /**
     * @var callable(string, array<string, mixed>, array<int, string>, array<int, string>): void
     */
    private $dbChecker;

    /**
     * @var callable(): string
     */
    private $nameGenerator;

    private ?Backup $config;
    private ?array $connection;
    private ?string $environment;
    private ?string $optionFile     = null;
    private bool $scratchCreated    = false;
    private string $scratchDatabase = '';

    /**
     * @param callable(array<int, string>, ?string, ?string): array{exitCode: int, stderr: string}|null $processRunner
     * @param callable(string, array<string, mixed>, array<int, string>, array<int, string>): void|null $dbChecker
     * @param callable(): string|null                                                                   $nameGenerator
     */
    public function __construct(
        ?Backup $config = null,
        ?array $connection = null,
        ?callable $processRunner = null,
        ?callable $dbChecker = null,
        ?string $environment = null,
        ?callable $nameGenerator = null,
    ) {
        $this->config        = $config;
        $this->connection    = $connection;
        $this->environment   = $environment;
        $this->processRunner = $processRunner ?? $this->defaultProcessRunner(...);
        $this->dbChecker     = $dbChecker ?? $this->defaultDbChecker(...);
        $this->nameGenerator = $nameGenerator ?? $this->generateScratchName(...);
    }

    /**
     * Restore-verifies the dump and returns the verification report.
     *
     * Every required stage must succeed: the scratch database is created and
     * proves to be a freshly created scratch database, the restore process
     * exits 0, the sanity/integrity checks pass, and the scratch database is
     * dropped again. Any failure - including a failed cleanup - throws.
     *
     * @return array{scratchDatabase: string, checkedAt: string}
     *
     * @throws RuntimeException on any failure; the scratch database (when
     *                          created by this run) and the temporary
     *                          credentials file are cleaned up first
     */
    public function verify(string $dumpPath): array
    {
        $this->optionFile      = null;
        $this->scratchCreated  = false;
        $this->scratchDatabase = '';

        $failure = null;
        $result  = null;

        try {
            try {
                $result = $this->doVerify($dumpPath);
            } finally {
                if ($this->optionFile !== null && is_file($this->optionFile)) {
                    @unlink($this->optionFile);
                }
            }
        } catch (Throwable $e) {
            $failure = $e;
        }

        // Runs on success AND on failure: drop the scratch database created by
        // this run, then decide what to report.
        $cleanupError = $this->scratchCreated ? $this->dropScratchDatabase() : null;

        if ($cleanupError !== null) {
            throw new RuntimeException(
                ($failure !== null ? $failure->getMessage() . ' ' : '') . $cleanupError,
            );
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /**
     * @return array{scratchDatabase: string, checkedAt: string}
     */
    private function doVerify(string $dumpPath): array
    {
        $connection = $this->defaultConnection();
        $backup     = $this->backupConfig();

        $this->assertNotTestingEnvironment();
        $this->assertNotTestDatabase((string) $connection['database']);
        $this->assertDumpFile($dumpPath);

        $scratch = ($this->nameGenerator)();
        $this->assertValidScratchDatabase($scratch, (string) $connection['database']);

        $client           = $this->resolveClientExecutable($backup);
        $this->optionFile = BackupCredentials::writeOptionFile((string) $connection['password']);
        $baseCommand      = $this->clientBaseCommand($client, $connection, $this->optionFile);

        // 1. Create the scratch database on the same configured server.
        $createSql = 'CREATE DATABASE `' . $scratch . '` ' . $this->createDatabaseOptions($connection);
        $create    = $this->runClient($baseCommand, '--execute=' . $createSql);

        if ($create['exitCode'] !== 0) {
            throw new RuntimeException(
                'Could not create scratch verification database "' . $scratch . '":'
                . $this->describeClientError($create['stderr'], (string) $connection['password']),
            );
        }

        $this->scratchCreated  = true;
        $this->scratchDatabase = $scratch;

        // 2. Restore the dump into the scratch database (SQL via stdin, no shell).
        $restore = $this->runClient($baseCommand, $scratch, $dumpPath);

        if ($restore['exitCode'] !== 0) {
            throw new RuntimeException(
                'Restore into scratch verification database "' . $scratch . '" failed (exit ' . $restore['exitCode'] . '):'
                . $this->describeClientError($restore['stderr'], (string) $connection['password']),
            );
        }

        // 3. Sanity / integrity checks against the scratch database only.
        ($this->dbChecker)(
            $scratch,
            $connection,
            $this->criticalTables($connection),
            $this->extractTableNames($dumpPath),
        );

        return [
            'scratchDatabase' => $scratch,
            'checkedAt'       => date('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * Drops only the exact scratch database created by this run.
     *
     * @return string|null an error message when cleanup fails, null otherwise
     */
    private function dropScratchDatabase(): ?string
    {
        if ($this->scratchDatabase === '') {
            return null;
        }

        // Only ever drop the database created by this run, re-checked against
        // the strict scratch pattern. No arbitrary or user-supplied names, and
        // never a broadened cleanup.
        if (preg_match(self::SCRATCH_PATTERN, $this->scratchDatabase) !== 1) {
            return 'Cleanup refused: "' . $this->scratchDatabase . '" is not a recognized scratch verification database name.';
        }

        $connection = $this->defaultConnection();
        $backup     = $this->backupConfig();
        $optionFile = null;

        try {
            $client     = $this->resolveClientExecutable($backup);
            $optionFile = BackupCredentials::writeOptionFile((string) $connection['password']);
            $result     = $this->runClient(
                $this->clientBaseCommand($client, $connection, $optionFile),
                '--execute=DROP DATABASE `' . $this->scratchDatabase . '`',
            );
        } catch (Throwable $e) {
            return 'Cleanup FAILED: ' . $e->getMessage() . ' Scratch database "' . $this->scratchDatabase . '" still exists.';
        } finally {
            if ($optionFile !== null && is_file($optionFile)) {
                @unlink($optionFile);
            }
        }

        if ($result['exitCode'] !== 0) {
            return 'Cleanup FAILED: could not drop scratch verification database "' . $this->scratchDatabase . '". Drop it manually if needed.';
        }

        return null;
    }

    private function assertNotTestingEnvironment(): void
    {
        $environment = $this->environment ?? ENVIRONMENT;

        if ($environment === 'testing') {
            throw new RuntimeException('Refusing restore verification while the environment is "testing".');
        }
    }

    private function assertNotTestDatabase(string $databaseName): void
    {
        if (preg_match('/test/i', $databaseName) === 1) {
            throw new RuntimeException('Refusing restore verification: configured database "' . $databaseName . '" looks like a test database.');
        }
    }

    private function assertDumpFile(string $dumpPath): void
    {
        if (! is_file($dumpPath)) {
            throw new RuntimeException('Dump file not found: ' . $dumpPath);
        }

        if (filesize($dumpPath) === 0) {
            throw new RuntimeException('Dump file is empty - refusing to restore-verify it.');
        }
    }

    private function assertValidScratchDatabase(string $scratch, string $defaultDatabase): void
    {
        if (in_array(strtolower($scratch), self::PROTECTED_DATABASES, true)) {
            throw new RuntimeException('Refusing: target database "' . $scratch . '" is a protected database name.');
        }

        if (strcasecmp($scratch, $defaultDatabase) === 0) {
            throw new RuntimeException('Refusing: target database "' . $scratch . '" equals the configured default database.');
        }

        if (preg_match(self::SCRATCH_PATTERN, $scratch) !== 1) {
            throw new RuntimeException('Refusing: target database "' . $scratch . '" does not match the scratch verification naming convention.');
        }
    }

    private function generateScratchName(): string
    {
        return self::SCRATCH_PREFIX . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
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
        if ($this->connection !== null) {
            return $this->connection;
        }

        $db = config(Database::class);

        return (array) $db->{'default'};
    }

    private function resolveClientExecutable(Backup $backup): string
    {
        if ($backup->clientExecutable !== '') {
            if (! is_file($backup->clientExecutable)) {
                throw new RuntimeException('Configured client executable not found: ' . $backup->clientExecutable);
            }

            return $backup->clientExecutable;
        }

        // Prefer a client binary sitting next to a configured dump binary.
        if ($backup->dumpExecutable !== '') {
            $directory = dirname($backup->dumpExecutable);

            foreach (['mariadb', 'mysql'] as $candidate) {
                $sibling = $directory . DIRECTORY_SEPARATOR . $candidate . $this->platformExtension();

                if (is_file($sibling)) {
                    return $sibling;
                }
            }
        }

        foreach (['mariadb', 'mysql'] as $candidate) {
            $found = $this->findOnPath($candidate);

            if ($found !== '') {
                return $found;
            }
        }

        throw new RuntimeException('Could not find a mariadb or mysql client on PATH. Set backup.clientExecutable in .env, e.g. C:\Program Files\MariaDB 10.6\bin\mariadb.exe.');
    }

    private function platformExtension(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
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

    /**
     * Common client options; the password itself lives only in the option file.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    private function clientBaseCommand(string $client, array $connection, string $optionFile): array
    {
        $command = [$client, '--defaults-extra-file=' . $optionFile];

        if (isset($connection['hostname']) && $connection['hostname'] !== '') {
            $command[] = '--host=' . $connection['hostname'];
        }

        if (isset($connection['port']) && $connection['port'] !== '' && (int) $connection['port'] !== 0) {
            $command[] = '--port=' . $connection['port'];
        }

        if (isset($connection['username']) && $connection['username'] !== '') {
            $command[] = '--user=' . $connection['username'];
        }

        return $command;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function createDatabaseOptions(array $connection): string
    {
        $charset   = isset($connection['charset']) && $connection['charset'] !== '' ? $connection['charset'] : 'utf8mb4';
        $collation = isset($connection['DBCollat']) && $connection['DBCollat'] !== '' ? $connection['DBCollat'] : '';

        $options = 'CHARACTER SET ' . $charset;

        if ($collation !== '') {
            $options .= ' COLLATE ' . $collation;
        }

        return $options;
    }

    /**
     * Runs one client command with $tail appended as its final argument. SQL is
     * fed through stdin when $stdinFile is given; stdout is routed to a
     * temporary file so a chatty client can never block on a full pipe.
     *
     * @param array<int, string> $baseCommand
     *
     * @return array{exitCode: int, stderr: string}
     */
    private function runClient(array $baseCommand, string $tail, ?string $stdinFile = null): array
    {
        $stdoutFile = @tempnam(sys_get_temp_dir(), 'db-backup-client-');

        if ($stdoutFile === false) {
            throw new RuntimeException('Could not create a temporary output file for the database client.');
        }

        try {
            return ($this->processRunner)([...$baseCommand, $tail], $stdinFile, $stdoutFile);
        } finally {
            @unlink($stdoutFile);
        }
    }

    private function describeClientError(string $stderr, string $password): string
    {
        if ($stderr === '') {
            return ' The client reported an error without details.';
        }

        return ' ' . BackupCredentials::redact($stderr, $password);
    }

    /**
     * Critical tables expected by this application, built from the configured
     * table prefix. All of these exist in the OSPOS base schema.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<int, string>
     */
    private function criticalTables(array $connection): array
    {
        $prefix = (string) ($connection['DBPrefix'] ?? 'ospos_');
        $core   = [
            'app_config',
            'people',
            'customers',
            'employees',
            'items',
            'item_kits',
            'inventory',
            'sales',
            'sales_items',
            'suppliers',
            'stock_locations',
        ];

        return array_map(static fn (string $name): string => $prefix . $name, $core);
    }

    /**
     * Reads the table names encoded in the dump itself; the restored schema is
     * later compared against this list, which is the authoritative structural
     * reference - the live database is never queried during verification.
     *
     * @return array<int, string>
     */
    private function extractTableNames(string $dumpPath): array
    {
        $names  = [];
        $handle = @fopen($dumpPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not read the dump file for restore verification.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $line, $match) === 1) {
                    $names[] = $match[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return array_values(array_unique($names));
    }

    /**
     * The default sanity checker connects ONLY to the scratch database.
     *
     * It proves reachability, enforces that the connected database really is
     * the scratch database, requires a non-empty schema, requires the restored
     * table set to match the dump's own table list, requires every critical
     * table to exist, and runs a basic SELECT against each critical table. No
     * exact row counts are required for volatile tables; only the app_config
     * settings table must be non-empty.
     *
     * @return callable(string, array<string, mixed>, array<int, string>, array<int, string>): void
     */
    private function defaultDbChecker(): callable
    {
        return static function (
            string $databaseName,
            array $connection,
            array $criticalTables,
            array $expectedTables,
        ): void {
            $config             = $connection;
            $config['database'] = $databaseName;
            $config['DBDebug']  = true;

            $db = null;

            try {
                // A failed connect throws (the MySQLi driver redacts the
                // configured username/password from its own error message).
                $db = DatabaseConfig::connect($config, false);
                $db->connect();
                $db->query('SELECT 1');

                if (strcasecmp((string) $db->getDatabase(), $databaseName) !== 0) {
                    throw new RuntimeException('Sanity check failed: connected to "' . $db->getDatabase() . '" instead of the scratch database "' . $databaseName . '".');
                }

                $tables = $db->listTables();
                sort($tables);
                $expected = array_values(array_unique($expectedTables));
                sort($expected);

                if ($tables === []) {
                    throw new RuntimeException('Sanity check failed: the restored schema is empty.');
                }

                $missing = array_values(array_diff($expected, $tables));

                if ($missing !== []) {
                    throw new RuntimeException('Sanity check failed: the restored schema is missing ' . count($missing) . ' table(s) present in the dump, including "' . $missing[0] . '".');
                }

                $unexpected = array_values(array_diff($tables, $expected));

                if ($unexpected !== []) {
                    throw new RuntimeException('Sanity check failed: the restored schema contains ' . count($unexpected) . ' table(s) not present in the dump, including "' . $unexpected[0] . '".');
                }

                foreach ($criticalTables as $table) {
                    if (! in_array($table, $tables, true)) {
                        throw new RuntimeException('Sanity check failed: critical table "' . $table . '" is missing from the restored database.');
                    }

                    $db->query('SELECT COUNT(*) FROM `' . $table . '`');
                }

                $prefix   = (string) ($connection['DBPrefix'] ?? 'ospos_');
                $settings = $db->query('SELECT COUNT(*) FROM `' . $prefix . 'app_config`')->getRowArray();

                if ((int) ($settings['COUNT(*)'] ?? 0) === 0) {
                    throw new RuntimeException('Sanity check failed: table "' . $prefix . 'app_config" is empty in the restored database.');
                }
            } finally {
                if ($db !== null) {
                    $db->close();
                }
            }
        };
    }

    /**
     * Runs the client with optional stdin and file-backed stdout.
     *
     * @param array<int, string> $command
     *
     * @return array{exitCode: int, stderr: string}
     */
    private function defaultProcessRunner(): callable
    {
        return static function (array $command, ?string $stdinFile, ?string $stdoutFile): array {
            $pipes   = [];
            $process = proc_open(
                $command,
                [
                    0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
                    1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the database client process.');
            }

            if ($stdinFile === null) {
                fclose($pipes[0]);
            }

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            return [
                'exitCode' => proc_close($process),
                'stderr'   => $stderr === false ? '' : $stderr,
            ];
        };
    }
}
