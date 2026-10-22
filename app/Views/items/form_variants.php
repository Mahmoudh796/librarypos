<?php
/**
 * @var string $controller_name
 * @var array $config
 * @var array $suppliers
 * @var bool $categoryDropdown
 * @var array $categories
 * @var bool $useDestinationBasedTax
 * @var array $taxCategories
 */
?>

<?= view('partial/header') ?>

<div id="title_bar" class="btn-toolbar print_hide">
    <a class="btn btn-default btn-sm" href="<?= esc(base_url($controller_name)) ?>" title="<?= lang('Common.back_to') . ' ' . lang('Module.items') ?>">
        <span class="glyphicon glyphicon-arrow-left">&nbsp;</span><?= lang('Common.back_to') . ' ' . lang('Module.items') ?>
    </a>
</div>

<div class="dj-page-head">
    <?= view('partial/page_path', ['crumbs' => [['module' => 'items'], ['label' => lang('Items.create_variants')]]]) ?>
    <?= view('partial/page_nav') ?>
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-th" aria-hidden="true"></span><?= lang('Items.create_variants') ?>
    </h1>
    <p class="dj-page-subtitle"><?= lang('Items.variant_options_hint') ?></p>
</div>

<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('items/createVariants', ['id' => 'variants_form', 'class' => 'dj-form-panel', 'autocomplete' => 'off']) ?>

    <fieldset id="variant_basic_info" class="dj-form-section">
        <h2 class="dj-form-section-title"><?= lang('Items.section_item_data') ?></h2>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.name'), 'name', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <?= form_input([
                    'name'  => 'name',
                    'id'    => 'name',
                    'class' => 'form-control input-sm dj-input-text'
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.category'), 'category', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <?php if ($categoryDropdown): ?>
                    <?= form_dropdown('category', $categories, '', ['id' => 'category', 'class' => 'form-control input-sm selectpicker']) ?>
                <?php else: ?>
                    <div class="input-group">
                        <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-tag"></span></span>
                        <?= form_input([
                            'name'  => 'category',
                            'id'    => 'category',
                            'class' => 'form-control input-sm dj-input-text'
                        ]) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.supplier'), 'supplier', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <?= form_dropdown('supplier_id', $suppliers, '', ['class' => 'form-control input-sm']) ?>
            </div>
        </div>
    </fieldset>

    <fieldset id="variant_pricing_info" class="dj-form-section">
        <h2 class="dj-form-section-title"><?= lang('Items.section_pricing_taxes') ?></h2>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.cost_price'), 'cost_price', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'cost_price',
                        'id'    => 'cost_price',
                        'class' => 'form-control input-sm dj-input-number'
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.unit_price'), 'unit_price', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'unit_price',
                        'id'    => 'unit_price',
                        'class' => 'form-control input-sm dj-input-number'
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($useDestinationBasedTax): ?>
            <div class="form-group form-group-sm">
                <?= form_label(lang('Items.tax_category'), 'tax_category_id', ['class' => 'control-label']) ?>
                <div class="dj-form-cell">
                    <?= form_dropdown('tax_category_id', $taxCategories, '', ['class' => 'form-control input-sm']) ?>
                </div>
            </div>
        <?php else: ?>
            <div class="form-group form-group-sm">
                <?= form_label(lang('Items.tax_1'), 'tax_percent_1', ['class' => 'control-label']) ?>
                <div class="dj-form-cell">
                    <?= form_input([
                        'name'  => 'tax_names[]',
                        'id'    => 'tax_name_1',
                        'class' => 'form-control input-sm dj-input-text',
                        'value' => $config['default_tax_1_name']
                    ]) ?>
                </div>
                <div class="dj-form-cell">
                    <div class="input-group input-group-sm">
                        <?= form_input([
                            'name'  => 'tax_percents[]',
                            'id'    => 'tax_percent_name_1',
                            'class' => 'form-control input-sm dj-input-number',
                            'value' => to_tax_decimals($config['default_tax_1_rate'])
                        ]) ?>
                        <span class="input-group input-group-addon"><b>%</b></span>
                    </div>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Items.tax_2'), 'tax_percent_2', ['class' => 'control-label']) ?>
                <div class="dj-form-cell">
                    <?= form_input([
                        'name'  => 'tax_names[]',
                        'id'    => 'tax_name_2',
                        'class' => 'form-control input-sm dj-input-text',
                        'value' => $config['default_tax_2_name']
                    ]) ?>
                </div>
                <div class="dj-form-cell">
                    <div class="input-group input-group-sm">
                        <?= form_input([
                            'name'  => 'tax_percents[]',
                            'id'    => 'tax_percent_name_2',
                            'class' => 'form-control input-sm dj-input-number',
                            'value' => to_tax_decimals($config['default_tax_2_rate'])
                        ]) ?>
                        <span class="input-group input-group-addon"><b>%</b></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </fieldset>

    <fieldset id="variant_stock_info" class="dj-form-section">
        <h2 class="dj-form-section-title"><?= lang('Items.section_stock') ?></h2>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.reorder_level'), 'reorder_level', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <?= form_input([
                    'name'  => 'reorder_level',
                    'id'    => 'reorder_level',
                    'class' => 'form-control input-sm dj-input-number'
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.description'), 'description', ['class' => 'control-label']) ?>
            <div class="dj-form-cell dj-form-cell-wide">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control input-sm dj-input-text'
                ]) ?>
            </div>
        </div>
    </fieldset>

    <fieldset id="variant_attributes_info" class="dj-form-section">
        <h2 class="dj-form-section-title"><?= lang('Items.section_variant_options') ?></h2>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.variant_colors'), 'colors', ['class' => 'control-label']) ?>
            <div class="dj-form-cell dj-form-cell-wide">
                <?= form_textarea([
                    'name'  => 'colors',
                    'id'    => 'colors',
                    'rows'  => 3,
                    'class' => 'form-control input-sm dj-input-text',
                    'placeholder' => lang('Items.variant_colors_placeholder')
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.variant_sizes'), 'sizes', ['class' => 'control-label']) ?>
            <div class="dj-form-cell dj-form-cell-wide">
                <?= form_textarea([
                    'name'  => 'sizes',
                    'id'    => 'sizes',
                    'rows'  => 3,
                    'class' => 'form-control input-sm dj-input-text',
                    'placeholder' => "XS\nS\nM\nL\nXL"
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Items.variant_sku_prefix'), 'sku_prefix', ['class' => 'control-label']) ?>
            <div class="dj-form-cell">
                <?= form_input([
                    'name'  => 'sku_prefix',
                    'id'    => 'sku_prefix',
                    'class' => 'form-control input-sm dj-input-code',
                    'placeholder' => 'CLASSIC-SHIRT'
                ]) ?>
            </div>
        </div>
    </fieldset>

    <div class="dj-form-actions">
        <button type="button" id="variant_preview" class="btn btn-default btn-sm">
            <span class="glyphicon glyphicon-eye-open">&nbsp;</span><?= lang('Items.variant_preview') ?>
        </button>
        <button type="submit" id="variant_create" class="btn btn-primary btn-sm">
            <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang('Items.variant_create_action') ?>
        </button>
    </div>

    <table id="variant_rows" class="table table-striped table-hover hide">
        <thead>
            <tr>
                <th style="width: 5%;"><?= lang('Items.variant_include') ?></th>
                <th style="width: 30%;"><?= lang('Items.name') ?></th>
                <th style="width: 25%;"><?= lang('Items.item_number') ?></th>
                <th style="width: 12%;"><?= lang('Items.cost_price') ?></th>
                <th style="width: 12%;"><?= lang('Items.unit_price') ?></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
<?= form_close() ?>

<?= view('partial/footer') ?>

<script type="text/javascript">
    const variant_create_url = "<?= esc(base_url('items/createVariants'), 'js') ?>";
    const variant_preview_url = "<?= esc(base_url('items/variantPreview'), 'js') ?>";
    const variant_redirect_url = "<?= esc(base_url($controller_name), 'js') ?>";
    const variant_sku_in_use = "<?= esc(lang('Items.variant_sku_in_use')) ?>";

    $(document).ready(function() {
        $('#category').autocomplete({
            source: "<?= esc(base_url('items/suggestCategory'), 'js') ?>",
            delay: 10
        });

        const render_variant_rows = function(rows) {
            const tbody = $('#variant_rows tbody');
            tbody.empty();

            $.each(rows, function(index, row) {
                const nameInput = $('<input>').attr({
                    'type': 'text',
                    'class': 'form-control input-sm dj-input-text',
                    'name': 'rows[' + index + '][name]'
                }).val(row.name);

                const skuInput = $('<input>').attr({
                    'type': 'text',
                    'class': 'form-control input-sm dj-input-code',
                    'name': 'rows[' + index + '][sku]'
                }).val(row.sku);

                const costInput = $('<input>').attr({
                    'type': 'text',
                    'class': 'form-control input-sm dj-input-number',
                    'name': 'rows[' + index + '][cost_price]'
                }).val(row.cost_price);

                const priceInput = $('<input>').attr({
                    'type': 'text',
                    'class': 'form-control input-sm dj-input-number',
                    'name': 'rows[' + index + '][unit_price]'
                }).val(row.unit_price);

                const includeInput = $('<input>').attr({
                    'type': 'checkbox',
                    'name': 'rows[' + index + '][include]',
                    'value': '1',
                    'class': 'form-control input-sm'
                }).prop('checked', true);

                const colorInput = $('<input>').attr({
                    'type': 'hidden',
                    'name': 'rows[' + index + '][color]'
                }).val(row.color === null ? '' : row.color);

                const sizeInput = $('<input>').attr({
                    'type': 'hidden',
                    'name': 'rows[' + index + '][size]'
                }).val(row.size === null ? '' : row.size);

                const skuCell = $('<td>').append(skuInput);

                if (row.exists) {
                    skuCell.append(' <span class="label label-warning" title="' + variant_sku_in_use + '">?!</span>');
                }

                tbody.append($('<tr>')
                    .append($('<td>').append(includeInput))
                    .append($('<td>').append(nameInput))
                    .append(skuCell)
                    .append($('<td>').append(costInput))
                    .append($('<td>').append(priceInput))
                    .append(colorInput)
                    .append(sizeInput)
                );
            });

            $('#variant_rows').removeClass('hide');
        };

        $('#variant_preview').click(function() {
            $('#variants_form').ajaxSubmit({
                url: variant_preview_url,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        render_variant_rows(response.rows);
                    } else {
                        $.notify(response.message, {type: 'danger'});
                    }
                }
            });
        });

        $('#variants_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    url: variant_create_url,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $.notify(response.message, {type: 'success'});
                            setTimeout(function() {
                                window.location.href = variant_redirect_url;
                            }, 600);
                        } else {
                            $.notify(response.message, {type: 'danger'});
                        }
                    }
                });
            },

            rules: {
                name: {
                    required: true
                },
                cost_price: {
                    number: true
                },
                unit_price: {
                    number: true
                }
            },

            messages: {
                name: {
                    required: "<?= lang('Items.name_required') ?>"
                },
                cost_price: {
                    number: "<?= lang('Items.cost_price_number') ?>"
                },
                unit_price: {
                    number: "<?= lang('Items.unit_price_number') ?>"
                }
            }
        }, form_support.error));
    });
</script>
