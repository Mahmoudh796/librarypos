<?php

namespace App\Controllers;

use App\Libraries\Accounting\Ledger;
use App\Libraries\Accounting\Reports;
use App\Libraries\AuditTrail;
use Config\Database;

/**
 * Accounting reports: trial balance, income statement, balance sheet, general
 * ledger, customer/supplier statements and aging, dimensional analysis,
 * inventory valuation, cost history and the audit trail.
 */
class Accounting extends Ledger_Controller
{
    private Reports $reports;

    public function __construct()
    {
        parent::__construct('accounting', 'accounting_view');
        $this->reports = new Reports(Database::connect());
    }

    public function getIndex(): string
    {
        [$from, $to] = $this->dateRange(date('Y-m-01'));
        $pl = $this->reports->incomeStatement($from, $to);
        $sheet = $this->reports->balanceSheet($to);
        $ledger = new Ledger(Database::connect());

        return view('accounting/index', [
            'from'        => $from,
            'to'          => $to,
            'pl'          => $pl['totals'],
            'sheet'       => $sheet,
            'receivables' => $this->reports->accountBalance($ledger->mapped('accounts_receivable'), $to),
            // "+ 0.0" turns a negated zero (-0.0) back into 0.0 so it never prints as "-0".
            'payables'    => -$this->reports->accountBalance($ledger->mapped('accounts_payable'), $to) + 0.0,
            'inventory'   => $this->reports->accountBalance($ledger->mapped('inventory'), $to),
        ]);
    }

    public function getTrialBalance(): string
    {
        [$from, $to] = $this->dateRange(date('Y-01-01'));
        $rows = array_values(array_filter($this->reports->trialBalance($from, $to), static fn (array $r): bool => $r['opening'] != 0.0 || $r['debit'] != 0.0 || $r['credit'] != 0.0));

        return view('accounting/trial_balance', ['rows' => $rows, 'from' => $from, 'to' => $to]);
    }

    public function getIncomeStatement(): string
    {
        [$from, $to] = $this->dateRange(date('Y-01-01'));
        $valueId = (int) $this->request->getGet('dimension_value') ?: null;

        return view('accounting/income_statement', [
            'report'          => $this->reports->incomeStatement($from, $to, $valueId),
            'from'            => $from,
            'to'              => $to,
            'dimension_value' => $valueId,
            'centers'         => $this->dimensionValueOptions(['profit_center', 'cost_center', 'sales_channel']),
        ]);
    }

    public function getBalanceSheet(): string
    {
        $asOf = $this->asOfDate();

        return view('accounting/balance_sheet', ['report' => $this->reports->balanceSheet($asOf), 'as_of' => $asOf]);
    }

    public function getLedger(): string
    {
        [$from, $to] = $this->dateRange(date('Y-m-01'));
        $accountId = (int) $this->request->getGet('account_id');
        $valueId = (int) $this->request->getGet('dimension_value') ?: null;
        $page = max(1, (int) $this->request->getGet('page'));
        $pageSize = 200;

        $report = null;
        if ($accountId > 0) {
            $report = $this->reports->ledger($accountId, $from, $to, null, null, $valueId, $pageSize + 1, ($page - 1) * $pageSize);
        }

        $hasMore = $report !== null && count($report['rows']) > $pageSize;
        if ($hasMore) {
            array_pop($report['rows']);
        }

        return view('accounting/ledger', [
            'accounts'        => $this->accountOptions(),
            'account_id'      => $accountId,
            'report'          => $report,
            'from'            => $from,
            'to'              => $to,
            'dimension_value' => $valueId,
            'dimension_values' => $this->dimensionValueOptions(),
            'page'            => $page,
            'pages'           => $hasMore ? $page + 1 : $page,
        ]);
    }

    public function getStatement(): string
    {
        [$from, $to] = $this->dateRange(date('Y-01-01'));
        $partyType = $this->request->getGet('party_type') === 'supplier' ? 'supplier' : 'customer';
        $partyId = (int) $this->request->getGet('party_id');

        return view('accounting/statement', [
            'party_type' => $partyType,
            'party_id'   => $partyId,
            'parties'    => $this->partyOptions($partyType),
            'report'     => $partyId > 0 ? $this->reports->partyStatement($partyType, $partyId, $from, $to) : null,
            'from'       => $from,
            'to'         => $to,
            'can_vouchers' => $this->employee->has_grant('treasury_vouchers', $this->personId()),
        ]);
    }

    public function getAging(): string
    {
        $partyType = $this->request->getGet('party_type') === 'supplier' ? 'supplier' : 'customer';
        $asOf = $this->asOfDate();

        return view('accounting/aging', [
            'party_type' => $partyType,
            'rows'       => $this->reports->aging($partyType, $asOf),
            'names'      => $this->partyOptions($partyType),
            'as_of'      => $asOf,
        ]);
    }

    public function getDimensions(): string
    {
        [$from, $to] = $this->dateRange(date('Y-m-01'));
        $db = Database::connect();
        $dimensions = array_column($db->table('acc_dimensions')->where('active', 1)->orderBy('dimension_id')->get()->getResultArray(), 'name', 'code');
        $code = array_key_exists((string) $this->request->getGet('dimension'), $dimensions) ? (string) $this->request->getGet('dimension') : (string) array_key_first($dimensions);

        return view('accounting/dimensions', [
            'dimensions' => $dimensions,
            'dimension'  => $code,
            'rows'       => $this->reports->dimensionSummary($code, $from, $to),
            'from'       => $from,
            'to'         => $to,
        ]);
    }

    public function getValuation(): string
    {
        return view('accounting/valuation', ['report' => $this->reports->inventoryValuation()]);
    }

    public function getCostHistory(): string
    {
        $db = Database::connect();
        $itemId = (int) $this->request->getGet('item_id');

        $builder = $db->table('item_cost_history')
            ->select('item_cost_history.*, items.name, items.item_number, people.first_name, people.last_name')
            ->join('items', 'items.item_id = item_cost_history.item_id')
            ->join('people', 'people.person_id = item_cost_history.employee_id', 'left');

        if ($itemId > 0) {
            $builder->where('item_cost_history.item_id', $itemId);
        }

        return view('accounting/cost_history', [
            'rows'    => $builder->orderBy('history_id', 'DESC')->limit(300)->get()->getResultArray(),
            'item_id' => $itemId,
        ]);
    }

    public function getAudit(): string
    {
        $this->requireGrant('accounting_setup');
        [$from, $to] = $this->dateRange(date('Y-m-d', strtotime('-30 days')));

        return view('accounting/audit', [
            'rows'        => (new AuditTrail(Database::connect()))->search(['from' => $from, 'to' => $to, 'entity_type' => (string) $this->request->getGet('entity_type')], 500),
            'from'        => $from,
            'to'          => $to,
            'entity_type' => (string) $this->request->getGet('entity_type'),
        ]);
    }

    private function accountOptions(): array
    {
        $options = ['' => lang('Common.none_selected_text')];
        foreach (Database::connect()->table('acc_accounts')->where('is_group', 0)->orderBy('code')->get()->getResult() as $account) {
            $options[$account->account_id] = $account->code . ' - ' . $account->name;
        }

        return $options;
    }

    /**
     * @param string[]|null $dimensionCodes
     */
    private function dimensionValueOptions(?array $dimensionCodes = null): array
    {
        $builder = Database::connect()->table('acc_dimension_values')
            ->select('acc_dimension_values.value_id, acc_dimension_values.name, acc_dimensions.name AS dimension_name')
            ->join('acc_dimensions', 'acc_dimensions.dimension_id = acc_dimension_values.dimension_id')
            ->where('acc_dimension_values.active', 1)
            ->orderBy('acc_dimensions.dimension_id')->orderBy('acc_dimension_values.name');

        if ($dimensionCodes !== null) {
            $builder->whereIn('acc_dimensions.code', $dimensionCodes);
        }

        $options = ['' => lang('Accounting.all_dimensions')];
        foreach ($builder->get()->getResult() as $value) {
            $options[$value->value_id] = $value->dimension_name . ': ' . $value->name;
        }

        return $options;
    }

    private function partyOptions(string $partyType): array
    {
        $table = $partyType === 'supplier' ? 'suppliers' : 'customers';
        $builder = Database::connect()->table($table)
            ->select("$table.person_id, people.first_name, people.last_name")
            ->join('people', "people.person_id = $table.person_id")
            ->orderBy('people.first_name');

        if ($table === 'suppliers') {
            $builder->select('suppliers.company_name');
        }

        $options = ['' => lang('Common.none_selected_text')];
        foreach ($builder->get()->getResult() as $person) {
            $options[$person->person_id] = trim(($person->company_name ?? '') !== '' ? $person->company_name : $person->first_name . ' ' . $person->last_name);
        }

        return $options;
    }
}
