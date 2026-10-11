<?php

namespace Tests\Libraries;

use App\Libraries\BackupDump;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Backup;
use Config\Database;
use RuntimeException;

/**
 * Tests for BackupDump.
 *
 * The external dump process is faked, so no test touches a real database or
 * runs a real dump binary. The success-path tests run the runner with the
 * "production" environment injected so the built-in ENVIRONMENT constant
 * (always "testing" under PHPUnit) does not trip the environment guard.
 */
final class BackupDumpTest extends CIUnitTestCase
{
    private const DB_PASSWORD = 's3cr3t-passw0rd!';

    private string $backupDir;

    private string $dumpExecutable;

    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH') ?: '';
        $this->backupDir    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup-dump-test-' . bin2hex(random_bytes(6));
        mkdir($this->backupDir, 0700, true);

        // Fake "dump executable" that satisfies is_file() during resolution.
        // The process runner is faked, so this file is never executed.
        $this->dumpExecutable = $this->backupDir . DIRECTORY_SEPARATOR . 'fake-dump.exe';
        file_put_contents($this->dumpExecutable, '');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        $this->removeDirectory($this->backupDir);

        parent::tearDown();
    }

    /**
     * @param callable(array<int, string>, string): array{exitCode: int, stderr: string}|null $processRunner
     */
    private function runner(?Database $db = null, ?callable $processRunner = null): BackupDump
    {
        return new BackupDump(
            $this->config(),
            $db ?? $this->databaseConfig(),
            $processRunner,
            'production',
        );
    }

    private function config(): Backup
    {
        $config = new Backup();
        $config->backupDir        = $this->backupDir;
        $config->dumpExecutable   = $this->dumpExecutable;

        return $config;
    }

    private function databaseConfig(string $databaseName = 'ospos'): Database
    {
        $db = new Database();
        $db->defaultGroup            = 'default';
        $db->default['hostname']     = 'localhost';
        $db->default['port']         = 3306;
        $db->default['username']     = 'backup_user';
        $db->default['password']     = self::DB_PASSWORD;
        $db->default['database']     = $databaseName;

        return $db;
    }

    /**
     * @return callable(array<int, string>, string): array{exitCode: int, stderr: string}
     */
    private function successRunner(string $sql = "CREATE DATABASE ospos;\n"): callable
    {
        return static function (array $command, string $stdoutFile) use ($sql): array {
            file_put_contents($stdoutFile, $sql);

            return ['exitCode' => 0, 'stderr' => ''];
        };
    }

    public function testSuccessfulBackupProducesVerifiedReport(): void
    {
        $sql    = "CREATE DATABASE ospos;\n";
        $result = $this->runner(processRunner: $this->successRunner($sql))->execute();

        $this->assertMatchesRegularExpression('/^ospos_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{4}\.sql$/', basename($result['path']));
        $this->assertFileExists($result['path']);
        $this->assertSame(strlen($sql), $result['size']);
        $this->assertSame($sql, (string) file_get_contents($result['path']));
        $this->assertSame(hash_file('sha256', $result['path']), $result['sha256']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{4}$/', $result['timestamp']);

        // The partial file must not remain, and only the one final backup exists.
        $files = array_filter(glob($this->backupDir . '/*') ?: [], 'is_file');
        $this->assertCount(2, $files); // the fake executable plus the backup
        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);

        // The temporary credentials file must always be removed.
        $this->assertSame([], glob(rtrim(sys_get_temp_dir(), '/\\') . '/db-backup-*.cnf') ?: []);
    }

    public function testDumpCommandNeverContainsPassword(): void
    {
        $captured = null;
        $runner   = static function (array $command, string $stdoutFile) use (&$captured): array {
            $captured = $command;
            file_put_contents($stdoutFile, "CREATE DATABASE ospos;\n");

            return ['exitCode' => 0, 'stderr' => ''];
        };

        $result = $this->runner(processRunner: $runner)->execute();

        $this->assertFileExists($result['path']);
        $this->assertIsArray($captured);
        $this->assertSame($this->dumpExecutable, $captured[0]);
        $this->assertStringContainsString('--defaults-extra-file=', $captured[1]);
        $this->assertStringContainsString('--host=localhost', implode(' ', $captured));
        $this->assertStringContainsString('--port=3306', implode(' ', $captured));
        $this->assertStringContainsString('--user=backup_user', implode(' ', $captured));
        $this->assertStringContainsString('--single-transaction', implode(' ', $captured));
        $this->assertStringContainsString('--databases ospos', implode(' ', $captured));
        $this->assertStringNotContainsString(self::DB_PASSWORD, implode(' ', $captured));
    }

    public function testNonZeroDumpExitThrowsCleansUpAndRedactsSecret(): void
    {
        $runner = static function (array $command, string $stdoutFile): array {
            file_put_contents($stdoutFile, 'partial data');
            // Real mariadb-dump never prints the password, but the redaction is
            // tested defensively with it present in the reported error stream.
            return ['exitCode' => 2, 'stderr' => 'mysqldump: Access denied for user (using password: ' . self::DB_PASSWORD . ')'];
        };

        try {
            $this->runner(processRunner: $runner)->execute();
            $this->fail('Expected a RuntimeException for a non-zero dump exit code.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exit 2', $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
            $this->assertStringNotContainsString(self::DB_PASSWORD, $e->getMessage());
        }

        // No completed-looking backup and no leftover partial file survive.
        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
        $this->assertSame([], glob(rtrim(sys_get_temp_dir(), '/\\') . '/db-backup-*.cnf') ?: []);
    }

    public function testZeroByteDumpRejected(): void
    {
        $runner = static function (array $command, string $stdoutFile): array {
            file_put_contents($stdoutFile, '');

            return ['exitCode' => 0, 'stderr' => ''];
        };

        try {
            $this->runner(processRunner: $runner)->execute();
            $this->fail('Expected a RuntimeException for an empty dump output.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty file', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
    }

    public function testDumpWithNoOutputFileRejected(): void
    {
        $runner = static function (array $command, string $stdoutFile): array {
            return ['exitCode' => 0, 'stderr' => ''];
        };

        try {
            $this->runner(processRunner: $runner)->execute();
            $this->fail('Expected a RuntimeException when the dump creates no output file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no output file', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
    }

    public function testUnsafeDatabaseNameIsSanitizedInFilename(): void
    {
        $db     = $this->databaseConfig('my db/name!');
        $result = $this->runner($db, $this->successRunner())->execute();

        $this->assertMatchesRegularExpression('/^my-db-name-_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{4}\.sql$/', basename($result['path']));
        $this->assertStringNotContainsString('/', $result['path']);
        $this->assertStringNotContainsString(' ', $result['path']);
    }

    public function testRefusesDatabaseWhoseNameLooksLikeATestDatabase(): void
    {
        $db = $this->databaseConfig('ospos_test');

        try {
            $this->runner($db, $this->successRunner())->execute();
            $this->fail('Expected a RuntimeException for a test-looking database name.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('test database', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testRefusesToRunWhileEnvironmentIsTesting(): void
    {
        $runner = new BackupDump($this->config(), $this->databaseConfig(), $this->successRunner(), 'testing');

        try {
            $runner->execute();
            $this->fail('Expected a RuntimeException in the testing environment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('testing', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testConfiguredExecutableThatDoesNotExistFailsFast(): void
    {
        $config = new Backup();
        $config->backupDir        = $this->backupDir;
        $config->dumpExecutable   = $this->backupDir . DIRECTORY_SEPARATOR . 'missing-dump.exe';

        $runner = new BackupDump($config, $this->databaseConfig(), $this->successRunner(), 'production');

        try {
            $runner->execute();
            $this->fail('Expected a RuntimeException for a missing configured executable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testFallsBackToPathLookupWhenNoExecutableConfigured(): void
    {
        $binDir  = $this->backupDir . DIRECTORY_SEPARATOR . 'bin';
        mkdir($binDir, 0700, true);
        $fakeBin = $binDir . DIRECTORY_SEPARATOR . 'mariadb-dump.exe';
        file_put_contents($fakeBin, '');

        putenv('PATH=' . $binDir . ';' . $this->originalPath);

        $config              = new Backup();
        $config->backupDir   = $this->backupDir;
        $config->dumpExecutable = '';

        $captured = null;
        $runner   = static function (array $command, string $stdoutFile) use (&$captured): array {
            $captured = $command;
            file_put_contents($stdoutFile, "CREATE DATABASE ospos;\n");

            return ['exitCode' => 0, 'stderr' => ''];
        };

        $result = (new BackupDump($config, $this->databaseConfig(), $runner, 'production'))->execute();

        $this->assertFileExists($result['path']);
        $this->assertIsArray($captured);
        $this->assertSame($fakeBin, $captured[0]);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob(rtrim($dir, '/\\') . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->removeDirectory($entry);
            } else {
                @unlink($entry);
            }
        }

        @rmdir($dir);
    }
}