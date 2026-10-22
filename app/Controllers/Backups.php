<?php

namespace App\Controllers;

use App\Libraries\BackupDump;
use App\Libraries\BackupRetention;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Backup;
use Throwable;

/**
 * Administration surface for the database backup capability.
 *
 * The actual dump/verify/replicate/prune work lives in `php spark db:backup`
 * (App\Commands\DbBackup and App\Libraries\Backup*). This page only reports the
 * existing backup configuration and the backups already on disk, and offers the
 * same local, dump-verified backup the CLI command performs.
 *
 * Restore is deliberately NOT offered here: it overwrites the live database, so
 * it stays an explicit, operator-run CLI procedure. The page states that rather
 * than presenting a destructive button.
 *
 * The screen is not a grantable module of its own; it is an administration
 * surface, so it is protected by the `config` grant.
 */
class Backups extends Secure_Controller
{
    public function __construct()
    {
        parent::__construct('config');
    }

    public function getIndex(): string
    {
        return view('backups/index', $this->pageData());
    }

    /**
     * Creates one local, dump-verified backup.
     *
     * Mirrors `php spark db:backup`: when a retention count is explicitly
     * configured, the run ends with the same prune the CLI performs here, so
     * the web copy never accumulates unbounded local dumps. Only files that
     * match the strict backup naming rule can ever be removed and every
     * removal is logged; nothing is deleted silently or unconfigured. There
     * is no restore verification, no replication and never a restore.
     */
    public function postCreate(): RedirectResponse
    {
        try {
            $result = (new BackupDump())->execute();
        } catch (Throwable $error) {
            log_message('error', 'Manual database backup failed: {message}', ['message' => $error->getMessage()]);
            return redirect()->to('backups')->with('backup_error', lang('Common.backup_failed', [$error->getMessage()]));
        }

        log_message('info', 'Manual database backup created: {path} ({size} bytes, sha256 {sha256})', [
            'path'   => $result['path'],
            'size'   => (string) $result['size'],
            'sha256' => $result['sha256'],
        ]);

        $successMessage = lang('Common.backup_created_ok') . ' ' . $result['timestamp'] . ' — ' . $this->humanSize($result['size']);

        // Explicit retention policy: keep the configured newest count. A count
        // of zero (the default) disables pruning entirely.
        $retentionCount = config(Backup::class)->retentionCount;

        if ($retentionCount > 0) {
            $directory = (new BackupDump())->findBackupDirectory();

            if ($directory !== null) {
                try {
                    $report = (new BackupRetention())->prune($directory, $retentionCount);
                } catch (Throwable $error) {
                    log_message('error', 'Backup retention failed after the manual backup: {message}', ['message' => $error->getMessage()]);
                }

                if (!empty($report) && $report['deleted'] !== []) {
                    foreach ($report['deleted'] as $removed) {
                        log_message('info', 'Backup retention removed: {file}', ['file' => $removed]);
                    }

                    $successMessage .= ' ' . lang('Common.backup_pruned_ok', [(string) count($report['kept']), (string) count($report['deleted'])]);
                }
            }
        }

        return redirect()->to('backups')->with('backup_success', $successMessage);
    }

    /**
     * Read-only description of the existing backup configuration, plus the
     * backups already present in the backup directory.
     *
     * @return array<string, mixed>
     */
    private function pageData(): array
    {
        $backup = config(Backup::class);
        $dumper    = new BackupDump();
        $directory = $dumper->findBackupDirectory();
        $backups   = $directory === null ? [] : (new BackupRetention())->listBackups($directory);

        $totalSize = array_sum(array_column($backups, 'size'));
        $lastBackup = $backups === [] ? null : (int) $backups[0]['modified'];

        return [
            'executable'           => $dumper->findDumpExecutable(),
            'executableConfigured' => trim($backup->dumpExecutable) !== '',
            'backupDirectory'      => $directory,
            'backupDirConfigured'  => trim($backup->backupDir) !== '',
            'replicaDir'           => trim($backup->replicaDir),
            'retentionCount'       => $backup->retentionCount,
            'backups'              => $backups,
            // Presentational pre-formats so the view keeps no formatting rules.
            'backupCountSummary'   => count($backups) . ($backups === [] ? '' : ' · ' . $this->humanSize($totalSize)),
            'lastBackupSummary'    => $lastBackup === null ? null : to_datetime($lastBackup),
        ];
    }

    /**
     * Formats a byte count for the confirmation message.
     */
    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
