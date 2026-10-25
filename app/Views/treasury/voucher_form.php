<?php
/**
 * @var string $type
 * @var array $types
 * @var array $treasuries
 * @var array $customers
 * @var array $suppliers
 * @var array $accounts
 * @var array $cost_centers
 * @var int $party_id
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-briefcase" aria-hidden="true"></span>
        <?= lang('Treasury.new_voucher') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<form id="voucher_form" class="form-horizontal dj-form-panel" autocomplete="off">
    <fieldset class="dj-form-section">
        <div class="form-group form-group-sm">
            <label class="control-label col-xs-3" for="voucher_type"><?= lang('Treasury.voucher_type') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('voucher_type', $types, $type, ['id' => 'voucher_type', 'class' => 'form-control input-sm']) ?>
                <p class="help-block dj-hint" id="voucher_help"><?= lang('Treasury.help_' . $type) ?></p>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <label class="control-label col-xs-3 required" for="voucher_date"><?= lang('Accounting.date') ?></label>
            <div class="col-xs-4">
                <input type="date" id="voucher_date" name="voucher_date" class="form-control input-sm dj-ltr" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <label class="control-label col-xs-3 required" for="treasury_id" id="treasury_label"><?= lang('Treasury.treasury') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('treasury_id', $treasuries, '', ['id' => 'treasury_id', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm js-field js-transfer">
            <label class="control-label col-xs-3 required" for="target_treasury_id"><?= lang('Treasury.target_treasury') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('target_treasury_id', ['' => lang('Common.none_selected_text')] + $treasuries, '', ['id' => 'target_treasury_id', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm js-field js-customer_receipt">
            <label class="control-label col-xs-3 required" for="customer_id"><?= lang('Accounting.customer') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('', $customers, $type === 'customer_receipt' ? (string) $party_id : '', ['id' => 'customer_id', 'class' => 'form-control input-sm selectpicker', 'data-live-search' => 'true', 'data-width' => '100%']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm js-field js-supplier_payment">
            <label class="control-label col-xs-3 required" for="supplier_id"><?= lang('Accounting.supplier') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('', $suppliers, $type === 'supplier_payment' ? (string) $party_id : '', ['id' => 'supplier_id', 'class' => 'form-control input-sm selectpicker', 'data-live-search' => 'true', 'data-width' => '100%']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm js-field js-other_receipt js-other_payment">
            <label class="control-label col-xs-3 required" for="account_id"><?= lang('Accounting.account') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('account_id', $accounts, '', ['id' => 'account_id', 'class' => 'form-control input-sm selectpicker', 'data-live-search' => 'true', 'data-width' => '100%']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm js-field js-other_payment">
            <label class="control-label col-xs-3" for="cost_center_id"><?= lang('Accounting.cost_center') ?></label>
            <div class="col-xs-8">
                <?= form_dropdown('cost_center_id', ['' => lang('Common.none_selected_text')] + $cost_centers, '', ['id' => 'cost_center_id', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <label class="control-label col-xs-3 required" for="amount"><?= lang('Accounting.amount') ?></label>
            <div class="col-xs-4">
                <input type="text" inputmode="decimal" id="amount" name="amount" class="form-control input-sm dj-input-number dj-ltr" required>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <label class="control-label col-xs-3" for="reference"><?= lang('Accounting.reference') ?></label>
            <div class="col-xs-8">
                <input type="text" id="reference" name="reference" maxlength="64" class="form-control input-sm" dir="auto">
            </div>
        </div>

        <div class="form-group form-group-sm">
            <label class="control-label col-xs-3" for="description"><?= lang('Accounting.description') ?></label>
            <div class="col-xs-8">
                <textarea id="description" name="description" rows="2" maxlength="255" class="form-control input-sm" dir="auto"></textarea>
            </div>
        </div>
    </fieldset>

    <div class="dj-form-actions">
        <a class="btn btn-default" href="<?= esc(base_url('treasury'), 'attr') ?>"><?= lang('Common.cancel') ?></a>
        <button type="submit" class="btn btn-primary" id="voucher_submit"><?= lang('Treasury.post_voucher') ?></button>
    </div>
</form>

<script type="text/javascript">
    $(document).ready(function() {
        const help = <?= json_encode(array_combine(array_keys($types), array_map(static fn ($t) => lang('Treasury.help_' . $t), array_keys($types))), JSON_UNESCAPED_UNICODE) ?>;
        const sourceLabel = { transfer: <?= json_encode(lang('Treasury.source_treasury')) ?> };
        const defaultLabel = <?= json_encode(lang('Treasury.treasury')) ?>;

        const showFields = function() {
            const type = $('#voucher_type').val();
            $('.js-field').hide();
            $('.js-' + type).show();
            $('#voucher_help').text(help[type] || '');
            $('#treasury_label').text(sourceLabel[type] || defaultLabel);
        };

        $('#voucher_type').on('change', showFields);
        showFields();

        $('#voucher_form').on('submit', function(event) {
            event.preventDefault();
            const type = $('#voucher_type').val();
            const data = $(this).serializeArray();
            if (type === 'customer_receipt') {
                data.push({ name: 'party_id', value: $('#customer_id').val() });
            } else if (type === 'supplier_payment') {
                data.push({ name: 'party_id', value: $('#supplier_id').val() });
            }

            $('#voucher_submit').prop('disabled', true);
            $.post('<?= site_url('treasury/voucher') ?>', $.param(data), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.href = '<?= site_url('treasury/vouchers') ?>';
                } else {
                    $('#voucher_submit').prop('disabled', false);
                }
            }, 'json').fail(function() {
                $('#voucher_submit').prop('disabled', false);
            });
        });
    });
</script>

<?= view('partial/footer') ?>
