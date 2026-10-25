<?php
/**
 * @var list<object> $assets
 * @var array $treasuries
 * @var array $suppliers
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'assets']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-hdd" aria-hidden="true"></span> <?= lang('Accounting.fixed_assets') ?></h1>
    <p class="dj-page-subtitle"><?= lang('Accounting.fixed_assets_intro') ?></p>
    <?= view('partial/page_nav') ?>
</div>


<p class="help-block dj-hint"><?= lang('Accounting.fixed_assets_help') ?></p>

<div class="btn-toolbar dj-page-actions print_hide">
    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#asset_modal"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> <?= lang('Accounting.new_asset') ?></button>
    <form class="form-inline" id="depreciation_form">
        <label for="dep_month" class="sr-only"><?= lang('Accounting.period') ?></label>
        <input type="month" id="dep_month" class="form-control input-sm dj-ltr" value="<?= date('Y-m', strtotime('first day of last month')) ?>" required>
        <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?= lang('Accounting.run_depreciation') ?></button>
    </form>
</div>

<?php if ($assets === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_assets') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.code') ?></th>
                    <th><?= lang('Accounting.name') ?></th>
                    <th><?= lang('Accounting.asset_category') ?></th>
                    <th><?= lang('Accounting.purchase_date') ?></th>
                    <th class="text-right"><?= lang('Accounting.purchase_cost') ?></th>
                    <th class="text-right"><?= lang('Accounting.useful_life_months') ?></th>
                    <th class="text-right"><?= lang('Accounting.monthly_depreciation') ?></th>
                    <th class="text-right"><?= lang('Accounting.accumulated_depreciation') ?></th>
                    <th class="text-right"><?= lang('Accounting.net_book_value') ?></th>
                    <th><?= lang('Accounting.status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assets as $asset): ?>
                    <tr>
                        <td class="dj-ltr"><?= esc($asset->code) ?></td>
                        <td dir="auto"><?= esc($asset->name) ?><?= $asset->location !== '' ? ' <small class="text-muted">' . esc($asset->location) . '</small>' : '' ?></td>
                        <td dir="auto"><?= esc($asset->category) ?></td>
                        <td class="dj-num"><?= esc($asset->purchase_date) ?></td>
                        <td class="text-right dj-num"><?= to_currency($asset->purchase_cost) ?></td>
                        <td class="text-right dj-num"><?= (int) $asset->useful_life_months ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $asset->monthly) ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $asset->accumulated) ?></td>
                        <td class="text-right dj-num"><strong><?= to_currency((string) $asset->net_book_value) ?></strong></td>
                        <td><span class="label label-<?= $asset->status === 'active' ? 'success' : 'default' ?>"><?= lang('Accounting.asset_status_' . $asset->status) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th colspan="4"><?= lang('Accounting.total') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_map(static fn ($a) => (float) $a->purchase_cost, $assets))) ?></th>
                    <th colspan="2"></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_map(static fn ($a) => $a->accumulated, $assets))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_map(static fn ($a) => $a->net_book_value, $assets))) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<div class="modal fade" id="asset_modal" tabindex="-1" role="dialog" aria-labelledby="asset_modal_title">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content form-horizontal" id="asset_form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('Common.close'), 'attr') ?>"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="asset_modal_title"><?= lang('Accounting.new_asset') ?></h4>
            </div>
            <div class="modal-body">
                <fieldset class="dj-form-section">
                    <legend class="dj-form-section-title"><?= lang('Accounting.asset_basic_data') ?></legend>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3 required" for="fa_name"><?= lang('Accounting.name') ?></label>
                        <div class="col-sm-8"><input type="text" id="fa_name" name="name" maxlength="150" class="form-control" dir="auto" required></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_code"><?= lang('Accounting.code') ?></label>
                        <div class="col-sm-4"><input type="text" id="fa_code" name="code" maxlength="30" class="form-control dj-ltr" placeholder="<?= esc(lang('Accounting.auto_code'), 'attr') ?>"></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_category"><?= lang('Accounting.asset_category') ?></label>
                        <div class="col-sm-5"><input type="text" id="fa_category" name="category" maxlength="100" class="form-control" dir="auto" list="asset_categories"></div>
                        <datalist id="asset_categories">
                            <?php foreach (['asset_cat_computers', 'asset_cat_printers', 'asset_cat_furniture', 'asset_cat_shelves', 'asset_cat_air_conditioning', 'asset_cat_other'] as $key): ?>
                                <option value="<?= esc(lang('Accounting.' . $key), 'attr') ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_location"><?= lang('Accounting.location') ?></label>
                        <div class="col-sm-5"><input type="text" id="fa_location" name="location" maxlength="100" class="form-control" dir="auto"></div>
                    </div>
                </fieldset>
                <fieldset class="dj-form-section">
                    <legend class="dj-form-section-title"><?= lang('Accounting.asset_cost_and_depreciation') ?></legend>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3 required" for="fa_date"><?= lang('Accounting.purchase_date') ?></label>
                        <div class="col-sm-4"><input type="date" id="fa_date" name="purchase_date" class="form-control dj-ltr" value="<?= date('Y-m-d') ?>" required></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3 required" for="fa_cost"><?= lang('Accounting.purchase_cost') ?></label>
                        <div class="col-sm-4"><input type="text" inputmode="decimal" id="fa_cost" name="purchase_cost" class="form-control dj-ltr" required></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_salvage"><?= lang('Accounting.salvage_value') ?></label>
                        <div class="col-sm-4"><input type="text" inputmode="decimal" id="fa_salvage" name="salvage_value" class="form-control dj-ltr" value="0"></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3 required" for="fa_life"><?= lang('Accounting.useful_life_months') ?></label>
                        <div class="col-sm-3"><input type="number" min="1" max="600" id="fa_life" name="useful_life_months" class="form-control dj-ltr" required></div>
                        <p class="help-block dj-hint col-sm-offset-3 col-sm-8"><?= lang('Accounting.useful_life_help') ?></p>
                    </div>
                </fieldset>
                <fieldset class="dj-form-section">
                    <legend class="dj-form-section-title"><?= lang('Accounting.payment') ?></legend>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_source"><?= lang('Accounting.payment_source') ?></label>
                        <div class="col-sm-5"><?= form_dropdown('payment_source', ['treasury' => lang('Accounting.paid_from_treasury'), 'payable' => lang('Accounting.on_supplier_credit')], 'treasury', ['id' => 'fa_source', 'class' => 'form-control']) ?></div>
                    </div>
                    <div class="form-group form-group-sm js-source-treasury">
                        <label class="control-label col-sm-3" for="fa_treasury"><?= lang('Treasury.treasury') ?></label>
                        <div class="col-sm-5"><?= form_dropdown('treasury_id', $treasuries, '', ['id' => 'fa_treasury', 'class' => 'form-control']) ?></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_supplier"><?= lang('Accounting.supplier') ?></label>
                        <div class="col-sm-5"><?= form_dropdown('supplier_id', $suppliers, '', ['id' => 'fa_supplier', 'class' => 'form-control']) ?></div>
                    </div>
                    <div class="form-group form-group-sm">
                        <label class="control-label col-sm-3" for="fa_notes"><?= lang('Accounting.notes') ?></label>
                        <div class="col-sm-8"><textarea id="fa_notes" name="notes" rows="2" class="form-control" dir="auto"></textarea></div>
                    </div>
                </fieldset>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= lang('Accounting.capitalise_asset') ?></button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const done = function(response) {
            $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
            if (response.success) {
                setTimeout(function() { window.location.reload(); }, 600);
            }
        };

        $('#fa_source').on('change', function() {
            $('.js-source-treasury').toggle($(this).val() === 'treasury');
        });

        $('#asset_form').on('submit', function(event) {
            event.preventDefault();
            ruyaPost(this, '<?= site_url('fixed_assets/save') ?>', $(this).serialize(), done);
        });

        $('#depreciation_form').on('submit', function(event) {
            event.preventDefault();
            const parts = $('#dep_month').val().split('-');
            if (!window.confirm(<?= json_encode(lang('Accounting.run_depreciation_confirm')) ?>)) {
                return;
            }
            ruyaPost(this, '<?= site_url('fixed_assets/depreciate') ?>', { year: parts[0], month: parts[1] }, done);
        });
    });
</script>

<?= view('partial/footer') ?>
