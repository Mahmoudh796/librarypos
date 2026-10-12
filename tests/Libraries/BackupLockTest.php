<?php

namespace Tests\Libraries;

use App\Libraries\BackupLock;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Tests for the cross-process execution lock used by `db:backup`.
 *
 * flock() conflicts between two separately opened file handles even inside the
 * same process (on both POSIX and Windows), so a second lock instance against
 * the same file deterministically represents an overlapping invocation without
 * needing real processes. No real database or backup file is involved.
 */
final class BackupLockTest extends CIUnitTestCase
{
    private string $root;
    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root     = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'backup-lock-' . bin2hex(random_bytes(4));
        $this->lockFile = $this->root . DIRECTORY_SEPARATOR . 'db-backup.lock';

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
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    public function testAcquireSucceedsAndCreatesTheLockFile(): void
    {
        $lock = new BackupLock($this->lockFile);

        $this->assertTrue($lock->acquire());
        $this->assertFileExists($this->lockFile);

        $lock->release();
    }

    public function testReleaseAllowsReacquisition(): void
    {
        $first = new BackupLock($this->lockFile);
        $first->acquire();
        $first->release();

        $second = new BackupLock($this->lockFile);
        $this->assertTrue($second->acquire());

        $second->release();
    }

    public function testSecondInvocationFailsClearlyWhileTheLockIsHeld(): void
    {
        $holder = new BackupLock($this->lockFile);
        $holder->acquire();

        try {
            $contender = new BackupLock($this->lockFile, 0);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already running');

            $contender->acquire();
        } finally {
            $holder->release();
        }
    }

    public function testContentionWaitIsBoundedAndThenFails(): void
    {
        $holder = new BackupLock($this->lockFile);
        $holder->acquire();

        try {
            $contender = new BackupLock($this->lockFile, 0.25);

            $started = microtime(true);

            try {
                $contender->acquire();

                $this->fail('A held lock must eventually fail the contender.');
            } catch (RuntimeException $e) {
                $elapsed = microtime(true) - $started;

                $this->assertStringContainsString('already running', $e->getMessage());
                // Bounded wait: a 0.25s budget must not turn into a long stall.
                $this->assertLessThan(2.0, $elapsed);
            }

            // The holder still owns the lock after the contender gave up.
            $recheck = new BackupLock($this->lockFile, 0);

            $this->expectException(RuntimeException::class);
            $recheck->acquire();
        } finally {
            $holder->release();
        }
    }

    public function testReleaseIsIdempotent(): void
    {
        $lock = new BackupLock($this->lockFile);
        $lock->acquire();

        $lock->release();
        $lock->release();
        $lock->release();

        $another = new BackupLock($this->lockFile);
        $this->assertTrue($another->acquire());

        $another->release();
    }

    public function testDefaultLockLocationIsUnderTheWritableDirectory(): void
    {
        $lock = new BackupLock();

        $this->assertSame(WRITEPATH . 'db-backup.lock', $lock->lockFile());
    }
}