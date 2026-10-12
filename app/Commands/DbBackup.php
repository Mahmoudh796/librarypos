<?php

namespace App\Commands;

use App\Libraries\BackupDump;
use App\Libraries\BackupReplica;
use App\Libraries\BackupRestoreVerify;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creates a verified local SQL dump of the configured default database and,
 * optionally, restore-verifies it and/or copies it off-device.
 *
 * Without options the dump gets dump-level verification (exit code 0, non-empty
 * output, SHA-256) and is reported as DUMP VERIFIED. With `--verify-restore`
 * the finished dump is additionally loaded into a freshly created scratch
 * database (`ospos_backup_verify_<timestamp>_<random>`), sanity-checked against
 * that scratch database only, and the scratch database is dropped again; only
 * then is the backup reported as RESTORE VERIFIED. With `--replicate` the
 * verified backup is copied into `backup.replicaDir` and the copy is
 * re-checked, reported as REPLICA VERIFIED - replication never modifies or
 * removes the local backup, so a failed replica leaves the local backup intact.
 * The configured/default database is never used as a restore target.
 */
class DbBackup extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:backup';
    protected $usage       = 'db:backup [--verify-restore] [--replicate]';
    protected $description = 'Creates a verified local SQL dump of the configured default database; optionally restore-verifies it (--verify-restore) and/or copies it to an off-device replica directory (--replicate).';
    private ?BackupDump $dumper;
    private ?BackupRestoreVerify $verifier;
    private ?BackupReplica $replicator;

    public function __construct(
        LoggerInterface $logger,
        Commands $commands,
        ?BackupDump $dumper = null,
        ?BackupRestoreVerify $verifier = null,
        ?BackupReplica $replicator = null,
    ) {
        parent::__construct($logger, $commands);

        $this->dumper     = $dumper;
        $this->verifier   = $verifier;
        $this->replicator = $replicator;
    }

    public function run(array $params): int
    {
        $positional = array_values(array_filter($params, static fn ($key): bool => is_int($key), ARRAY_FILTER_USE_KEY));

        if ($positional !== []) {
            CLI::error('db:backup does not accept positional arguments.');

            return EXIT_USER_INPUT;
        }

        $verifyRestore = array_key_exists('verify-restore', $params);
        $replicate     = array_key_exists('replicate', $params);

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
        CLI::write('DUMP VERIFIED - dump-level checks passed (exit code 0, non-empty output, SHA-256).');

        if ($verifyRestore) {
            try {
                $verification = $this->makeVerifier()->verify($result['path']);
            } catch (RuntimeException $e) {
                CLI::error('Restore verification failed: ' . $e->getMessage());

                return EXIT_ERROR;
            }

            CLI::write('  restored  : scratch database ' . $verification['scratchDatabase'] . ' (created, verified, dropped)');
            CLI::write('  checkedAt : ' . $verification['checkedAt']);
            CLI::write('RESTORE VERIFIED - the dump was restored into a scratch database, passed all integrity checks, and the scratch database was removed.');
        }

        if ($replicate) {
            try {
                $replica = $this->makeReplicator()->replicate($result);
            } catch (RuntimeException $e) {
                CLI::error('Replication failed: ' . $e->getMessage());
                CLI::write('The local backup is still valid and was left untouched:');
                CLI::write('  path   : ' . $result['path']);
                CLI::write('  size   : ' . $result['size'] . ' bytes');
                CLI::write('  sha256 : ' . $result['sha256']);

                return EXIT_ERROR;
            }

            $copyState = $replica['status'] === BackupReplica::STATUS_IDENTICAL
                ? 'already present and identical'
                : 'copied';

            CLI::write('  replica   : ' . $replica['replicaPath']);
            CLI::write('  copy      : ' . $copyState . ', size ' . $replica['size'] . ' bytes, sha256 ' . $replica['sha256']);
            CLI::write('REPLICA VERIFIED - the verified backup was copied off-device and the replica was re-checked (size and SHA-256 match).');
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

    protected function makeReplicator(): BackupReplica
    {
        return $this->replicator ?? new BackupReplica();
    }
}
