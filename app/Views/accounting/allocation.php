<?php
/**
 * @var array $preview
 */
$year = $preview['year'];
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'shareholders', 'leaf' => lang('Accounting.profit_allocation')]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-tasks" aria-hidden="true"></span> <?= lang('Accounting.profit_allocation') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<form method="get" class="form-inline dj-ledger-filter print_hide">
    <label for="year"><?= lang('Accounting.fiscal_year') ?></label>
    <input type="number" id="year" name="year" min="2000" max="2100" class="form-control input-sm dj-ltr" value="<?= $year ?>">
    <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounting.show') ?></button>
</form>

<p class="help-block dj-hint"><?= lang('Accounting.allocation_help') ?></p>

<div class="row dj-kpi-grid">
    <div class="col-xs-12 col-sm-6">
        <article class="dj-kpi dj-kpi--accent">
            <header class="dj-kpi-head"><h2 class="dj-kpi-label"><?= lang($preview['net_profit'] < 0 ? 'Accounting.net_loss' : 'Accounting.net_profit') ?> <?= $year ?></h2></header>
            <p class="dj-kpi-value dj-num"><?= to_currency((string) $preview['net_profit']) ?></p>
        </article>
    </div>
    <div class="col-xs-12 col-sm-6">
        <article class="dj-kpi<?= abs($preview['total_share'] - 100) > 0.0001 ? ' dj-kpi--warning' : '' ?>">
            <header class="dj-kpi-head"><h2 class="dj-kpi-label"><?= lang('Accounting.total_profit_share') ?></h2></header>
            <p class="dj-kpi-value dj-num"><?= to_tax_decimals((string) $preview['total_share']) ?>%</p>
        </article>
    </div>
</div>

<table class="table table-bordered table-condensed dj-ledger-table">
    <thead>
        <tr>
            <th><?= lang('Accounting.shareholder') ?></th>
            <th class="text-right"><?= lang('Accounting.profit_share_percent') ?></th>
            <th class="text-right"><?= lang('Accounting.allocated_amount') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($preview['rows'] as $row): ?>
            <tr>
                <td dir="auto"><?= esc($row['name']) ?></td>
                <td class="text-right dj-num"><?= to_tax_decimals((string) $row['share']) ?>%</td>
                <td class="text-right dj-num"><?= to_currency((string) $row['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($preview['already_allocated']): ?>
    <div class="alert alert-info"><?= lang('Accounting.year_already_allocated', [$year]) ?></div>
<?php elseif (abs($preview['total_share'] - 100) > 0.0001 && $preview['rows'] !== []): ?>
    <div class="alert alert-warning" role="alert"><span class="glyphicon glyphicon-warning-sign" aria-hidden="true"></span> <?= lang('Accounting.profit_shares_must_total_100', [round($preview['total_share'], 2)]) ?> <a href="<?= esc(base_url('shareholders'), 'attr') ?>"><?= lang('Accounting.shareholders') ?></a></div>
<?php elseif ($preview['net_profit'] != 0.0 && $preview['rows'] !== []): ?>
    <button type="button" class="btn btn-primary" id="allocate"><?= lang('Accounting.post_allocation') ?></button>
    <script type="text/javascript">
        $('#allocate').on('click', function() {
            if (!window.confirm(<?= json_encode(lang('Accounting.allocation_confirm', [$year])) ?>)) {
                return;
            }
            const button = $(this);
            ruyaOnce(button, function() {
                $.post('<?= site_url('shareholders/allocate') ?>', { year: <?= $year ?> }, function(response) {
                    $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                    if (response.success) {
                        setTimeout(function() { window.location.reload(); }, 600);
                    } else {
                        ruyaRelease(button);
                    }
                }, 'json').fail(function() { ruyaRelease(button); });
            });
        });
    </script>
<?php endif; ?>

<?= view('partial/footer') ?>
