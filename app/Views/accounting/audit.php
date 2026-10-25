<?php
/**
 * @var list<array> $rows
 * @var string $from
 * @var string $to
 * @var string $entity_type
 */
$entityTypes = ['' => lang('Accounting.all_entities'), 'treasury_voucher' => lang('Treasury.vouchers'), 'journal_entry' => lang('Accounting.journal'),
    'shareholder' => lang('Accounting.shareholders'), 'fixed_asset' => lang('Accounting.fixed_assets'), 'period' => lang('Accounting.periods'),
    'account' => lang('Accounting.chart_of_accounts'), 'sale' => lang('Module.sales'), 'item' => lang('Module.items'), 'employee' => lang('Module.employees')];
$extra = '<div class="form-group">' . form_dropdown('entity_type', $entityTypes, $entity_type, ['class' => 'form-control input-sm', 'aria-label' => lang('Accounting.entity')]) . '</div>';
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'audit']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-eye-open" aria-hidden="true"></span> <?= lang('Accounting.audit_trail') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting/audit'), 'extra' => $extra]) ?>

<?php if ($rows === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_audit_records') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table dj-audit-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.date') ?></th>
                    <th><?= lang('Accounting.employee') ?></th>
                    <th><?= lang('Accounting.action') ?></th>
                    <th><?= lang('Accounting.entity') ?></th>
                    <th><?= lang('Accounting.old_values') ?></th>
                    <th><?= lang('Accounting.new_values') ?></th>
                    <th><?= lang('Accounting.reason') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="dj-num text-nowrap"><?= esc($row['logged_at']) ?></td>
                        <td><?= esc(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?></td>
                        <td><?= esc(lang('Accounting.audit_' . $row['action'])) ?></td>
                        <td><span class="dj-ltr"><?= esc($row['entity_type']) ?> #<?= esc($row['entity_id']) ?></span></td>
                        <td><code class="dj-ltr dj-audit-values"><?= esc((string) $row['old_values']) ?></code></td>
                        <td><code class="dj-ltr dj-audit-values"><?= esc((string) $row['new_values']) ?></code></td>
                        <td dir="auto"><?= esc($row['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
