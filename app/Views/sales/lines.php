<?php
/**
 * Product lines of one sale, rendered inside an expanded sales-history row.
 *
 * @var list<array> $lines
 */
?>
<table class="table table-condensed table-bordered dj-sale-lines">
    <thead>
        <tr>
            <th><?= lang('Items.item_number') ?></th>
            <th><?= lang('Sales.item_name') ?></th>
            <th class="text-right"><?= lang('Sales.quantity') ?></th>
            <th class="text-right"><?= lang('Sales.price') ?></th>
            <th class="text-right"><?= lang('Sales.item_discounts') ?></th>
            <th class="text-right"><?= lang('Sales.invoice_discount') ?></th>
            <th class="text-right"><?= lang('Sales.net_amount') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($lines as $line): ?>
            <tr>
                <td class="dj-ltr"><?= esc((string) $line['item_number']) ?></td>
                <td dir="auto"><?= esc((string) $line['name']) ?></td>
                <td class="text-right dj-num"><?= to_quantity_decimals((string) $line['quantity_purchased']) ?></td>
                <td class="text-right dj-num"><?= to_currency((string) $line['item_unit_price']) ?></td>
                <td class="text-right dj-num"><?= $line['line_discount'] != 0.0 ? to_currency((string) $line['line_discount']) : '' ?></td>
                <td class="text-right dj-num"><?= (float) $line['invoice_discount'] != 0.0 ? to_currency((string) $line['invoice_discount']) : '' ?></td>
                <td class="text-right dj-num"><strong><?= to_currency((string) $line['net']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
