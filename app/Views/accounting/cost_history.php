<?php
/**
 * @var list<array> $rows
 * @var int $item_id
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'cost_history']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-random" aria-hidden="true"></span> <?= lang('Accounting.cost_history') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<p class="help-block dj-hint"><?= lang('Accounting.cost_history_help') ?></p>

<?php if ($rows === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_cost_changes') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.date') ?></th>
                    <th><?= lang('Items.name') ?></th>
                    <th><?= lang('Accounting.source') ?></th>
                    <th class="text-right"><?= lang('Accounting.qty_before') ?></th>
                    <th class="text-right"><?= lang('Accounting.cost_before') ?></th>
                    <th class="text-right"><?= lang('Accounting.qty_in') ?></th>
                    <th class="text-right"><?= lang('Accounting.unit_cost_in') ?></th>
                    <th class="text-right"><?= lang('Accounting.cost_after') ?></th>
                    <th><?= lang('Accounting.employee') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="dj-num text-nowrap"><?= esc(substr($row['changed_at'], 0, 16)) ?></td>
                        <td dir="auto"><a href="<?= esc(base_url('accounting/costHistory') . '?item_id=' . $row['item_id'], 'attr') ?>"><?= esc($row['name']) ?></a></td>
                        <td><?= lang('Accounting.cost_source_' . $row['source_type']) ?><?= $row['source_id'] ? ' <span class="dj-ltr">#' . (int) $row['source_id'] . '</span>' : '' ?></td>
                        <td class="text-right dj-num"><?= to_quantity_decimals($row['qty_before']) ?></td>
                        <td class="text-right dj-num"><?= to_currency($row['cost_before']) ?></td>
                        <td class="text-right dj-num"><?= (float) $row['qty_change'] != 0.0 ? to_quantity_decimals($row['qty_change']) : '' ?></td>
                        <td class="text-right dj-num"><?= $row['unit_cost_in'] !== null ? to_currency($row['unit_cost_in']) : '' ?></td>
                        <td class="text-right dj-num"><strong><?= to_currency($row['cost_after']) ?></strong></td>
                        <td><?= esc(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
