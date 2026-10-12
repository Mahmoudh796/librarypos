<?php

namespace Tests\Libraries;

use App\Libraries\BackupRetention;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Tests for safe backup retention/pruning.
 *
 * All tests use real temporary directories and generated dummy files - never
 * the production database or real backups.
 */
final class BackupRetentionTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'backup-retention-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
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
            } elseif ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function makeDir(string $name): string
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . $name;
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * Writes a backup-shaped file `<db>_<stamp>.sql`.
     *
     * @param string $db     safe database name prefix
     * @param string $stamp  full timestamp+nonce, e.g. "2026-10-12_120000_0001"
     */
    private function makeBackup(string $dir, string $stamp, string $db = 'ospos'): string
    {
        $path = $dir . DIRECTORY_SEPARATOR . $db . '_' . $stamp . '.sql';
        file_put_contents($path, "-- dummy dump for {$db}_{$stamp}\n");

        return $path;
    }

    public function testZeroRetentionDisablesPruning(): void
    {
        $dir    = $this->makeDir('disabled');
        $newest = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $older  = $this->makeBackup($dir, '2026-10-10_120000_0002');

        $report = (new BackupRetention())->prune($dir, 0);

        $this->assertTrue($report['disabled']);
        $this->assertSame([], $report['deleted']);
        $this->assertFileExists($newest);
        $this->assertFileExists($older);
    }

    public function testPrunesOnlyTheOldestBeyondRetentionCount(): void
    {
        $dir    = $this->makeDir('basic');
        $newest = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $older  = $this->makeBackup($dir, '2026-10-10_120000_0002');
        $oldest = $this->makeBackup($dir, '2026-10-08_120000_0003');

        $report = (new BackupRetention())->prune($dir, 1);

        $this->assertFalse($report['disabled']);
        $this->assertSame(3, $report['candidates']);
        $this->assertSame([$newest], $report['kept']);
        $this->assertSame([$older, $oldest], $report['deleted']);
        $this->assertSame($newest, $report['newest']);
        $this->assertFileExists($newest);
        $this->assertFileDoesNotExist($older);
        $this->assertFileDoesNotExist($oldest);
    }

    public function testNewestBackupIsAlwaysKept(): void
    {
        $dir    = $this->makeDir('newest-kept');
        $newest = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $older  = $this->makeBackup($dir, '2026-10-10_120000_0002');

        $report = (new BackupRetention())->prune($dir, 1);

        $this->assertSame([$newest], $report['kept']);
        $this->assertSame([$older], $report['deleted']);
        $this->assertFileExists($newest);
        $this->assertFileDoesNotExist($older);
    }

    public function testSortsByEmbeddedTimestampNotByFilename(): void
    {
        $dir     = $this->makeDir('timestamp-sort');
        $newest  = $this->makeBackup($dir, '2026-10-12_120000_0001', 'a-ospos');
        $older   = $this->makeBackup($dir, '2026-10-10_120000_0002', 'z-ospos');

        $report = (new BackupRetention())->prune($dir, 1);

        $this->assertSame([$newest], $report['kept']);
        $this->assertSame([$older], $report['deleted']);
    }

    public function testDryRunRemovesNothingButNamesTheFiles(): void
    {
        $dir    = $this->makeDir('dry-run');
        $newest = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $older  = $this->makeBackup($dir, '2026-10-10_120000_0002');

        $report = (new BackupRetention())->prune($dir, 1, true);

        $this->assertTrue($report['dryRun']);
        $this->assertSame([$older], $report['deleted']);
        $this->assertFileExists($newest);
        $this->assertFileExists($older);
    }

    public function testNeverDeletesNonBackupFiles(): void
    {
        $dir = $this->makeDir('foreign');

        $newest       = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $envLike      = $dir . DIRECTORY_SEPARATOR . '.env';
        $readme       = $dir . DIRECTORY_SEPARATOR . 'README.md';
        $partFile     = $dir . DIRECTORY_SEPARATOR . 'ospos_2026-10-12_120000_0001.sql.part';
        $uppercaseHex = $dir . DIRECTORY_SEPARATOR . 'ospos_2026-10-10_120000_AB12.sql';
        $noNonce      = $dir . DIRECTORY_SEPARATOR . 'ospos_2026-10-10_120000.sql';
        $wrongExt     = $dir . DIRECTORY_SEPARATOR . 'ospos_2026-10-10_120000_0002.txt';
        $noTimestamp  = $dir . DIRECTORY_SEPARATOR . 'ospos_latest.sql';

        foreach ([$envLike, $readme, $partFile, $uppercaseHex, $noNonce, $wrongExt, $noTimestamp] as $path) {
            file_put_contents($path, 'keep me');
        }

        $report = (new BackupRetention())->prune($dir, 1);

        $this->assertSame([$newest], $report['kept']);
        $this->assertSame([], $report['deleted']);

        foreach ([$envLike, $readme, $partFile, $uppercaseHex, $noNonce, $wrongExt, $noTimestamp] as $path) {
            $this->assertFileExists($path, 'should never prune: ' . basename($path));
        }
    }

    public function testDoesNotTraverseIntoSubdirectories(): void
    {
        $dir       = $this->makeDir('no-traversal');
        $newest    = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $subDir    = $dir . DIRECTORY_SEPARATOR . 'sub';
        mkdir($subDir, 0700, true);
        $hidden    = $this->makeBackup($subDir, '2026-10-11_120000_0002');

        $report = (new BackupRetention())->prune($dir, 1);

        $this->assertSame(1, $report['candidates']);
        $this->assertSame([$newest], $report['kept']);
        $this->assertFileExists($hidden);
    }

    public function testPruningTheReplicaDirectoryDoesNotTouchTheLocalDirectory(): void
    {
        $localDir  = $this->makeDir('local');
        $replicaDir = $this->makeDir('replica');

        $localNewest  = $this->makeBackup($localDir, '2026-10-12_120000_0001', 'local');
        $localOlder   = $this->makeBackup($localDir, '2026-10-10_120000_0002', 'local');
        $replicaOlder = $this->makeBackup($replicaDir, '2026-10-10_120000_0002', 'replica');
        $replicaNew   = $this->makeBackup($replicaDir, '2026-10-12_120000_0001', 'replica');

        $retention = new BackupRetention();
        $report    = $retention->prune($replicaDir, 1);

        $this->assertSame([$replicaNew], $report['kept']);
        $this->assertSame([$replicaOlder], $report['deleted']);
        $this->assertFileDoesNotExist($replicaOlder);

        // The local directory is untouched by the replica prune.
        $this->assertFileExists($localNewest);
        $this->assertFileExists($localOlder);
    }

    public function testMissingDirectoryIsAnEmptyNoop(): void
    {
        $missing = $this->root . DIRECTORY_SEPARATOR . 'never-created';

        $report = (new BackupRetention())->prune($missing, 5, true);

        $this->assertSame(0, $report['candidates']);
        $this->assertSame([], $report['deleted']);
        $this->assertSame('', $report['newest']);
    }

    public function testFilePathInsteadOfDirectoryThrows(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'not-a-dir';
        file_put_contents($file, 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a directory');

        (new BackupRetention())->prune($file, 5);
    }

    public function testRemovalFailureSurfacesAsRetentionError(): void
    {
        $dir    = $this->makeDir('unlink-fails');
        $newest = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $older  = $this->makeBackup($dir, '2026-10-10_120000_0002');

        @chmod($dir, DIRECTORY_SEPARATOR === '\\' ? 0500 : 0555);

        try {
            if (@unlink($older) || ! is_file($older)) {
                $this->markTestSkipped('This platform allows deleting files in a read-only directory; cannot force a removal failure.');
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('could not remove');

            (new BackupRetention())->prune($dir, 1);
        } finally {
            @chmod($dir, 0700);
        }
    }

    public function testSymlinkedBackupFileIsIgnored(): void
    {
        $dir      = $this->makeDir('symlink');
        $newest   = $this->makeBackup($dir, '2026-10-12_120000_0001');
        $outside  = $this->root . DIRECTORY_SEPARATOR . 'outside-target.sql';
        file_put_contents($outside, 'do not touch');

        $link = $dir . DIRECTORY_SEPARATOR . 'ospos_2026-10-11_120000_0002.sql';

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('Cannot create symlinks on this platform (may require elevated privileges on Windows).');
        }

        $report = (new BackupRetention())->prune($dir, 1);

        $this->assertSame([$newest], $report['kept']);
        $this->assertSame([], $report['deleted']);
        $this->assertFileExists($outside, 'the symlink target must never be removed');
    }
}