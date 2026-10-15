<?php
/**
 * @var array $allowed_modules
 * @var array $config
 * @var array|null $sales_dashboard
 * @var array|null $inventory_dashboard
 */

/**
 * Percentage change between two periods, or null when the prior period is zero.
 * Never fabricates a percentage from a zero base.
 */
$percentChange = static function (float $current, float $prior): ?float {
    if (abs($prior) < 0.00001) {
        return null;
    }

    return ($current - $prior) / abs($prior) * 100;
};

$formatPercent = static function (?float $percent): string {
    if ($percent === null) {
        return lang('Dashboard.no_prior_data');
    }

    return ($percent >= 0 ? '+' : '') . number_format($percent, 1) . '%';
};
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?php if (isset($sales_dashboard) || isset($inventory_dashboard)) { ?>
    <div id="dashboard">
        <h3 class="page-header"><span class="glyphicon glyphicon-dashboard">&nbsp;</span><?= lang('Dashboard.dashboard') ?></h3>

        <?php if (isset($sales_dashboard)) { ?>
            <?php $today = $sales_dashboard['today']; ?>

            <div class="row">
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-primary">
                        <div class="panel-heading"><?= lang('Dashboard.sales_today') ?></div>
                        <div class="panel-body">
                            <h3><?= to_currency($today['gross_sales']) ?></h3>
                            <small><?= lang('Dashboard.net_sales') ?>: <?= to_currency($today['net_sales']) ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-info">
                        <div class="panel-heading"><?= lang('Dashboard.transactions') ?></div>
                        <div class="panel-body">
                            <h3><?= to_quantity_decimals($today['transaction_count']) ?></h3>
                            <small><?= lang('Dashboard.average_transaction') ?>: <?= to_currency($today['average_transaction']) ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-warning">
                        <div class="panel-heading"><?= lang('Dashboard.gross_profit') ?></div>
                        <div class="panel-body">
                            <h3><?= to_currency($today['gross_profit']) ?></h3>
                            <small><?= lang('Dashboard.cost') ?>: <?= to_currency($today['cost']) ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-default">
                        <div class="panel-heading"><?= lang('Dashboard.returns') ?> / <?= lang('Dashboard.discounts') ?></div>
                        <div class="panel-body">
                            <h3><?= to_currency($today['returns']) ?></h3>
                            <small><?= lang('Dashboard.discounts') ?>: <?= to_currency($today['discounts']) ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><?= lang('Dashboard.sales_trend') ?></div>
                <table class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th><?= lang('Reports.date') ?></th>
                            <th class="text-right"><?= lang('Dashboard.net_sales') ?></th>
                            <th class="text-right"><?= lang('Dashboard.transactions') ?></th>
                            <th class="text-right"><?= lang('Dashboard.gross_profit') ?></th>
                            <th class="text-right"><?= lang('Dashboard.returns') ?></th>
                            <th class="text-right"><?= lang('Dashboard.discounts') ?></th>
                            <th class="text-right"><?= lang('Dashboard.tax') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $comparisons = [
                            ['label' => lang('Datepicker.today'), 'prior_label' => lang('Datepicker.yesterday'), 'current' => $sales_dashboard['today'], 'previous' => $sales_dashboard['yesterday']],
                            ['label' => lang('Datepicker.last_7'), 'prior_label' => lang('Datepicker.last_7'), 'current' => $sales_dashboard['last_7'], 'previous' => $sales_dashboard['prev_7']],
                            ['label' => lang('Datepicker.this_month'), 'prior_label' => lang('Datepicker.last_month'), 'current' => $sales_dashboard['this_month'], 'previous' => $sales_dashboard['prev_month']],
                        ];

                        foreach ($comparisons as $row) {
                            $change = $percentChange((float) $row['current']['net_sales'], (float) $row['previous']['net_sales']);
                            ?>
                            <tr>
                                <th>
                                    <?= esc($row['label']) ?>
                                    <br>
                                    <span class="text-muted small">
                                        <?= $formatPercent($change) ?> <?= lang('Dashboard.vs_period') ?> <?= esc($row['prior_label']) ?>
                                    </span>
                                </th>
                                <td class="text-right">
                                    <?= to_currency($row['current']['net_sales']) ?><br>
                                    <span class="text-muted small"><?= to_currency($row['previous']['net_sales']) ?></span>
                                </td>
                                <td class="text-right">
                                    <?= to_quantity_decimals($row['current']['transaction_count']) ?><br>
                                    <span class="text-muted small"><?= to_quantity_decimals($row['previous']['transaction_count']) ?></span>
                                </td>
                                <td class="text-right"><?= to_currency($row['current']['gross_profit']) ?><br><span class="text-muted small"><?= to_currency($row['previous']['gross_profit']) ?></span></td>
                                <td class="text-right"><?= to_currency($row['current']['returns']) ?><br><span class="text-muted small"><?= to_currency($row['previous']['returns']) ?></span></td>
                                <td class="text-right"><?= to_currency($row['current']['discounts']) ?><br><span class="text-muted small"><?= to_currency($row['previous']['discounts']) ?></span></td>
                                <td class="text-right"><?= to_currency($row['current']['tax']) ?><br><span class="text-muted small"><?= to_currency($row['previous']['tax']) ?></span></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($sales_dashboard['trend']) > 1) { ?>
                <div class="panel panel-default">
                    <div class="panel-heading"><?= lang('Dashboard.sales_trend') ?></div>
                    <div class="panel-body">
                        <div id="dashboard_trend" style="height: 220px;"></div>
                    </div>
                </div>
                <script type="text/javascript">
                    (function () {
                        if (typeof Chartist === 'undefined') {
                            return;
                        }

                        const data = {
                            labels: <?= json_encode(array_map(static fn (array $point): string => to_date(strtotime($point['date'])), $sales_dashboard['trend']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                            series: [<?= json_encode(array_map(static fn (array $point): float => round($point['net_sales'], totals_decimals()), $sales_dashboard['trend']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>]
                        };

                        new Chartist.Line('#dashboard_trend', data, {
                            width: '100%',
                            height: '220px',
                            showPoint: true,
                            lineSmooth: false
                        });
                    })();
                </script>
            <?php } ?>
        <?php } ?>

        <?php if (isset($inventory_dashboard)) { ?>
            <div class="row">
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-warning">
                        <div class="panel-heading"><?= lang('Dashboard.low_stock') ?></div>
                        <div class="panel-body">
                            <h3><?= to_quantity_decimals($inventory_dashboard['low_stock_count']) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-danger">
                        <div class="panel-heading"><?= lang('Dashboard.out_of_stock') ?></div>
                        <div class="panel-body">
                            <h3><?= to_quantity_decimals($inventory_dashboard['out_of_stock_count']) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-default">
                        <div class="panel-heading"><?= lang('Dashboard.inventory_cost_value') ?></div>
                        <div class="panel-body">
                            <h3><?= to_currency($inventory_dashboard['cost_value']) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="panel panel-default">
                        <div class="panel-heading"><?= lang('Dashboard.inventory_retail_value') ?></div>
                        <div class="panel-body">
                            <h3><?= to_currency($inventory_dashboard['retail_value']) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
<?php } ?>

<h3 class="text-center"><?= lang('Common.welcome_message') ?></h3>

<div id="home_module_list">
    <?php foreach($allowed_modules as $module) { ?>
        <div class="module_item" title="<?= lang("Module.$module->module_id" . '_desc') ?>">
            <a href="<?= base_url($module->module_id) ?>"><img src="<?= base_url("images/menubar/$module->module_id.svg") ?>" style="border-width: 0; height: 64px; max-width: 64px;" alt="Menubar Image"></a>
            <a href="<?= base_url($module->module_id) ?>"><?= lang("Module.$module->module_id") ?></a>
        </div>
    <?php } ?>
</div>

<?= view('partial/footer') ?>
