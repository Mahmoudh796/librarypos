<?php

namespace Tests\Libraries;

use App\Libraries\BackupRestoreVerify;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Backup;
use RuntimeException;

/**
 * Tests for the guarded scratch-restore verification.
 *
 * The external process runner, the sanity checker and the scratch-name
 * generator are all injected, so these tests never create/drop/restore against
 * a real database and never touch the ospos or ospos_test databases.
 */
final class BackupRestoreVerifyTest extends CIUnitTestCase
{
    private const DB_PASSWORD = 's3cr3t-π-p@ss';

    private const DUMP_SQL = "-- MariaDB dump 10.6 distro 1-10.6.12\n"
        . "CREATE TABLE `ospos_app_config` (\n  `key` varchar(255) NOT NULL\n) ENGINE=InnoDB;\n"
        . "INSERT INTO `ospos_app_config` VALUES ('version','3.4.2');\n"
        . "CREATE TABLE `ospos_people` (\n  `person_id` int(11) NOT NULL\n) ENGINE=InnoDB;\n"
        . "CREATE TABLE `ospos_employees` (\n  `username` varchar(255) DEFAULT NULL\n) ENGINE=InnoDB;\n"
        . "CREATE TABLE `ospos_sales` (\n  `sale_id` int(11) NOT NULL\n) ENGINE=InnoDB;\n";

    private string $backupDir;
    private string $clientExecutable;
    private string $dumpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'backup-verify-' . bin2hex(random_bytes(4));
        mkdir($this->backupDir, 0700, true);

        $this->clientExecutable = $this->backupDir . DIRECTORY_SEPARATOR . 'mariadb.exe';
        file_put_contents($this->clientExecutable, "# fake client\n");

        $this->dumpPath = $this->backupDir . DIRECTORY_SEPARATOR . 'ospos_dump.sql';
        file_put_contents($this->dumpPath, self::DUMP_SQL);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->backupDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \FilesystemIterator($dir);

        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * @return object{calls: array<int, array{command: array<int, string>, stdinFile: ?string, stdoutFile: ?string}>}
     */
    private function fakeRunner(int $createExit = 0, int $restoreExit = 0, int $dropExit = 0, string $restoreStderr = ''): object
    {
        return new class ($createExit, $restoreExit, $dropExit, $restoreStderr) {
            public array $calls = [];
            private int $createExit;
            private int $restoreExit;
            private int $dropExit;
            private string $restoreStderr;

            public function __construct(int $createExit, int $restoreExit, int $dropExit, string $restoreStderr)
            {
                $this->createExit     = $createExit;
                $this->restoreExit    = $restoreExit;
                $this->restoreStderr  = $restoreStderr;
                $this->dropExit       = $dropExit;
            }

            public function __invoke(array $command, ?string $stdinFile, ?string $stdoutFile): array
            {
                $this->calls[] = ['command' => $command, 'stdinFile' => $stdinFile, 'stdoutFile' => $stdoutFile];
                $last         = (string) end($command);

                if (str_starts_with($last, '--execute=CREATE DATABASE')) {
                    return ['exitCode' => $this->createExit, 'stderr' => ''];
                }

                if (str_starts_with($last, '--execute=DROP DATABASE')) {
                    return ['exitCode' => $this->dropExit, 'stderr' => ''];
                }

                return ['exitCode' => $this->restoreExit, 'stderr' => $this->restoreStderr];
            }
        };
    }

    /**
     * @param array{dbName: string, connection: array<string, mixed>, criticalTables: array<int, string>, expectedTables: array<int, string>}|null $seen
     *
     * @return callable(string, array<string, mixed>, array<int, string>, array<int, string>): void
     */
    private function passingDbChecker(?array &$seen = null): callable
    {
        return static function (string $dbName, array $connection, array $criticalTables, array $expectedTables) use (&$seen): void {
            $seen = [
                'dbName'         => $dbName,
                'connection'     => $connection,
                'criticalTables' => $criticalTables,
                'expectedTables' => $expectedTables,
            ];
        };
    }

    private function config(): Backup
    {
        $config = new Backup();
        $config->clientExecutable = $this->clientExecutable;

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(string $databaseName = 'ospos'): array
    {
        return [
            'hostname' => 'localhost',
            'port'     => 3306,
            'username' => 'backup_user',
            'password' => self::DB_PASSWORD,
            'database' => $databaseName,
            'DBDriver' => 'MySQLi',
            'DBPrefix' => 'ospos_',
            'charset'  => 'utf8mb4',
            'DBCollat' => 'utf8mb4_general_ci',
        ];
    }

    private function validScratchName(): string
    {
        return 'ospos_backup_verify_20261011_184500_1a2b3c4d';
    }

    /**
     * @param array<string, mixed>|null $connection
     */
    private function verifier(
        callable $processRunner,
        ?callable $dbChecker = null,
        ?array $connection = null,
        ?string $environment = 'production',
        ?callable $nameGenerator = null,
    ): BackupRestoreVerify {
        return new BackupRestoreVerify(
            $this->config(),
            $connection ?? $this->connection(),
            $processRunner,
            $dbChecker ?? $this->passingDbChecker(),
            $environment,
            $nameGenerator ?? $this->validScratchName(...),
        );
    }

    private function assertNoCredentialsFilesLeft(): void
    {
        $this->assertSame([], glob(rtrim(sys_get_temp_dir(), '/\\') . '/db-backup-*.cnf') ?: []);
    }

    public function testSuccessfulScratchRestoreVerificationAndCleanup(): void
    {
        $runner = $this->fakeRunner();
        $seen   = null;

        $result = $this->verifier($runner, $this->passingDbChecker($seen))->verify($this->dumpPath);

        $this->assertSame($this->validScratchName(), $result['scratchDatabase']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $result['checkedAt']);

        // Stage order: create, restore (dump via stdin), drop.
        $this->assertCount(3, $runner->calls);

        $create = $runner->calls[0]['command'];
        $this->assertStringStartsWith('--execute=CREATE DATABASE `' . $this->validScratchName() . '`', $create[array_key_last($create)]);
        $this->assertNull($runner->calls[0]['stdinFile']);

        $restore = $runner->calls[1]['command'];
        $this->assertSame($this->validScratchName(), $restore[array_key_last($restore)]);
        $this->assertSame($this->dumpPath, $runner->calls[1]['stdinFile']);

        // Cleanup after successful verification drops exactly the scratch db.
        $drop = $runner->calls[2]['command'];
        $this->assertStringStartsWith('--execute=DROP DATABASE `' . $this->validScratchName() . '`', $drop[array_key_last($drop)]);
        $this->assertStringNotContainsString('USE ', implode(' ', $drop));

        // The sanity checker was pointed at the scratch database only.
        $this->assertSame($this->validScratchName(), $seen['dbName']);
        $this->assertNotSame('ospos', $seen['dbName']);
        $this->assertNotSame('ospos_test', $seen['dbName']);

        // Integrity reference comes from the dump itself.
        $this->assertSame(['ospos_app_config', 'ospos_people', 'ospos_employees', 'ospos_sales'], $seen['expectedTables']);
        $this->assertContains('ospos_app_config', $seen['criticalTables']);
        $this->assertContains('ospos_items', $seen['criticalTables']);

        $this->assertNoCredentialsFilesLeft();
    }

    public function testRefusesTargetEqualToConfiguredDefaultDatabase(): void
    {
        $runner     = $this->fakeRunner();
        $connection = $this->connection($this->validScratchName());

        try {
            $this->verifier($runner, connection: $connection)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException for a target equal to the default database.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('equals the configured default database', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }

    public function testRefusesTargetNamedOspos(): void
    {
        $runner = $this->fakeRunner();

        try {
            $this->verifier(
                $runner,
                connection: $this->connection('mystore'),
                nameGenerator: static fn (): string => 'ospos',
            )->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException for a target named ospos.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('protected database name', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }

    public function testRefusesTargetNamedOsposTest(): void
    {
        $runner = $this->fakeRunner();

        try {
            $this->verifier(
                $runner,
                connection: $this->connection('mystore'),
                nameGenerator: static fn (): string => 'ospos_test',
            )->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException for a target named ospos_test.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('protected database name', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }

    public function testRefusesTargetNotMatchingScratchConvention(): void
    {
        $runner = $this->fakeRunner();

        try {
            $this->verifier(
                $runner,
                nameGenerator: static fn (): string => 'scratch_db_123',
            )->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException for a non-conforming scratch name.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('naming convention', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }

    public function testCreateFailureFailsBeforeAnyRestoreOrDrop(): void
    {
        $runner = $this->fakeRunner(createExit: 1);

        try {
            $this->verifier($runner)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException when creating the scratch database fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not create scratch verification database', $e->getMessage());
            $this->assertStringContainsString($this->validScratchName(), $e->getMessage());
        }

        // Nothing was created, so nothing may be dropped either.
        $this->assertCount(1, $runner->calls);
        $this->assertNoCredentialsFilesLeft();
    }

    public function testRestoreFailureFailsVerificationCleansUpAndRedactsSecret(): void
    {
        $runner = $this->fakeRunner(
            restoreExit: 3,
            restoreStderr: 'ERROR 1146 (42S02): Table does not exist (using password: ' . self::DB_PASSWORD . ')',
        );

        try {
            $this->verifier($runner)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException when the restore process fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Restore into scratch verification database', $e->getMessage());
            $this->assertStringContainsString('exit 3', $e->getMessage());
            $this->assertStringContainsString($this->validScratchName(), $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
            $this->assertStringNotContainsString(self::DB_PASSWORD, $e->getMessage());
        }

        // Cleanup after failed verification still drops exactly the scratch db.
        $this->assertCount(3, $runner->calls);
        $last    = end($runner->calls);
        $lastCmd = $last['command'];
        $this->assertStringStartsWith('--execute=DROP DATABASE `' . $this->validScratchName() . '`', $lastCmd[array_key_last($lastCmd)]);
        $this->assertNoCredentialsFilesLeft();
    }

    public function testMissingCriticalTableFailsVerificationAndCleansUp(): void
    {
        $runner = $this->fakeRunner();
        $dbChecker = static function (): void {
            throw new RuntimeException('Sanity check failed: critical table "ospos_items" is missing from the restored database.');
        };

        try {
            $this->verifier($runner, $dbChecker)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException for a missing critical table.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('critical table "ospos_items" is missing', $e->getMessage());
        }

        // create, restore and (failed) sanity all happened; cleanup still drops.
        $this->assertCount(3, $runner->calls);
        $last    = end($runner->calls);
        $lastCmd = $last['command'];
        $this->assertStringStartsWith('--execute=DROP DATABASE `' . $this->validScratchName() . '`', $lastCmd[array_key_last($lastCmd)]);
    }

    public function testFailedSanityQueryFailsVerificationAndCleansUp(): void
    {
        $runner    = $this->fakeRunner();
        $dbChecker = static function (string $dbName): void {
            throw new RuntimeException('Sanity check failed: query against "' . $dbName . '" could not be executed.');
        };

        try {
            $this->verifier($runner, $dbChecker)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException when a sanity query fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Sanity check failed', $e->getMessage());
            $this->assertStringContainsString($this->validScratchName(), $e->getMessage());
        }

        $this->assertCount(3, $runner->calls);
        $this->assertNoCredentialsFilesLeft();
    }

    public function testCleanupFailureCausesOverallFailureAndNamesTheScratchDatabase(): void
    {
        $runner = $this->fakeRunner(dropExit: 1);

        try {
            $this->verifier($runner)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException when dropping the scratch database fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cleanup FAILED', $e->getMessage());
            $this->assertStringContainsString($this->validScratchName(), $e->getMessage());
        }

        $this->assertCount(3, $runner->calls);
    }

    public function testCleanupFailureOnFailedVerificationReportsBothFailures(): void
    {
        $runner = $this->fakeRunner(restoreExit: 2, dropExit: 1);

        try {
            $this->verifier($runner)->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException when restore and cleanup both fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Restore into scratch verification database', $e->getMessage());
            $this->assertStringContainsString('Cleanup FAILED', $e->getMessage());
            $this->assertStringContainsString($this->validScratchName(), $e->getMessage());
        }
    }

    public function testPasswordNeverAppearsInAnyProcessCommand(): void
    {
        $runner = $this->fakeRunner(restoreStderr: 'generic mysql error text');

        $this->verifier($runner)->verify($this->dumpPath);

        $this->assertCount(3, $runner->calls);

        foreach ($runner->calls as $call) {
            $this->assertStringNotContainsString(self::DB_PASSWORD, implode(' ', $call['command']));
        }
    }

    public function testConfiguredDatabaseIsNeverTargetedOrConnected(): void
    {
        $connection = $this->connection('mystore');
        $runner     = $this->fakeRunner();
        $seen       = null;

        $this->verifier($runner, $this->passingDbChecker($seen), $connection)->verify($this->dumpPath);

        $this->assertSame($this->validScratchName(), $seen['dbName']);

        // The configured database name must not appear in any client command,
        // and the sanity checker never received it.
        foreach ($runner->calls as $call) {
            $this->assertStringNotContainsString('mystore', implode(' ', $call['command']));
        }
    }

    public function testRestoreVerifiedOnlyWhenEveryStageSucceeds(): void
    {
        $runtimeFailures = [
            'create fails'  => $this->fakeRunner(createExit: 7),
            'restore fails' => $this->fakeRunner(restoreExit: 7),
            'drop fails'    => $this->fakeRunner(dropExit: 7),
        ];

        foreach ($runtimeFailures as $label => $runner) {
            try {
                $this->verifier($runner)->verify($this->dumpPath);
                $this->fail('Expected verification to fail when ' . $label . '.');
            } catch (RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $throwingChecker = static function (): void {
            throw new RuntimeException('Sanity check failed.');
        };

        try {
            $this->verifier($this->fakeRunner(), $throwingChecker)->verify($this->dumpPath);
            $this->fail('Expected verification to fail when sanity checks fail.');
        } catch (RuntimeException $e) {
            $this->addToAssertionCount(1);
        }

        // When every stage succeeds the report is returned instead of throwing.
        $report = $this->verifier($this->fakeRunner())->verify($this->dumpPath);
        $this->assertSame($this->validScratchName(), $report['scratchDatabase']);
    }

    public function testRefusesInTestingEnvironment(): void
    {
        $runner = $this->fakeRunner();

        try {
            $this->verifier($runner, environment: 'testing')->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException in the testing environment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('testing', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }

    public function testRefusesWhenConfiguredDatabaseLooksLikeATestDatabase(): void
    {
        $runner = $this->fakeRunner();

        try {
            $this->verifier($runner, connection: $this->connection('ospos_test'))->verify($this->dumpPath);
            $this->fail('Expected a RuntimeException for a test-looking configured database.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('looks like a test database', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }

    public function testRefusesMissingOrEmptyDumpFile(): void
    {
        $runner = $this->fakeRunner();

        try {
            $this->verifier($runner)->verify($this->backupDir . '/missing.sql');
            $this->fail('Expected a RuntimeException for a missing dump file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Dump file not found', $e->getMessage());
        }

        $empty = $this->backupDir . '/empty.sql';
        file_put_contents($empty, '');

        try {
            $this->verifier($runner)->verify($empty);
            $this->fail('Expected a RuntimeException for an empty dump file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Dump file is empty', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
    }
}
