<?php
/**
 * Shared head of every report result: where am I, which period, the totals,
 * and the actions — before any row is read.
 *
 * Report routes are reports/<presentation>_<name>/<start>/<end>/<filters…>, so
 * changing the period rewrites the two date segments and keeps every other
 * filter, and switching between summary / chart / detail swaps the first one.
 *
 * @var string $title
 * @var string $subtitle
 * @var array  $summary       name => value, shown as KPI cards
 * @var bool   $costProfit    whether the cost/profit toggle applies
 */

$reportPath = get_report_path_info();
$segments = service('uri')->getSegments();
$segment = $segments[1] ?? '';
$hasPeriod = isset($segments[2], $segments[3]) && preg_match('/^\d{4}-\d{2}-\d{2}/', $segments[2]) === 1;

$presentations = [
    'summary'           => ['Reports.view_summary', 'list', ['sales', 'categories', 'expenses_categories', 'customers', 'suppliers', 'items', 'employees', 'taxes', 'sales_taxes', 'discounts', 'payments']],
    'graphical_summary' => ['Reports.view_chart', 'stats', ['sales', 'categories', 'expenses_categories', 'customers', 'suppliers', 'items', 'employees', 'taxes', 'sales_taxes', 'discounts', 'payments']],
    'detailed'          => ['Reports.view_detail', 'list-alt', ['sales', 'receivings']],
];

$current = null;
$name = $segment;
foreach (array_keys($presentations) as $prefix) {
    if (str_starts_with($segment, $prefix . '_') && ($current === null || strlen($prefix) > strlen($current))) {
        $current = $prefix;
        $name = substr($segment, strlen($prefix) + 1);
    }
}

$variants = [];
if ($current !== null && $hasPeriod) {
    foreach ($presentations as $prefix => [$label, $icon, $names]) {
        if (in_array($name, $names, true)) {
            $variants[$prefix] = [$label, $icon, site_url(implode('/', array_merge(['reports', $prefix . '_' . $name], array_slice($segments, 2))))];
        }
    }
}
?>
<div class="dj-page-head">
    <?= view('partial/page_path', ['crumbs' => [['module' => 'reports'], ['label' => $title]]]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-stats" aria-hidden="true"></span> <?= esc($title) ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<div class="ruya-filterbar print_hide">
    <?php if ($hasPeriod): ?>
        <div class="ruya-filterbar__field">
            <label for="daterangepicker"><?= lang('Reports.period') ?></label>
            <input type="text" id="daterangepicker" class="form-control input-sm">
        </div>
    <?php endif; ?>

    <?php if (count($variants) > 1): ?>
        <div class="ruya-filterbar__field">
            <span class="ruya-filterbar__label-text"><?= lang('Reports.presentation') ?></span>
            <div class="btn-group btn-group-sm" role="group" aria-label="<?= esc(lang('Reports.presentation'), 'attr') ?>">
                <?php foreach ($variants as $prefix => [$label, $icon, $href]): ?>
                    <a class="btn <?= $prefix === $current ? 'btn-primary active' : 'btn-default' ?>" href="<?= esc($href, 'attr') ?>"<?= $prefix === $current ? ' aria-current="page"' : '' ?>>
                        <span class="glyphicon glyphicon-<?= $icon ?>" aria-hidden="true"></span> <?= lang($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ruya-filterbar__actions">
        <a id="edit_report_inputs" class="btn btn-default btn-sm" href="<?= $reportPath['input_path'] ?>">
            <span class="glyphicon glyphicon-filter" aria-hidden="true"></span> <?= lang('Reports.edit_inputs') ?>
        </a>
        <?php if (!empty($costProfit)): ?>
            <button id="toggleCostProfitButton" type="button" class="btn btn-default btn-sm"><?= lang('Reports.toggle_cost_and_profit') ?></button>
        <?php endif; ?>
        <button type="button" class="btn btn-default btn-sm" onclick="window.print()">
            <span class="glyphicon glyphicon-print" aria-hidden="true"></span> <?= lang('Common.print') ?>
        </button>
    </div>
</div>

<p class="ruya-report-period"><?= esc($subtitle) ?></p>

<?php if (!empty($summary)): ?>
    <div class="ruya-kpis" aria-label="<?= esc(lang('Reports.totals'), 'attr') ?>">
        <?php foreach ($summary as $key => $value): ?>
            <div class="ruya-kpi<?= $key === 'total' ? ' ruya-kpi--hero' : '' ?><?= is_numeric($value) && $value < 0 ? ' ruya-kpi--negative' : '' ?>" data-key="<?= esc($key, 'attr') ?>">
                <span class="ruya-kpi__label"><?= lang("Reports.$key") ?></span>
                <span class="ruya-kpi__value dj-money">
                    <?= $key === 'total_quantity' || !is_numeric($value) ? esc((string) $value) : esc(to_currency((string) $value)) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($hasPeriod): ?>
    <script type="text/javascript">
        $(document).ready(function () {
            <?= view('partial/daterangepicker') ?>
            setTimeout(function () {
                window.djPeriod.set(<?= json_encode(substr($segments[2], 0, 10)) ?>, <?= json_encode(substr($segments[3], 0, 10)) ?>);
            }, 0);

            // A new period reloads the same report with every other filter kept.
            $('#daterangepicker').on('apply.daterangepicker', function () {
                const parts = window.location.pathname.split('/');
                const at = parts.lastIndexOf(<?= json_encode($segment) ?>);
                parts[at + 1] = start_date.substring(0, 10);
                parts[at + 2] = end_date.substring(0, 10);
                window.location.pathname = parts.join('/');
            });
        });
    </script>
<?php endif; ?>
