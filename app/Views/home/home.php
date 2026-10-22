<?php
/**
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

/**
 * Direction of a delta as a class, so an increase/decrease is signalled by the
 * glyph and the sign as well as by colour.
 */
$deltaTone = static function (?float $percent): string {
    if ($percent === null) {
        return 'dj-delta--flat';
    }

    return $percent >= 0 ? 'dj-delta--up' : 'dj-delta--down';
};

$deltaIcon = static function (?float $percent): string {
    if ($percent === null) {
        return 'glyphicon-minus';
    }

    return $percent >= 0 ? 'glyphicon-triangle-top' : 'glyphicon-triangle-bottom';
};
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?php if (isset($sales_dashboard) || isset($inventory_dashboard)) { ?>
    <div id="dashboard" class="dj-dashboard">
        <div class="dj-page-head">
            <h1 class="dj-page-title"><span class="glyphicon glyphicon-dashboard" aria-hidden="true"></span><?= lang('Dashboard.dashboard') ?></h1>
            <?= view('partial/page_nav') ?>
        </div>

        <?php if (isset($sales_dashboard)) { ?>
            <?php $today = $sales_dashboard['today']; ?>

            <div class="row dj-kpi-grid">
                <div class="col-sm-6 col-md-3">
                    <article class="dj-kpi dj-kpi--accent">
                        <header class="dj-kpi-head">
                            <span class="dj-kpi-icon glyphicon glyphicon-stats" aria-hidden="true"></span>
                            <h2 class="dj-kpi-label"><?= lang('Dashboard.sales_today') ?></h2>
                        </header>
                        <p class="dj-kpi-value dj-num"><?= to_currency($today['gross_sales']) ?></p>
                        <footer class="dj-kpi-meta">
                            <span class="dj-kpi-meta-key"><?= lang('Dashboard.net_sales') ?></span>
                            <span class="dj-kpi-meta-value dj-num"><?= to_currency($today['net_sales']) ?></span>
                        </footer>
                    </article>
                </div>
                <div class="col-sm-6 col-md-3">
                    <article class="dj-kpi">
                        <header class="dj-kpi-head">
                            <span class="dj-kpi-icon glyphicon glyphicon-list" aria-hidden="true"></span>
                            <h2 class="dj-kpi-label"><?= lang('Dashboard.transactions') ?></h2>
                        </header>
                        <p class="dj-kpi-value dj-num"><?= to_quantity_decimals($today['transaction_count']) ?></p>
                        <footer class="dj-kpi-meta">
                            <span class="dj-kpi-meta-key"><?= lang('Dashboard.average_transaction') ?></span>
                            <span class="dj-kpi-meta-value dj-num"><?= to_currency($today['average_transaction']) ?></span>
                        </footer>
                    </article>
                </div>
                <div class="col-sm-6 col-md-3">
                    <article class="dj-kpi">
                        <header class="dj-kpi-head">
                            <span class="dj-kpi-icon glyphicon glyphicon-usd" aria-hidden="true"></span>
                            <h2 class="dj-kpi-label"><?= lang('Dashboard.gross_profit') ?></h2>
                        </header>
                        <p class="dj-kpi-value dj-num"><?= to_currency($today['gross_profit']) ?></p>
                        <footer class="dj-kpi-meta">
                            <span class="dj-kpi-meta-key"><?= lang('Dashboard.cost') ?></span>
                            <span class="dj-kpi-meta-value dj-num"><?= to_currency($today['cost']) ?></span>
                        </footer>
                    </article>
                </div>
                <div class="col-sm-6 col-md-3">
                    <article class="dj-kpi">
                        <header class="dj-kpi-head">
                            <span class="dj-kpi-icon glyphicon glyphicon-retweet" aria-hidden="true"></span>
                            <h2 class="dj-kpi-label"><?= lang('Dashboard.returns') ?> / <?= lang('Dashboard.discounts') ?></h2>
                        </header>
                        <p class="dj-kpi-value dj-num"><?= to_currency($today['returns']) ?></p>
                        <footer class="dj-kpi-meta">
                            <span class="dj-kpi-meta-key"><?= lang('Dashboard.discounts') ?></span>
                            <span class="dj-kpi-meta-value dj-num"><?= to_currency($today['discounts']) ?></span>
                        </footer>
                    </article>
                </div>
            </div>

            <section class="dj-section" aria-labelledby="dj-trend-title">
                <div class="dj-section-head">
                    <h2 class="dj-section-title" id="dj-trend-title"><span class="glyphicon glyphicon-signal" aria-hidden="true"></span><?= lang('Dashboard.sales_trend') ?></h2>
                </div>

                <?php if (count($sales_dashboard['trend']) > 1) { ?>
                    <div class="dj-chart" role="img" aria-label="<?= esc(lang('Dashboard.sales_trend'), 'attr') ?>">
                        <div id="dashboard_trend" class="dj-chart-canvas"></div>
                    </div>
                <?php } ?>

                <?php
                $comparisons = [
                    ['label' => lang('Datepicker.today'), 'current' => $sales_dashboard['today'], 'previous' => $sales_dashboard['yesterday']],
                    ['label' => lang('Datepicker.last_7'), 'current' => $sales_dashboard['last_7'], 'previous' => $sales_dashboard['prev_7']],
                    ['label' => lang('Datepicker.this_month'), 'current' => $sales_dashboard['this_month'], 'previous' => $sales_dashboard['prev_month']],
                ];
                ?>
                <div class="dj-periods">
                    <?php foreach ($comparisons as $row) {
                        $change = $percentChange((float) $row['current']['net_sales'], (float) $row['previous']['net_sales']);
                        ?>
                        <article class="dj-period">
                            <h3 class="dj-period-label"><?= esc($row['label']) ?></h3>
                            <p class="dj-period-figure">
                                <span class="dj-period-value dj-num"><?= to_currency($row['current']['net_sales']) ?></span>
                                <span class="dj-delta <?= $deltaTone($change) ?>">
                                    <span class="glyphicon <?= $deltaIcon($change) ?>" aria-hidden="true"></span><?= $formatPercent($change) ?>
                                </span>
                            </p>
                            <p class="dj-period-prior"><?= lang('Dashboard.vs_period') ?> <span class="dj-num"><?= to_currency($row['previous']['net_sales']) ?></span></p>
                            <dl class="dj-period-meta">
                                <div class="dj-period-meta-row">
                                    <dt><?= lang('Dashboard.transactions') ?></dt>
                                    <dd class="dj-num"><?= to_quantity_decimals($row['current']['transaction_count']) ?></dd>
                                </div>
                                <div class="dj-period-meta-row">
                                    <dt><?= lang('Dashboard.gross_profit') ?></dt>
                                    <dd class="dj-num"><?= to_currency($row['current']['gross_profit']) ?></dd>
                                </div>
                                <div class="dj-period-meta-row">
                                    <dt><?= lang('Dashboard.returns') ?></dt>
                                    <dd class="dj-num"><?= to_currency($row['current']['returns']) ?></dd>
                                </div>
                                <div class="dj-period-meta-row">
                                    <dt><?= lang('Dashboard.discounts') ?></dt>
                                    <dd class="dj-num"><?= to_currency($row['current']['discounts']) ?></dd>
                                </div>
                                <div class="dj-period-meta-row">
                                    <dt><?= lang('Dashboard.tax') ?></dt>
                                    <dd class="dj-num"><?= to_currency($row['current']['tax']) ?></dd>
                                </div>
                            </dl>
                        </article>
                    <?php } ?>
                </div>

                <?php if (count($sales_dashboard['trend']) > 1) { ?>
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
                                height: '240px',
                                showPoint: true,
                                lineSmooth: false
                            });
                        })();
                    </script>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if (isset($inventory_dashboard)) { ?>
            <section class="dj-section" aria-labelledby="dj-inventory-title">
                <div class="dj-section-head">
                    <h2 class="dj-section-title" id="dj-inventory-title"><span class="glyphicon glyphicon-shopping-cart" aria-hidden="true"></span><?= lang('Dashboard.inventory') ?></h2>
                </div>

                <div class="row dj-kpi-grid">
                    <div class="col-sm-6 col-md-3">
                        <article class="dj-kpi dj-kpi--warning">
                            <header class="dj-kpi-head">
                                <span class="dj-kpi-icon glyphicon glyphicon-warning-sign" aria-hidden="true"></span>
                                <h2 class="dj-kpi-label"><?= lang('Dashboard.low_stock') ?></h2>
                            </header>
                            <p class="dj-kpi-value dj-num"><?= to_quantity_decimals($inventory_dashboard['low_stock_count']) ?></p>
                        </article>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <article class="dj-kpi dj-kpi--danger">
                            <header class="dj-kpi-head">
                                <span class="dj-kpi-icon glyphicon glyphicon-ban-circle" aria-hidden="true"></span>
                                <h2 class="dj-kpi-label"><?= lang('Dashboard.out_of_stock') ?></h2>
                            </header>
                            <p class="dj-kpi-value dj-num"><?= to_quantity_decimals($inventory_dashboard['out_of_stock_count']) ?></p>
                        </article>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <article class="dj-kpi">
                            <header class="dj-kpi-head">
                                <span class="dj-kpi-icon glyphicon glyphicon-tags" aria-hidden="true"></span>
                                <h2 class="dj-kpi-label"><?= lang('Dashboard.inventory_cost_value') ?></h2>
                            </header>
                            <p class="dj-kpi-value dj-num"><?= to_currency($inventory_dashboard['cost_value']) ?></p>
                        </article>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <article class="dj-kpi">
                            <header class="dj-kpi-head">
                                <span class="dj-kpi-icon glyphicon glyphicon-tag" aria-hidden="true"></span>
                                <h2 class="dj-kpi-label"><?= lang('Dashboard.inventory_retail_value') ?></h2>
                            </header>
                            <p class="dj-kpi-value dj-num"><?= to_currency($inventory_dashboard['retail_value']) ?></p>
                        </article>
                    </div>
                </div>
            </section>
        <?php } ?>
    </div>
<?php } else { ?>
    <div class="dj-empty-state">
        <h1 class="dj-page-title"><?= lang('Common.welcome_message') ?></h1>
    </div>
<?php } ?>

<?= view('partial/footer') ?>
