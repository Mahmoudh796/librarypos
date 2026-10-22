<?php
/**
 * Administration screen for the database backup capability.
 *
 * Read-only status plus one non-destructive action (create a local, verified
 * dump). Restore is intentionally absent: it overwrites the live database and
 * stays an operator-run CLI procedure.
 *
 * @var string|null $executable
 * @var bool        $executableConfigured
 * @var string|null $backupDirectory
 * @var bool        $backupDirConfigured
 * @var string      $replicaDir
 * @var int         $retentionCount
 * @var array<int, array{name: string, path: string, size: int, modified: int}> $backups
 * @var array $config
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('partial/page_path', ['crumbs' => [['module' => 'office'], ['label' => lang('Common.backup')]]]) ?>
    <?= view('partial/page_nav') ?>
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-hdd" aria-hidden="true"></span>
        <?= lang('Common.backup') ?>
    </h1>
</div>

<div class="dj-workspace">
    <?php if (session()->getFlashdata('backup_success') !== null): ?>
        <div class="alert alert-success" role="status">
            <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
            <?= esc((string) session()->getFlashdata('backup_success')) ?>
        </div>
    <?php endif; ?>

    <?php if (session()->getFlashdata('backup_error') !== null): ?>
        <div class="alert alert-danger" role="alert">
            <span class="glyphicon glyphicon-warning-sign" aria-hidden="true"></span>
            <?= esc((string) session()->getFlashdata('backup_error')) ?>
        </div>
    <?php endif; ?>

    <section class="dj-panel" aria-labelledby="dj-backup-status-title">
        <div class="dj-section-head">
            <h2 class="dj-section-title" id="dj-backup-status-title">
                <span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span><?= lang('Common.backup_status') ?>
            </h2>
        </div>

        <dl class="dj-meta-list">
            <dt><?= lang('Common.backup_local_status') ?></dt>
            <dd>
                <?php if ($executable !== null && $backupDirectory !== null): ?>
                    <span class="dj-status dj-status-normal"><?= lang('Common.backup_ready') ?></span>
                <?php else: ?>
                    <span class="dj-status dj-status-out"><?= lang('Common.backup_not_configured') ?></span>
                <?php endif; ?>
            </dd>

            <dt><?= lang('Common.backup_offsite_status') ?></dt>
            <dd>
                <?php if ($replicaDir !== ''): ?>
                    <span class="dj-status dj-status-normal"><?= lang('Common.backup_configured') ?></span>
                    <code class="dj-ltr"><?= esc($replicaDir) ?></code>
                <?php else: ?>
                    <span class="dj-status dj-status-out"><?= lang('Common.backup_not_configured') ?></span>
                <?php endif; ?>
            </dd>

            <dt><?= lang('Common.backup_retention') ?></dt>
            <dd>
                <?php if ($retentionCount > 0): ?>
                    <span class="dj-status dj-status-normal"><?= esc(lang('Common.backup_pruning_keep', [$retentionCount])) ?></span>
                <?php else: ?>
                    <span class="dj-status dj-status-out"><?= esc(lang('Common.backup_pruning_disabled')) ?></span>
                <?php endif; ?>
            </dd>

            <dt><?= lang('Common.backup_count_on_disk') ?></dt>
            <dd class="dj-num" dir="ltr"><?= esc($backupCountSummary) ?></dd>

            <dt><?= lang('Common.backup_last_success') ?></dt>
            <dd>
                <?php if ($lastBackupSummary !== null): ?>
                    <span class="dj-ltr"><?= esc($lastBackupSummary) ?></span>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </dd>
        </dl>

        <p class="dj-hint"><?= lang('Common.backup_offsite_hint') ?></p>

        <details class="dj-details">
            <summary><?= lang('Common.backup_technical_details') ?></summary>
            <dl class="dj-meta-list">
                <dt><?= lang('Common.backup_executable') ?></dt>
                <dd>
                    <?php if ($executable !== null): ?>
                        <span class="dj-status dj-status-normal"><?= lang('Common.backup_configured') ?></span>
                        <code class="dj-ltr"><?= esc($executable) ?></code>
                    <?php else: ?>
                        <span class="dj-status dj-status-out"><?= lang('Common.backup_not_configured') ?></span>
                        <?php if (! $executableConfigured): ?>
                            <code class="dj-ltr">backup.dumpExecutable</code>
                        <?php endif; ?>
                    <?php endif; ?>
                </dd>

                <dt><?= lang('Common.backup_directory') ?></dt>
                <dd>
                    <?php if ($backupDirectory !== null): ?>
                        <code class="dj-ltr"><?= esc($backupDirectory) ?></code>
                    <?php else: ?>
                        <span class="dj-status dj-status-out"><?= lang('Common.backup_not_configured') ?></span>
                    <?php endif; ?>
                </dd>

                <dt><?= lang('Common.backup_replica') ?></dt>
                <dd>
                    <?php if ($replicaDir !== ''): ?>
                        <code class="dj-ltr"><?= esc($replicaDir) ?></code>
                    <?php else: ?>
                        <span class="text-muted"><?= lang('Common.backup_not_configured') ?></span>
                    <?php endif; ?>
                </dd>
            </dl>
            <p class="dj-hint"><?= lang('Common.backup_replica_hint') ?></p>
        </details>
    </section>

    <section class="dj-panel" aria-labelledby="dj-backup-create-title">
        <div class="dj-section-head">
            <h2 class="dj-section-title" id="dj-backup-create-title">
                <span class="glyphicon glyphicon-cloud-download" aria-hidden="true"></span><?= lang('Common.backup_create') ?>
            </h2>
        </div>

        <p class="text-muted">
            <?= lang('Common.backup_restore_notice') ?>
            <code class="dj-ltr">php spark db:backup --verify-restore</code>
        </p>

        <?= form_open('backups/create') ?>
            <div class="dj-form-actions">
                <button type="submit" class="btn btn-success">
                    <span class="glyphicon glyphicon-floppy-save" aria-hidden="true"></span>
                    <?= lang('Common.backup_create') ?>
                </button>
            </div>
        <?= form_close() ?>
    </section>

    <section class="dj-panel" aria-labelledby="dj-backup-history-title">
        <div class="dj-section-head">
            <h2 class="dj-section-title" id="dj-backup-history-title">
                <span class="glyphicon glyphicon-time" aria-hidden="true"></span><?= lang('Common.backup_history') ?>
            </h2>
        </div>

        <?php if ($backups === []): ?>
            <p class="dj-empty"><?= lang('Common.backup_no_backups') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Common.backup_file') ?></th>
                            <th scope="col"><?= lang('Common.backup_created') ?></th>
                            <th scope="col" class="text-right"><?= lang('Common.backup_size') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backupFile): ?>
                            <tr>
                                <td><code class="dj-ltr"><?= esc($backupFile['name']) ?></code></td>
                                <td class="dj-ltr"><?= esc(date('Y-m-d H:i', $backupFile['modified'])) ?></td>
                                <td class="text-right dj-num" dir="ltr"><?= esc(number_format($backupFile['size'] / 1024, 1)) ?> KB</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?= view('partial/footer') ?>
