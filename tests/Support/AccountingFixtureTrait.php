<?php

namespace Tests\Support;

use App\Models\Sale;
use Config\Database;
use Config\OSPOS;

/**
 * Builders for ledger tests: stocked items, completed sales through the real
 * Sale::save_value() pipeline, and balance helpers over the journal.
 */
trait AccountingFixtureTrait
{
    private array $originalAccountingSettings = [];

    protected function enableAccounting(): void
    {
        $settings = &config(OSPOS::class)->settings;
        $this->originalAccountingSettings = [
            'accounting_enabled'                => $settings['accounting_enabled'] ?? null,
            'accounting_start_date'             => $settings['accounting_start_date'] ?? null,
            'receiving_calculate_average_price' => $settings['receiving_calculate_average_price'] ?? null,
        ];
        $settings['accounting_enabled'] = '1';
        $settings['accounting_start_date'] = '';
        $settings['receiving_calculate_average_price'] = '1';
    }

    protected function restoreAccounting(): void
    {
        $settings = &config(OSPOS::class)->settings;
        foreach ($this->originalAccountingSettings as $key => $value) {
            $settings[$key] = $value;
        }
    }

    protected function createStockedItem(float $cost, float $price, float $quantity, string $category = 'Ledger Test'): int
    {
        $db = Database::connect();
        $unique = uniqid();

        $db->table('items')->insert([
            'name'        => "Ledger Item $unique",
            'category'    => $category,
            'description' => '',
            'cost_price'  => $cost,
            'unit_price'  => $price,
            'item_number' => "LEDGER-$unique",
            'stock_type'  => HAS_STOCK,
            'deleted'     => 0,
        ]);
        $itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => 1, 'quantity' => $quantity]);
        $db->table('inventory')->insert([
            'trans_items' => $itemId, 'trans_user' => 1, 'trans_location' => 1, 'trans_comment' => 'fixture opening stock',
            'trans_inventory' => $quantity, 'trans_date' => date('Y-m-d H:i:s'),
        ]);

        return $itemId;
    }

    /**
     * @param list<array{item_id: int, quantity: float, price: float, cost: float, discount?: float, discount_type?: int}> $lines
     * @param list<array{type: string, amount: float, refund?: float}> $payments
     */
    protected function completeSale(array $lines, array $payments, ?int $customerId = null, int $employeeId = 1, int $saleType = SALE_TYPE_POS): int
    {
        $items = [];
        foreach (array_values($lines) as $index => $line) {
            $items[$index + 1] = [
                'item_id'       => $line['item_id'],
                'line'          => $index + 1,
                'description'   => '',
                'serialnumber'  => '',
                'quantity'      => $line['quantity'],
                'discount'      => $line['discount'] ?? 0,
                'discount_type' => $line['discount_type'] ?? PERCENT,
                'cost_price'    => $line['cost'],
                'price'         => $line['price'],
                'item_location' => 1,
                'print_option'  => PRINT_YES,
                'invoice_discount' => $line['invoice_discount'] ?? 0,
            ];
        }

        $paymentRows = [];
        foreach ($payments as $payment) {
            $paymentRows[] = [
                'payment_type'    => $payment['type'],
                'payment_amount'  => $payment['amount'],
                'cash_refund'     => $payment['refund'] ?? 0,
                'cash_adjustment' => 0,
            ];
        }

        $status = COMPLETED;
        $taxes = [[], []];

        return model(Sale::class)->save_value(NEW_ENTRY, $status, $items, $customerId ?? NEW_ENTRY, $employeeId, '', null, null, null, $saleType, $paymentRows, null, $taxes);
    }

    /**
     * Debit-minus-credit balance of an account (by mapping key), optionally for one party.
     */
    protected function mappedBalance(string $mappingKey, ?string $partyType = null, ?int $partyId = null): float
    {
        $db = Database::connect();
        $accountId = (int) $db->table('acc_account_mappings')->where('mapping_key', $mappingKey)->get()->getRow()->account_id;

        return $this->accountBalance($accountId, $partyType, $partyId);
    }

    protected function accountBalance(int $accountId, ?string $partyType = null, ?int $partyId = null): float
    {
        $builder = Database::connect()->table('acc_journal_lines')->select('SUM(debit) - SUM(credit) AS balance', false)->where('account_id', $accountId);

        if ($partyType !== null) {
            $builder->where('party_type', $partyType)->where('party_id', $partyId);
        }

        return round((float) ($builder->get()->getRow()->balance ?? 0), 2);
    }

    protected function cashAccountId(): int
    {
        return (int) Database::connect()->table('treasuries')->where('is_default', 1)->get()->getRow()->account_id;
    }

    protected function liveEntryFor(string $sourceType, int $sourceId): ?object
    {
        return Database::connect()->table('acc_journal_entries')
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('reverses_entry_id', null)->where('reversed_by_entry_id', null)
            ->get()->getRow();
    }

    /**
     * Every entry in the journal balances (invariant I1).
     */
    protected function assertJournalBalances(): void
    {
        $unbalanced = Database::connect()->query(
            'SELECT entry_id FROM ' . Database::connect()->prefixTable('acc_journal_lines') . ' GROUP BY entry_id HAVING ROUND(SUM(debit) - SUM(credit), 2) <> 0'
        )->getResultArray();

        $this->assertSame([], $unbalanced, 'Every journal entry must balance.');
    }

    /**
     * Treasury movements reconcile with the treasury account (invariant I2).
     */
    protected function assertTreasuryReconciles(): void
    {
        $db = Database::connect();
        foreach ($db->table('treasuries')->get()->getResult() as $treasury) {
            $movements = $db->table('treasury_movements')
                ->select("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net", false)
                ->where('treasury_id', $treasury->treasury_id)->get()->getRow();
            $last = $db->table('treasury_movements')->where('treasury_id', $treasury->treasury_id)->orderBy('movement_id', 'DESC')->limit(1)->get()->getRow();

            $this->assertEqualsWithDelta($this->accountBalance((int) $treasury->account_id), round((float) ($movements->net ?? 0), 2), 0.001, "Treasury $treasury->name must equal its GL account.");
            $this->assertEqualsWithDelta(round((float) ($movements->net ?? 0), 2), (float) ($last->balance_after ?? 0), 0.001, "Treasury $treasury->name running balance must equal the sum of its movements.");
        }
    }
}
