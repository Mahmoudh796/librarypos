<?php
/**
 * @var string $title
 * @var string $subtitle
 * @var string $chart_type
 * @var array $summary_data_1
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?php $reportPath = get_report_path_info(); ?>

<div class="dj-page-head">
    <?= view('partial/page_path', ['crumbs' => $reportPath['crumbs']]) ?>
    <?= view('partial/page_nav') ?>
</div>

<div id="page_title"><?= esc($title) ?></div>

<div id="page_subtitle"><?= esc($subtitle) ?></div>

<div class="ct-chart ct-golden-section" id="chart1"></div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <!-- Toggle Button -->
        <a id="edit_report_inputs" class="btn btn-default btn-sm print_hide" href="<?= $reportPath['input_path'] ?>">
            <span class="glyphicon glyphicon-filter">&nbsp;</span><?= lang('Reports.edit_inputs') ?>
        </a>
        <button id="toggleCostProfitButton" class="btn btn-default btn-sm print_hide">
            <?php echo lang('Reports.toggle_cost_and_profit'); ?>
        </button>
    </div>
</div>

<?= view($chart_type) ?>

<div id="chart_report_summary">
    <?php foreach ($summary_data_1 as $name => $value) { ?>
        <div class="summary_row" data-key="<?= esc($name) ?>"><?= lang("Reports.$name") . ': ' . (is_numeric($value) ? '<span class="dj-money" dir="ltr">' . esc(to_currency($value)) . '</span>' : esc($value)) ?></div>
    <?php } ?>
</div>

<script src="<?= base_url('js/hide_cost_profit.js') ?>"></script>

<?= view('partial/footer') ?>
