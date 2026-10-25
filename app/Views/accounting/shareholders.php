<?php
/**
 * @var list<array> $shareholders
 * @var array $treasuries
 */
$totalOwnership = array_sum(array_map(static fn ($s) => (int) $s['active'] === 1 ? (float) $s['ownership_percent'] : 0, $shareholders));
$totalShare = array_sum(array_map(static fn ($s) => (int) $s['active'] === 1 ? (float) $s['profit_share_percent'] : 0, $shareholders));
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'shareholders']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?= lang('Accounting.shareholders') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<div class="btn-toolbar dj-page-actions print_hide">
    <button type="button" class="btn btn-primary btn-sm js-shareholder-edit" data-shareholder="{}"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> <?= lang('Accounting.new_shareholder') ?></button>
    <a class="btn btn-default btn-sm" href="<?= esc(base_url('shareholders/allocation'), 'attr') ?>"><span class="glyphicon glyphicon-tasks" aria-hidden="true"></span> <?= lang('Accounting.profit_allocation') ?></a>
</div>

<?php if (abs($totalShare - 100) > 0.0001 && $shareholders !== []): ?>
    <div class="alert alert-warning"><?= lang('Accounting.profit_shares_not_100', [round($totalShare, 2)]) ?></div>
<?php endif; ?>

<?php if ($shareholders === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_shareholders') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.code') ?></th>
                    <th><?= lang('Accounting.name') ?></th>
                    <th class="text-right"><?= lang('Accounting.ownership_percent') ?></th>
                    <th class="text-right"><?= lang('Accounting.profit_share_percent') ?></th>
                    <th class="text-right"><?= lang('Accounting.capital') ?></th>
                    <th class="text-right"><?= lang('Accounting.drawings') ?></th>
                    <th class="text-right"><?= lang('Accounting.current_account') ?></th>
                    <th class="text-right"><?= lang('Accounting.equity_balance') ?></th>
                    <th class="print_hide"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shareholders as $shareholder): ?>
                    <tr<?= (int) $shareholder['active'] === 0 ? ' class="text-muted"' : '' ?>>
                        <td class="dj-ltr"><?= esc($shareholder['code']) ?></td>
                        <td dir="auto"><a href="<?= esc(base_url('shareholders/statement/' . $shareholder['shareholder_id']), 'attr') ?>"><?= esc($shareholder['name']) ?></a></td>
                        <td class="text-right dj-num"><?= to_tax_decimals((string) $shareholder['ownership_percent']) ?>%</td>
                        <td class="text-right dj-num"><?= to_tax_decimals((string) $shareholder['profit_share_percent']) ?>%</td>
                        <td class="text-right dj-num"><?= to_currency((string) $shareholder['capital']) ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $shareholder['drawings']) ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $shareholder['current']) ?></td>
                        <td class="text-right dj-num"><strong><?= to_currency((string) $shareholder['equity']) ?></strong></td>
                        <td class="print_hide text-nowrap">
                            <button type="button" class="btn btn-default btn-xs js-transaction" data-id="<?= (int) $shareholder['shareholder_id'] ?>" data-name="<?= esc($shareholder['name'], 'attr') ?>"><?= lang('Accounting.new_transaction') ?></button>
                            <button type="button" class="btn btn-link btn-xs js-shareholder-edit" data-shareholder="<?= esc(json_encode($shareholder, JSON_UNESCAPED_UNICODE), 'attr') ?>"><?= lang('Common.edit') ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th colspan="2"><?= lang('Accounting.total') ?></th>
                    <th class="text-right dj-num"><?= to_tax_decimals((string) $totalOwnership) ?>%</th>
                    <th class="text-right dj-num"><?= to_tax_decimals((string) $totalShare) ?>%</th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($shareholders, 'capital'))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($shareholders, 'drawings'))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($shareholders, 'current'))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($shareholders, 'equity'))) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<div class="modal fade" id="shareholder_modal" tabindex="-1" role="dialog" aria-labelledby="shareholder_modal_title">
    <div class="modal-dialog" role="document">
        <form class="modal-content form-horizontal" id="shareholder_form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('Common.close'), 'attr') ?>"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="shareholder_modal_title"><?= lang('Accounting.shareholder') ?></h4>
            </div>
            <div class="modal-body">
                <input type="hidden" name="shareholder_id" value="">
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4 required" for="sh_name"><?= lang('Accounting.name') ?></label>
                    <div class="col-xs-8"><input type="text" id="sh_name" name="name" maxlength="150" class="form-control" dir="auto" required></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="sh_own"><?= lang('Accounting.ownership_percent') ?></label>
                    <div class="col-xs-4"><input type="text" inputmode="decimal" id="sh_own" name="ownership_percent" class="form-control dj-ltr"></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="sh_share"><?= lang('Accounting.profit_share_percent') ?></label>
                    <div class="col-xs-4"><input type="text" inputmode="decimal" id="sh_share" name="profit_share_percent" class="form-control dj-ltr"></div>
                    <p class="help-block dj-hint col-xs-offset-4 col-xs-8"><?= lang('Accounting.profit_share_help') ?></p>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="sh_phone"><?= lang('Accounting.phone') ?></label>
                    <div class="col-xs-6"><input type="tel" id="sh_phone" name="phone" maxlength="40" class="form-control dj-ltr"></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="sh_notes"><?= lang('Accounting.notes') ?></label>
                    <div class="col-xs-8"><textarea id="sh_notes" name="notes" rows="2" class="form-control" dir="auto"></textarea></div>
                </div>
                <div class="form-group form-group-sm">
                    <div class="col-xs-offset-4 col-xs-8"><label class="checkbox-inline"><input type="checkbox" name="inactive" value="1"> <?= lang('Accounting.inactive') ?></label></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= lang('Common.submit') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="transaction_modal" tabindex="-1" role="dialog" aria-labelledby="transaction_modal_title">
    <div class="modal-dialog" role="document">
        <form class="modal-content form-horizontal" id="transaction_form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('Common.close'), 'attr') ?>"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="transaction_modal_title"><?= lang('Accounting.new_transaction') ?></h4>
            </div>
            <div class="modal-body">
                <input type="hidden" name="shareholder_id" value="">
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="tx_type"><?= lang('Accounting.transaction_type') ?></label>
                    <div class="col-xs-8"><?= form_dropdown('transaction_type', ['contribution' => lang('Accounting.sh_type_contribution'), 'additional_contribution' => lang('Accounting.sh_type_additional_contribution'), 'withdrawal' => lang('Accounting.sh_type_withdrawal')], 'contribution', ['id' => 'tx_type', 'class' => 'form-control']) ?></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4 required" for="tx_amount"><?= lang('Accounting.amount') ?></label>
                    <div class="col-xs-4"><input type="text" inputmode="decimal" id="tx_amount" name="amount" class="form-control dj-ltr" required></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="tx_treasury"><?= lang('Treasury.treasury') ?></label>
                    <div class="col-xs-8"><?= form_dropdown('treasury_id', $treasuries, '', ['id' => 'tx_treasury', 'class' => 'form-control']) ?></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="tx_date"><?= lang('Accounting.date') ?></label>
                    <div class="col-xs-5"><input type="date" id="tx_date" name="transaction_date" class="form-control dj-ltr" value="<?= date('Y-m-d') ?>"></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="tx_reference"><?= lang('Accounting.reference') ?></label>
                    <div class="col-xs-8"><input type="text" id="tx_reference" name="reference" maxlength="64" class="form-control" dir="auto"></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="tx_notes"><?= lang('Accounting.notes') ?></label>
                    <div class="col-xs-8"><input type="text" id="tx_notes" name="notes" maxlength="255" class="form-control" dir="auto"></div>
                </div>
                <p class="help-block dj-hint"><?= lang('Accounting.withdrawal_help') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= lang('Accounting.post_transaction') ?></button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const done = function(response) {
            $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
            if (response.success) {
                setTimeout(function() { window.location.reload(); }, 500);
            }
        };

        $('.js-shareholder-edit').on('click', function() {
            const s = $(this).data('shareholder') || {};
            const form = $('#shareholder_form');
            form[0].reset();
            $('[name="shareholder_id"]', form).val(s.shareholder_id || '');
            $('[name="name"]', form).val(s.name || '');
            $('[name="ownership_percent"]', form).val(s.ownership_percent || '');
            $('[name="profit_share_percent"]', form).val(s.profit_share_percent || '');
            $('[name="phone"]', form).val(s.phone || '');
            $('[name="notes"]', form).val(s.notes || '');
            $('[name="inactive"]', form).prop('checked', s.active == 0);
            $('#shareholder_modal').modal('show');
        });

        $('#shareholder_form').on('submit', function(event) {
            event.preventDefault();
            const id = $('[name="shareholder_id"]', this).val();
            ruyaPost(this, '<?= site_url('shareholders/save') ?>' + (id ? '/' + id : ''), $(this).serialize(), done);
        });

        $('.js-transaction').on('click', function() {
            const form = $('#transaction_form');
            form[0].reset();
            $('[name="shareholder_id"]', form).val($(this).data('id'));
            $('#transaction_modal_title').text(<?= json_encode(lang('Accounting.new_transaction')) ?> + ' - ' + $(this).data('name'));
            $('#transaction_modal').modal('show');
        });

        $('#transaction_form').on('submit', function(event) {
            event.preventDefault();
            ruyaPost(this, '<?= site_url('shareholders/transaction') ?>/' + $('[name="shareholder_id"]', this).val(), $(this).serialize(), done);
        });
    });
</script>

<?= view('partial/footer') ?>
