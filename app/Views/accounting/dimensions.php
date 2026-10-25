<?php
/**
 * @var array $dimensions
 * @var string $dimension
 * @var list<array> $rows
 * @var string $from
 * @var string $to
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'dimensions']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-th" aria-hidden="true"></span> <?= lang('Accounting.dimension_analysis') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<?php
$extra = '<div class="form-group">' . form_dropdown('dimension', $dimensions, $dimension, ['class' => 'form-control input-sm', 'aria-label' => lang('Accounting.dimension')]) . '</div>';
?>
<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting/dimensions'), 'extra' => $extra]) ?>

<p class="help-block dj-hint"><?= lang('Accounting.dimension_analysis_help') ?></p>

<?php if ($rows === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_entries') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= esc($dimensions[$dimension] ?? '') ?></th>
                    <th class="text-right"><?= lang('Accounting.revenue') ?></th>
                    <th class="text-right"><?= lang('Accounting.expenses') ?></th>
                    <th class="text-right"><?= lang('Accounting.net') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td dir="auto"><?= esc($row['value_name'] ?? lang('Accounting.unassigned')) ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $row['revenue']) ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $row['expense']) ?></td>
                        <td class="text-right dj-num<?= $row['net'] < 0 ? ' text-danger' : '' ?>"><?= to_currency((string) $row['net']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th><?= lang('Accounting.total') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($rows, 'revenue'))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($rows, 'expense'))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($rows, 'net'))) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
