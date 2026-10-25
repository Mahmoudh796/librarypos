<?php

namespace App\Libraries\Accounting;

use App\Libraries\Tax_lib;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;

/**
 * Turns operational documents into journal entries (rules in docs/ruya/ACCOUNTING_DESIGN.md).
 *
 * Every post*() method is idempotent: it compares the entry it would write with
 * the document's live entry, does nothing when they match, and otherwise
 * reverses the live entry and posts the new one. A document that no longer
 * qualifies (cancelled sale, deleted expense) just has its live entry reversed.
 *
 * Callers run these inside the document's own transaction.
 */
class Posting
{
    public const SOURCE_SALE = 'sale';
    public const SOURCE_RECEIVING = 'receiving';
    public const SOURCE_EXPENSE = 'expense';
    public const SOURCE_CASHUP = 'cashup';
    public const SOURCE_INVENTORY = 'inventory';
    public const SOURCE_COST = 'cost_revaluation';

    private BaseConnection $db;
    private Ledger $ledger;

    public function __construct(?BaseConnection $db = null, ?Ledger $ledger = null)
    {
        $this->db = $db ?? Database::connect();
        $this->ledger = $ledger ?? new Ledger($this->db);
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public static function enabled(): bool
    {
        $settings = config(OSPOS::class)->settings;

        return ($settings['accounting_enabled'] ?? '0') === '1';
    }

    /**
     * Documents dated before the accounting start date belong to the pre-ledger
     * era; they are only posted by the explicit backfill.
     */
    public static function inScope(string $documentDate, bool $backfill = false): bool
    {
        if ($backfill) {
            return true;
        }

        $start = config(OSPOS::class)->settings['accounting_start_date'] ?? '';

        return $start === '' || substr($documentDate, 0, 10) >= $start;
    }

    public function defaultTreasuryAccount(): int
    {
        $row = $this->db->table('treasuries')->where('active', 1)->orderBy('is_default', 'DESC')->orderBy('treasury_id')->get()->getRow();

        if ($row === null) {
            throw new AccountingException('No active treasury is configured.');
        }

        return (int) $row->account_id;
    }

    public function treasuryAccount(?int $treasuryId): int
    {
        if ($treasuryId) {
            $row = $this->db->table('treasuries')->where('treasury_id', $treasuryId)->get()->getRow();

            if ($row !== null) {
                return (int) $row->account_id;
            }
        }

        return $this->defaultTreasuryAccount();
    }

    // ------------------------------------------------------------------ sales

    public function postSale(int $saleId, ?int $employeeId = null, bool $backfill = false): ?int
    {
        $sale = $this->db->table('sales')->where('sale_id', $saleId)->get()->getRow();

        if ($sale === null || (int) $sale->sale_status !== COMPLETED) {
            return $this->reverseSource(self::SOURCE_SALE, $saleId, $employeeId);
        }

        if (!self::inScope($sale->sale_time, $backfill) && $this->ledger->liveEntry(self::SOURCE_SALE, $saleId) === null) {
            return null;
        }

        return $this->sync(self::SOURCE_SALE, $saleId, [
            'entry_date'  => $sale->sale_time,
            'description' => lang('Accounting.sale_entry', ['POS ' . $saleId]),
            'reference'   => 'POS ' . $saleId,
            'created_by'  => $employeeId ?? (int) $sale->employee_id,
        ], $this->saleLines($sale));
    }

    public function saleLines(object $sale): array
    {
        $saleId = (int) $sale->sale_id;
        $customerId = $sale->customer_id !== null ? (int) $sale->customer_id : null;
        $hasInvoiceDiscount = $this->db->fieldExists('invoice_discount', 'sales_items');

        $items = $this->db->table('sales_items')
            ->select('sales_items.*, items.category, items.stock_type, items.name')
            ->join('items', 'items.item_id = sales_items.item_id', 'left')
            ->where('sales_items.sale_id', $saleId)
            ->get()
            ->getResult();

        $byCategory = [];
        $totalNet = 0.0;

        foreach ($items as $item) {
            $quantity = (float) $item->quantity_purchased;
            $price = (float) $item->item_unit_price;
            $gross = round($quantity * $price, 2);
            $discount = (int) $item->discount_type === PERCENT
                ? round($quantity * $price * (float) $item->discount / 100, 2)
                : round($quantity * (float) $item->discount, 2);
            $invoiceDiscount = $hasInvoiceDiscount ? round((float) $item->invoice_discount, 2) : 0.0;

            $category = trim((string) $item->category) !== '' ? (string) $item->category : lang('Accounting.uncategorized');
            $byCategory[$category] ??= ['gross' => 0.0, 'discount' => 0.0, 'cost' => 0.0];
            $byCategory[$category]['gross'] += $gross;
            $byCategory[$category]['discount'] += $discount + $invoiceDiscount;

            // COGS follows the stock movement: only stock items moved inventory.
            if ((int) $item->stock_type === HAS_STOCK) {
                $byCategory[$category]['cost'] += round($quantity * (float) $item->item_cost_price, 2);
            }

            $totalNet += $gross - $discount - $invoiceDiscount;
        }

        $taxExcluded = 0.0;
        $taxIncluded = 0.0;
        foreach ($this->db->table('sales_taxes')->where('sale_id', $saleId)->get()->getResult() as $tax) {
            if ((string) $tax->tax_type === Tax_lib::TAX_TYPE_INCLUDED) {
                $taxIncluded += round((float) $tax->sale_tax_amount, 2);
            } else {
                $taxExcluded += round((float) $tax->sale_tax_amount, 2);
            }
        }

        $dimensions = $this->saleDimensions($sale);
        $lines = [];

        foreach ($byCategory as $category => $amounts) {
            $categoryDimension = $dimensions + ['product_category' => ['code' => mb_substr(md5($category), 0, 12) . ':' . mb_substr($category, 0, 40), 'name' => $category]];

            $lines[] = ['account' => 'sales_revenue', 'amount' => -$amounts['gross'], 'description' => $category, 'dimensions' => $categoryDimension];
            $lines[] = ['account' => 'sales_discounts', 'amount' => $amounts['discount'], 'description' => $category, 'dimensions' => $categoryDimension];
            $lines[] = ['account' => 'cogs', 'amount' => $amounts['cost'], 'description' => $category, 'dimensions' => $categoryDimension];
            $lines[] = ['account' => 'inventory', 'amount' => -$amounts['cost'], 'description' => $category];
        }

        if ($taxIncluded != 0.0) {
            $lines[] = ['account' => 'sales_revenue', 'amount' => $taxIncluded, 'description' => lang('Accounting.tax_included_in_price'), 'dimensions' => $dimensions];
            $lines[] = ['account' => 'tax_payable', 'amount' => -$taxIncluded];
        }

        if ($taxExcluded != 0.0) {
            $lines[] = ['account' => 'tax_payable', 'amount' => -$taxExcluded];
        }

        $receivable = round($totalNet + $taxExcluded, 2);
        $paid = 0.0;

        foreach ($this->db->table('sales_payments')->where('sale_id', $saleId)->get()->getResult() as $payment) {
            $amount = round((float) $payment->payment_amount - (float) $payment->cash_refund, 2);
            $method = PaymentMethod::resolve((string) $payment->payment_type);
            $mapping = PaymentMethod::mappingKey($method);

            $line = $mapping === null
                ? ['account_id' => $this->defaultTreasuryAccount()]
                : ['account' => $mapping];

            if ($method === PaymentMethod::DUE) {
                $line += ['party_type' => 'customer', 'party_id' => $customerId];
            }

            $lines[] = $line + ['amount' => $amount, 'payment_method' => $method, 'description' => (string) $payment->payment_type];
            $paid += $amount;
        }

        // Whatever the payments leave open is owed by the customer; pennies of
        // rounding go to cash over/short instead of the customer's account.
        $residual = round($receivable - $paid, 2);

        if (abs($residual) >= 0.01) {
            if (abs($residual) <= 0.10) {
                $lines[] = ['account' => 'cash_over_short', 'amount' => $residual, 'description' => lang('Accounting.rounding')];
            } else {
                $lines[] = ['account' => 'accounts_receivable', 'amount' => $residual, 'party_type' => 'customer', 'party_id' => $customerId, 'description' => lang('Accounting.unpaid_balance')];
            }
        }

        return $lines;
    }

    private function saleDimensions(object $sale): array
    {
        $employee = $this->db->table('people')->select('first_name, last_name')->where('person_id', $sale->employee_id)->get()->getRow();

        return [
            'cashier'       => ['code' => 'emp-' . $sale->employee_id, 'name' => $employee ? trim($employee->first_name . ' ' . $employee->last_name) : 'emp-' . $sale->employee_id],
            'sales_channel' => (int) $sale->sale_type === SALE_TYPE_INVOICE ? 'invoice' : 'pos',
            'profit_center' => 'main_store',
        ];
    }

    // ------------------------------------------------------------- receivings

    public function postReceiving(int $receivingId, ?int $employeeId = null, bool $backfill = false): ?int
    {
        $receiving = $this->db->table('receivings')->where('receiving_id', $receivingId)->get()->getRow();

        if ($receiving === null) {
            return $this->reverseSource(self::SOURCE_RECEIVING, $receivingId, $employeeId);
        }

        if (!self::inScope($receiving->receiving_time, $backfill) && $this->ledger->liveEntry(self::SOURCE_RECEIVING, $receivingId) === null) {
            return null;
        }

        return $this->sync(self::SOURCE_RECEIVING, $receivingId, [
            'entry_date'  => $receiving->receiving_time,
            'description' => lang('Accounting.receiving_entry', ['RECV ' . $receivingId]),
            'reference'   => $receiving->reference ?: 'RECV ' . $receivingId,
            'created_by'  => $employeeId ?? (int) $receiving->employee_id,
        ], $this->receivingLines($receiving));
    }

    public function receivingLines(object $receiving): array
    {
        $supplierId = $receiving->supplier_id !== null ? (int) $receiving->supplier_id : null;
        $inventory = 0.0;
        $variance = 0.0;
        $payable = 0.0;

        foreach ($this->db->table('receivings_items')->where('receiving_id', $receiving->receiving_id)->get()->getResult() as $line) {
            $quantity = (float) $line->quantity_purchased;
            $packSize = (float) $line->receiving_quantity > 0 ? (float) $line->receiving_quantity : 1.0;
            $gross = $quantity * (float) $line->item_unit_price;
            $discount = (int) $line->discount_type === PERCENT ? $gross * (float) $line->discount / 100 : $quantity * (float) $line->discount;
            $net = round($gross - $discount, 2);

            if ($quantity < 0) {
                // Returned units leave inventory at the average cost they were carried at.
                $carried = round($quantity * $packSize * (float) $line->item_cost_price, 2);
                $inventory += $carried;
                $variance += $net - $carried;
            } else {
                $inventory += $net;
            }

            $payable += $net;
        }

        // The new moving average is stored at two decimals, which revalues the stock
        // on hand by the rounding. Booking that difference keeps the inventory
        // account equal to quantity × stored cost; it comes from the cost history,
        // so a repost of the same receiving gives the same amount.
        $rounding = 0.0;
        foreach ($this->db->table('item_cost_history')->where('source_type', self::SOURCE_RECEIVING)->where('source_id', $receiving->receiving_id)->get()->getResult() as $history) {
            $quantityAfter = (float) $history->qty_before + (float) $history->qty_change;
            $rounding += $quantityAfter * round((float) $history->cost_after, 2)
                - (float) $history->qty_before * (float) $history->cost_before
                - round((float) $history->qty_change * (float) $history->unit_cost_in, 2);
        }
        $rounding = round($rounding, 2);

        $method = PaymentMethod::resolve((string) $receiving->payment_type);
        $lines = [
            ['account' => 'inventory', 'amount' => round($inventory, 2)],
            ['account' => 'cost_variance', 'amount' => round($variance, 2), 'description' => lang('Accounting.return_cost_variance')],
            ['account' => 'inventory', 'amount' => $rounding, 'description' => lang('Accounting.cost_rounding')],
            ['account' => 'cost_variance', 'amount' => -$rounding, 'description' => lang('Accounting.cost_rounding')],
        ];
        $lines[] = $this->outflowLine($method, -round($payable, 2), 'supplier', $supplierId, null, (string) $receiving->payment_type);

        return $lines;
    }

    /**
     * Credit side of a purchase or expense: cash from the treasury, "due" to the
     * supplier's payable account, anything else from the bank.
     */
    private function outflowLine(string $method, float $amount, ?string $partyType, ?int $partyId, ?int $treasuryId, string $label): array
    {
        $line = match ($method) {
            PaymentMethod::CASH, PaymentMethod::CASH_DEPOSIT, PaymentMethod::CASH_ADJUSTMENT => ['account_id' => $this->treasuryAccount($treasuryId)],
            PaymentMethod::DUE => ['account' => 'accounts_payable', 'party_type' => 'supplier', 'party_id' => $partyId],
            default            => ['account' => 'bank'],
        };

        if ($method !== PaymentMethod::DUE && $partyType !== null && $partyId !== null) {
            $line += ['party_type' => $partyType, 'party_id' => $partyId];
        }

        return $line + ['amount' => $amount, 'payment_method' => $method === PaymentMethod::UNKNOWN ? 'other' : $method, 'description' => $label];
    }

    // --------------------------------------------------------------- expenses

    public function postExpense(int $expenseId, ?int $employeeId = null, bool $backfill = false): ?int
    {
        $expense = $this->db->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        if ($expense === null || (int) $expense->deleted === 1) {
            return $this->reverseSource(self::SOURCE_EXPENSE, $expenseId, $employeeId);
        }

        $date = $expense->date ?? date('Y-m-d H:i:s');

        if (!self::inScope($date, $backfill) && $this->ledger->liveEntry(self::SOURCE_EXPENSE, $expenseId) === null) {
            return null;
        }

        $category = $this->db->table('expense_categories')->where('expense_category_id', $expense->expense_category_id)->get()->getRow();
        $expenseAccount = $category !== null && !empty($category->account_id) ? ['account_id' => (int) $category->account_id] : ['account' => 'general_expense'];
        $amount = round((float) $expense->amount, 2);
        $method = PaymentMethod::resolve((string) $expense->payment_type);
        $supplierId = $expense->supplier_id !== null ? (int) $expense->supplier_id : null;
        $costCenter = !empty($expense->cost_center_id) ? (int) $expense->cost_center_id : 'main_store';

        return $this->sync(self::SOURCE_EXPENSE, $expenseId, [
            'entry_date'  => $date,
            'description' => trim(lang('Accounting.expense_entry', [$expenseId]) . ' ' . ($category->category_name ?? '') . ' ' . $expense->description),
            'reference'   => $expense->reference ?? '',
            'created_by'  => $employeeId ?? (int) $expense->employee_id,
        ], [
            $expenseAccount + ['amount' => $amount, 'description' => (string) $expense->description, 'dimensions' => ['cost_center' => $costCenter]],
            $this->outflowLine($method, -$amount, 'supplier', $supplierId, !empty($expense->treasury_id) ? (int) $expense->treasury_id : null, (string) $expense->payment_type),
        ]);
    }

    // ---------------------------------------------------------------- cash-up

    /**
     * Posts the counted-vs-expected difference of a closed shift.
     */
    public function postCashup(int $cashupId, ?int $employeeId = null): ?int
    {
        $cashup = $this->db->table('cash_up')->where('cashup_id', $cashupId)->get()->getRow();

        if ($cashup === null || (int) $cashup->deleted === 1 || !isset($cashup->difference_cash) || $cashup->difference_cash === null) {
            return $this->reverseSource(self::SOURCE_CASHUP, $cashupId, $employeeId);
        }

        if (!self::inScope((string) $cashup->open_date) && $this->ledger->liveEntry(self::SOURCE_CASHUP, $cashupId) === null) {
            return null;
        }

        $difference = round((float) $cashup->difference_cash, 2);
        $treasuryAccount = $this->treasuryAccount(!empty($cashup->treasury_id) ? (int) $cashup->treasury_id : null);
        $description = $difference > 0 ? lang('Accounting.cash_overage') : lang('Accounting.cash_shortage');

        return $this->sync(self::SOURCE_CASHUP, $cashupId, [
            'entry_date'  => $cashup->close_date ?? date('Y-m-d H:i:s'),
            'description' => lang('Accounting.cashup_entry', [$cashupId]) . ' - ' . $description,
            'reference'   => 'CASHUP ' . $cashupId,
            'created_by'  => $employeeId ?? (int) $cashup->close_employee_id,
        ], [
            ['account_id' => $treasuryAccount, 'amount' => $difference, 'description' => $description],
            ['account' => 'cash_over_short', 'amount' => -$difference, 'description' => $description],
        ]);
    }

    // -------------------------------------------------------------- inventory

    /**
     * Manual stock change valued at the item's average cost.
     *
     * $opening = true for stock brought into the system (a new item's starting
     * quantity, a CSV import): it was already owned, so it is opening balance
     * equity, not a gain. Later counts and corrections are inventory adjustments.
     */
    public function postInventoryAdjustment(int $inventoryTransId, int $itemId, float $quantityDelta, ?int $employeeId, string $comment = '', ?string $date = null, bool $opening = false): ?int
    {
        // A stock row never changes once written and was valued at the cost of its
        // day; reposting it later would revalue history at today's average cost.
        $live = $this->ledger->liveEntry(self::SOURCE_INVENTORY, $inventoryTransId);
        if ($live !== null) {
            return (int) $live->entry_id;
        }

        $item = $this->db->table('items')->select('cost_price, name, stock_type')->where('item_id', $itemId)->get()->getRow();

        if ($item === null || (int) $item->stock_type !== HAS_STOCK) {
            return null;
        }

        $value = round($quantityDelta * (float) $item->cost_price, 2);

        if ($value == 0.0) {
            return null;
        }

        return $this->sync(self::SOURCE_INVENTORY, $inventoryTransId, [
            'entry_date'  => $date ?? date('Y-m-d H:i:s'),
            'description' => lang($opening ? 'Accounting.opening_stock_item_entry' : 'Accounting.inventory_adjustment_entry', [$item->name]) . ($comment !== '' ? ' - ' . $comment : ''),
            'reference'   => 'INV ' . $inventoryTransId,
            'created_by'  => $employeeId,
        ], [
            ['account' => 'inventory', 'amount' => $value],
            ['account' => $opening ? 'opening_balance' : 'inventory_adjustment', 'amount' => -$value],
        ]);
    }

    /**
     * Revalues stock on hand after a manual cost change (amount = value change).
     */
    public function postCostRevaluation(int $historyId, int $itemId, float $amount, ?int $employeeId): ?int
    {
        if (round($amount, 2) == 0.0) {
            return null;
        }

        $item = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        return $this->ledger->post([
            'entry_date'  => date('Y-m-d H:i:s'),
            'description' => lang('Accounting.cost_revaluation_entry', [$item->name ?? $itemId]),
            'source_type' => self::SOURCE_COST,
            'source_id'   => $historyId,
            'reference'   => 'COST ' . $itemId,
            'created_by'  => $employeeId,
        ], [
            ['account' => 'inventory', 'amount' => round($amount, 2)],
            ['account' => 'cost_variance', 'amount' => -round($amount, 2)],
        ]);
    }

    // ----------------------------------------------------------------- shared

    public function reverseSource(string $sourceType, int $sourceId, ?int $employeeId = null): ?int
    {
        $live = $this->ledger->liveEntry($sourceType, $sourceId);

        return $live === null ? null : $this->ledger->reverse((int) $live->entry_id, null, '', $employeeId);
    }

    /**
     * Makes the document's live entry equal to $lines: no-op when unchanged,
     * reverse + repost when changed, plain post when there is none.
     */
    private function sync(string $sourceType, int $sourceId, array $header, array $lines): ?int
    {
        $live = $this->ledger->liveEntry($sourceType, $sourceId);
        $proposed = $this->ledger->proposedSignature($lines);

        if ($live !== null && $this->ledger->lineSignature((int) $live->entry_id) === $proposed) {
            return (int) $live->entry_id;
        }

        $this->db->transStart();

        if ($live !== null) {
            // A correction belongs to the document's own period: the reversal takes the old
            // entry's date and the repost the document's date, so each month shows the
            // corrected document instead of a swing between this month and the original one.
            // Once either period is closed, both halves go into the current period.
            $now = date('Y-m-d H:i:s');
            $reversalDate = (string) $live->entry_date;

            if (!$this->ledger->isPeriodOpen($reversalDate) || !$this->ledger->isPeriodOpen((string) ($header['entry_date'] ?? $now))) {
                $reversalDate = $now;
                $header['entry_date'] = $now;
            }

            $this->ledger->reverse((int) $live->entry_id, $reversalDate, '', $header['created_by'] ?? null);
        }

        $entryId = count($proposed) >= 2
            ? $this->ledger->post($header + ['source_type' => $sourceType, 'source_id' => $sourceId], $lines)
            : null;

        $this->db->transComplete();

        return $entryId;
    }
}
