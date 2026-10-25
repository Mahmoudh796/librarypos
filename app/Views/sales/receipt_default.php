<?php
/**
 * 80 mm thermal receipt.
 *
 * Store details come from the configuration only (sample values left by the
 * installer are skipped). Each product takes two short lines — the name, then
 * quantity × unit price and the line total — so it stays readable at 72 mm of
 * printable width. Reprinting renders the saved sale again; nothing is posted.
 *
 * @var string $transaction_time
 * @var string $sale_id
 * @var string $invoice_number
 * @var string $employee
 * @var array  $cart
 * @var float  $prediscount_subtotal
 * @var float  $subtotal
 * @var array  $taxes
 * @var float  $total
 * @var array  $payments
 * @var float  $amount_change
 * @var string $barcode
 * @var array  $config
 */

helper('store');

$lineDiscountTotal = 0.0;
foreach ($cart as $item) {
    $lineDiscountTotal += (float) $item['total'] - (float) $item['discounted_total'];
}
$invoiceDiscountAmount = (float) ($invoice_discount_amount ?? 0);
$hasDiscounts = abs($lineDiscountTotal) >= 0.005 || abs($invoiceDiscountAmount) >= 0.005;
$isReturn = (float) $total < 0;
$address = store_info('address');
$phone = store_info('phone');
$returnPolicy = store_info('return_policy');
?>

<div id="receipt_wrapper" class="ruya-receipt" style="font-size: <?= (int) $config['receipt_font_size'] ?>px;">
    <header class="ruya-receipt__header">
        <?php if ($config['company_logo'] != ''): ?>
            <img id="image" class="ruya-receipt__logo" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="<?= esc($config['company'], 'attr') ?>">
        <?php endif; ?>
        <?php if ($config['receipt_show_company_name']): ?>
            <div id="company_name" class="ruya-receipt__store"><?= nl2br(esc($config['company'])) ?></div>
        <?php endif; ?>
        <?php if ($address !== ''): ?>
            <div id="company_address"><?= nl2br(esc($address)) ?></div>
        <?php endif; ?>
        <?php if ($phone !== ''): ?>
            <div id="company_phone" dir="ltr"><?= esc($phone) ?></div>
        <?php endif; ?>
        <?php if (!empty($config['tax_id'])): ?>
            <div><?= lang('Sales.tax_id') ?>: <span dir="ltr"><?= esc($config['tax_id']) ?></span></div>
        <?php endif; ?>
        <div id="sale_receipt" class="ruya-receipt__title"><?= lang($isReturn ? 'Sales.return_receipt' : 'Sales.receipt') ?></div>
    </header>

    <dl id="receipt_general_info" class="ruya-receipt__meta">
        <div><dt><?= lang('Sales.receipt_number') ?></dt><dd id="sale_id" dir="ltr"><?= esc($sale_id) ?></dd></div>
        <?php if (!empty($invoice_number)): ?>
            <div><dt><?= lang('Sales.invoice_number') ?></dt><dd id="invoice_number" dir="ltr"><?= esc($invoice_number) ?></dd></div>
        <?php endif; ?>
        <div><dt><?= lang('Sales.date') ?></dt><dd id="sale_time" dir="ltr"><?= esc($transaction_time) ?></dd></div>
        <div><dt><?= lang('Sales.cashier') ?></dt><dd id="employee"><?= esc($employee) ?></dd></div>
        <?php if (isset($customer)): ?>
            <div><dt><?= lang('Customers.customer') ?></dt><dd id="customer"><?= esc($customer) ?></dd></div>
        <?php endif; ?>
    </dl>

    <table id="receipt_items" class="ruya-receipt__items">
        <thead>
            <tr>
                <th><?= lang('Sales.item_name') ?></th>
                <th class="total-value"><?= lang('Sales.total') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cart as $item): ?>
                <?php if ($item['print_option'] != PRINT_YES) {
                    continue;
                } ?>
                <?php $lineDiscount = (float) $item['total'] - (float) $item['discounted_total']; ?>
                <tr class="ruya-receipt__item-name">
                    <td colspan="2"><?= esc(trim($item['name'] . ' ' . $item['attribute_values'])) ?><?= $config['receipt_show_tax_ind'] ? ' ' . esc($item['taxed_flag']) : '' ?></td>
                </tr>
                <tr class="ruya-receipt__item-line">
                    <td><span dir="ltr"><?= to_quantity_decimals($item['quantity']) ?> × <?= to_currency($item['price']) ?></span></td>
                    <td class="total-value"><?= to_currency($item['total']) ?></td>
                </tr>
                <?php if (abs($lineDiscount) >= 0.005): ?>
                    <tr class="ruya-receipt__item-discount">
                        <td class="discount">
                            <?= lang('Sales.discount') ?>
                            <?php if ($item['discount_type'] == PERCENT): ?>(<span dir="ltr"><?= to_decimals($item['discount']) ?>%</span>)<?php endif; ?>
                        </td>
                        <td class="total-value"><?= to_currency((string) -$lineDiscount) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($config['receipt_show_description'] && trim((string) $item['description']) !== '' && trim((string) $item['description']) !== trim((string) $item['name'])): ?>
                    <tr class="ruya-receipt__item-note"><td colspan="2"><?= esc($item['description']) ?></td></tr>
                <?php endif; ?>
                <?php if ($config['receipt_show_serialnumber'] && trim((string) $item['serialnumber']) !== ''): ?>
                    <tr class="ruya-receipt__item-note"><td colspan="2" dir="ltr"><?= esc($item['serialnumber']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="ruya-receipt__totals">
        <?php if ($hasDiscounts): ?>
            <tr><td><?= lang('Sales.gross_total') ?></td><td class="total-value"><?= to_currency($prediscount_subtotal) ?></td></tr>
            <?php if (abs($lineDiscountTotal) >= 0.005): ?>
                <tr><td><?= lang('Sales.item_discounts') ?></td><td class="total-value"><?= to_currency((string) -$lineDiscountTotal) ?></td></tr>
            <?php endif; ?>
            <?php if (abs($invoiceDiscountAmount) >= 0.005): ?>
                <tr>
                    <td>
                        <?= lang('Sales.invoice_discount') ?>
                        <?php if ((int) ($invoice_discount['type'] ?? FIXED) === PERCENT): ?>(<span dir="ltr"><?= to_tax_decimals((string) $invoice_discount['value']) ?>%</span>)<?php endif; ?>
                    </td>
                    <td class="total-value"><?= to_currency((string) -$invoiceDiscountAmount) ?></td>
                </tr>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($config['receipt_show_taxes'] && $taxes !== []): ?>
            <tr><td><?= lang('Sales.sub_total') ?></td><td class="total-value"><?= to_currency($subtotal) ?></td></tr>
            <?php foreach ($taxes as $tax): ?>
                <tr><td><?= esc($tax['tax_group']) ?> <span dir="ltr"><?= (float) $tax['tax_rate'] ?>%</span></td><td class="total-value"><?= to_currency_tax($tax['sale_tax_amount']) ?></td></tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <tr class="ruya-receipt__grand">
            <td><?= lang($isReturn ? 'Sales.refund_total' : 'Sales.total_payable') ?></td>
            <td class="total-value"><?= to_currency($total) ?></td>
        </tr>
    </table>

    <table class="ruya-receipt__payments">
        <?php
        $onlySaleCheck = false;
        $showGiftcardRemainder = false;
        foreach ($payments as $payment):
            $onlySaleCheck |= $payment['payment_type'] == lang('Sales.check');
            $paymentType = explode(':', $payment['payment_type']);
            $showGiftcardRemainder |= $paymentType[0] == lang('Sales.giftcard');
            ?>
            <tr><td><?= esc($paymentType[0]) ?></td><td class="total-value"><?= to_currency($payment['payment_amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (isset($cur_giftcard_value) && $showGiftcardRemainder): ?>
            <tr><td><?= lang('Sales.giftcard_balance') ?></td><td class="total-value"><?= to_currency($cur_giftcard_value) ?></td></tr>
        <?php endif; ?>
        <tr class="ruya-receipt__change">
            <td><?= lang($amount_change >= 0 ? ($onlySaleCheck ? 'Sales.check_balance' : 'Sales.change_due_receipt') : 'Sales.amount_due') ?></td>
            <td class="total-value"><?= to_currency((string) abs((float) $amount_change)) ?></td>
        </tr>
    </table>

    <?php if ($returnPolicy !== ''): ?>
        <div id="sale_return_policy" class="ruya-receipt__policy"><?= nl2br(esc($returnPolicy)) ?></div>
    <?php endif; ?>

    <div id="barcode" class="ruya-receipt__barcode">
        <?= $barcode ?>
        <div dir="ltr"><?= esc($sale_id) ?></div>
    </div>
</div>
