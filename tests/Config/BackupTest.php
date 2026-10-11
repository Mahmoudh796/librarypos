<?php

namespace Tests\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Backup;

/**
 * Tests for Config\Backup, including the BaseConfig env-override convention
 * used by `php spark db:backup` (`backup.backupDir`, `backup.dumpExecutable`,
 * `backup.clientExecutable`).
 */
final class BackupTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEnvKeys();
    }

    protected function tearDown(): void
    {
        $this->clearEnvKeys();

        parent::tearDown();
    }

    private function clearEnvKeys(): void
    {
        foreach ([
            'backup.backupDir',
            'backup.dumpExecutable',
            'backup.clientExecutable',
            'backup_backupDir',
            'backup_dumpExecutable',
            'backup_clientExecutable',
        ] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testDefaultsPointAtWritableBackups(): void
    {
        $config = new Backup();

        $this->assertSame(WRITEPATH . 'backups', $config->backupDir);
        $this->assertSame('', $config->dumpExecutable);
        $this->assertSame('', $config->clientExecutable);
    }

    public function testEnvOverridesUseTheBackupPrefix(): void
    {
        putenv('backup.backupDir=C:\Backups\Store');
        putenv('backup.dumpExecutable=C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe');
        putenv('backup.clientExecutable=C:\Program Files\MariaDB 10.6\bin\mariadb.exe');

        $config = new Backup();

        $this->assertSame('C:\Backups\Store', $config->backupDir);
        $this->assertSame('C:\Program Files\MariaDB 10.6\bin\mariadb-dump.exe', $config->dumpExecutable);
        $this->assertSame('C:\Program Files\MariaDB 10.6\bin\mariadb.exe', $config->clientExecutable);
    }

    public function testUnderscoreEnvVariantIsRecognized(): void
    {
        putenv('backup_dumpExecutable=C:\mariadb-dump.exe');
        putenv('backup_clientExecutable=C:\mariadb.exe');

        $config = new Backup();

        $this->assertSame('C:\mariadb-dump.exe', $config->dumpExecutable);
        $this->assertSame('C:\mariadb.exe', $config->clientExecutable);
    }
}
