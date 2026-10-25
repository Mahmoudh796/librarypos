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


<?= view('reports/partials/result_head', ['title' => $title, 'subtitle' => $subtitle, 'summary' => $summary_data_1, 'costProfit' => true]) ?>

<div class="ct-chart ct-golden-section" id="chart1"></div>

<?= view($chart_type) ?>

<script src="<?= base_url('js/hide_cost_profit.js') ?>"></script>

<?= view('partial/footer') ?>
