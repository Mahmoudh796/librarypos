<?php
/**
 * Dashboard: answers, in order, how sales are doing, whether we are making
 * money, how much cash we hold, who owes whom, and what stock needs action.
 * Every figure links to the screen that explains it.
 *
 * @var string     $period
 * @var array|null $sales_dashboard
 * @var array|null $inventory_dashboard
 * @var array|null $finance_dashboard
 * @var list<string> $store_placeholders
 */

/** Change against the comparison period, or null when there is nothing to compare with. */
$change = static function (float $current, float $previous): ?float {
    return abs($previous) < 0.005 ? null : ($current - $previous) / abs($previous) * 100;
};

$trend = static function (?float $percent) {
    if ($percent === null) {
        return '<span class="ruya-trend ruya-trend--flat">' . lang('Dashboard.no_prior_data') . '</span>';
    }

    $tone = abs($percent) < 0.05 ? 'flat' : ($percent > 0 ? 'up' : 'down');
    $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '■'][$tone];
    $text = ($percent > 0 ? '+' : '') . to_percent($percent);

    return '<span class="ruya-trend ruya-trend--' . $tone . '"><span aria-hidden="true">' . $arrow . '</span> <span dir="ltr">' . esc($text) . '</span></span>';
};

/** Arabic needs four count forms; the key carries them, {0} is the number. */
$countPhrase = static function (string $key, int $count): string {
    $form = $count === 1 ? 'one' : ($count === 2 ? 'two' : ($count >= 3 && $count <= 10 ? 'few' : 'many'));

    return lang($key . '_' . $form, [to_quantity_decimals((string) $count)]);
};

$periods = ['today' => 'Datepicker.today', 'week' => 'Datepicker.last_7', 'month' => 'Datepicker.this_month'];
$previousLabel = ['today' => 'Dashboard.vs_yesterday', 'week' => 'Dashboard.vs_previous_week', 'month' => 'Dashboard.vs_previous_month'][$period];
$finance = $finance_dashboard ?? [];
$range = $sales_dashboard['range'] ?? [date('Y-m-d'), date('Y-m-d')];
$salesReport = static fn (string $report) => site_url("reports/$report/{$range[0]}/{$range[1]}/complete/all");
?>

<?= view('partial/header') ?>

<?php if (isset($sales_dashboard) || isset($inventory_dashboard) || $finance !== [] || !empty($store_placeholders)): ?>
<div id="dashboard" class="dj-dashboard">
    <div class="dj-page-head ruya-report-head">
        <h1 class="dj-page-title"><span class="glyphicon glyphicon-dashboard" aria-hidden="true"></span><?= lang('Dashboard.dashboard') ?></h1>
        <?php if (isset($sales_dashboard) || isset($finance['expenses'])): ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="<?= esc(lang('Reports.period'), 'attr') ?>">
                <?php foreach ($periods as $key => $label): ?>
                    <a class="btn <?= $key === $period ? 'btn-primary active' : 'btn-default' ?>" href="<?= site_url('home?period=' . $key) ?>"<?= $key === $period ? ' aria-current="true"' : '' ?>><?= lang($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $alerts = [];
    if (!empty($finance['unmapped_payments'])) {
        $alerts[] = ['warning', lang('Dashboard.unmapped_payments', [esc(implode('، ', $finance['unmapped_payments']))]), 'accounts/mappings'];
    }
    foreach ($finance['treasuries'] ?? [] as $treasury) {
        if ($treasury['balance'] < 0) {
            $alerts[] = ['danger', lang('Dashboard.negative_treasury', [esc($treasury['name'])]), 'treasury?treasury_id=' . $treasury['treasury_id']];
        }
    }
    if (!empty($store_placeholders)) {
        $alerts[] = ['warning', lang('Dashboard.store_details_missing', [esc(implode('، ', array_map(static fn ($key) => lang('Dashboard.store_field_' . $key), $store_placeholders)))]), 'config'];
    }
    if (!empty($inventory_dashboard['out_of_stock_count'])) {
        $alerts[] = ['warning', esc($countPhrase('Dashboard.out_of_stock_n', (int) $inventory_dashboard['out_of_stock_count'])), 'items?filters%5B%5D=out_of_stock'];
    }
    ?>
    <?php if ($alerts !== []): ?>
        <section class="ruya-alerts" aria-label="<?= esc(lang('Dashboard.attention'), 'attr') ?>">
            <?php foreach ($alerts as [$tone, $message, $href]): ?>
                <a class="alert alert-<?= $tone ?> ruya-alert" href="<?= esc(site_url($href), 'attr') ?>">
                    <span class="glyphicon glyphicon-<?= $tone === 'danger' ? 'exclamation-sign' : 'warning-sign' ?>" aria-hidden="true"></span>
                    <span><?= $message ?></span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (isset($sales_dashboard) || isset($finance['month_profit'])): ?>
        <h2 class="ruya-section-title"><?= lang('Dashboard.section_sales_profit') ?></h2>
        <div class="ruya-kpis ruya-kpis--primary">
            <?php if (isset($sales_dashboard)): ?>
                <?php [$current, $previous] = [$sales_dashboard['current'], $sales_dashboard['previous']]; ?>
                <a class="ruya-kpi ruya-kpi--hero" href="<?= esc($period === 'today' ? $salesReport('detailed_sales') : $salesReport('summary_sales'), 'attr') ?>">
                    <span class="ruya-kpi__label"><?= lang('Dashboard.net_sales_' . $period) ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $current['net_sales']) ?></span>
                    <span class="ruya-kpi__meta"><?= $trend($change($current['net_sales'], $previous['net_sales'])) ?> <span><?= lang($previousLabel) ?> <span class="dj-num"><?= to_currency((string) $previous['net_sales']) ?></span></span></span>
                </a>
                <a class="ruya-kpi ruya-kpi--hero<?= $current['gross_profit'] < 0 ? ' ruya-kpi--negative' : '' ?>" href="<?= esc($salesReport('summary_items'), 'attr') ?>">
                    <span class="ruya-kpi__label"><?= lang('Dashboard.gross_profit') ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $current['gross_profit']) ?></span>
                    <span class="ruya-kpi__meta">
                        <?= $trend($change($current['gross_profit'], $previous['gross_profit'])) ?>
                        <?php if ($current['net_sales'] > 0): ?>
                            <span><?= lang('Dashboard.margin') ?> <span class="dj-num" dir="ltr"><?= to_percent($current['gross_profit'] / $current['net_sales'] * 100) ?></span></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endif; ?>
            <?php if (isset($finance['month_profit'])): ?>
                <a class="ruya-kpi<?= $finance['month_profit'] < 0 ? ' ruya-kpi--negative' : '' ?>" href="<?= esc(site_url('accounting/incomeStatement?from=' . date('Y-m-01') . '&to=' . date('Y-m-d')), 'attr') ?>">
                    <span class="ruya-kpi__label"><?= lang('Dashboard.month_net_profit') ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $finance['month_profit']) ?></span>
                    <span class="ruya-kpi__meta"><?= lang('Dashboard.month_net_profit_hint') ?></span>
                </a>
            <?php endif; ?>
            <?php if (isset($finance['expenses'])): ?>
                <a class="ruya-kpi" href="<?= esc(site_url('expenses?start_date=' . $finance['expenses_range'][0] . '&end_date=' . $finance['expenses_range'][1]), 'attr') ?>">
                    <span class="ruya-kpi__label"><?= lang('Dashboard.expenses_' . $period) ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $finance['expenses']) ?></span>
                    <span class="ruya-kpi__meta"><?= lang('Dashboard.expenses_hint') ?></span>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($finance['treasuries']) || isset($finance['receivables'])): ?>
        <h2 class="ruya-section-title"><?= lang('Dashboard.section_cash') ?></h2>
        <div class="ruya-kpis ruya-kpis--primary">
            <?php foreach ($finance['treasuries'] ?? [] as $treasury): ?>
                <a class="ruya-kpi<?= $treasury['kind'] === 'cash' ? ' ruya-kpi--hero' : '' ?><?= $treasury['balance'] < 0 ? ' ruya-kpi--negative' : '' ?>" href="<?= esc(site_url('treasury?treasury_id=' . $treasury['treasury_id']), 'attr') ?>">
                    <span class="ruya-kpi__label"><span class="glyphicon glyphicon-<?= $treasury['kind'] === 'cash' ? 'usd' : 'credit-card' ?>" aria-hidden="true"></span> <?= esc($treasury['name']) ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $treasury['balance']) ?></span>
                    <span class="ruya-kpi__meta"><?= lang('Treasury.show_movements') ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (isset($finance['receivables'])): ?>
                <a class="ruya-kpi" href="<?= esc(site_url('accounting/statement?party_type=customer'), 'attr') ?>">
                    <span class="ruya-kpi__label"><?= lang('Dashboard.receivables') ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $finance['receivables']) ?></span>
                    <span class="ruya-kpi__meta"><?= lang('Dashboard.receivables_hint') ?></span>
                </a>
                <a class="ruya-kpi" href="<?= esc(site_url('accounting/statement?party_type=supplier'), 'attr') ?>">
                    <span class="ruya-kpi__label"><?= lang('Dashboard.payables') ?></span>
                    <span class="ruya-kpi__value dj-money"><?= to_currency((string) $finance['payables']) ?></span>
                    <span class="ruya-kpi__meta"><?= lang('Dashboard.payables_hint') ?></span>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($inventory_dashboard)): ?>
        <h2 class="ruya-section-title"><?= lang('Dashboard.inventory') ?></h2>
        <div class="ruya-kpis">
            <a class="ruya-kpi<?= $inventory_dashboard['out_of_stock_count'] > 0 ? ' ruya-kpi--alert' : '' ?>" href="<?= esc(site_url('items?filters%5B%5D=out_of_stock'), 'attr') ?>">
                <span class="ruya-kpi__label"><span class="glyphicon glyphicon-ban-circle" aria-hidden="true"></span> <?= lang('Dashboard.out_of_stock') ?></span>
                <span class="ruya-kpi__value dj-num"><?= to_quantity_decimals((string) $inventory_dashboard['out_of_stock_count']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.show_products') ?></span>
            </a>
            <a class="ruya-kpi<?= $inventory_dashboard['low_stock_count'] > 0 ? ' ruya-kpi--alert' : '' ?>" href="<?= esc(site_url('items?filters%5B%5D=low_inventory'), 'attr') ?>">
                <span class="ruya-kpi__label"><span class="glyphicon glyphicon-warning-sign" aria-hidden="true"></span> <?= lang('Dashboard.low_stock') ?></span>
                <span class="ruya-kpi__value dj-num"><?= to_quantity_decimals((string) $inventory_dashboard['low_stock_count']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.show_products') ?></span>
            </a>
            <a class="ruya-kpi" href="<?= esc(site_url('accounting/valuation'), 'attr') ?>">
                <span class="ruya-kpi__label"><?= lang('Dashboard.inventory_cost_value') ?></span>
                <span class="ruya-kpi__value dj-money"><?= to_currency((string) $inventory_dashboard['cost_value']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.inventory_cost_hint') ?></span>
            </a>
            <div class="ruya-kpi">
                <span class="ruya-kpi__label"><?= lang('Dashboard.inventory_retail_value') ?></span>
                <span class="ruya-kpi__value dj-money"><?= to_currency((string) $inventory_dashboard['retail_value']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.inventory_retail_hint') ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($sales_dashboard)): ?>
        <?php
        // One point per day, zero on days without sales, so gaps are not drawn as trends.
        $byDate = array_column($sales_dashboard['trend'], 'net_sales', 'date');
        $trendFrom = $period === 'month' ? $range[0] : date('Y-m-d', strtotime('-6 days'));
        $points = [];
        for ($day = strtotime($trendFrom); $day <= strtotime('today'); $day = strtotime('+1 day', $day)) {
            $points[date('Y-m-d', $day)] = (float) ($byDate[date('Y-m-d', $day)] ?? 0);
        }
        $current = $sales_dashboard['current'];
        ?>
        <div class="ruya-split">
            <section>
                <h2 class="ruya-section-title"><?= lang('Dashboard.sales_trend') ?> <span class="ruya-section-hint"><?= lang($period === 'month' ? 'Datepicker.this_month' : 'Datepicker.last_7') ?></span></h2>
                <div class="dj-chart" role="img" aria-label="<?= esc(lang('Dashboard.sales_trend'), 'attr') ?>">
                    <?php if (array_sum($points) > 0): ?>
                        <div id="dashboard_trend" class="dj-chart-canvas"></div>
                    <?php else: ?>
                        <div class="ruya-empty"><span class="glyphicon glyphicon-stats" aria-hidden="true"></span><?= lang('Dashboard.no_sales_in_period') ?></div>
                    <?php endif; ?>
                </div>
            </section>
            <section>
                <h2 class="ruya-section-title"><?= lang('Dashboard.top_products') ?></h2>
                <?php if ($sales_dashboard['top_items'] === []): ?>
                    <div class="ruya-empty"><?= lang('Dashboard.no_sales_in_period') ?></div>
                <?php else: ?>
                    <ol class="ruya-ranking">
                        <?php foreach ($sales_dashboard['top_items'] as $item): ?>
                            <li>
                                <span class="ruya-ranking__name" dir="auto"><?= esc($item['name'] ?? '') ?></span>
                                <span class="ruya-ranking__figure dj-num"><?= to_currency((string) $item['revenue']) ?></span>
                                <span class="ruya-ranking__sub"><?= lang('Dashboard.units_sold', [to_quantity_decimals((string) $item['quantity_sold'])]) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </div>

        <h2 class="ruya-section-title"><?= lang('Dashboard.section_details') ?></h2>
        <div class="ruya-kpis">
            <div class="ruya-kpi">
                <span class="ruya-kpi__label"><?= lang('Dashboard.transactions') ?></span>
                <span class="ruya-kpi__value dj-num"><?= to_quantity_decimals((string) $current['transaction_count']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.average_transaction') ?> <span class="dj-num"><?= to_currency((string) $current['average_transaction']) ?></span></span>
            </div>
            <div class="ruya-kpi">
                <span class="ruya-kpi__label"><?= lang('Dashboard.returns') ?></span>
                <span class="ruya-kpi__value dj-money"><?= to_currency((string) $current['returns']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.return_count', [to_quantity_decimals((string) $current['return_count'])]) ?></span>
            </div>
            <a class="ruya-kpi" href="<?= esc(site_url("reports/summary_discounts/{$range[0]}/{$range[1]}/complete/all/0"), 'attr') ?>">
                <span class="ruya-kpi__label"><?= lang('Dashboard.discounts') ?></span>
                <span class="ruya-kpi__value dj-money"><?= to_currency((string) $current['discounts']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.discounts_hint') ?></span>
            </a>
            <div class="ruya-kpi">
                <span class="ruya-kpi__label"><?= lang('Dashboard.cost') ?></span>
                <span class="ruya-kpi__value dj-money"><?= to_currency((string) $current['cost']) ?></span>
                <span class="ruya-kpi__meta"><?= lang('Dashboard.cost_hint') ?></span>
            </div>
        </div>

        <?php if (array_sum($points) > 0): ?>
            <script type="text/javascript">
                (function () {
                    if (typeof Chartist === 'undefined') {
                        return;
                    }
                    const values = <?= json_encode(array_values(array_map(static fn ($value) => round($value, totals_decimals()), $points))) ?>;
                    const labels = <?= json_encode(array_map(static fn ($date) => date('d/m', strtotime($date)), array_keys($points))) ?>;
                    const money = <?= json_encode(array_values(array_map(static fn ($value) => to_currency((string) $value), $points)), JSON_UNESCAPED_UNICODE) ?>;

                    new Chartist.Line('#dashboard_trend', { labels: labels, series: [values.map(function (value, i) { return { meta: labels[i], value: value }; })] }, {
                        height: '240px',
                        showArea: true,
                        lineSmooth: false,
                        fullWidth: true,
                        chartPadding: { right: 24, left: 8 },
                        axisX: { labelInterpolationFnc: function (value, index) { return values.length > 14 && index % 3 !== 0 ? '' : value; } },
                        plugins: Chartist.plugins && Chartist.plugins.tooltip ? [Chartist.plugins.tooltip({
                            transformTooltipTextFnc: function (value) {
                                const index = values.indexOf(parseFloat(value));
                                return index > -1 ? money[index] : value;
                            }
                        })] : []
                    });
                })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>
    <div class="dj-empty-state">
        <h1 class="dj-page-title"><?= lang('Common.welcome_message') ?></h1>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
