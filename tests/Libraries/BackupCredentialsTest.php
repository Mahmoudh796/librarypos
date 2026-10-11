<?php

namespace Tests\Libraries;

use App\Libraries\BackupCredentials;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for the shared temporary `--defaults-extra-file` credential handling
 * used by both the dump and the scratch-restore verifier.
 */
final class BackupCredentialsTest extends CIUnitTestCase
{
    private const DB_PASSWORD = 's3cr3t-π-p@ss';

    protected function tearDown(): void
    {
        foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . '/db-backup-*.cnf') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testOptionFileContainsClientSectionAndPassword(): void
    {
        $path = BackupCredentials::writeOptionFile(self::DB_PASSWORD);

        try {
            $this->assertFileExists($path);
            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString('[client]', $contents);
            $this->assertStringContainsString('password=' . self::DB_PASSWORD, $contents);
        } finally {
            @unlink($path);
        }
    }

    public function testOptionFileIsRestrictedOnPosix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission assertion only applies on POSIX.');
        }

        $path = BackupCredentials::writeOptionFile(self::DB_PASSWORD);

        try {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        } finally {
            @unlink($path);
        }
    }

    public function testRedactReplacesSecretWithRedactedMarker(): void
    {
        $this->assertSame(
            'Access denied (using password: [REDACTED])',
            BackupCredentials::redact('Access denied (using password: s3cr3t)', 's3cr3t'),
        );
    }

    public function testRedactLeavesTextUntouchedWhenSecretIsEmpty(): void
    {
        $this->assertSame('plain text', BackupCredentials::redact('plain text', ''));
    }
}
