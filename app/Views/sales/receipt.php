<?php
/**
 * @var int $sale_id_num
 * @var bool $print_after_sale
 * @var string $receipt_template_view
 * @var array $config
 */

use App\Models\Employee;

$template = $receipt_template_view ?? 'receipt_default';

?>

<?= view('partial/header') ?>

<style type="text/css" media="print">
    /* 80mm thermal receipt presentation — scoped to this document only.
       Invoice/quote/work-order pages keep their own (A4) print behaviour. */
    @page {
        size: 80mm auto;
        margin: 3mm 4mm;
    }

    html, body {
        width: 80mm;
        margin: 0;
        padding: 0;
        background: #ffffff !important;
    }

    #receipt_wrapper {
        width: 100% !important;
        max-width: 80mm;
        margin: 0 auto;
        padding: 0;
        overflow-wrap: anywhere;
        word-wrap: break-word;
    }

    #receipt_wrapper table {
        width: 100%;
        border-collapse: collapse;
    }

    #receipt_items td,
    #receipt_items th {
        padding: 1.5pt 0;
        vertical-align: top;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .total-value {
        text-align: right;
        white-space: nowrap;
    }

    #receipt_wrapper #image {
        max-width: 30mm;
        max-height: 20mm;
    }

    #receipt_wrapper #barcode img {
        max-width: 100%;
        height: auto;
    }

    /* Money, invoice/sale numbers, timestamps and phone stay LTR-read when
       embedded in Arabic RTL labels. */
    #receipt_general_info div,
    #sale_time,
    #company_phone {
        unicode-bidi: plaintext;
    }
</style>

<?php
if (isset($error_message)) {
    echo '<div class="alert alert-dismissible alert-danger">' . $error_message . '</div>';
    exit;
}
?>

<?php if (!empty($customer_email)): ?>
    <script type="text/javascript">
        $(document).ready(function() {
            const send_email = function() {
                $.get('<?= site_url() . esc("/sales/sendPdf/$sale_id_num/receipt") ?>',
                    function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        })
                    }, 'json'
                );
            };

            $("#show_email_button").click(send_email);

            <?php if (!empty($email_receipt)): ?>
                send_email();
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?= view('partial/print_receipt', ['print_after_sale' => $print_after_sale, 'selected_printer' => 'receipt_printer']) ?>

<div class="print_hide" id="control_buttons" style="text-align: right;">
    <a href="javascript:printdoc();">
        <div class="btn btn-info btn-sm" id="show_print_button"><?= '<span class="glyphicon glyphicon-print">&nbsp;</span>' . lang('Common.print') ?></div>
    </a>
    <?php if (!empty($customer_email)): ?>
        <a href="javascript:void(0);">
            <div class="btn btn-info btn-sm" id="show_email_button"><?= '<span class="glyphicon glyphicon-envelope">&nbsp;</span>' . lang('Sales.send_receipt') ?></div>
        </a>
    <?php endif; ?>
    <?= anchor('sales', '<span class="glyphicon glyphicon-shopping-cart">&nbsp;</span>' . lang('Sales.register'), ['class' => 'btn btn-info btn-sm', 'id' => 'show_sales_button']) ?>
    <?php
    $employee = model(Employee::class);
    if ($employee->has_grant('reports_sales', session('person_id'))): ?>
        <?= anchor('sales/manage', '<span class="glyphicon glyphicon-list-alt">&nbsp;</span>' . lang('Sales.takings'), ['class' => 'btn btn-info btn-sm', 'id' => 'show_takings_button']) ?>
    <?php endif; ?>
</div>

<?= view('sales/' . $template) ?>

<?= view('partial/footer') ?>
