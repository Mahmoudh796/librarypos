<?php
/**
 * @var list<array> $rows
 * @var string $from
 * @var string $to
 */
$sum = static fn (string $key): float => round(array_sum(array_column($rows, $key)), 2);
$debitBalance = static fn (float $v): string => $v > 0 ? to_currency((string) $v) : '';
$creditBalance = static fn (float $v): string => $v < 0 ? to_currency((string) -$v) : '';
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'trial_balance']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-list-alt" aria-hidden="true"></span> <?= lang('Accounting.trial_balance') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting/trialBalance')]) ?>

<?php if ($rows === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_entries') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered table-condensed dj-ledger-table ruya-tb">
            <thead>
                <tr>
                    <th rowspan="2"><?= lang('Accounting.account_code') ?></th>
                    <th rowspan="2"><?= lang('Accounting.account') ?></th>
                    <th colspan="2" class="text-center"><?= lang('Accounting.opening_balance') ?></th>
                    <th colspan="2" class="text-center"><?= lang('Accounting.period_movement') ?></th>
                    <th colspan="2" class="text-center"><?= lang('Accounting.closing_balance') ?></th>
                </tr>
                <tr>
                    <?php for ($i = 0; $i < 3; $i++): ?>
                        <th class="text-right"><?= lang('Accounting.debit') ?></th>
                        <th class="text-right"><?= lang('Accounting.credit') ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="dj-ltr"><a href="<?= esc(base_url('accounting/ledger') . "?account_id={$row['account_id']}&from=$from&to=$to", 'attr') ?>"><?= esc($row['code']) ?></a></td>
                        <td><?= esc($row['name']) ?></td>
                        <td class="text-right dj-num"><?= $debitBalance($row['opening']) ?></td>
                        <td class="text-right dj-num"><?= $creditBalance($row['opening']) ?></td>
                        <td class="text-right dj-num"><?= $row['debit'] != 0.0 ? to_currency((string) $row['debit']) : '' ?></td>
                        <td class="text-right dj-num"><?= $row['credit'] != 0.0 ? to_currency((string) $row['credit']) : '' ?></td>
                        <td class="text-right dj-num"><?= $debitBalance($row['closing']) ?></td>
                        <td class="text-right dj-num"><?= $creditBalance($row['closing']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php
                $openingDebit = round(array_sum(array_map(static fn ($r) => max($r['opening'], 0), $rows)), 2);
                $openingCredit = round(array_sum(array_map(static fn ($r) => max(-$r['opening'], 0), $rows)), 2);
                $closingDebit = round(array_sum(array_map(static fn ($r) => max($r['closing'], 0), $rows)), 2);
                $closingCredit = round(array_sum(array_map(static fn ($r) => max(-$r['closing'], 0), $rows)), 2);
                ?>
                <tr class="dj-total-row">
                    <th colspan="2"><?= lang('Accounting.total') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $openingDebit) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $openingCredit) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $sum('debit')) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $sum('credit')) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $closingDebit) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $closingCredit) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if (round($closingDebit - $closingCredit, 2) != 0.0 || round($sum('debit') - $sum('credit'), 2) != 0.0): ?>
        <div class="alert alert-danger" role="alert"><span class="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span> <?= lang('Accounting.trial_balance_out_of_balance') ?></div>
    <?php else: ?>
        <p class="dj-badge dj-badge--success" role="status"><span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span> <?= lang('Accounting.trial_balance_balanced') ?></p>
    <?php endif; ?>
<?php endif; ?>

<?= view('partial/footer') ?>
