<?php
/**
 * @var list<array> $accounts
 * @var array $groups
 * @var bool $can_setup
 */
$types = ['asset' => lang('Accounting.type_asset'), 'liability' => lang('Accounting.type_liability'), 'equity' => lang('Accounting.type_equity'), 'revenue' => lang('Accounting.type_revenue'), 'expense' => lang('Accounting.type_expense')];
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'accounts']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-tree-conifer" aria-hidden="true"></span> <?= lang('Accounting.chart_of_accounts') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<?php if ($can_setup): ?>
    <div class="btn-toolbar dj-page-actions print_hide">
        <button type="button" class="btn btn-primary btn-sm js-account-edit" data-account="{}"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> <?= lang('Accounting.new_account') ?></button>
        <a class="btn btn-default btn-sm" href="<?= esc(base_url('accounts/mappings'), 'attr') ?>"><span class="glyphicon glyphicon-link" aria-hidden="true"></span> <?= lang('Accounting.posting_mappings') ?></a>
        <a class="btn btn-default btn-sm" href="<?= esc(base_url('accounts/dimensionValues'), 'attr') ?>"><span class="glyphicon glyphicon-th" aria-hidden="true"></span> <?= lang('Accounting.cost_centers_and_dimensions') ?></a>
    </div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-condensed table-hover dj-ledger-table dj-chart ruya-coa">
        <thead>
            <tr>
                <th style="width: 110px"><?= lang('Accounting.account_code') ?></th>
                <th><?= lang('Accounting.account') ?></th>
                <th class="text-right" style="width: 220px"><?= lang('Accounting.balance') ?></th>
                <?php if ($can_setup): ?><th class="print_hide"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $account): ?>
                <?php $depth = min(4, (int) $account['depth']); ?>
                <tr class="ruya-coa__row ruya-coa__depth-<?= $depth ?><?= (int) $account['is_group'] === 1 ? ' dj-chart-group ruya-coa__group' : '' ?><?= (int) $account['active'] === 0 ? ' text-muted' : '' ?>">
                    <td class="dj-ltr"><?= esc($account['code']) ?></td>
                    <td class="ruya-coa__name" style="padding-inline-start: <?= 8 + 22 * $depth ?>px">
                        <?php if ((int) $account['is_group'] === 1): ?>
                            <strong><?= esc($account['name']) ?></strong>
                        <?php else: ?>
                            <a href="<?= esc(base_url('accounting/ledger') . '?account_id=' . $account['account_id'] . '&from=' . date('Y-01-01'), 'attr') ?>"><?= esc($account['name']) ?></a>
                        <?php endif; ?>
                        <?php if ((int) $account['is_treasury'] === 1): ?><span class="label label-info"><?= lang('Accounting.treasury_account') ?></span><?php endif; ?>
                        <?php if ($account['party_type']): ?><span class="label label-default"><?= lang('Accounting.party_' . $account['party_type']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-right dj-num">
                        <?php if ($account['balance'] != 0.0): ?>
                            <?= to_currency((string) abs($account['balance'])) ?> <small class="ruya-coa__side"><?= lang($account['balance'] > 0 ? 'Accounting.dr' : 'Accounting.cr') ?></small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($can_setup): ?>
                        <td class="print_hide"><button type="button" class="btn btn-link btn-xs js-account-edit" data-account="<?= esc(json_encode($account, JSON_UNESCAPED_UNICODE), 'attr') ?>"><?= lang('Common.edit') ?></button></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($can_setup): ?>
<div class="modal fade" id="account_modal" tabindex="-1" role="dialog" aria-labelledby="account_modal_title">
    <div class="modal-dialog" role="document">
        <form class="modal-content form-horizontal" id="account_form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('Common.close'), 'attr') ?>"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="account_modal_title"><?= lang('Accounting.account') ?></h4>
            </div>
            <div class="modal-body">
                <input type="hidden" name="account_id" value="">
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4 required" for="acc_code"><?= lang('Accounting.account_code') ?></label>
                    <div class="col-xs-6"><input type="text" id="acc_code" name="code" maxlength="20" class="form-control dj-ltr" required></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4 required" for="acc_name"><?= lang('Accounting.account_name') ?></label>
                    <div class="col-xs-8"><input type="text" id="acc_name" name="name" maxlength="150" class="form-control" dir="auto" required></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="acc_name_en"><?= lang('Accounting.account_name_en') ?></label>
                    <div class="col-xs-8"><input type="text" id="acc_name_en" name="name_en" maxlength="150" class="form-control" dir="ltr"></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="acc_type"><?= lang('Accounting.account_type') ?></label>
                    <div class="col-xs-6"><?= form_dropdown('account_type', $types, 'expense', ['id' => 'acc_type', 'class' => 'form-control']) ?></div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="control-label col-xs-4" for="acc_parent"><?= lang('Accounting.parent_account') ?></label>
                    <div class="col-xs-8"><?= form_dropdown('parent_id', ['' => lang('Common.none_selected_text')] + $groups, '', ['id' => 'acc_parent', 'class' => 'form-control']) ?></div>
                </div>
                <div class="form-group form-group-sm">
                    <div class="col-xs-offset-4 col-xs-8">
                        <label class="checkbox-inline"><input type="checkbox" name="is_group" value="1"> <?= lang('Accounting.is_group') ?></label>
                        <label class="checkbox-inline"><input type="checkbox" name="inactive" value="1"> <?= lang('Accounting.inactive') ?></label>
                    </div>
                </div>
                <p class="help-block dj-hint"><?= lang('Accounting.account_edit_help') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= lang('Common.submit') ?></button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('.js-account-edit').on('click', function() {
            const account = $(this).data('account') || {};
            const form = $('#account_form');
            form[0].reset();
            $('[name="account_id"]', form).val(account.account_id || '');
            $('[name="code"]', form).val(account.code || '');
            $('[name="name"]', form).val(account.name || '');
            $('[name="name_en"]', form).val(account.name_en || '');
            $('[name="account_type"]', form).val(account.account_type || 'expense');
            $('[name="parent_id"]', form).val(account.parent_id || '');
            $('[name="is_group"]', form).prop('checked', account.is_group == 1);
            $('[name="inactive"]', form).prop('checked', account.active == 0);
            $('#account_modal').modal('show');
        });

        $('#account_form').on('submit', function(event) {
            event.preventDefault();
            const id = $('[name="account_id"]', this).val();
            $.post('<?= site_url('accounts/save') ?>' + (id ? '/' + id : ''), $(this).serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    setTimeout(function() { window.location.reload(); }, 500);
                }
            }, 'json');
        });
    });
</script>
<?php endif; ?>

<?= view('partial/footer') ?>
