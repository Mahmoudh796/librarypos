<?php
/**
 * @var string $party_type
 * @var list<array> $rows
 * @var array $names
 * @var string $as_of
 */
$isCustomer = $party_type === 'customer';
$buckets = ['current' => 'Accounting.aging_current', 'd30' => 'Accounting.aging_31_60', 'd60' => 'Accounting.aging_61_90', 'd90' => 'Accounting.aging_91_120', 'older' => 'Accounting.aging_older'];
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'statements']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?= lang($isCustomer ? 'Accounting.receivables_aging' : 'Accounting.payables_aging') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<ul class="nav nav-pills dj-subnav-pills print_hide">
    <li<?= $isCustomer ? ' class="active"' : '' ?>><a href="<?= esc(base_url('accounting/aging') . '?party_type=customer', 'attr') ?>"><?= lang('Accounting.customers') ?></a></li>
    <li<?= !$isCustomer ? ' class="active"' : '' ?>><a href="<?= esc(base_url('accounting/aging') . '?party_type=supplier', 'attr') ?>"><?= lang('Accounting.suppliers') ?></a></li>
</ul>

<?= view('accounting/partials/range', ['as_of' => $as_of, 'action' => base_url('accounting/aging'), 'extra' => '<input type="hidden" name="party_type" value="' . esc($party_type, 'attr') . '">']) ?>

<?php if ($rows === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_outstanding_balances') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang($isCustomer ? 'Accounting.customer' : 'Accounting.supplier') ?></th>
                    <th class="text-right"><?= lang('Accounting.balance') ?></th>
                    <?php foreach ($buckets as $label): ?><th class="text-right"><?= lang($label) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td dir="auto"><a href="<?= esc(base_url('accounting/statement') . "?party_type=$party_type&party_id={$row['party_id']}&from=" . date('Y-01-01') . "&to=$as_of", 'attr') ?>"><?= esc($names[$row['party_id']] ?? ('#' . $row['party_id'])) ?></a></td>
                        <td class="text-right dj-num"><strong><?= to_currency((string) $row['balance']) ?></strong></td>
                        <?php foreach (array_keys($buckets) as $bucket): ?>
                            <td class="text-right dj-num"><?= $row[$bucket] != 0.0 ? to_currency((string) $row[$bucket]) : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th><?= lang('Accounting.total') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($rows, 'balance'))) ?></th>
                    <?php foreach (array_keys($buckets) as $bucket): ?>
                        <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($rows, $bucket))) ?></th>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
