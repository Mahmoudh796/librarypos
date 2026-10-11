<?php

namespace App\Commands;

use App\Libraries\BackupDump;
use App\Libraries\BackupRestoreVerify;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creates a verified local SQL dump of the configured default database and,
 * optionally, restore-verifies it.
 *
 * Without options the dump gets dump-level verification (exit code 0, non-empty
 * output, SHA-256) and is reported as DUMP VERIFIED. With `--verify-restore`
 * the finished dump is additionally loaded into a freshly created scratch
 * database (`ospos_backup_verify_<timestamp>_<random>`), sanity-checked against
 * that scratch database only, and the scratch database is dropped again; only
 * then is the backup reported as RESTORE VERIFIED. The configured/default
 * database is never used as a restore target.
 */
class DbBackup extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:backup';
    protected $usage       = 'db:backup [--verify-restore]';
    protected $description = 'Creates a verified local SQL dump of the configured default database; with --verify-restore the dump is additionally restored into a scratch database and integrity-checked (RESTORE VERIFIED).';
    private ?BackupDump $dumper;
    private ?BackupRestoreVerify $verifier;

    public function __construct(
        LoggerInterface $logger,
        Commands $commands,
        ?BackupDump $dumper = null,
        ?BackupRestoreVerify $verifier = null,
    ) {
        parent::__construct($logger, $commands);

        $this->dumper   = $dumper;
        $this->verifier = $verifier;
    }

    public function run(array $params): int
    {
        $positional = array_values(array_filter($params, static fn ($key): bool => is_int($key), ARRAY_FILTER_USE_KEY));

        if ($positional !== []) {
            CLI::error('db:backup does not accept positional arguments.');

            return EXIT_USER_INPUT;
        }

        $verifyRestore = array_key_exists('verify-restore', $params);

        try {
            $result = $this->makeDumper()->execute();

            CLI::write('Backup created:');
            CLI::write('  path      : ' . $result['path']);
            CLI::write('  size      : ' . $result['size'] . ' bytes');
            CLI::write('  sha256    : ' . $result['sha256']);
            CLI::write('  timestamp : ' . $result['timestamp']);

            if ($verifyRestore) {
                $verification = $this->makeVerifier()->verify($result['path']);

                CLI::write('  restored  : scratch database ' . $verification['scratchDatabase'] . ' (created, verified, dropped)');
                CLI::write('  checkedAt : ' . $verification['checkedAt']);
                CLI::write('RESTORE VERIFIED - the dump was restored into a scratch database, passed all integrity checks, and the scratch database was removed.');
            } else {
                CLI::write('DUMP VERIFIED - dump-level checks passed (exit code 0, non-empty output, SHA-256). Restore verification is not part of this backup.');
            }
        } catch (RuntimeException $e) {
            CLI::error('Backup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    protected function makeDumper(): BackupDump
    {
        return $this->dumper ?? new BackupDump();
    }

    protected function makeVerifier(): BackupRestoreVerify
    {
        return $this->verifier ?? new BackupRestoreVerify();
    }
}
