<?php

namespace Tests\Commands;

use App\Commands\DbBackup;
use App\Libraries\BackupDump;
use App\Libraries\BackupReplica;
use App\Libraries\BackupRestoreVerify;
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

    private function config(string $replicaDir): Backup
    {
        $config                   = new Backup();
        $config->backupDir        = $this->backupDir;
        $config->replicaDir       = $replicaDir;
        $config->dumpExecutable   = $this->dumpExecutable;
        $config->clientExecutable = $this->clientExecutable;

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
    ): DbBackup {
        return new DbBackup(
            service('logger'),
            new Commands(service('logger')),
            $dumper ?? $this->dumper($config),
            $verifier,
            $replicator ?? new BackupReplica($config),
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
}
