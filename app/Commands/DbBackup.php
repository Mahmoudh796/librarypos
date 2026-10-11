<?php

namespace App\Commands;

use App\Libraries\BackupDump;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creates a verified local SQL dump of the configured default database.
 *
 * The dump is written to backup.backupDir (default writable/backups), given
 * basic dump-level verification (exit code 0, non-empty output, SHA-256) and
 * reported with its path, size and checksum. This is NOT a restore-verified
 * backup; that verification is handled by a later scratch-restore step.
 */
class DbBackup extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:backup';
    protected $usage       = 'db:backup';
    protected $description = 'Creates a verified local SQL dump of the configured default database (dump-level verification only, not a restore-verified backup).';
    private ?BackupDump $dumper;

    public function __construct(LoggerInterface $logger, Commands $commands, ?BackupDump $dumper = null)
    {
        parent::__construct($logger, $commands);

        $this->dumper = $dumper;
    }

    public function run(array $params): int
    {
        if ($params !== []) {
            CLI::error('db:backup does not accept arguments.');

            return EXIT_USER_INPUT;
        }

        try {
            $result = $this->makeDumper()->execute();
        } catch (RuntimeException $e) {
            CLI::error('Backup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Backup created:');
        CLI::write('  path      : ' . $result['path']);
        CLI::write('  size      : ' . $result['size'] . ' bytes');
        CLI::write('  sha256    : ' . $result['sha256']);
        CLI::write('  timestamp : ' . $result['timestamp']);
        CLI::write('Dump-level verification only - restore verification is not part of this backup.');

        return EXIT_SUCCESS;
    }

    protected function makeDumper(): BackupDump
    {
        return $this->dumper ?? new BackupDump();
    }
}
