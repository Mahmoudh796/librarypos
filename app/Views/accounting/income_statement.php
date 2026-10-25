<?php
/**
 * @var array $report
 * @var string $from
 * @var string $to
 * @var ?int $dimension_value
 * @var array $centers
 */
$sections = $report['sections'];
$totals = $report['totals'];

$section = static function (string $titleKey, array $rows, float $total): void {
    ?>
    <tr class="dj-section-row"><th colspan="2"><?= lang($titleKey) ?></th></tr>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td class="dj-indent"><span class="dj-ltr"><?= esc($row['code']) ?></span> <?= esc($row['name']) ?></td>
            <td class="text-right dj-num"><?= to_currency((string) $row['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    <tr class="dj-subtotal-row">
        <th><?= lang('Accounting.total') ?> <?= lang($titleKey) ?></th>
        <th class="text-right dj-num"><?= to_currency((string) $total) ?></th>
    </tr>
    <?php
};
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'income_statement']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-stats" aria-hidden="true"></span> <?= lang('Accounting.income_statement') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<?php
$extra = '<div class="form-group">' . form_dropdown('dimension_value', $centers, (string) ($dimension_value ?? ''), ['class' => 'form-control input-sm', 'aria-label' => lang('Accounting.dimension')]) . '</div>';
?>
<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting/incomeStatement'), 'extra' => $extra]) ?>

<div class="table-responsive dj-statement">
    <table class="table table-condensed dj-ledger-table">
        <tbody>
            <?php $section('Accounting.section_revenue', $sections['revenue'], $totals['revenue']); ?>
            <?php $section('Accounting.section_cost_of_sales', $sections['cost_of_sales'], $totals['cost_of_sales']); ?>
            <tr class="dj-total-row"><th><?= lang('Accounting.gross_profit') ?></th><th class="text-right dj-num"><?= to_currency((string) $totals['gross_profit']) ?></th></tr>
            <?php $section('Accounting.section_operating_expenses', $sections['operating_expenses'], $totals['operating_expenses']); ?>
            <?php $section('Accounting.section_depreciation', $sections['depreciation'], $totals['depreciation']); ?>
            <tr class="dj-total-row"><th><?= lang('Accounting.operating_profit') ?></th><th class="text-right dj-num"><?= to_currency((string) $totals['operating_profit']) ?></th></tr>
            <?php $section('Accounting.section_other_income', $sections['other_income'], $totals['other_income']); ?>
            <?php $section('Accounting.section_other_expenses', $sections['other_expenses'], $totals['other_expenses']); ?>
            <tr class="dj-grand-total-row<?= $totals['net_profit'] < 0 ? ' dj-negative' : '' ?>">
                <th><?= lang($totals['net_profit'] < 0 ? 'Accounting.net_loss' : 'Accounting.net_profit') ?></th>
                <th class="text-right dj-num"><?= to_currency((string) $totals['net_profit']) ?></th>
            </tr>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
