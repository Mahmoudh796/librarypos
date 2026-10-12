<?php

namespace Tests\Libraries;

use App\Libraries\BackupReplica;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Backup;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for off-device backup replication.
 *
 * These use real temporary directories (never a network share) and injected
 * copier/renamer/sizer/hasher seams to exercise every failure path. The source
 * backup used here is a generated dummy file, never a real ospos dump.
 */
final class BackupReplicaTest extends CIUnitTestCase
{
    private string $root;
    private string $backupDir;
    private string $replicaDir;
    private string $sourcePath;
    private string $sourceContent;
    private string $sourceHash;
    private int $sourceSize;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root       = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'backup-replica-' . bin2hex(random_bytes(4));
        $this->backupDir  = $this->root . DIRECTORY_SEPARATOR . 'backups';
        $this->replicaDir = $this->root . DIRECTORY_SEPARATOR . 'replica';

        mkdir($this->backupDir, 0700, true);
        mkdir($this->replicaDir, 0700, true);

        $this->sourceContent = "-- MariaDB dump\nCREATE TABLE `ospos_items` (`id` int);\n";
        $this->sourcePath    = $this->backupDir . DIRECTORY_SEPARATOR . 'ospos_2026-10-12_120000_ab12.sql';
        file_put_contents($this->sourcePath, $this->sourceContent);

        $this->sourceSize = strlen($this->sourceContent);
        $this->sourceHash = hash('sha256', $this->sourceContent);
    }

    protected function tearDown(): void
    {
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
        $config             = new Backup();
        $config->backupDir  = $this->backupDir;
        $config->replicaDir = $replicaDir;

        return $config;
    }

    /**
     * @return array{path: string, size: int, sha256: string}
     */
    private function report(): array
    {
        return ['path' => $this->sourcePath, 'size' => $this->sourceSize, 'sha256' => $this->sourceHash];
    }

    private function replica(
        string $replicaDir,
        ?callable $copier = null,
        ?callable $renamer = null,
        ?callable $sizer = null,
        ?callable $hasher = null,
    ): BackupReplica {
        return new BackupReplica($this->config($replicaDir), $copier, $renamer, $sizer, $hasher);
    }

    private function replicaPath(): string
    {
        return $this->replicaDir . DIRECTORY_SEPARATOR . basename($this->sourcePath);
    }

    private function assertSourceUntouched(): void
    {
        $this->assertFileExists($this->sourcePath);
        $this->assertSame($this->sourceContent, file_get_contents($this->sourcePath));
        $this->assertSame($this->sourceHash, hash_file('sha256', $this->sourcePath));
    }

    public function testSuccessfulReplicationWritesPartThenRenamesAndVerifies(): void
    {
        $copyTargets = [];
        $copier      = function (string $src, string $dst) use (&$copyTargets): void {
            $copyTargets[] = $dst;
            copy($src, $dst);
        };

        $result = $this->replica($this->replicaDir, $copier)->replicate($this->report());

        $this->assertSame(BackupReplica::STATUS_COPIED, $result['status']);
        $this->assertSame($this->replicaPath(), $result['replicaPath']);
        $this->assertSame($this->sourceSize, $result['size']);
        $this->assertSame($this->sourceHash, $result['sha256']);

        // Copied to a .part file, never straight to the final name.
        $this->assertCount(1, $copyTargets);
        $this->assertSame($this->replicaPath() . '.part', $copyTargets[0]);

        // Final replica exists with byte-identical content and size + hash match.
        $this->assertFileExists($this->replicaPath());
        $this->assertSame($this->sourceContent, file_get_contents($this->replicaPath()));
        $this->assertSame($this->sourceSize, filesize($this->replicaPath()));
        $this->assertSame($this->sourceHash, hash_file('sha256', $this->replicaPath()));

        // No leftover part file.
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);

        $this->assertSourceUntouched();
    }

    public function testDefaultStreamCopierReplicatesExactlyAndLeavesSourceUnchanged(): void
    {
        $result = $this->replica($this->replicaDir)->replicate($this->report());

        $this->assertSame(BackupReplica::STATUS_COPIED, $result['status']);
        $this->assertSame($this->sourceContent, file_get_contents($this->replicaPath()));
        $this->assertSame($this->sourceHash, hash_file('sha256', $this->replicaPath()));
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);

        $this->assertSourceUntouched();
    }

    public function testReplicationDisabledWhenReplicaDirNotConfigured(): void
    {
        try {
            $this->replica('')->replicate($this->report());
            $this->fail('Expected a RuntimeException when replicaDir is not configured.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
        $this->assertSourceUntouched();
    }

    public function testReplicaDirEqualToBackupDirIsRejected(): void
    {
        try {
            $this->replica($this->backupDir)->replicate($this->report());
            $this->fail('Expected a RuntimeException when replicaDir equals backupDir.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('same directory', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testSelfReferentialReplicaPathResolvingToBackupDirIsRejected(): void
    {
        $selfReferential = $this->backupDir . DIRECTORY_SEPARATOR . '.';

        try {
            $this->replica($selfReferential)->replicate($this->report());
            $this->fail('Expected a RuntimeException for a self-referential destination.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('same directory', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
    }

    public function testCopyFailureCleansUpPartAndPreservesSource(): void
    {
        $copier = static function (string $src, string $dst): void {
            file_put_contents($dst, 'partial data');

            throw new RuntimeException('simulated disk full');
        };

        try {
            $this->replica($this->replicaDir, $copier)->replicate($this->report());
            $this->fail('Expected the copy failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated disk full', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->replicaPath());
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testHashMismatchOnPartFileCleansUp(): void
    {
        $hasher = function (string $path): string {
            return $path === $this->sourcePath ? $this->sourceHash : str_repeat('0', 64);
        };

        try {
            $this->replica($this->replicaDir, null, null, null, $hasher)->replicate($this->report());
            $this->fail('Expected a hash mismatch to fail replication.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->replicaPath());
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testSizeMismatchOnPartFileCleansUp(): void
    {
        $sizer = function (string $path): int {
            return $path === $this->sourcePath ? $this->sourceSize : $this->sourceSize + 1;
        };

        try {
            $this->replica($this->replicaDir, null, null, $sizer)->replicate($this->report());
            $this->fail('Expected a size mismatch to fail replication.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('size does not match', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->replicaPath());
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
    }

    public function testRenameFailureCleansUpPartFile(): void
    {
        $renamer = static fn (string $from, string $to): bool => false;

        try {
            $this->replica($this->replicaDir, null, $renamer)->replicate($this->report());
            $this->fail('Expected the rename failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not move the replica into place', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->replicaPath());
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testPostRenameVerificationFailureRemovesBadReplica(): void
    {
        $replicaPath = $this->replicaPath();
        $hasher      = static function (string $path) use ($replicaPath): string {
            if ($path === $replicaPath) {
                return str_repeat('f', 64);
            }

            return (string) hash_file('sha256', $path);
        };

        try {
            $this->replica($this->replicaDir, null, null, null, $hasher)->replicate($this->report());
            $this->fail('Expected post-rename verification to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('final replica', $e->getMessage());
        }

        $this->assertFileDoesNotExist($replicaPath);
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testExistingIdenticalReplicaIsIdempotent(): void
    {
        file_put_contents($this->replicaPath(), $this->sourceContent);

        $copierCalled = false;
        $copier       = static function () use (&$copierCalled): void {
            $copierCalled = true;

            throw new RuntimeException('copier must not run for an identical replica');
        };

        $result = $this->replica($this->replicaDir, $copier)->replicate($this->report());

        $this->assertSame(BackupReplica::STATUS_IDENTICAL, $result['status']);
        $this->assertFalse($copierCalled);
        $this->assertSame($this->sourceContent, file_get_contents($this->replicaPath()));
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testExistingDifferentReplicaFailsWithoutOverwrite(): void
    {
        file_put_contents($this->replicaPath(), 'A DIFFERENT BACKUP');

        try {
            $this->replica($this->replicaDir)->replicate($this->report());
            $this->fail('Expected a differing existing replica to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different replica', $e->getMessage());
        }

        $this->assertSame('A DIFFERENT BACKUP', file_get_contents($this->replicaPath()));
        $this->assertSame([], glob($this->replicaDir . '/*.part') ?: []);
    }

    public function testSourceOutsideBackupDirectoryIsRefused(): void
    {
        $elsewhere = $this->root . DIRECTORY_SEPARATOR . 'elsewhere';
        mkdir($elsewhere, 0700, true);
        $otherFile = $elsewhere . DIRECTORY_SEPARATOR . 'other.sql';
        file_put_contents($otherFile, 'not a real backup');

        $report = [
            'path'   => $otherFile,
            'size'   => filesize($otherFile),
            'sha256' => hash_file('sha256', $otherFile),
        ];

        try {
            $this->replica($this->replicaDir)->replicate($report);
            $this->fail('Expected a source outside the backup directory to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('configured backup directory', $e->getMessage());
        }

        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
    }

    public function testSourceThatDoesNotMatchItsReportIsRefused(): void
    {
        // Simulates a stale/failed report: the checksum no longer matches the
        // file, so it must never be treated as a verified backup.
        $report           = $this->report();
        $report['sha256'] = str_repeat('a', 64);

        try {
            $this->replica($this->replicaDir)->replicate($report);
            $this->fail('Expected a report that does not match the file to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source backup', $e->getMessage());
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }

        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
    }

    public function testMissingOrEmptySourceIsRefused(): void
    {
        $missing = [
            'path'   => $this->backupDir . DIRECTORY_SEPARATOR . 'missing.sql',
            'size'   => 5,
            'sha256' => str_repeat('a', 64),
        ];

        try {
            $this->replica($this->replicaDir)->replicate($missing);
            $this->fail('Expected a missing source to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('was not found', $e->getMessage());
        }

        $emptyPath = $this->backupDir . DIRECTORY_SEPARATOR . 'empty.sql';
        file_put_contents($emptyPath, '');
        $empty = ['path' => $emptyPath, 'size' => 0, 'sha256' => hash('sha256', '')];

        try {
            $this->replica($this->replicaDir)->replicate($empty);
            $this->fail('Expected an empty source to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertSame([], glob($this->replicaDir . '/*') ?: []);
    }

    public function testReplicaDirPointingAtAFileIsRefused(): void
    {
        $filePath = $this->root . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($filePath, 'x');

        try {
            $this->replica($filePath)->replicate($this->report());
            $this->fail('Expected a file used as replicaDir to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is a file', $e->getMessage());
        }

        $this->assertSourceUntouched();
    }

    public function testUnavailableReplicaDirFailsWithoutFallingBackToBackupDir(): void
    {
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'x');
        $unavailable = $blocker . DIRECTORY_SEPARATOR . 'child';

        try {
            $this->replica($unavailable)->replicate($this->report());
            $this->fail('Expected an uncreatable replicaDir to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not create or access the replica directory', $e->getMessage());
        }

        // No silent fallback into the local backup directory.
        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
        $this->assertSourceUntouched();
    }

    public function testWindowsAbsolutePathWithSpacesReplicates(): void
    {
        $spacedDir = $this->root . DIRECTORY_SEPARATOR . 'replica with spaces';
        mkdir($spacedDir, 0700, true);

        $result = $this->replica($spacedDir)->replicate($this->report());

        $expected = $spacedDir . DIRECTORY_SEPARATOR . basename($this->sourcePath);
        $this->assertSame($expected, $result['replicaPath']);
        $this->assertFileExists($expected);
        $this->assertStringContainsString(' ', $result['replicaPath']);
        $this->assertSourceUntouched();
    }

    public function testRelativeReplicaDirResolvesUnderWritableDirectory(): void
    {
        $relative = 'backup-replica-rel-' . bin2hex(random_bytes(4));
        $expected = realpath(WRITEPATH) . DIRECTORY_SEPARATOR . $relative;

        try {
            $result = $this->replica($relative)->replicate($this->report());

            $this->assertDirectoryExists($expected);
            $this->assertSame($expected, dirname($result['replicaPath']));
            $this->assertFileExists($result['replicaPath']);
        } finally {
            $this->removeDirectory($expected);
        }
    }

    public function testPathRecognitionCoversWindowsPosixAndUnc(): void
    {
        $method = new ReflectionMethod(BackupReplica::class, 'isAbsolutePath');

        $replica = $this->replica($this->replicaDir);

        $this->assertTrue($method->invoke($replica, 'C:\\Backups\\ospos'));
        $this->assertTrue($method->invoke($replica, 'C:/Backups/ospos'));
        $this->assertTrue($method->invoke($replica, '\\\\server\\share\\ospos'));
        $this->assertTrue($method->invoke($replica, '/mnt/backups'));
        $this->assertFalse($method->invoke($replica, 'backup-replica-relative'));
        $this->assertFalse($method->invoke($replica, 'backups\\ospos'));
    }
}
