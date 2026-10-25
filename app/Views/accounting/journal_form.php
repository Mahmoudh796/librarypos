<?php
/**
 * Manual journal entry. Accounts are searchable and grouped by type; an account
 * kept per customer / supplier / partner asks for that person by name. The entry
 * can only be posted once debits equal credits (the server checks it again).
 *
 * @var list<array{id: int, label: string, party: ?string, type: string}> $accounts
 * @var list<array> $cost_centers
 * @var array<string, array<int, string>> $parties
 */

$groups = [];
foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $type) {
    $groups[$type] = [];
}
foreach ($accounts as $account) {
    $groups[$account['type']][] = $account;
}
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'journal', 'leaf' => lang('Accounting.new_entry')]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-pencil" aria-hidden="true"></span> <?= lang('Accounting.new_entry') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<p class="help-block dj-hint"><?= lang('Accounting.manual_entry_help') ?></p>

<form id="journal_form" autocomplete="off">
    <div class="row">
        <div class="col-sm-3 form-group">
            <label for="entry_date" class="required"><?= lang('Accounting.date') ?></label>
            <input type="date" id="entry_date" name="entry_date" class="form-control input-sm dj-ltr" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-sm-3 form-group">
            <label for="reference"><?= lang('Accounting.reference') ?></label>
            <input type="text" id="reference" name="reference" maxlength="64" class="form-control input-sm" dir="auto">
        </div>
        <div class="col-sm-6 form-group">
            <label for="description" class="required"><?= lang('Accounting.description') ?></label>
            <input type="text" id="description" name="description" maxlength="255" class="form-control input-sm" dir="auto" required>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-condensed dj-ledger-table" id="journal_lines">
            <thead>
                <tr>
                    <th style="min-width: 260px"><?= lang('Accounting.account') ?></th>
                    <th><?= lang('Accounting.line_description') ?></th>
                    <th style="min-width: 180px"><?= lang('Accounting.party') ?></th>
                    <th><?= lang('Accounting.cost_center') ?></th>
                    <th class="text-right" style="width: 130px"><?= lang('Accounting.debit') ?></th>
                    <th class="text-right" style="width: 130px"><?= lang('Accounting.credit') ?></th>
                    <th style="width: 32px"></th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th colspan="4">
                        <button type="button" class="btn btn-default btn-xs" id="add_line"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> <?= lang('Accounting.add_line') ?></button>
                    </th>
                    <th class="text-right dj-num" id="total_debit">0.00</th>
                    <th class="text-right dj-num" id="total_credit">0.00</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div id="balance_state" class="dj-badge dj-badge--warning" role="status" aria-live="polite"></div>

    <div class="dj-form-actions">
        <a class="btn btn-default" href="<?= esc(base_url('journal'), 'attr') ?>"><?= lang('Common.cancel') ?></a>
        <button type="submit" class="btn btn-primary" id="post_entry" disabled><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> <?= lang('Accounting.post_entry') ?></button>
    </div>
</form>

<template id="line_template">
    <tr>
        <td>
            <select name="account_id[]" class="form-control input-sm js-account" data-live-search="true" data-width="100%" data-size="10" title="<?= esc(lang('Accounting.choose_account_option'), 'attr') ?>">
                <option value=""><?= esc(lang('Accounting.choose_account_option')) ?></option>
                <?php foreach ($groups as $type => $items): ?>
                    <?php if ($items === []) {
                        continue;
                    } ?>
                    <optgroup label="<?= esc(lang('Accounting.type_' . $type), 'attr') ?>">
                        <?php foreach ($items as $account): ?>
                            <option value="<?= $account['id'] ?>" data-party="<?= esc((string) $account['party'], 'attr') ?>"><?= esc($account['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="line_description[]" maxlength="255" class="form-control input-sm" dir="auto"></td>
        <td>
            <select name="party_id[]" class="form-control input-sm js-party" disabled aria-label="<?= esc(lang('Accounting.party'), 'attr') ?>">
                <option value=""><?= esc(lang('Accounting.party_not_needed')) ?></option>
            </select>
        </td>
        <td>
            <select name="cost_center_id[]" class="form-control input-sm" aria-label="<?= esc(lang('Accounting.cost_center'), 'attr') ?>">
                <option value=""></option>
                <?php foreach ($cost_centers as $center): ?>
                    <option value="<?= (int) $center['value_id'] ?>"><?= esc($center['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" inputmode="decimal" name="debit[]" class="form-control input-sm dj-ltr text-right js-amount" aria-label="<?= esc(lang('Accounting.debit'), 'attr') ?>"></td>
        <td><input type="text" inputmode="decimal" name="credit[]" class="form-control input-sm dj-ltr text-right js-amount" aria-label="<?= esc(lang('Accounting.credit'), 'attr') ?>"></td>
        <td><button type="button" class="btn btn-link btn-xs js-remove" aria-label="<?= esc(lang('Common.delete'), 'attr') ?>"><span class="glyphicon glyphicon-remove" aria-hidden="true"></span></button></td>
    </tr>
</template>

<script type="text/javascript">
    $(document).ready(function() {
        const labels = {
            balanced: <?= json_encode(lang('Accounting.entry_balanced')) ?>,
            difference: <?= json_encode(lang('Accounting.entry_difference')) ?>,
            needsLines: <?= json_encode(lang('Accounting.entry_needs_two_lines')) ?>,
            choose: <?= json_encode(lang('Accounting.choose_party')) ?>,
            notNeeded: <?= json_encode(lang('Accounting.party_not_needed')) ?>
        };
        const parties = <?= json_encode($parties, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

        // Accept Arabic-Indic digits and separators as typed; the server parses them the same way.
        const toCents = function(value) {
            const western = String(value || '').replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace('٫', '.').replace(/[٬,\s]/g, '');
            const parsed = parseFloat(western);
            return isNaN(parsed) ? 0 : Math.round(parsed * 100);
        };

        const recalc = function() {
            let debit = 0;
            let credit = 0;
            let lines = 0;
            $('#journal_lines tbody tr').each(function() {
                const d = toCents($('[name="debit[]"]', this).val());
                const c = toCents($('[name="credit[]"]', this).val());
                debit += d;
                credit += c;
                if ($('select.js-account', this).val() && (d || c)) {
                    lines++;
                }
            });
            $('#total_debit').text((debit / 100).toFixed(2));
            $('#total_credit').text((credit / 100).toFixed(2));

            const balanced = debit === credit && debit > 0 && lines >= 2;
            let message = labels.balanced;
            if (lines < 2) {
                message = labels.needsLines;
            } else if (debit !== credit) {
                message = labels.difference.replace('{0}', (Math.abs(debit - credit) / 100).toFixed(2));
            }
            $('#balance_state')
                .html('<span class="glyphicon glyphicon-' + (balanced ? 'ok-circle' : 'exclamation-sign') + '" aria-hidden="true"></span> ')
                .append(document.createTextNode(message))
                .toggleClass('dj-badge--success', balanced)
                .toggleClass('dj-badge--warning', !balanced);
            $('#post_entry').prop('disabled', !balanced);
        };

        const addLine = function() {
            const row = $($('#line_template').html());
            $('#journal_lines tbody').append(row);
            row.find('select.js-account').selectpicker();
            recalc();
        };

        $('#add_line').on('click', addLine);
        addLine();
        addLine();

        $('#journal_lines').on('input change', 'input, select', recalc);

        // A line is either a debit or a credit.
        $('#journal_lines').on('input', '[name="debit[]"], [name="credit[]"]', function() {
            if ($(this).val() !== '') {
                $(this).closest('tr').find($(this).attr('name') === 'debit[]' ? '[name="credit[]"]' : '[name="debit[]"]').val('');
            }
        });

        // Accounts kept per person ask for the customer / supplier / partner by name.
        $('#journal_lines').on('change', 'select.js-account', function() {
            const partyType = $('option:selected', this).data('party');
            const party = $(this).closest('tr').find('.js-party').empty();
            if (!partyType) {
                party.append(new Option(labels.notNeeded, '')).prop('disabled', true).prop('required', false);
                return;
            }
            party.append(new Option(labels.choose, ''));
            $.each(parties[partyType] || {}, function(id, name) {
                party.append(new Option(name, id));
            });
            party.prop('disabled', false).prop('required', true);
        });

        $('#journal_lines').on('click', '.js-remove', function() {
            $(this).closest('tr').remove();
            recalc();
        });

        $('#journal_form').on('submit', function(event) {
            event.preventDefault();
            const button = $('#post_entry');
            if (button.hasClass('is-busy')) {
                return;
            }
            button.prop('disabled', true).addClass('is-busy');
            $.post('<?= site_url('journal/save') ?>', $(this).serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.href = '<?= site_url('journal/view') ?>/' + response.id;
                } else {
                    button.removeClass('is-busy');
                    recalc();
                }
            }, 'json').fail(function() {
                button.removeClass('is-busy');
                recalc();
            });
        });
    });
</script>

<?= view('partial/footer') ?>
