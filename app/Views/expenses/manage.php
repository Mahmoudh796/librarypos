<?php
/**
 * @var string $controller_name
 * @var string $table_headers
 * @var array $filters
 * @var array $selected_filters
 * @var array $config
 * @var string|null $start_date
 * @var string|null $end_date
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        // Filter and date changes reload the table via partial/table_filter_persistence.
        <?= view('partial/daterangepicker') ?>

        <?= view('partial/bootstrap_tables_locale') ?>

        <?php if (isset($start_date) && $start_date): ?>
        start_date = "<?= esc($start_date) ?>";
        <?php endif; ?>
        <?php if (isset($end_date) && $end_date): ?>
        end_date = "<?= esc($end_date) ?>";
        <?php endif; ?>

        table_support.init({
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'expense_id',
            onLoadSuccess: function(response) {
                if ($("#table tbody tr").length > 1) {
                    $("#payment_summary").html(response.payment_summary);
                    $("#table tbody tr:last td:first").html("");
                    $("#table tbody tr:last").css('font-weight', 'bold');
                }
            },
            queryParams: function() {
                return $.extend(arguments[0], {
                    "start_date": start_date,
                    "end_date": end_date,
                    "filters": $("#filters").val()
                });
            }
        });

    });
</script>
<?= view('partial/table_filter_persistence') ?>

<?= view('partial/print_receipt', ['print_after_sale' => false, 'selected_printer' => 'takings_printer']) ?>

<div class="dj-page-head">
    <?= view('partial/page_path', ['crumbs' => [['label' => lang('Common.nav_costs')], ['label' => lang('Module.expenses')]]]) ?>
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-tags" aria-hidden="true"></span><?= lang('Module.expenses') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<div id="title_bar" class="print_hide btn-toolbar">
    <button onclick="printdoc()" class="btn btn-info btn-sm pull-right">
        <span class="glyphicon glyphicon-print">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <button class="btn btn-info btn-sm pull-right modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "$controller_name/view" ?>" title="<?= lang(ucfirst($controller_name) . '.new') ?>">
        <span class="glyphicon glyphicon-tags">&nbsp;</span><?= lang(ucfirst($controller_name) . '.new') ?>
    </button>
</div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <button id="delete" class="btn btn-default btn-sm print_hide">
            <span class="glyphicon glyphicon-trash">&nbsp;</span><?= lang('Common.delete') ?>
        </button>
        <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm dj-input-date', 'id' => 'daterangepicker']) ?>
        <?= form_multiselect('filters[]', esc($filters), $selected_filters ?? [], [
            'id'                        => 'filters',
            'data-none-selected-text'   => lang('Common.none_selected_text'),
            'class'                     => 'selectpicker show-menu-arrow',
            'data-selected-text-format' => 'count > 1',
            'data-style'                => 'btn-default btn-sm',
            'data-width'                => 'fit'
        ]) ?>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<div id="payment_summary"></div>

<?= view('partial/footer') ?>
