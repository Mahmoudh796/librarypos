<?php
/**
 * @var array $report
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'valuation']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-th-list" aria-hidden="true"></span> <?= lang('Accounting.inventory_valuation') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<div class="row dj-kpi-grid">
    <div class="col-xs-12 col-sm-4">
        <article class="dj-kpi dj-kpi--accent">
            <header class="dj-kpi-head"><h2 class="dj-kpi-label"><?= lang('Accounting.stock_at_average_cost') ?></h2></header>
            <p class="dj-kpi-value dj-num"><?= to_currency((string) $report['stock_value']) ?></p>
        </article>
    </div>
    <div class="col-xs-12 col-sm-4">
        <article class="dj-kpi">
            <header class="dj-kpi-head"><h2 class="dj-kpi-label"><?= lang('Accounting.inventory_account_balance') ?></h2></header>
            <p class="dj-kpi-value dj-num"><?= to_currency((string) $report['ledger_value']) ?></p>
        </article>
    </div>
    <div class="col-xs-12 col-sm-4">
        <article class="dj-kpi<?= abs($report['difference']) >= 0.01 ? ' dj-kpi--warning' : '' ?>">
            <header class="dj-kpi-head"><h2 class="dj-kpi-label"><?= lang('Accounting.difference') ?></h2></header>
            <p class="dj-kpi-value dj-num"><?= to_currency((string) $report['difference']) ?></p>
        </article>
    </div>
</div>

<p class="help-block dj-hint"><?= lang('Accounting.valuation_help') ?></p>

<div class="table-responsive">
    <table class="table table-striped table-bordered table-condensed dj-ledger-table">
        <thead>
            <tr>
                <th><?= lang('Items.item_number') ?></th>
                <th><?= lang('Items.name') ?></th>
                <th><?= lang('Items.category') ?></th>
                <th class="text-right"><?= lang('Items.quantity') ?></th>
                <th class="text-right"><?= lang('Accounting.average_cost') ?></th>
                <th class="text-right"><?= lang('Accounting.value') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report['items'] as $item): ?>
                <tr>
                    <td class="dj-ltr"><?= esc($item['item_number']) ?></td>
                    <td dir="auto"><a href="<?= esc(base_url('accounting/costHistory') . '?item_id=' . $item['item_id'], 'attr') ?>"><?= esc($item['name']) ?></a></td>
                    <td dir="auto"><?= esc($item['category']) ?></td>
                    <td class="text-right dj-num"><?= to_quantity_decimals($item['quantity']) ?></td>
                    <td class="text-right dj-num"><?= to_currency($item['cost_price']) ?></td>
                    <td class="text-right dj-num"><?= to_currency((string) $item['value']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
