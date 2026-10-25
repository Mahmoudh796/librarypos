<?php
/**
 * Ledger rows with opening balance, running balance and closing balance.
 * Balances are shown as debit/credit columns so the sign never has to be guessed.
 *
 * @var array $report
 * @var bool $show_party
 */
$side = static function (float $balance): string {
    if (abs($balance) < 0.005) {
        return to_currency('0');
    }

    return to_currency((string) abs($balance)) . ' ' . lang($balance > 0 ? 'Accounting.dr' : 'Accounting.cr');
};
?>
<div class="table-responsive">
    <table class="table table-striped table-bordered table-condensed dj-ledger-table">
        <thead>
            <tr>
                <th><?= lang('Accounting.date') ?></th>
                <th><?= lang('Accounting.entry_number') ?></th>
                <th><?= lang('Accounting.reference') ?></th>
                <th><?= lang('Accounting.description') ?></th>
                <th class="text-right"><?= lang('Accounting.debit') ?></th>
                <th class="text-right"><?= lang('Accounting.credit') ?></th>
                <th class="text-right"><?= lang('Accounting.balance') ?></th>
            </tr>
        </thead>
        <tbody>
            <tr class="dj-subtotal-row">
                <td colspan="6"><?= lang('Accounting.opening_balance') ?></td>
                <td class="text-right dj-num"><?= $side($report['opening']) ?></td>
            </tr>
            <?php foreach ($report['rows'] as $row): ?>
                <tr<?= $row['reverses_entry_id'] !== null ? ' class="dj-reversal-row"' : '' ?>>
                    <td class="dj-num text-nowrap"><?= esc(substr($row['entry_date'], 0, 10)) ?></td>
                    <td class="dj-ltr"><a href="<?= esc(base_url('journal/view/' . $row['entry_id']), 'attr') ?>"><?= esc($row['entry_number']) ?></a></td>
                    <td class="dj-ltr"><?= esc($row['reference']) ?></td>
                    <td dir="auto"><?= esc($row['description'] !== '' ? $row['description'] : $row['entry_description']) ?></td>
                    <td class="text-right dj-num"><?= (float) $row['debit'] != 0.0 ? to_currency($row['debit']) : '' ?></td>
                    <td class="text-right dj-num"><?= (float) $row['credit'] != 0.0 ? to_currency($row['credit']) : '' ?></td>
                    <td class="text-right dj-num"><?= $side((float) $row['balance']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="dj-total-row">
                <th colspan="6"><?= lang('Accounting.closing_balance') ?></th>
                <th class="text-right dj-num"><?= $side($report['closing']) ?></th>
            </tr>
        </tfoot>
    </table>
</div>
