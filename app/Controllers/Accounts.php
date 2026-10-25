<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Periods;
use App\Libraries\Accounting\Reports;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * دليل الحسابات and accounting setup: chart of accounts, posting mappings,
 * analytical dimensions and accounting periods.
 */
class Accounts extends Ledger_Controller
{
    private const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    public function __construct()
    {
        parent::__construct('accounting', 'accounting_view');
    }

    public function getIndex(): string
    {
        $db = Database::connect();
        $accounts = $db->table('acc_accounts')->orderBy('code')->get()->getResultArray();
        $balances = [];
        foreach ((new Reports($db))->trialBalance('1900-01-01', date('Y-m-d')) as $row) {
            $balances[$row['account_id']] = $row['closing'];
        }

        // Group accounts show the sum of their descendants.
        $children = [];
        foreach ($accounts as $account) {
            $children[(int) $account['parent_id']][] = (int) $account['account_id'];
        }
        $total = static function (int $id) use (&$total, $children, $balances): float {
            $sum = $balances[$id] ?? 0.0;
            foreach ($children[$id] ?? [] as $child) {
                $sum += $total($child);
            }

            return round($sum, 2);
        };

        $depth = [];
        foreach ($accounts as &$account) {
            $id = (int) $account['account_id'];
            $parent = (int) $account['parent_id'];
            $depth[$id] = $parent > 0 ? ($depth[$parent] ?? 0) + 1 : 0;
            $account['depth'] = $depth[$id];
            $account['balance'] = $total($id);
        }
        unset($account);

        return view('accounting/accounts', [
            'accounts'  => $accounts,
            'groups'    => array_column(array_filter($accounts, static fn ($a) => (int) $a['is_group'] === 1), 'name', 'account_id'),
            'can_setup' => $this->can('accounting_setup'),
        ]);
    }

    public function postSave(int $accountId = NEW_ENTRY): ResponseInterface
    {
        if ($denied = $this->denied('accounting_setup')) {
            return $denied;
        }

        $db = Database::connect();
        $data = [
            'code'         => trim((string) $this->request->getPost('code')),
            'name'         => trim((string) $this->request->getPost('name', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'name_en'      => trim((string) $this->request->getPost('name_en', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'account_type' => (string) $this->request->getPost('account_type'),
            'parent_id'    => (int) $this->request->getPost('parent_id') ?: null,
            'is_group'     => $this->request->getPost('is_group') ? 1 : 0,
            'active'       => $this->request->getPost('inactive') ? 0 : 1,
        ];

        return $this->act(function () use ($db, $data, $accountId) {
            if (!preg_match('/^[0-9A-Za-z\-]{2,20}$/', $data['code']) || $data['name'] === '' || !in_array($data['account_type'], self::TYPES, true)) {
                throw new AccountingException(lang('Accounting.account_invalid'));
            }

            $duplicate = $db->table('acc_accounts')->where('code', $data['code'])->where('account_id !=', $accountId)->countAllResults();
            if ($duplicate > 0) {
                throw new AccountingException(lang('Accounting.account_code_exists'));
            }

            if ($accountId === NEW_ENTRY) {
                $db->table('acc_accounts')->insert($data + ['is_system' => 0, 'is_treasury' => 0, 'created_at' => date('Y-m-d H:i:s')]);
                $accountId = (int) $db->insertID();
                $this->audit('account_create', 'account', $accountId, null, $data);

                return $accountId;
            }

            $existing = $db->table('acc_accounts')->where('account_id', $accountId)->get()->getRowArray();
            if ($existing === null) {
                throw new AccountingException(lang('Accounting.account_invalid'));
            }

            $hasLines = $db->table('acc_journal_lines')->where('account_id', $accountId)->countAllResults() > 0;

            // The engine relies on system accounts and posted history relies on the type and grouping.
            if ((int) $existing['is_system'] === 1 || $hasLines) {
                $data['account_type'] = $existing['account_type'];
                $data['is_group'] = $existing['is_group'];
                $data['code'] = (int) $existing['is_system'] === 1 ? $existing['code'] : $data['code'];
            }

            $db->table('acc_accounts')->where('account_id', $accountId)->update($data);
            $this->audit('account_update', 'account', $accountId, $existing, $data);

            return $accountId;
        }, lang('Accounting.account_saved'));
    }

    public function getMappings(): string
    {
        $this->requireGrant('accounting_setup');
        $db = Database::connect();

        return view('accounting/mappings', [
            'mappings' => array_column($db->table('acc_account_mappings')->get()->getResultArray(), 'account_id', 'mapping_key'),
            'accounts' => array_column(array_map(static fn ($a) => ['id' => $a['account_id'], 'label' => $a['code'] . ' - ' . $a['name']], $db->table('acc_accounts')->orderBy('code')->get()->getResultArray()), 'label', 'id'),
            'expense_categories' => $db->table('expense_categories')->where('deleted', 0)->orderBy('category_name')->get()->getResultArray(),
            'expense_accounts'   => ['' => lang('Accounting.default_general_expense')] + array_column(array_map(static fn ($a) => ['id' => $a['account_id'], 'label' => $a['code'] . ' - ' . $a['name']], $db->table('acc_accounts')->where('account_type', 'expense')->where('is_group', 0)->orderBy('code')->get()->getResultArray()), 'label', 'id'),
        ]);
    }

    public function postMappings(): ResponseInterface
    {
        if ($denied = $this->denied('accounting_setup')) {
            return $denied;
        }

        $db = Database::connect();
        $mappings = (array) $this->request->getPost('mapping');
        $categoryAccounts = (array) $this->request->getPost('category_account');

        return $this->act(function () use ($db, $mappings, $categoryAccounts) {
            $existing = array_column($db->table('acc_account_mappings')->get()->getResultArray(), 'account_id', 'mapping_key');

            $db->transStart();
            foreach ($mappings as $key => $accountId) {
                if (isset($existing[$key]) && (int) $accountId > 0 && (int) $existing[$key] !== (int) $accountId) {
                    $db->table('acc_account_mappings')->where('mapping_key', $key)->update(['account_id' => (int) $accountId]);
                }
            }
            foreach ($categoryAccounts as $categoryId => $accountId) {
                $db->table('expense_categories')->where('expense_category_id', (int) $categoryId)->update(['account_id' => (int) $accountId ?: null]);
            }
            $db->transComplete();

            $this->audit('mappings_update', 'account', 'mappings', $existing, $mappings + ['expense_categories' => $categoryAccounts]);

            return null;
        }, lang('Accounting.mappings_saved'));
    }

    public function getDimensionValues(): string
    {
        $this->requireGrant('accounting_setup');
        $db = Database::connect();

        return view('accounting/dimension_values', [
            'dimensions' => $db->table('acc_dimensions')->orderBy('dimension_id')->get()->getResultArray(),
            'values'     => $db->table('acc_dimension_values')->orderBy('dimension_id')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function postDimensionValue(): ResponseInterface
    {
        if ($denied = $this->denied('accounting_setup')) {
            return $denied;
        }

        $db = Database::connect();
        $dimensionId = (int) $this->request->getPost('dimension_id');
        $name = trim((string) $this->request->getPost('name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        return $this->act(function () use ($db, $dimensionId, $name) {
            if ($name === '' || $db->table('acc_dimensions')->where('dimension_id', $dimensionId)->countAllResults() === 0) {
                throw new AccountingException(lang('Accounting.dimension_value_invalid'));
            }

            $code = 'v-' . substr(md5($name . microtime()), 0, 10);
            $db->table('acc_dimension_values')->insert(['dimension_id' => $dimensionId, 'code' => $code, 'name' => mb_substr($name, 0, 150), 'active' => 1]);
            $valueId = (int) $db->insertID();
            $this->audit('dimension_value_create', 'dimension_value', $valueId, null, ['dimension_id' => $dimensionId, 'name' => $name]);

            return $valueId;
        }, lang('Accounting.dimension_value_saved'));
    }

    public function getPeriods(): string
    {
        $this->requireGrant('accounting_periods');
        $year = (int) ($this->request->getGet('year') ?: date('Y'));

        return view('accounting/periods', [
            'year'    => $year,
            'months'  => (new Periods(Database::connect()))->year($year),
            'closed_year_entry' => Database::connect()->table('acc_journal_entries')->where('source_type', Periods::SOURCE_YEAR_CLOSE)->where('source_id', $year)->where('reversed_by_entry_id', null)->where('reverses_entry_id', null)->get()->getRow(),
        ]);
    }

    public function postPeriodStatus(): ResponseInterface
    {
        if ($denied = $this->denied('accounting_periods')) {
            return $denied;
        }

        $year = (int) $this->request->getPost('year');
        $month = (int) $this->request->getPost('month');
        $status = (string) $this->request->getPost('status');

        return $this->act(function () use ($year, $month, $status) {
            (new Periods(Database::connect()))->setStatus($year, $month, $status, $this->personId());
            $this->audit('period_' . $status, 'period', sprintf('%04d-%02d', $year, $month), null, ['status' => $status]);

            return null;
        }, lang('Accounting.period_saved'));
    }

    public function postCloseYear(): ResponseInterface
    {
        if ($denied = $this->denied('accounting_periods')) {
            return $denied;
        }

        $year = (int) $this->request->getPost('year');

        return $this->act(function () use ($year) {
            $entryId = (new Periods(Database::connect()))->closeYear($year, $this->personId());
            $this->audit('year_close', 'period', (string) $year, null, ['entry_id' => $entryId]);

            return $entryId;
        }, lang('Accounting.year_closed'));
    }
}
