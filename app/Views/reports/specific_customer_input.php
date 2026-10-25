<?php
/**
 * @var string $specific_input_name
 * @var array $specific_input_data
 * @var array $sale_type_options
 * @var array $payment_type
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
        <?= form_label(lang('Reports.date_range'), 'report_date_range_label', ['class' => 'control-label col-xs-2 text-danger required']) ?>
        <div class="col-xs-3">
            <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm dj-input-date', 'id' => 'daterangepicker']) ?>
        </div>
    </div>

    <div class="form-group form-group-sm" id="report_specific_input_data">
        <?= form_label($specific_input_name, 'specific_input_name_label', ['class' => 'control-label col-xs-2 text-danger required']) ?>
        <div class="col-xs-3">
            <?= form_dropdown('specific_input_data', $specific_input_data, '', 'id="specific_input_data" class="form-control selectpicker" data-live-search="true"') ?>
        </div>
    </div>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Reports.sale_type'), 'reports_sale_type_label', ['class' => 'control-label col-xs-2 text-danger required']) ?>
        <div id="report_sale_type" class="col-xs-3">
            <?= form_dropdown('sale_type', $sale_type_options, 'complete', 'id="input_type" class="form-control"') ?>
        </div>
    </div>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Reports.payment_type'), 'reports_payment_type_label', ['class' => 'control-label col-xs-2 text-danger required']) ?>
        <div class="col-xs-3">
            <?= form_dropdown('payment_type', $payment_type, '', 'id="input_payment_type" class="form-control"') ?>
        </div>
    </div>

    <div class="dj-report-actions">
        <?php
        echo form_button([
            'name'    => 'generate_report',
            'id'      => 'generate_report',
            'content' => lang('Reports.show_report'),
            'class'   => 'btn btn-primary btn-sm'
        ]);    ?>
    </div>

    <?= form_close() ?>
</section>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/daterangepicker') ?>

        $("#generate_report").click(function() {
            window.location = [window.location, start_date, end_date, $('#specific_input_data').val(), $("#input_type").val(), $('#input_payment_type').val() || 0].join("/");
        });

    });
</script>
