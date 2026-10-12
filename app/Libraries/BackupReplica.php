<?php

namespace App\Libraries;

use Config\Backup;
use RuntimeException;
use Throwable;

/**
 * Copies a verified local backup to an optional off-device replica directory.
 *
 * This is copy-only: the source backup is never modified, removed or
 * overwritten, so a failed replication can never invalidate the local backup.
 * The destination is written to a `<name>.part` file first and only renamed to
 * the final name once its size and SHA-256 match the source; the final file is
 * re-checked before success is reported. A partially written replica never
 * looks like a completed backup.
 *
 * The replicator only ever runs on the report produced internally by
 * {@see BackupDump} (`path`, `size`, `sha256`) and additionally requires the
 * source to live inside the configured backup directory, so there is no way to
 * feed it an arbitrary file from the command line. It uses PHP stream and
 * filesystem functions exclusively - no shell copy, no cmd/xcopy/robocopy - and
 * never touches database or network-share credentials.
 *
 * The copier, renamer, file sizer and hasher are injectable so the failure
 * paths can be exercised without a real second device or network share.
 */
final class BackupReplica
{
    public const STATUS_COPIED    = 'copied';
    public const STATUS_IDENTICAL = 'identical';
    private const PART_SUFFIX     = '.part';

    /**
     * @var callable(string, string): void
     */
    private $copier;

    /**
     * @var callable(string, string): bool
     */
    private $renamer;

    /**
     * @var callable(string): int
     */
    private $sizer;

    /**
     * @var callable(string): string
     */
    private $hasher;

    private ?Backup $config;

    /**
     * @param callable(string, string): void|null $copier
     * @param callable(string, string): bool|null $renamer
     * @param callable(string): int|null          $sizer
     * @param callable(string): string|null       $hasher
     */
    public function __construct(
        ?Backup $config = null,
        ?callable $copier = null,
        ?callable $renamer = null,
        ?callable $sizer = null,
        ?callable $hasher = null,
    ) {
        $this->config  = $config;
        $this->copier  = $copier ?? $this->defaultCopier(...);
        $this->renamer = $renamer ?? static fn (string $from, string $to): bool => @rename($from, $to);
        $this->sizer   = $sizer ?? static function (string $path): int {
            $size = @filesize($path);

            if ($size === false) {
                throw new RuntimeException('Could not read the size of: ' . $path);
            }

            return $size;
        };
        $this->hasher = $hasher ?? static function (string $path): string {
            $hash = @hash_file('sha256', $path);

            if ($hash === false) {
                throw new RuntimeException('Could not compute a SHA-256 checksum for: ' . $path);
            }

            return $hash;
        };
    }

    /**
     * Copies the verified backup to the configured replica directory.
     *
     * @param array{path: string, size: int, sha256: string} $source the report produced by BackupDump
     *
     * @return array{sourcePath: string, replicaPath: string, size: int, sha256: string, status: string}
     *
     * @throws RuntimeException when replication is disabled/unavailable, the
     *                          destination is unsafe, or any copy/verify stage
     *                          fails; the local backup is never touched and any
     *                          partial replica file is removed first
     */
    public function replicate(array $source): array
    {
        $sourcePath = (string) ($source['path'] ?? '');
        $sourceSize = (int) ($source['size'] ?? -1);
        $sourceHash = (string) ($source['sha256'] ?? '');

        $this->assertEligibleSource($sourcePath, $sourceSize, $sourceHash);

        $replicaDir  = $this->resolveReplicaDirectory();
        $replicaPath = $replicaDir . DIRECTORY_SEPARATOR . basename($sourcePath);
        $partPath    = $replicaPath . self::PART_SUFFIX;

        $this->assertSafeDestination($replicaDir, $replicaPath, $sourcePath);

        // Never silently overwrite an existing completed replica.
        if (is_file($replicaPath)) {
            return $this->handleExistingReplica($replicaPath, $sourcePath, $sourceSize, $sourceHash);
        }

        $this->removeStalePart($partPath);

        $createdReplica = false;

        try {
            ($this->copier)($sourcePath, $partPath);

            $this->assertFileMatches($partPath, $sourceSize, $sourceHash, 'replica part file');

            if (! ($this->renamer)($partPath, $replicaPath)) {
                throw new RuntimeException('Could not move the replica into place: ' . $replicaPath);
            }

            $createdReplica = true;

            $this->assertFileMatches($replicaPath, $sourceSize, $sourceHash, 'final replica');
            $this->assertFileMatches($sourcePath, $sourceSize, $sourceHash, 'source backup');
        } catch (Throwable $e) {
            // Only ever clean up files this run created: the part file, and the
            // final file once it was renamed from that same part file.
            if ($createdReplica && is_file($replicaPath)) {
                @unlink($replicaPath);
            }

            if (is_file($partPath)) {
                @unlink($partPath);
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        return [
            'sourcePath'  => $sourcePath,
            'replicaPath' => $replicaPath,
            'size'        => $sourceSize,
            'sha256'      => $sourceHash,
            'status'      => self::STATUS_COPIED,
        ];
    }

    /**
     * Resolves (creating if needed) the configured replica directory, exactly
     * as {@see replicate()} would, without copying anything. Used by retention
     * so the replica directory can be pruned independently of a replication run.
     *
     * @throws RuntimeException when replication is not configured, or the
     *                          directory cannot be created or resolved
     */
    public function replicaDirectory(): string
    {
        return $this->resolveReplicaDirectory();
    }

    /**
     * Runs only on the structured report from BackupDump: the file must exist,
     * be non-empty, carry a checksum, match that checksum, and live inside the
     * configured backup directory.
     */
    private function assertEligibleSource(string $sourcePath, int $sourceSize, string $sourceHash): void
    {
        if ($sourcePath === '' || ! is_file($sourcePath)) {
            throw new RuntimeException('Refusing to replicate: the source backup was not found: ' . $sourcePath);
        }

        if ($sourceSize <= 0) {
            throw new RuntimeException('Refusing to replicate: the source backup is empty.');
        }

        if ($sourceHash === '') {
            throw new RuntimeException('Refusing to replicate: the source backup has no checksum.');
        }

        if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) !== 'sql') {
            throw new RuntimeException('Refusing to replicate: the source is not a SQL backup file.');
        }

        $backupDir = $this->resolveBackupDirectory();
        $sourceDir = realpath(dirname($sourcePath));

        if ($sourceDir === false || $backupDir === false || ! $this->samePath($sourceDir, $backupDir)) {
            throw new RuntimeException('Refusing to replicate: the source does not live in the configured backup directory.');
        }

        // The report must still describe the file on disk.
        $this->assertFileMatches($sourcePath, $sourceSize, $sourceHash, 'source backup');
    }

    private function resolveReplicaDirectory(): string
    {
        $configured = trim($this->backupConfig()->replicaDir);

        if ($configured === '') {
            throw new RuntimeException('Replication requested but backup.replicaDir is not configured. Set backup.replicaDir in .env to a directory outside the local backup directory.');
        }

        $dir = $configured;

        if (! $this->isAbsolutePath($dir)) {
            $dir = WRITEPATH . ltrim($dir, '/\\');
        }

        $dir = rtrim($dir, '/\\');

        if (is_file($dir)) {
            throw new RuntimeException('Configured replica directory is a file, not a directory: ' . $dir);
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create or access the replica directory: ' . $dir);
        }

        $resolved = realpath($dir);

        if ($resolved === false) {
            throw new RuntimeException('Could not resolve the replica directory: ' . $dir);
        }

        return $resolved;
    }

    private function assertSafeDestination(string $replicaDir, string $replicaPath, string $sourcePath): void
    {
        $sourceDir = realpath(dirname($sourcePath));
        $backupDir = $this->resolveBackupDirectory();

        if ($sourceDir !== false && $this->samePath($replicaDir, $sourceDir)) {
            throw new RuntimeException('Refusing to replicate: backup.replicaDir is the same directory as the local backup directory.');
        }

        if ($backupDir !== false && $this->samePath($replicaDir, $backupDir)) {
            throw new RuntimeException('Refusing to replicate: backup.replicaDir is the same directory as the local backup directory.');
        }

        if ($this->pathsReferToSameFile($sourcePath, $replicaPath)) {
            throw new RuntimeException('Refusing to replicate: the replica destination resolves to the same file as the source backup.');
        }
    }

    /**
     * @return array{sourcePath: string, replicaPath: string, size: int, sha256: string, status: string}
     */
    private function handleExistingReplica(string $replicaPath, string $sourcePath, int $sourceSize, string $sourceHash): array
    {
        $identical = ($this->sizer)($replicaPath) === $sourceSize
            && hash_equals($sourceHash, ($this->hasher)($replicaPath));

        if (! $identical) {
            throw new RuntimeException('A different replica already exists at "' . $replicaPath . '" (size or SHA-256 differs from the source). Refusing to overwrite it.');
        }

        // Idempotent: the exact same bytes are already off-device.
        $this->assertFileMatches($sourcePath, $sourceSize, $sourceHash, 'source backup');

        return [
            'sourcePath'  => $sourcePath,
            'replicaPath' => $replicaPath,
            'size'        => $sourceSize,
            'sha256'      => $sourceHash,
            'status'      => self::STATUS_IDENTICAL,
        ];
    }

    private function removeStalePart(string $partPath): void
    {
        if (is_file($partPath) && ! @unlink($partPath)) {
            throw new RuntimeException('Could not remove a stale replica part file: ' . $partPath);
        }
    }

    private function assertFileMatches(string $path, int $expectedSize, string $expectedHash, string $label): void
    {
        if (! is_file($path)) {
            throw new RuntimeException('Replica verification failed: the ' . $label . ' is missing: ' . $path);
        }

        $actualSize = ($this->sizer)($path);

        if ($actualSize !== $expectedSize) {
            throw new RuntimeException('Replica verification failed: the ' . $label . ' size does not match the source (expected ' . $expectedSize . ', got ' . $actualSize . ').');
        }

        $actualHash = ($this->hasher)($path);

        if (! hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
            throw new RuntimeException('Replica verification failed: the ' . $label . ' SHA-256 does not match the source.');
        }
    }

    private function backupConfig(): Backup
    {
        return $this->config ?? config(Backup::class);
    }

    /**
     * Resolves the configured backup directory the same way BackupDump does, so
     * the eligibility check compares against the real dump location.
     *
     * @return false|string the canonical directory, or false when it cannot be resolved
     */
    private function resolveBackupDirectory(): false|string
    {
        $dir = $this->backupConfig()->backupDir;

        if ($dir === '') {
            $dir = WRITEPATH . 'backups';
        } elseif (! $this->isAbsolutePath($dir)) {
            $dir = WRITEPATH . ltrim($dir, '/\\');
        }

        return realpath(rtrim($dir, '/\\'));
    }

    private function pathsReferToSameFile(string $a, string $b): bool
    {
        $realA = realpath($a);
        $realB = realpath($b);

        if ($realA !== false && $realB !== false) {
            return $this->samePath($realA, $realB);
        }

        return $this->samePath($this->normalizePath($a), $this->normalizePath($b));
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $normalized = rtrim($normalized, DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function samePath(string $a, string $b): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($a, $b) === 0;
        }

        return $a === $b;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * Streams the source into the part file. No shell, no external process.
     */
    private function defaultCopier(string $source, string $destination): void
    {
        $in = @fopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException('Could not open the source backup for reading: ' . $source);
        }

        $out = @fopen($destination, 'wb');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException('Could not open the replica part file for writing: ' . $destination);
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException('Failed while copying the backup to the replica part file.');
            }

            if (! fflush($out)) {
                throw new RuntimeException('Could not flush the replica part file to disk.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
