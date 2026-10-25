<?php
/**
 * @var list<array> $treasuries
 * @var list<array> $movements
 * @var array<string, string> $parties
 * @var float $total_in
 * @var float $total_out
 * @var string $from
 * @var string $to
 * @var int $treasury_id
 * @var string $direction
 * @var int $page
 * @var int $pages
 * @var bool $can_vouchers
 * @var array{opening: float, closing: float}|null $position
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-briefcase" aria-hidden="true"></span>
        <?= lang('Treasury.title') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<ul class="nav nav-tabs dj-subnav print_hide">
    <li class="active"><a href="<?= esc(base_url('treasury'), 'attr') ?>" aria-current="page"><?= lang('Treasury.movements') ?></a></li>
    <li><a href="<?= esc(base_url('treasury/vouchers'), 'attr') ?>"><?= lang('Treasury.vouchers') ?></a></li>
    <li><a href="<?= esc(base_url('cashups'), 'attr') ?>"><?= lang('Treasury.shifts') ?></a></li>
</ul>

<div class="ruya-kpis ruya-kpis--primary">
    <?php foreach ($treasuries as $treasury): ?>
        <a class="ruya-kpi<?= (int) $treasury['treasury_id'] === $treasury_id ? ' ruya-kpi--hero' : '' ?><?= $treasury['balance'] < 0 ? ' ruya-kpi--negative' : '' ?>" href="<?= esc(base_url('treasury') . '?treasury_id=' . $treasury['treasury_id'] . '&from=' . $from . '&to=' . $to, 'attr') ?>">
            <span class="ruya-kpi__label"><span class="glyphicon glyphicon-<?= $treasury['kind'] === 'cash' ? 'usd' : 'credit-card' ?>" aria-hidden="true"></span> <?= esc($treasury['name']) ?></span>
            <span class="ruya-kpi__value dj-money"><?= to_currency((string) $treasury['balance']) ?></span>
            <span class="ruya-kpi__meta"><?= lang('Treasury.current_balance') ?> · <?= lang('Treasury.kind_' . $treasury['kind']) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($can_vouchers): ?>
    <div class="btn-toolbar dj-page-actions print_hide" role="toolbar">
        <?php foreach (['customer_receipt' => 'log-in', 'supplier_payment' => 'log-out', 'other_receipt' => 'plus', 'other_payment' => 'minus', 'transfer' => 'transfer', 'opening_balance' => 'flag'] as $type => $icon): ?>
            <a class="btn btn-default btn-sm" href="<?= esc(base_url('treasury/voucher') . '?type=' . $type, 'attr') ?>">
                <span class="glyphicon glyphicon-<?= $icon ?>" aria-hidden="true"></span> <?= lang('Treasury.type_' . $type) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$treasuryOptions = ['' => lang('Treasury.all_treasuries')] + array_column($treasuries, 'name', 'treasury_id');
$extra = '<div class="form-group"><label for="filter_treasury">' . lang('Treasury.treasury') . '</label>' . form_dropdown('treasury_id', $treasuryOptions, (string) ($treasury_id ?: ''), ['class' => 'form-control input-sm', 'id' => 'filter_treasury']) . '</div>'
    . '<div class="form-group"><label for="filter_direction">' . lang('Treasury.direction') . '</label>' . form_dropdown('direction', ['' => lang('Treasury.all_directions'), 'in' => lang('Treasury.direction_in'), 'out' => lang('Treasury.direction_out')], $direction, ['class' => 'form-control input-sm', 'id' => 'filter_direction']) . '</div>';
?>
<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('treasury'), 'extra' => $extra]) ?>

<?php if ($position !== null): ?>
    <div class="ruya-kpis" aria-label="<?= esc(lang('Treasury.period_position'), 'attr') ?>">
        <div class="ruya-kpi"><span class="ruya-kpi__label"><?= lang('Treasury.opening_of_period') ?></span><span class="ruya-kpi__value dj-money"><?= to_currency((string) $position['opening']) ?></span></div>
        <div class="ruya-kpi"><span class="ruya-kpi__label"><?= lang('Treasury.direction_in') ?></span><span class="ruya-kpi__value dj-money dj-amount-in"><?= to_currency((string) $total_in) ?></span></div>
        <div class="ruya-kpi"><span class="ruya-kpi__label"><?= lang('Treasury.direction_out') ?></span><span class="ruya-kpi__value dj-money dj-amount-out"><?= to_currency((string) $total_out) ?></span></div>
        <div class="ruya-kpi ruya-kpi--hero"><span class="ruya-kpi__label"><?= lang('Treasury.closing_of_period') ?></span><span class="ruya-kpi__value dj-money"><?= to_currency((string) $position['closing']) ?></span></div>
    </div>
<?php endif; ?>

<?php if ($movements === []): ?>
    <div class="ruya-empty"><span class="glyphicon glyphicon-inbox" aria-hidden="true"></span><?= lang('Treasury.no_movements') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.date') ?></th>
                    <th><?= lang('Treasury.treasury') ?></th>
                    <th><?= lang('Treasury.movement_type') ?></th>
                    <th><?= lang('Accounting.description') ?></th>
                    <th><?= lang('Accounting.reference') ?></th>
                    <th><?= lang('Accounting.party') ?></th>
                    <th class="text-right"><?= lang('Treasury.direction_in') ?></th>
                    <th class="text-right"><?= lang('Treasury.direction_out') ?></th>
                    <th class="text-right"><?= lang('Treasury.running_balance') ?></th>
                    <th><?= lang('Accounting.employee') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $movement): ?>
                    <tr>
                        <td class="dj-num text-nowrap"><?= esc(substr($movement['movement_time'], 0, 16)) ?></td>
                        <td><?= esc($movement['treasury_name']) ?></td>
                        <td><?= esc(lang('Treasury.movement_' . $movement['movement_type'])) ?></td>
                        <td dir="auto"><?= esc($movement['description']) ?></td>
                        <td class="dj-ltr"><?= esc($movement['reference']) ?></td>
                        <td dir="auto"><?= esc($parties[$movement['party_type'] . ':' . $movement['party_id']] ?? '') ?></td>
                        <td class="text-right dj-num dj-amount-in"><?= $movement['direction'] === 'in' ? to_currency($movement['amount']) : '' ?></td>
                        <td class="text-right dj-num dj-amount-out"><?= $movement['direction'] === 'out' ? to_currency($movement['amount']) : '' ?></td>
                        <td class="text-right dj-num"><?= to_currency((string) $movement['running_balance']) ?></td>
                        <td><?= esc(trim($movement['first_name'] . ' ' . $movement['last_name'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th colspan="6"><?= lang('Accounting.period_totals') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $total_in) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) $total_out) ?></th>
                    <th class="text-right dj-num" title="<?= esc(lang('Treasury.net_movement'), 'attr') ?>"><?= to_currency((string) ($total_in - $total_out)) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?= view('accounting/partials/pager', ['page' => $page, 'pages' => $pages]) ?>
<?php endif; ?>

<?= view('partial/footer') ?>
