<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Read-only accounting reports, all computed from journal lines.
 * Dates are inclusive 'Y-m-d'; balances are debit minus credit unless noted.
 */
class Reports
{
    private BaseConnection $db;
    private Ledger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->ledger = new Ledger($this->db);
    }

    private function lines(): string
    {
        return $this->db->prefixTable('acc_journal_lines');
    }

    private function entries(): string
    {
        return $this->db->prefixTable('acc_journal_entries');
    }

    private function accounts(): string
    {
        return $this->db->prefixTable('acc_accounts');
    }

    /**
     * Per postable account: opening balance before $from, period debit/credit, closing balance.
     *
     * @return list<array{account_id: int, code: string, name: string, name_en: string, account_type: string, opening: float, debit: float, credit: float, closing: float}>
     */
    public function trialBalance(string $from, string $to): array
    {
        $sql = "SELECT a.account_id, a.code, a.name, a.name_en, a.account_type,
                    COALESCE(SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit END), 0) AS opening,
                    COALESCE(SUM(CASE WHEN e.entry_date >= ? AND e.entry_date < ? THEN l.debit END), 0) AS debit,
                    COALESCE(SUM(CASE WHEN e.entry_date >= ? AND e.entry_date < ? THEN l.credit END), 0) AS credit
                FROM {$this->accounts()} a
                LEFT JOIN {$this->lines()} l ON l.account_id = a.account_id
                LEFT JOIN {$this->entries()} e ON e.entry_id = l.entry_id AND e.entry_date < ?
                WHERE a.is_group = 0
                GROUP BY a.account_id, a.code, a.name, a.name_en, a.account_type
                ORDER BY a.code";

        $end = $this->dayAfter($to);
        $rows = $this->db->query($sql, [$from, $from, $end, $from, $end, $end])->getResultArray();

        return array_map(static function (array $row): array {
            $row['account_id'] = (int) $row['account_id'];
            $row['opening'] = round((float) $row['opening'], 2);
            $row['debit'] = round((float) $row['debit'], 2);
            $row['credit'] = round((float) $row['credit'], 2);
            $row['closing'] = round($row['opening'] + $row['debit'] - $row['credit'], 2);

            return $row;
        }, $rows);
    }

    /**
     * Account ledger with running balance, optionally filtered by party and dimension value.
     *
     * @return array{opening: float, rows: list<array>, closing: float}
     */
    public function ledger(int $accountId, string $from, string $to, ?string $partyType = null, ?int $partyId = null, ?int $dimensionValueId = null, int $limit = 1000, int $offset = 0): array
    {
        $partySql = '';
        $params = [$accountId, $from];

        if ($partyType !== null) {
            $partySql = ' AND l.party_type = ? AND l.party_id = ?';
            $params[] = $partyType;
            $params[] = $partyId;
        }

        $dimensionJoin = '';
        if ($dimensionValueId !== null) {
            $dimensionJoin = ' JOIN ' . $this->db->prefixTable('acc_journal_line_dimensions') . ' d ON d.line_id = l.line_id AND d.value_id = ' . (int) $dimensionValueId;
        }

        $opening = (float) ($this->db->query(
            "SELECT SUM(l.debit - l.credit) AS balance FROM {$this->lines()} l JOIN {$this->entries()} e ON e.entry_id = l.entry_id $dimensionJoin
             WHERE l.account_id = ? AND e.entry_date < ? $partySql",
            $params
        )->getRow()->balance ?? 0);

        $rowParams = [$accountId, $from, $this->dayAfter($to)];
        if ($partyType !== null) {
            $rowParams[] = $partyType;
            $rowParams[] = $partyId;
        }

        $rows = $this->db->query(
            "SELECT e.entry_id, e.entry_number, e.entry_date, e.description AS entry_description, e.source_type, e.source_id, e.reference,
                    e.reverses_entry_id, l.line_id, l.debit, l.credit, l.description, l.party_type, l.party_id
             FROM {$this->lines()} l JOIN {$this->entries()} e ON e.entry_id = l.entry_id $dimensionJoin
             WHERE l.account_id = ? AND e.entry_date >= ? AND e.entry_date < ? $partySql
             ORDER BY e.entry_date, e.entry_id, l.line_id
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $rowParams
        )->getResultArray();

        $balance = round($opening, 2);

        // The running balance starts from everything before this page.
        if ($offset > 0 && $rows !== []) {
            $before = $this->db->query(
                "SELECT SUM(x.debit - x.credit) AS s FROM (SELECT l.debit, l.credit FROM {$this->lines()} l JOIN {$this->entries()} e ON e.entry_id = l.entry_id $dimensionJoin
                 WHERE l.account_id = ? AND e.entry_date >= ? AND e.entry_date < ? $partySql ORDER BY e.entry_date, e.entry_id, l.line_id LIMIT $offset) x",
                $rowParams
            )->getRow();
            $balance += round((float) ($before->s ?? 0), 2);
        }

        foreach ($rows as &$row) {
            $balance = round($balance + (float) $row['debit'] - (float) $row['credit'], 2);
            $row['balance'] = $balance;
        }
        unset($row);

        $closing = (float) ($this->db->query(
            "SELECT SUM(l.debit - l.credit) AS balance FROM {$this->lines()} l JOIN {$this->entries()} e ON e.entry_id = l.entry_id $dimensionJoin
             WHERE l.account_id = ? AND e.entry_date < ? $partySql",
            array_merge([$accountId, $this->dayAfter($to)], $partyType !== null ? [$partyType, $partyId] : [])
        )->getRow()->balance ?? 0);

        return ['opening' => round($opening, 2), 'rows' => $rows, 'closing' => round($closing, 2)];
    }

    /**
     * Party statement on its control account (customer -> receivable, supplier -> payable).
     */
    public function partyStatement(string $partyType, int $partyId, string $from, string $to): array
    {
        $accountId = $this->ledger->mapped($partyType === 'customer' ? 'accounts_receivable' : 'accounts_payable');

        return $this->ledger($accountId, $from, $to, $partyType, $partyId);
    }

    /**
     * Outstanding balance per party at a date (receivable: positive = owes us;
     * payable: positive = we owe), with simple aging of the balance by the age
     * of the unsettled debits (receivable) or credits (payable), oldest first settled.
     *
     * @return list<array{party_id: int, balance: float, current: float, d30: float, d60: float, d90: float, older: float}>
     */
    public function aging(string $partyType, string $asOf): array
    {
        $isCustomer = $partyType === 'customer';
        $accountId = $this->ledger->mapped($isCustomer ? 'accounts_receivable' : 'accounts_payable');
        $end = $this->dayAfter($asOf);

        $rows = $this->db->query(
            "SELECT l.party_id, e.entry_date, l.debit, l.credit FROM {$this->lines()} l JOIN {$this->entries()} e ON e.entry_id = l.entry_id
             WHERE l.account_id = ? AND l.party_type = ? AND l.party_id IS NOT NULL AND e.entry_date < ?
             ORDER BY l.party_id, e.entry_date, e.entry_id",
            [$accountId, $partyType, $end]
        )->getResultArray();

        $byParty = [];
        foreach ($rows as $row) {
            $byParty[(int) $row['party_id']][] = $row;
        }

        $result = [];
        foreach ($byParty as $partyId => $partyRows) {
            // Charges raise the balance (debits for customers, credits for suppliers); settlements reduce the oldest first.
            $open = [];
            $settled = 0.0;
            foreach ($partyRows as $row) {
                $charge = $isCustomer ? (float) $row['debit'] - (float) $row['credit'] : (float) $row['credit'] - (float) $row['debit'];
                if ($charge > 0) {
                    $open[] = ['date' => $row['entry_date'], 'amount' => $charge];
                } else {
                    $settled += -$charge;
                }
            }

            foreach ($open as &$charge) {
                $used = min($charge['amount'], $settled);
                $charge['amount'] -= $used;
                $settled -= $used;
            }
            unset($charge);

            $buckets = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'older' => 0.0];
            foreach ($open as $charge) {
                $days = (int) floor((strtotime($asOf . ' 23:59:59') - strtotime($charge['date'])) / 86400);
                $bucket = $days <= 30 ? 'current' : ($days <= 60 ? 'd30' : ($days <= 90 ? 'd60' : ($days <= 120 ? 'd90' : 'older')));
                $buckets[$bucket] += $charge['amount'];
            }

            $balance = round(array_sum($buckets) - $settled, 2);

            if (abs($balance) < 0.005) {
                continue;
            }

            $result[] = ['party_id' => $partyId, 'balance' => $balance] + array_map(static fn (float $v): float => round($v, 2), $buckets);
        }

        usort($result, static fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);

        return $result;
    }

    /**
     * Profit and loss for a period, grouped into the standard sections.
     *
     * @return array{sections: array<string, list<array>>, totals: array<string, float>}
     */
    public function incomeStatement(string $from, string $to, ?int $dimensionValueId = null): array
    {
        $special = [
            'other_income'         => $this->ledger->mapped('other_income'),
            'depreciation_expense' => $this->ledger->mapped('depreciation_expense'),
            'other_expense'        => $this->ledger->mapped('other_expense'),
        ];
        $costOfSalesGroup = $this->ledger->accountByCode('5000');

        $dimensionJoin = $dimensionValueId === null ? '' : ' JOIN ' . $this->db->prefixTable('acc_journal_line_dimensions') . ' d ON d.line_id = l.line_id AND d.value_id = ' . (int) $dimensionValueId;

        $rows = $this->db->query(
            "SELECT a.account_id, a.code, a.name, a.name_en, a.account_type, a.parent_id, SUM(l.credit - l.debit) AS amount
             FROM {$this->lines()} l
             JOIN {$this->entries()} e ON e.entry_id = l.entry_id
             JOIN {$this->accounts()} a ON a.account_id = l.account_id
             $dimensionJoin
             WHERE a.account_type IN ('revenue', 'expense') AND e.entry_date >= ? AND e.entry_date < ?
               AND (e.source_type IS NULL OR e.source_type <> '" . Periods::SOURCE_YEAR_CLOSE . "')
             GROUP BY a.account_id, a.code, a.name, a.name_en, a.account_type, a.parent_id
             ORDER BY a.code",
            [$from, $this->dayAfter($to)]
        )->getResultArray();

        $sections = ['revenue' => [], 'cost_of_sales' => [], 'operating_expenses' => [], 'depreciation' => [], 'other_income' => [], 'other_expenses' => []];

        foreach ($rows as $row) {
            $row['amount'] = round((float) $row['amount'], 2);
            $accountId = (int) $row['account_id'];

            if ($row['account_type'] === 'revenue') {
                $section = $accountId === $special['other_income'] ? 'other_income' : 'revenue';
            } elseif ($accountId === $special['depreciation_expense']) {
                $section = 'depreciation';
            } elseif ($accountId === $special['other_expense']) {
                $section = 'other_expenses';
            } elseif ($costOfSalesGroup !== null && $this->isDescendantOf($accountId, (int) $costOfSalesGroup->account_id)) {
                $section = 'cost_of_sales';
            } else {
                $section = 'operating_expenses';
            }

            // Expenses are shown as positive amounts.
            if ($row['account_type'] === 'expense') {
                $row['amount'] = -$row['amount'];
            }

            $sections[$section][] = $row;
        }

        $sum = static fn (array $list): float => round(array_sum(array_column($list, 'amount')), 2);

        $totals = [
            'revenue'            => $sum($sections['revenue']),
            'cost_of_sales'      => $sum($sections['cost_of_sales']),
            'operating_expenses' => $sum($sections['operating_expenses']),
            'depreciation'       => $sum($sections['depreciation']),
            'other_income'       => $sum($sections['other_income']),
            'other_expenses'     => $sum($sections['other_expenses']),
        ];
        $totals['gross_profit'] = round($totals['revenue'] - $totals['cost_of_sales'], 2);
        $totals['operating_profit'] = round($totals['gross_profit'] - $totals['operating_expenses'] - $totals['depreciation'], 2);
        $totals['net_profit'] = round($totals['operating_profit'] + $totals['other_income'] - $totals['other_expenses'], 2);

        return ['sections' => $sections, 'totals' => $totals];
    }

    /**
     * Balance sheet at a date. Profit not yet closed to retained earnings is shown
     * as "current period profit" inside equity. difference must be 0.
     */
    public function balanceSheet(string $asOf): array
    {
        $rows = $this->db->query(
            "SELECT a.account_id, a.code, a.name, a.name_en, a.account_type, SUM(l.debit - l.credit) AS balance
             FROM {$this->lines()} l
             JOIN {$this->entries()} e ON e.entry_id = l.entry_id
             JOIN {$this->accounts()} a ON a.account_id = l.account_id
             WHERE e.entry_date < ?
             GROUP BY a.account_id, a.code, a.name, a.name_en, a.account_type
             ORDER BY a.code",
            [$this->dayAfter($asOf)]
        )->getResultArray();

        $assets = $liabilities = $equity = [];
        $profit = 0.0;

        foreach ($rows as $row) {
            $balance = round((float) $row['balance'], 2);

            if (abs($balance) < 0.005) {
                continue;
            }

            switch ($row['account_type']) {
                case 'asset':
                    $assets[] = $row + ['amount' => $balance];
                    break;
                case 'liability':
                    $liabilities[] = $row + ['amount' => -$balance];
                    break;
                case 'equity':
                    $equity[] = $row + ['amount' => -$balance];
                    break;
                default:
                    $profit -= $balance;
            }
        }

        $totalAssets = round(array_sum(array_column($assets, 'amount')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'amount')), 2);
        $totalEquity = round(array_sum(array_column($equity, 'amount')) + $profit, 2);

        return [
            'assets'             => $assets,
            'liabilities'        => $liabilities,
            'equity'             => $equity,
            'current_profit'     => round($profit, 2),
            'total_assets'       => $totalAssets,
            'total_liabilities'  => $totalLiabilities,
            'total_equity'       => $totalEquity,
            'difference'         => round($totalAssets - $totalLiabilities - $totalEquity, 2),
        ];
    }

    /**
     * Revenue/expense lines grouped by the values of one dimension.
     *
     * @return list<array{value_id: ?int, value_name: ?string, revenue: float, expense: float, net: float}>
     */
    public function dimensionSummary(string $dimensionCode, string $from, string $to): array
    {
        $dimension = $this->db->table('acc_dimensions')->where('code', $dimensionCode)->get()->getRow();

        if ($dimension === null) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT v.value_id, v.name AS value_name,
                    SUM(CASE WHEN a.account_type = 'revenue' THEN l.credit - l.debit ELSE 0 END) AS revenue,
                    SUM(CASE WHEN a.account_type = 'expense' THEN l.debit - l.credit ELSE 0 END) AS expense
             FROM {$this->lines()} l
             JOIN {$this->entries()} e ON e.entry_id = l.entry_id
             JOIN {$this->accounts()} a ON a.account_id = l.account_id
             LEFT JOIN " . $this->db->prefixTable('acc_journal_line_dimensions') . " d ON d.line_id = l.line_id AND d.dimension_id = ?
             LEFT JOIN " . $this->db->prefixTable('acc_dimension_values') . " v ON v.value_id = d.value_id
             WHERE a.account_type IN ('revenue', 'expense') AND e.entry_date >= ? AND e.entry_date < ?
               AND (e.source_type IS NULL OR e.source_type <> '" . Periods::SOURCE_YEAR_CLOSE . "')
             GROUP BY v.value_id, v.name
             ORDER BY revenue DESC",
            [$dimension->dimension_id, $from, $this->dayAfter($to)]
        )->getResultArray();

        return array_map(static function (array $row): array {
            $row['revenue'] = round((float) $row['revenue'], 2);
            $row['expense'] = round((float) $row['expense'], 2);
            $row['net'] = round($row['revenue'] - $row['expense'], 2);

            return $row;
        }, $rows);
    }

    /**
     * Inventory at moving average cost versus the inventory account (invariant I6).
     *
     * @return array{stock_value: float, ledger_value: float, difference: float, items: list<array>}
     */
    public function inventoryValuation(int $limit = 500): array
    {
        $items = $this->db->query(
            'SELECT i.item_id, i.name, i.item_number, i.category, i.cost_price, SUM(q.quantity) AS quantity, SUM(q.quantity) * i.cost_price AS value
             FROM ' . $this->db->prefixTable('items') . ' i
             JOIN ' . $this->db->prefixTable('item_quantities') . ' q ON q.item_id = i.item_id
             JOIN ' . $this->db->prefixTable('stock_locations') . ' s ON s.location_id = q.location_id AND s.deleted = 0
             WHERE i.stock_type = ' . HAS_STOCK . ' AND i.deleted = 0
             GROUP BY i.item_id, i.name, i.item_number, i.category, i.cost_price
             HAVING SUM(q.quantity) <> 0
             ORDER BY value DESC
             LIMIT ' . max(1, $limit)
        )->getResultArray();

        $stockValue = (float) ($this->db->query(
            'SELECT SUM(x.value) AS total FROM (SELECT SUM(q.quantity) * i.cost_price AS value FROM ' . $this->db->prefixTable('items') . ' i
             JOIN ' . $this->db->prefixTable('item_quantities') . ' q ON q.item_id = i.item_id
             JOIN ' . $this->db->prefixTable('stock_locations') . ' s ON s.location_id = q.location_id AND s.deleted = 0
             WHERE i.stock_type = ' . HAS_STOCK . ' AND i.deleted = 0 GROUP BY i.item_id, i.cost_price) x'
        )->getRow()->total ?? 0);

        $ledgerValue = $this->accountBalance($this->ledger->mapped('inventory'), date('Y-m-d'));

        return [
            'stock_value'  => round($stockValue, 2),
            'ledger_value' => $ledgerValue,
            'difference'   => round($stockValue - $ledgerValue, 2),
            'items'        => $items,
        ];
    }

    /**
     * Payment type labels in use that no language file maps to a payment method.
     * Such payments post to a clearing or bank account and should be checked.
     *
     * @return list<string>
     */
    public function unmappedPaymentLabels(): array
    {
        $labels = array_merge(
            array_column($this->db->table('sales_payments')->distinct()->select('payment_type')->get()->getResultArray(), 'payment_type'),
            array_column($this->db->table('receivings')->distinct()->select('payment_type')->where('payment_type IS NOT NULL')->get()->getResultArray(), 'payment_type'),
            array_column($this->db->table('expenses')->distinct()->select('payment_type')->where('deleted', 0)->get()->getResultArray(), 'payment_type'),
        );

        $unmapped = array_filter(
            array_unique(array_map('strval', $labels)),
            static fn (string $label): bool => $label !== '' && PaymentMethod::resolve($label) === PaymentMethod::UNKNOWN
        );

        return array_values($unmapped);
    }

    public function accountBalance(int $accountId, string $asOf, ?string $partyType = null, ?int $partyId = null): float
    {
        $sql = "SELECT SUM(l.debit - l.credit) AS balance FROM {$this->lines()} l JOIN {$this->entries()} e ON e.entry_id = l.entry_id WHERE l.account_id = ? AND e.entry_date < ?";
        $params = [$accountId, $this->dayAfter($asOf)];

        if ($partyType !== null) {
            $sql .= ' AND l.party_type = ? AND l.party_id = ?';
            $params[] = $partyType;
            $params[] = $partyId;
        }

        return round((float) ($this->db->query($sql, $params)->getRow()->balance ?? 0), 2);
    }

    private function isDescendantOf(int $accountId, int $ancestorId): bool
    {
        $guard = 0;
        $current = $this->ledger->account($accountId);

        while ($current->parent_id !== null && $guard++ < 20) {
            if ((int) $current->parent_id === $ancestorId) {
                return true;
            }
            $current = $this->ledger->account((int) $current->parent_id);
        }

        return false;
    }

    private function dayAfter(string $date): string
    {
        return date('Y-m-d', strtotime(substr($date, 0, 10) . ' +1 day'));
    }
}
