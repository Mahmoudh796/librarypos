<?php
/**
 * @var string $party_type
 * @var int $party_id
 * @var array $parties
 * @var ?array $report
 * @var string $from
 * @var string $to
 * @var bool $can_vouchers
 */
$isCustomer = $party_type === 'customer';
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'statements']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?= lang($isCustomer ? 'Accounting.customer_statement' : 'Accounting.supplier_statement') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<ul class="nav nav-pills dj-subnav-pills print_hide">
    <li<?= $isCustomer ? ' class="active"' : '' ?>><a href="<?= esc(base_url('accounting/statement') . '?party_type=customer', 'attr') ?>"><?= lang('Accounting.customers') ?></a></li>
    <li<?= !$isCustomer ? ' class="active"' : '' ?>><a href="<?= esc(base_url('accounting/statement') . '?party_type=supplier', 'attr') ?>"><?= lang('Accounting.suppliers') ?></a></li>
    <li><a href="<?= esc(base_url('accounting/aging') . '?party_type=' . $party_type, 'attr') ?>"><?= lang('Accounting.aging') ?></a></li>
</ul>

<?php
$extra = '<input type="hidden" name="party_type" value="' . esc($party_type, 'attr') . '">'
    . '<div class="form-group">' . form_dropdown('party_id', $parties, (string) ($party_id ?: ''), ['class' => 'form-control input-sm selectpicker', 'data-live-search' => 'true', 'aria-label' => lang($isCustomer ? 'Accounting.customer' : 'Accounting.supplier')]) . '</div>';
?>
<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('accounting/statement'), 'extra' => $extra]) ?>

<?php if ($report === null): ?>
    <div class="dj-empty-state"><?= lang($isCustomer ? 'Accounting.choose_customer' : 'Accounting.choose_supplier') ?></div>
<?php else: ?>
    <div class="dj-statement-head">
        <h2 class="dj-section-title" dir="auto"><?= esc($parties[$party_id] ?? '') ?></h2>
        <?php
        // Receivable: a debit balance is owed to us. Payable: a credit balance is owed by us.
        $outstanding = $isCustomer ? $report['closing'] : -$report['closing'];
        ?>
        <p class="dj-statement-balance">
            <?= lang($isCustomer ? 'Accounting.customer_owes' : 'Accounting.we_owe_supplier') ?>:
            <strong class="dj-num"><?= to_currency((string) $outstanding) ?></strong>
        </p>
        <?php if ($can_vouchers): ?>
            <a class="btn btn-default btn-sm print_hide" href="<?= esc(base_url('treasury/voucher') . '?type=' . ($isCustomer ? 'customer_receipt' : 'supplier_payment') . '&party_id=' . $party_id, 'attr') ?>">
                <span class="glyphicon glyphicon-<?= $isCustomer ? 'log-in' : 'log-out' ?>" aria-hidden="true"></span>
                <?= lang($isCustomer ? 'Treasury.type_customer_receipt' : 'Treasury.type_supplier_payment') ?>
            </a>
        <?php endif; ?>
    </div>
    <?= view('accounting/partials/ledger_table', ['report' => $report, 'show_party' => false]) ?>
<?php endif; ?>

<?= view('partial/footer') ?>
