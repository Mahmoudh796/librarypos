<?php
/**
 * @var array $stock_locations
 * @var array $item_count
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?php $reportPath = get_report_path_info(); ?>

<div class="dj-page-head">
    <?= view('partial/page_path', ['crumbs' => $reportPath['crumbs']]) ?>
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-filter" aria-hidden="true"></span><?= esc($reportPath['crumbs'][1]['label']) ?>
    </h1>
    <p class="dj-page-subtitle"><?= lang('Reports.report_input') ?></p>
    <?= view('partial/page_nav') ?>
</div>

<?php
if (isset($error)) {
    echo '<div class="alert alert-dismissible alert-danger">' . esc($error) . '</div>';
}
?>

<section class="dj-report-input">
    <?= form_open('#', ['id' => 'item_form', 'enctype' => 'multipart/form-data', 'class' => 'form-horizontal']) ?>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Reports.stock_location'), 'reports_stock_location_label', ['class' => 'control-label col-xs-2 text-danger required']) ?>
        <div id="report_stock_location" class="col-xs-3">
            <?= form_dropdown('stock_location', $stock_locations, 'all', 'id="location_id" class="form-control"') ?>
        </div>
    </div>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Reports.item_count'), 'reports_item_count_label', ['class' => 'control-label col-xs-2 text-danger required']) ?>
        <div id="report_item_count" class="col-xs-3">
            <?= form_dropdown('item_count', $item_count, 'all', 'id="item_count" class="form-control"') ?>
        </div>
    </div>

    <div class="dj-report-actions">
        <?php
        echo form_button([
            'name'    => 'generate_report',
            'id'      => 'generate_report',
            'content' => lang('Reports.show_report'),
            'class'   => 'btn btn-primary btn-sm'
        ]) ?>
    </div>

    <?= form_close() ?>
</section>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $("#generate_report").click(function() {
            window.location = [window.location, $("#location_id").val(), $("#item_count").val()].join("/");
        });
    });
</script>
