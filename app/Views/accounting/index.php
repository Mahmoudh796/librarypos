<?php
/**
 * @var string $from
 * @var string $to
 * @var array $pl
 * @var array $sheet
 * @var float $receivables
 * @var float $payables
 * @var float $inventory
 */
$kpis = [
    ['Accounting.net_sales', $pl['revenue'], 'stats', 'accent'],
    ['Accounting.gross_profit', $pl['gross_profit'], 'signal', ''],
    ['Accounting.net_profit', $pl['net_profit'], 'piggy-bank', $pl['net_profit'] < 0 ? 'danger' : ''],
    ['Accounting.receivables', $receivables, 'user', ''],
    ['Accounting.payables', $payables, 'shopping-cart', ''],
    ['Accounting.inventory_value', $inventory, 'th', ''],
];
$reports = [
    ['accounting/incomeStatement', 'Accounting.income_statement', 'Accounting.income_statement_desc'],
    ['accounting/balanceSheet', 'Accounting.balance_sheet', 'Accounting.balance_sheet_desc'],
    ['accounting/trialBalance', 'Accounting.trial_balance', 'Accounting.trial_balance_desc'],
    ['accounting/ledger', 'Accounting.general_ledger', 'Accounting.general_ledger_desc'],
    ['journal', 'Accounting.journal', 'Accounting.journal_desc'],
    ['accounting/statement', 'Accounting.statements', 'Accounting.statements_desc'],
    ['accounting/aging', 'Accounting.aging', 'Accounting.aging_desc'],
    ['accounting/dimensions', 'Accounting.dimension_analysis', 'Accounting.dimension_analysis_desc'],
    ['accounting/valuation', 'Accounting.inventory_valuation', 'Accounting.inventory_valuation_desc'],
    ['accounting/costHistory', 'Accounting.cost_history', 'Accounting.cost_history_desc'],
    ['treasury', 'Treasury.title', 'Treasury.title_desc'],
    ['accounting/audit', 'Accounting.audit_trail', 'Accounting.audit_trail_desc'],
];
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-book" aria-hidden="true"></span>
        <?= lang('Module.accounting') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting')]) ?>

<?php if ($sheet['difference'] != 0.0): ?>
    <div class="alert alert-danger" role="alert"><?= lang('Accounting.balance_sheet_out_of_balance', [to_currency((string) $sheet['difference'])]) ?></div>
<?php endif; ?>

<div class="row dj-kpi-grid">
    <?php foreach ($kpis as [$label, $value, $icon, $tone]): ?>
        <div class="col-xs-12 col-sm-6 col-md-4">
            <article class="dj-kpi<?= $tone !== '' ? ' dj-kpi--' . $tone : '' ?>">
                <header class="dj-kpi-head">
                    <span class="dj-kpi-icon glyphicon glyphicon-<?= $icon ?>" aria-hidden="true"></span>
                    <h2 class="dj-kpi-label"><?= lang($label) ?></h2>
                </header>
                <p class="dj-kpi-value dj-num"><?= to_currency((string) $value) ?></p>
            </article>
        </div>
    <?php endforeach; ?>
</div>

<section class="dj-section">
    <h2 class="dj-section-title"><?= lang('Accounting.reports') ?></h2>
    <div class="list-group dj-report-list">
        <?php foreach ($reports as [$href, $title, $description]): ?>
            <a class="list-group-item" href="<?= esc(base_url($href), 'attr') ?>">
                <h3 class="list-group-item-heading"><?= lang($title) ?></h3>
                <p class="list-group-item-text dj-hint"><?= lang($description) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?= view('partial/footer') ?>
