<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Fixed asset register. A purchase is capitalised (Dr fixed assets) rather than
 * expensed; depreciation is straight line, monthly, starting the month after the
 * purchase and never taking net book value below the salvage value.
 */
class FixedAssets
{
    public const SOURCE_PURCHASE = 'asset_purchase';
    public const SOURCE_DEPRECIATION = 'depreciation';

    private BaseConnection $db;
    private Ledger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->ledger = new Ledger($this->db);
    }

    /**
     * Registers and capitalises an asset. Returns the asset id.
     */
    public function purchase(array $data, int $employeeId): int
    {
        $cost = round((float) $data['purchase_cost'], 2);
        $salvage = round((float) ($data['salvage_value'] ?? 0), 2);
        $life = (int) $data['useful_life_months'];
        $name = trim((string) $data['name']);
        $source = $data['payment_source'] === 'payable' ? 'payable' : 'treasury';

        if ($name === '' || $cost <= 0 || $life <= 0 || $salvage < 0 || $salvage >= $cost) {
            throw new AccountingException(lang('Accounting.asset_invalid'));
        }

        $supplierId = !empty($data['supplier_id']) ? (int) $data['supplier_id'] : null;

        if ($source === 'payable' && $supplierId === null) {
            throw new AccountingException(lang('Treasury.supplier_required'));
        }

        $treasuryId = $source === 'treasury' ? (int) $data['treasury_id'] : null;
        $treasury = $treasuryId ? $this->db->table('treasuries')->where('treasury_id', $treasuryId)->where('active', 1)->get()->getRow() : null;

        if ($source === 'treasury' && $treasury === null) {
            throw new AccountingException(lang('Treasury.treasury_required'));
        }

        $this->db->transStart();

        $next = (int) ($this->db->table('fixed_assets')->selectMax('asset_id')->get()->getRow()->asset_id ?? 0) + 1;
        $code = trim((string) ($data['code'] ?? '')) !== '' ? trim((string) $data['code']) : sprintf('FA-%04d', $next);

        $this->db->table('fixed_assets')->insert([
            'code'                => mb_substr($code, 0, 30),
            'name'                => mb_substr($name, 0, 150),
            'category'            => mb_substr((string) ($data['category'] ?? ''), 0, 100),
            'purchase_date'       => substr((string) $data['purchase_date'], 0, 10),
            'purchase_cost'       => $cost,
            'salvage_value'       => $salvage,
            'useful_life_months'  => $life,
            'depreciation_method' => 'straight_line',
            'supplier_id'         => $supplierId,
            'payment_source'      => $source,
            'treasury_id'         => $treasuryId,
            'location'            => mb_substr((string) ($data['location'] ?? ''), 0, 100),
            'status'              => 'active',
            'notes'               => (string) ($data['notes'] ?? ''),
            'employee_id'         => $employeeId,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
        $assetId = (int) $this->db->insertID();

        $description = lang('Accounting.asset_purchase_entry', [$name]);
        $creditLine = $source === 'treasury'
            ? ['account_id' => (int) $treasury->account_id]
            : ['account' => 'accounts_payable', 'party_type' => 'supplier', 'party_id' => $supplierId];

        $this->ledger->post([
            'entry_date'  => substr((string) $data['purchase_date'], 0, 10) . ' ' . date('H:i:s'),
            'description' => $description,
            'source_type' => self::SOURCE_PURCHASE,
            'source_id'   => $assetId,
            'reference'   => $code,
            'created_by'  => $employeeId,
        ], [
            ['account' => 'fixed_assets', 'amount' => $cost, 'description' => $name],
            $creditLine + ['amount' => -$cost, 'description' => $description],
        ]);

        $this->db->transComplete();

        return $assetId;
    }

    public static function monthlyAmount(float $cost, float $salvage, int $lifeMonths): float
    {
        return $lifeMonths > 0 ? round(($cost - $salvage) / $lifeMonths, 2) : 0.0;
    }

    public function accumulated(int $assetId): float
    {
        return round((float) ($this->db->table('fixed_asset_depreciation')->selectSum('amount')->where('asset_id', $assetId)->get()->getRow()->amount ?? 0), 2);
    }

    /**
     * Depreciation due for one asset in a month (0 when not yet started, fully
     * depreciated, disposed, or already run for that month).
     */
    public function dueFor(object $asset, int $year, int $month): float
    {
        if ($asset->status !== 'active') {
            return 0.0;
        }

        $firstMonth = (int) date('Y', strtotime($asset->purchase_date)) * 12 + (int) date('n', strtotime($asset->purchase_date)) + 1;

        if ($year * 12 + $month < $firstMonth) {
            return 0.0;
        }

        $already = $this->db->table('fixed_asset_depreciation')->where('asset_id', $asset->asset_id)->where('period_year', $year)->where('period_month', $month)->countAllResults();

        if ($already > 0) {
            return 0.0;
        }

        $depreciable = round((float) $asset->purchase_cost - (float) $asset->salvage_value, 2);
        $remaining = round($depreciable - $this->accumulated((int) $asset->asset_id), 2);

        return max(0.0, min(self::monthlyAmount((float) $asset->purchase_cost, (float) $asset->salvage_value, (int) $asset->useful_life_months), $remaining));
    }

    /**
     * Runs depreciation for every active asset for one month as a single entry.
     * Returns the entry id, or null when nothing was due.
     */
    public function depreciate(int $year, int $month, int $employeeId): ?int
    {
        $assets = $this->db->table('fixed_assets')->where('status', 'active')->get()->getResult();
        $due = [];

        foreach ($assets as $asset) {
            $amount = $this->dueFor($asset, $year, $month);

            if ($amount > 0) {
                $due[] = [$asset, $amount];
            }
        }

        if ($due === []) {
            return null;
        }

        $date = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));
        $total = round(array_sum(array_column($due, 1)), 2);
        $lines = [];

        foreach ($due as [$asset, $amount]) {
            $lines[] = ['account' => 'depreciation_expense', 'amount' => $amount, 'description' => $asset->code . ' ' . $asset->name, 'dimensions' => ['cost_center' => 'main_store']];
        }
        $lines[] = ['account' => 'accumulated_depreciation', 'amount' => -$total];

        $this->db->transStart();

        $entryId = $this->ledger->post([
            'entry_date'  => $date,
            'description' => lang('Accounting.depreciation_entry', [sprintf('%04d-%02d', $year, $month)]),
            'source_type' => self::SOURCE_DEPRECIATION,
            'source_id'   => $year * 100 + $month,
            'reference'   => sprintf('DEP-%04d-%02d', $year, $month),
            'created_by'  => $employeeId,
        ], $lines);

        foreach ($due as [$asset, $amount]) {
            $this->db->table('fixed_asset_depreciation')->insert([
                'asset_id'     => $asset->asset_id,
                'period_year'  => $year,
                'period_month' => $month,
                'amount'       => $amount,
                'entry_id'     => $entryId,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $this->db->transComplete();

        return $entryId;
    }

    /**
     * @return list<object> assets with accumulated depreciation and net book value
     */
    public function register(): array
    {
        $assets = $this->db->table('fixed_assets')->orderBy('purchase_date', 'DESC')->get()->getResult();

        foreach ($assets as $asset) {
            $asset->accumulated = $this->accumulated((int) $asset->asset_id);
            $asset->net_book_value = round((float) $asset->purchase_cost - $asset->accumulated, 2);
            $asset->monthly = self::monthlyAmount((float) $asset->purchase_cost, (float) $asset->salvage_value, (int) $asset->useful_life_months);
        }

        return $assets;
    }
}
