<?php

namespace Tests\Commands;

use App\Commands\DbBackup;
use App\Libraries\BackupDump;
use App\Libraries\BackupLock;
use App\Libraries\BackupReplica;
use App\Libraries\BackupRestoreVerify;
use App\Libraries\BackupRetention;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use CodeIgniter\CLI\InputOutput;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Backup;
use Config\Database;

/**
 * Tests for the `db:backup` command surface.
 *
 * The dumper, restore verifier and replicator are injected with fakes (a fake
 * process runner, a fake sanity checker, a fake client) so no test touches a
 * real database; the dummy dump content is a generated file. CLI output is
 * captured through an injected InputOutput so nothing is written to STDOUT.
 */
final class DbBackupTest extends CIUnitTestCase
{
    private string $root;
    private string $backupDir;
    private string $replicaDir;
    private string $dumpExecutable;
    private string $clientExecutable;
    private string $originalPath;

    /**
     * @var object{output: string}
     */
    private object $io;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH') ?: '';

        $this->root       = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'db-backup-command-' . bin2hex(random_bytes(4));
        $this->backupDir  = $this->root . DIRECTORY_SEPARATOR . 'backups';
        $this->replicaDir = $this->root . DIRECTORY_SEPARATOR . 'replica';

        mkdir($this->backupDir, 0700, true);
        mkdir($this->replicaDir, 0700, true);

        $this->dumpExecutable = $this->root . DIRECTORY_SEPARATOR . 'fake-dump.exe';
        file_put_contents($this->dumpExecutable, '');
        $this->clientExecutable = $this->root . DIRECTORY_SEPARATOR . 'mariadb.exe';
        file_put_contents($this->clientExecutable, '');

        $this->io = new class extends InputOutput {
            public string $output = '';

            public function fwrite($handle, string $string): void
            {
                $this->output .= $string;
            }
        };

        CLI::setInputOutput($this->io);
        CLI::resetLastWrite();
    }

    protected function tearDown(): void
    {
        CLI::resetInputOutput();
        putenv('PATH=' . $this->originalPath);
        $this->removeDirectory($this->root);

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

    private function config(string $replicaDir, int $retentionCount = 0): Backup
    {
        $config                   = new Backup();
        $config->backupDir        = $this->backupDir;
        $config->replicaDir       = $replicaDir;
        $config->dumpExecutable   = $this->dumpExecutable;
        $config->clientExecutable = $this->clientExecutable;
        $config->retentionCount   = $retentionCount;

        return $config;
    }

    private function databaseConfig(): Database
    {
        $db                        = new Database();
        $db->defaultGroup          = 'default';
        $db->default['hostname']   = 'localhost';
        $db->default['port']       = 3306;
        $db->default['username']   = 'backup_user';
        $db->default['password']   = 's3cr3t';
        $db->default['database']   = 'ospos';

        return $db;
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        return [
            'hostname' => 'localhost',
            'port'     => 3306,
            'username' => 'backup_user',
            'password' => 's3cr3t',
            'database' => 'ospos',
            'DBDriver' => 'MySQLi',
            'DBPrefix' => 'ospos_',
        ];
    }

    private function dumper(Backup $config, int $exit = 0): BackupDump
    {
        $runner = static function (array $command, string $stdoutFile) use ($exit): array {
            if ($exit === 0) {
                file_put_contents($stdoutFile, "CREATE TABLE `ospos_items` (`id` int);\n");
            }

            return ['exitCode' => $exit, 'stderr' => $exit === 0 ? '' : 'dump failed'];
        };

        return new BackupDump($config, $this->databaseConfig(), $runner, 'production');
    }

    private function verifier(Backup $config, int $restoreExit = 0): BackupRestoreVerify
    {
        $runner = new class ($restoreExit) {
            public function __construct(private int $restoreExit)
            {
            }

            public function __invoke(array $command, ?string $stdinFile, ?string $stdoutFile): array
            {
                $last = (string) end($command);

                if (str_starts_with($last, '--execute=CREATE DATABASE') || str_starts_with($last, '--execute=DROP DATABASE')) {
                    return ['exitCode' => 0, 'stderr' => ''];
                }

                return ['exitCode' => $this->restoreExit, 'stderr' => $this->restoreExit === 0 ? '' : 'restore failed'];
            }
        };

        return new BackupRestoreVerify(
            $config,
            $this->connection(),
            $runner,
            static function (): void {
            },
            'production',
            static fn (): string => 'ospos_backup_verify_20261012_120000_a1b2c3d4',
        );
    }

    private function command(
        Backup $config,
        ?BackupDump $dumper = null,
        ?BackupRestoreVerify $verifier = null,
        ?BackupReplica $replicator = null,
        ?BackupRetention $retention = null,
        ?BackupLock $lock = null,
    ): DbBackup {
        return new DbBackup(
            service('logger'),
            new Commands(service('logger')),
            $dumper ?? $this->dumper($config),
            $verifier,
            $replicator ?? new BackupReplica($config),
            $retention ?? new BackupRetention(),
            $lock ?? new BackupLock($this->root . DIRECTORY_SEPARATOR . 'backup.lock'),
            $config,
        );
    }

    public function testRejectsPositionalArgumentsWithoutTouchingAnything(): void
    {
        $command = $this->command($this->config($this->replicaDir));

        $exit = $command->run(['C:\\some\\supplied\\source.sql', 'D:\\some\\supplied\\destination']);

        $this->assertSame(EXIT_USER_INPUT, $exit);
        $this->assertStringContainsString('does not accept positional arguments', $this->io->output);
        $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
    }

    public function testReplicateWithoutConfiguredReplicaDirFailsButKeepsLocalBackup(): void
    {
        $command = $this->command($this->config(''));

        $exit = $command->run(['replicate' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('DUMP VERIFIED', $this->io->output);
        $this->assertStringContainsString('Replication failed', $this->io->output);
        $this->assertStringContainsString('not configured', $this->io->output);
        $this->assertStringNotContainsString('REPLICA VERIFIED', $this->io->output);

        $local = glob($this->backupDir . '/*.sql') ?: [];
        $this->assertCount(1, $local);
        $this->assertStringContainsString(basename($local[0]), $this->io->output);
    }

    public function testReplicationFailureReturnsNonZeroAndPreservesLocalBackup(): void
    {
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'x');

        $command = $this->command($this->config($blocker . DIRECTORY_SEPARATOR . 'child'));

        $exit = $command->run(['replicate' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Replication failed', $this->io->output);
        $this->assertStringContainsString('still valid and was left untouched', $this->io->output);
        $this->assertStringNotContainsString('REPLICA VERIFIED', $this->io->output);

        $this->assertCount(1, glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testSuccessfulReplicationReportsReplicaVerified(): void
    {
        $command = $this->command($this->config($this->replicaDir));

        $exit = $command->run(['replicate' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('DUMP VERIFIED', $this->io->output);
        $this->assertStringContainsString('REPLICA VERIFIED', $this->io->output);

        $local = glob($this->backupDir . '/*.sql') ?: [];
        $this->assertCount(1, $local);

        $replica = $this->replicaDir . DIRECTORY_SEPARATOR . basename($local[0]);
        $this->assertFileExists($replica);
        $this->assertSame(file_get_contents($local[0]), file_get_contents($replica));
    }

    public function testVerifyRestoreAndReplicateReportAllThreeStagesInOrder(): void
    {
        $config  = $this->config($this->replicaDir);
        $command = $this->command($config, $this->dumper($config), $this->verifier($config), new BackupReplica($config));

        $exit = $command->run(['verify-restore' => true, 'replicate' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);

        $dumpPos    = strpos($this->io->output, 'DUMP VERIFIED');
        $restorePos = strpos($this->io->output, 'RESTORE VERIFIED');
        $replicaPos = strpos($this->io->output, 'REPLICA VERIFIED');

        $this->assertIsInt($dumpPos);
        $this->assertIsInt($restorePos);
        $this->assertIsInt($replicaPos);
        $this->assertTrue($dumpPos < $restorePos, 'DUMP VERIFIED should precede RESTORE VERIFIED.');
        $this->assertTrue($restorePos < $replicaPos, 'RESTORE VERIFIED should precede REPLICA VERIFIED.');
    }

    public function testFailedDumpIsNeverReplicated(): void
    {
        $config  = $this->config($this->replicaDir);
        $command = $this->command($config, $this->dumper($config, exit: 1));

        $exit = $command->run(['replicate' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Backup failed', $this->io->output);
        $this->assertStringNotContainsString('DUMP VERIFIED', $this->io->output);
        $this->assertStringNotContainsString('REPLICA VERIFIED', $this->io->output);
        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
    }

    public function testFailedRestoreVerificationIsNeverReplicated(): void
    {
        $config  = $this->config($this->replicaDir);
        $command = $this->command($config, $this->dumper($config), $this->verifier($config, restoreExit: 1), new BackupReplica($config));

        $exit = $command->run(['verify-restore' => true, 'replicate' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('DUMP VERIFIED', $this->io->output);
        $this->assertStringContainsString('Restore verification failed', $this->io->output);
        $this->assertStringNotContainsString('RESTORE VERIFIED', $this->io->output);
        $this->assertStringNotContainsString('REPLICA VERIFIED', $this->io->output);
        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
    }

    public function testPruneRemovesOnlyBackupsOlderThanRetentionCount(): void
    {
        $older = $this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2020-01-01_000000_aaaa.sql';
        file_put_contents($older, 'old dump');

        $command = $this->command($this->config($this->replicaDir, retentionCount: 1));

        $exit = $command->run(['prune' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('DUMP VERIFIED', $this->io->output);
        $this->assertStringContainsString('RETENTION APPLIED', $this->io->output);
        $this->assertFileDoesNotExist($older);
        $this->assertCount(1, glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testPruneDryRunRemovesNothing(): void
    {
        $older = $this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2020-01-01_000000_aaaa.sql';
        file_put_contents($older, 'old dump');

        $command = $this->command($this->config($this->replicaDir, retentionCount: 1));

        $exit = $command->run(['prune' => true, 'dry-run' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('RETENTION DRY-RUN', $this->io->output);
        $this->assertFileExists($older);
        $this->assertCount(2, glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testPruneWithoutConfiguredRetentionCountFailsButKeepsLocalBackup(): void
    {
        $command = $this->command($this->config($this->replicaDir, retentionCount: 0));

        $exit = $command->run(['prune' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Retention failed', $this->io->output);
        $this->assertStringContainsString('retentionCount', $this->io->output);
        $this->assertStringContainsString('still valid', $this->io->output);
        $this->assertStringNotContainsString('RETENTION APPLIED', $this->io->output);
        $this->assertCount(1, glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testPruneAppliesToReplicaDirectoryAndNeverEndangersLocal(): void
    {
        $command = $this->command($this->config($this->replicaDir, retentionCount: 1));

        $exit = $command->run(['replicate' => true, 'prune' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('REPLICA VERIFIED', $this->io->output);
        $this->assertStringContainsString('RETENTION APPLIED', $this->io->output);

        $local = glob($this->backupDir . '/*.sql') ?: [];
        $this->assertCount(1, $local);

        $replicas = glob($this->replicaDir . '/*.sql') ?: [];
        $this->assertCount(1, $replicas);
        $this->assertSame(basename($local[0]), basename($replicas[0]));
    }

    public function testPruneWithoutReplicateStillPrunesConfiguredReplicaDirectory(): void
    {
        $replicaNewest = $this->replicaDir . DIRECTORY_SEPARATOR . 'ospos_2026-10-12_120000_0001.sql';
        $replicaOlder  = $this->replicaDir . DIRECTORY_SEPARATOR . 'ospos_2020-01-01_000000_aaaa.sql';
        file_put_contents($replicaNewest, 'newest replica');
        file_put_contents($replicaOlder, 'old replica');

        $command = $this->command($this->config($this->replicaDir, retentionCount: 1));

        $exit = $command->run(['prune' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('RETENTION APPLIED', $this->io->output);
        $this->assertFileExists($replicaNewest);
        $this->assertFileDoesNotExist($replicaOlder);
        $this->assertStringNotContainsString('REPLICA VERIFIED', $this->io->output);
    }

    public function testPruneSkipsReplicaWhenReplicaDirIsNotConfigured(): void
    {
        $command = $this->command($this->config('', retentionCount: 1));

        $exit = $command->run(['prune' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('replica pruning skipped', $this->io->output);
        $this->assertStringContainsString('RETENTION APPLIED', $this->io->output);
    }

    public function testRetentionFailureReturnsNonZeroAndPreservesLocalBackup(): void
    {
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'replica-blocker';
        file_put_contents($blocker, 'x');

        $command = $this->command($this->config($blocker, retentionCount: 1));

        $exit = $command->run(['prune' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Retention failed', $this->io->output);
        $this->assertStringContainsString('not a directory', $this->io->output);
        $this->assertStringContainsString('still valid', $this->io->output);
        $this->assertCount(1, glob($this->backupDir . '/*.sql') ?: []);
    }

    public function testCommandFailsClearlyWhileAnotherBackupHoldsTheLock(): void
    {
        $lockPath = $this->root . DIRECTORY_SEPARATOR . 'backup.lock';
        $holder   = new BackupLock($lockPath, 0);
        $holder->acquire();

        try {
            $command = $this->command($this->config($this->replicaDir), null, null, null, null, new BackupLock($lockPath, 0));

            $exit = $command->run([]);

            $this->assertSame(EXIT_ERROR, $exit);
            $this->assertStringContainsString('Backup lock unavailable', $this->io->output);
            $this->assertStringContainsString('already running', $this->io->output);
            $this->assertStringNotContainsString('DUMP VERIFIED', $this->io->output);
            $this->assertSame([], glob($this->backupDir . '/*.sql') ?: []);
        } finally {
            $holder->release();
        }
    }

    public function testOutputIncludesStartAndFinishTimestamps(): void
    {
        $command = $this->command($this->config($this->replicaDir));

        $exit = $command->run([]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('Backup started at ', $this->io->output);
        $this->assertStringContainsString('Backup finished at ', $this->io->output);
    }

    public function testLockIsReleasedAfterASuccessfulRun(): void
    {
        $lockPath = $this->root . DIRECTORY_SEPARATOR . 'backup.lock';
        $command  = $this->command($this->config($this->replicaDir), null, null, null, null, new BackupLock($lockPath, 0));

        $this->assertSame(EXIT_SUCCESS, $command->run([]));

        // The injected lock was released in a finally, so a fresh lock can immediately be taken.
        $recheck = new BackupLock($lockPath, 0);
        $this->assertTrue($recheck->acquire());

        $recheck->release();
    }

    public function testFullWorkflowVerifyRestoreReplicatePruneReportsEveryStageInOrder(): void
    {
        foreach (['ospos_2020-01-01_000000_0001.sql', 'ospos_2021-01-01_000000_0002.sql', 'ospos_2022-01-01_000000_0003.sql'] as $name) {
            file_put_contents($this->backupDir . DIRECTORY_SEPARATOR . $name, 'old local contents');
        }

        foreach (['ospos_2020-01-01_000000_0001.sql', 'ospos_2021-01-01_000000_0002.sql'] as $name) {
            file_put_contents($this->replicaDir . DIRECTORY_SEPARATOR . $name, 'old replica contents');
        }

        $config  = $this->config($this->replicaDir, retentionCount: 2);
        $command = $this->command($config, $this->dumper($config), $this->verifier($config), new BackupReplica($config));

        $exit = $command->run(['verify-restore' => true, 'replicate' => true, 'prune' => true]);

        $this->assertSame(EXIT_SUCCESS, $exit);

        $dumpPos    = strpos($this->io->output, 'DUMP VERIFIED');
        $restorePos = strpos($this->io->output, 'RESTORE VERIFIED');
        $replicaPos = strpos($this->io->output, 'REPLICA VERIFIED');
        $retentionPos = strpos($this->io->output, 'RETENTION APPLIED');

        $this->assertIsInt($dumpPos);
        $this->assertIsInt($restorePos);
        $this->assertIsInt($replicaPos);
        $this->assertIsInt($retentionPos);
        $this->assertTrue($dumpPos < $restorePos && $restorePos < $replicaPos && $replicaPos < $retentionPos, 'Verification markers must appear in stage order.');

        // Local: 3 seeded + 1 new; retention 2 keeps the new dump and the 2022 seed.
        $local = glob($this->backupDir . '/*.sql') ?: [];
        $this->assertCount(2, $local);
        $this->assertFileDoesNotExist($this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2020-01-01_000000_0001.sql');
        $this->assertFileDoesNotExist($this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2021-01-01_000000_0002.sql');
        $this->assertFileExists($this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2022-01-01_000000_0003.sql');

        // Replica: 2 seeded + new replica; retention 2 keeps the new replica and the 2021 seed.
        $replicas = glob($this->replicaDir . '/*.sql') ?: [];
        $this->assertCount(2, $replicas);
        $this->assertFileDoesNotExist($this->replicaDir . DIRECTORY_SEPARATOR . 'ospos_2020-01-01_000000_0001.sql');
        $this->assertFileExists($this->replicaDir . DIRECTORY_SEPARATOR . 'ospos_2021-01-01_000000_0002.sql');
    }

    public function testPruneDoesNotRunWhenAnEarlierStageFails(): void
    {
        $older = $this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2020-01-01_000000_aaaa.sql';
        file_put_contents($older, 'old dump');

        $blocker = $this->root . DIRECTORY_SEPARATOR . 'replica-blocker';
        file_put_contents($blocker, 'x');

        $command = $this->command($this->config($blocker, retentionCount: 5));

        $exit = $command->run(['replicate' => true, 'prune' => true]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Replication failed', $this->io->output);
        $this->assertStringNotContainsString('RETENTION APPLIED', $this->io->output);
        $this->assertStringNotContainsString('RETENTION DRY-RUN', $this->io->output);

        // Retention never ran, so the seeded old local backup is still there.
        $this->assertFileExists($older);
    }
}
