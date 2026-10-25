<?php
/**
 * @var object $shareholder
 * @var array $position
 * @var array $report
 * @var string $from
 * @var string $to
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'shareholders', 'leaf' => $shareholder->name]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?= lang('Accounting.shareholder_statement') ?> - <span dir="auto"><?= esc($shareholder->name) ?></span></h1>
    <?= view('partial/page_nav') ?>
</div>

<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('shareholders/statement/' . $shareholder->shareholder_id)]) ?>

<div class="row dj-kpi-grid">
    <?php foreach (['capital' => 'Accounting.capital', 'drawings' => 'Accounting.drawings', 'current' => 'Accounting.current_account', 'equity' => 'Accounting.equity_balance'] as $key => $label): ?>
        <div class="col-xs-6 col-md-3">
            <article class="dj-kpi<?= $key === 'equity' ? ' dj-kpi--accent' : '' ?>">
                <header class="dj-kpi-head"><h2 class="dj-kpi-label"><?= lang($label) ?></h2></header>
                <p class="dj-kpi-value dj-num"><?= to_currency((string) $position[$key]) ?></p>
            </article>
        </div>
    <?php endforeach; ?>
</div>

<div class="table-responsive">
    <table class="table table-striped table-bordered table-condensed dj-ledger-table">
        <thead>
            <tr>
                <th><?= lang('Accounting.date') ?></th>
                <th><?= lang('Accounting.entry_number') ?></th>
                <th><?= lang('Accounting.reference') ?></th>
                <th><?= lang('Accounting.transaction_type') ?></th>
                <th><?= lang('Accounting.description') ?></th>
                <th class="text-right"><?= lang('Accounting.debit') ?></th>
                <th class="text-right"><?= lang('Accounting.credit') ?></th>
                <th class="text-right"><?= lang('Accounting.equity_balance') ?></th>
            </tr>
        </thead>
        <tbody>
            <tr class="dj-subtotal-row"><td colspan="7"><?= lang('Accounting.opening_balance') ?></td><td class="text-right dj-num"><?= to_currency((string) $report['opening']) ?></td></tr>
            <?php foreach ($report['rows'] as $row): ?>
                <tr>
                    <td class="dj-num text-nowrap"><?= esc(substr($row['entry_date'], 0, 10)) ?></td>
                    <td class="dj-ltr"><a href="<?= esc(base_url('journal/view/' . $row['entry_id']), 'attr') ?>"><?= esc($row['entry_number']) ?></a></td>
                    <td class="dj-ltr"><?= esc($row['reference']) ?></td>
                    <td><?= lang('Accounting.equity_account_' . $row['account_kind']) ?></td>
                    <td dir="auto"><?= esc($row['description']) ?></td>
                    <td class="text-right dj-num"><?= (float) $row['debit'] != 0.0 ? to_currency($row['debit']) : '' ?></td>
                    <td class="text-right dj-num"><?= (float) $row['credit'] != 0.0 ? to_currency($row['credit']) : '' ?></td>
                    <td class="text-right dj-num"><?= to_currency((string) $row['balance']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="dj-total-row"><th colspan="7"><?= lang('Accounting.closing_balance') ?></th><th class="text-right dj-num"><?= to_currency((string) $report['closing']) ?></th></tr>
        </tfoot>
    </table>
</div>

<?= view('partial/footer') ?>
