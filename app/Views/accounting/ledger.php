<?php
/**
 * @var array $accounts
 * @var int $account_id
 * @var ?array $report
 * @var string $from
 * @var string $to
 * @var ?int $dimension_value
 * @var array $dimension_values
 * @var int $page
 * @var int $pages
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'ledger']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-book" aria-hidden="true"></span> <?= lang('Accounting.general_ledger') ?></h1>
    <?= view('partial/page_nav') ?>
</div>

<?php
$extra = '<div class="form-group">' . form_dropdown('account_id', $accounts, (string) ($account_id ?: ''), ['class' => 'form-control input-sm selectpicker', 'data-live-search' => 'true', 'aria-label' => lang('Accounting.account')]) . '</div>'
    . '<div class="form-group">' . form_dropdown('dimension_value', $dimension_values, (string) ($dimension_value ?? ''), ['class' => 'form-control input-sm', 'aria-label' => lang('Accounting.dimension')]) . '</div>';
?>
<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting/ledger'), 'extra' => $extra]) ?>

<?php if ($report === null): ?>
    <div class="dj-empty-state"><?= lang('Accounting.choose_account') ?></div>
<?php else: ?>
    <?= view('accounting/partials/ledger_table', ['report' => $report, 'show_party' => true]) ?>
    <?= view('accounting/partials/pager', ['page' => $page, 'pages' => $pages]) ?>
<?php endif; ?>

<?= view('partial/footer') ?>
