<?php

namespace App\Libraries;

use FilesystemIterator;
use RuntimeException;

/**
 * Prunes completed database backups inside a single backup-owned directory.
 *
 * The pruner is deliberately narrow. It only ever looks at the direct children
 * of exactly one directory - never subdirectories, never symlinks, never
 * anything but regular files - and only recognises filenames matching the
 * strict backup naming rule produced by {@see BackupDump}:
 *
 *     <safe-database-name>_YYYY-MM-DD_HHMMSS_<4 hex>.sql
 *
 * Everything else in the directory is left alone, so running the pruner can
 * never delete an arbitrary file (READMEs, `.env`-like files, application
 * sources, `.part` files, unrelated dumps). Retention is counted per directory:
 * a local prune and a replica prune are independent calls, and pruning the
 * replica directory can never touch the local backup. The newest valid backup
 * in a directory is always kept, even when the configured retention count
 * would otherwise cover fewer files. A retention count of zero or less disables
 * pruning entirely. Dry-run mode reports exactly what would be removed without
 * deleting anything.
 *
 * Retention failures (for example a file that cannot be removed) throw a
 * RuntimeException so the caller can fail the run explicitly instead of
 * silently losing retention coverage.
 */
final class BackupRetention
{
    /**
     * Strict backup filename rule: <db>_<YYYY-MM-DD_HHMMSS>_<4 hex>.sql
     */
    private const BACKUP_FILE_PATTERN = '/^[A-Za-z0-9_.-]+_(\d{4}-\d{2}-\d{2}_\d{6})_([0-9a-f]{4})\.sql$/';

    /**
     * @param int  $retentionCount how many of the newest valid backups to keep; 0 or less disables pruning
     * @param bool $dryRun         report what would be removed without removing anything
     *
     * @return array{dir: string, dryRun: bool, retentionCount: int, disabled: bool, candidates: int, kept: list<string>, deleted: list<string>, newest: string}
     *
     * @throws RuntimeException when the path exists but is not a directory, or
     *                          a matched backup file cannot be removed
     */
    public function prune(string $directory, int $retentionCount, bool $dryRun = false): array
    {
        if ($retentionCount <= 0) {
            return $this->emptyReport($directory, $retentionCount, $dryRun, true);
        }

        if (is_file($directory)) {
            throw new RuntimeException('Refusing to prune: not a directory: ' . $directory);
        }

        if (! is_dir($directory)) {
            // A configured directory that does not exist yet has nothing to prune.
            return $this->emptyReport($directory, $retentionCount, $dryRun, false);
        }

        $resolved = realpath($directory);

        if ($resolved === false) {
            throw new RuntimeException('Could not resolve the backup directory for pruning: ' . $directory);
        }

        $candidates = $this->candidates($resolved);

        // Newest first: the embedded timestamp and random nonce sort lexically.
        usort($candidates, static fn (array $a, array $b): int => [$b['timestamp'], $b['nonce']] <=> [$a['timestamp'], $a['nonce']]);

        $newest  = $candidates === [] ? '' : $candidates[0]['path'];
        $kept    = array_map(static fn (array $candidate): string => $candidate['path'], array_slice($candidates, 0, $retentionCount));
        $excess  = array_slice($candidates, $retentionCount);
        $deleted = [];

        foreach ($excess as $candidate) {
            $path = $candidate['path'];

            if ($dryRun || @unlink($path) || ! is_file($path)) {
                $deleted[] = $path;

                continue;
            }

            throw new RuntimeException('Retention failed: could not remove the old backup: ' . $path);
        }

        return [
            'dir'            => $resolved,
            'dryRun'         => $dryRun,
            'retentionCount' => $retentionCount,
            'disabled'       => false,
            'candidates'     => count($candidates),
            'kept'           => $kept,
            'deleted'        => $deleted,
            'newest'         => $newest,
        ];
    }

    /**
     * Lists the completed backups in a directory, newest first.
     *
     * Read-only companion to {@see prune()}: it applies the same strict naming
     * rule and the same scope (direct children, regular files only), so a
     * directory listing can only ever surface genuine application backups.
     *
     * @return list<array{name: string, path: string, size: int, modified: int}>
     */
    public function listBackups(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $resolved = realpath($directory);

        if ($resolved === false) {
            return [];
        }

        $candidates = $this->candidates($resolved);

        usort($candidates, static fn (array $a, array $b): int => [$b['timestamp'], $b['nonce']] <=> [$a['timestamp'], $a['nonce']]);

        $backups = [];

        foreach ($candidates as $candidate) {
            $backups[] = [
                'name'     => basename($candidate['path']),
                'path'     => $candidate['path'],
                'size'     => (int) @filesize($candidate['path']),
                'modified' => (int) @filemtime($candidate['path']),
            ];
        }

        return $backups;
    }

    /**
     * @return list<array{path: string, timestamp: string, nonce: string}>
     */
    private function candidates(string $resolvedDir): array
    {
        $candidates = [];
        $iterator   = new FilesystemIterator($resolvedDir, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->isLink()) {
                continue;
            }

            if (preg_match(self::BACKUP_FILE_PATTERN, $entry->getFilename(), $matches) !== 1) {
                continue;
            }

            $candidates[] = [
                'path'      => $entry->getPathname(),
                'timestamp' => $matches[1],
                'nonce'     => $matches[2],
            ];
        }

        return $candidates;
    }

    /**
     * @return array{dir: string, dryRun: bool, retentionCount: int, disabled: bool, candidates: int, kept: list<string>, deleted: list<string>, newest: string}
     */
    private function emptyReport(string $directory, int $retentionCount, bool $dryRun, bool $disabled): array
    {
        return [
            'dir'            => $directory,
            'dryRun'         => $dryRun,
            'retentionCount' => $retentionCount,
            'disabled'       => $disabled,
            'candidates'     => 0,
            'kept'           => [],
            'deleted'        => [],
            'newest'         => '',
        ];
    }
}
