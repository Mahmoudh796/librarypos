<?php
/**
 * @var array $report
 * @var string $as_of
 */
$block = static function (string $titleKey, array $rows, float $total, ?float $profit = null): void {
    ?>
    <tr class="dj-section-row"><th colspan="2"><?= lang($titleKey) ?></th></tr>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td class="dj-indent"><span class="dj-ltr"><?= esc($row['code']) ?></span> <?= esc($row['name']) ?></td>
            <td class="text-right dj-num"><?= to_currency((string) $row['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($profit !== null): ?>
        <tr>
            <td class="dj-indent"><?= lang('Accounting.current_period_profit') ?></td>
            <td class="text-right dj-num"><?= to_currency((string) $profit) ?></td>
        </tr>
    <?php endif; ?>
    <tr class="dj-subtotal-row"><th><?= lang('Accounting.total') ?> <?= lang($titleKey) ?></th><th class="text-right dj-num"><?= to_currency((string) $total) ?></th></tr>
    <?php
};
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'balance_sheet']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-scale" aria-hidden="true"></span> <?= lang('Accounting.balance_sheet') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<?= view('accounting/partials/range', ['as_of' => $as_of, 'action' => base_url('accounting/balanceSheet')]) ?>

<?php if ($report['difference'] != 0.0): ?>
    <div class="alert alert-danger" role="alert"><?= lang('Accounting.balance_sheet_out_of_balance', [to_currency((string) $report['difference'])]) ?></div>
<?php else: ?>
    <div class="alert alert-success dj-balance-ok" role="status"><?= lang('Accounting.balance_sheet_balanced') ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <table class="table table-condensed dj-ledger-table">
            <tbody>
                <?php $block('Accounting.section_assets', $report['assets'], $report['total_assets']); ?>
            </tbody>
        </table>
    </div>
    <div class="col-md-6">
        <table class="table table-condensed dj-ledger-table">
            <tbody>
                <?php $block('Accounting.section_liabilities', $report['liabilities'], $report['total_liabilities']); ?>
                <?php $block('Accounting.section_equity', $report['equity'], $report['total_equity'], $report['current_profit']); ?>
                <tr class="dj-grand-total-row">
                    <th><?= lang('Accounting.total_liabilities_and_equity') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) ($report['total_liabilities'] + $report['total_equity'])) ?></th>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?= view('partial/footer') ?>
