<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\FixedAssets;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * الأصول الثابتة: asset register, capitalised purchases and monthly depreciation.
 */
class Fixed_assets extends Ledger_Controller
{
    private FixedAssets $assets;

    public function __construct()
    {
        parent::__construct('accounting', 'accounting_assets');
        $this->assets = new FixedAssets(Database::connect());
    }

    public function getIndex(): string
    {
        $db = Database::connect();
        $suppliers = ['' => lang('Common.none_selected_text')];
        foreach ($db->table('suppliers')->select('person_id, company_name')->where('deleted', 0)->orderBy('company_name')->get()->getResult() as $supplier) {
            $suppliers[$supplier->person_id] = $supplier->company_name;
        }

        return view('accounting/fixed_assets', [
            'assets'     => $this->assets->register(),
            'treasuries' => array_column($db->table('treasuries')->where('active', 1)->get()->getResultArray(), 'name', 'treasury_id'),
            'suppliers'  => $suppliers,
        ]);
    }

    public function postSave(int $assetId = NEW_ENTRY): ResponseInterface
    {
        $data = [
            'code'               => trim((string) $this->request->getPost('code', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'name'               => trim((string) $this->request->getPost('name', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'category'           => trim((string) $this->request->getPost('category', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'purchase_date'      => $this->postedDateTime('purchase_date'),
            'purchase_cost'      => $this->postedAmount('purchase_cost'),
            'salvage_value'      => $this->postedAmount('salvage_value'),
            'useful_life_months' => (int) $this->request->getPost('useful_life_months'),
            'payment_source'     => (string) $this->request->getPost('payment_source'),
            'treasury_id'        => (int) $this->request->getPost('treasury_id'),
            'supplier_id'        => (int) $this->request->getPost('supplier_id') ?: null,
            'location'           => trim((string) $this->request->getPost('location', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'notes'              => trim((string) $this->request->getPost('notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
        ];

        return $this->act(function () use ($data) {
            $assetId = $this->assets->purchase($data, $this->personId());
            $this->audit('asset_purchase', 'fixed_asset', $assetId, null, $data);

            return $assetId;
        }, lang('Accounting.asset_saved'));
    }

    public function postDepreciate(): ResponseInterface
    {
        $year = (int) $this->request->getPost('year');
        $month = (int) $this->request->getPost('month');

        return $this->act(function () use ($year, $month) {
            $entryId = $this->assets->depreciate($year, $month, $this->personId());

            if ($entryId === null) {
                throw new AccountingException(lang('Accounting.nothing_to_depreciate'));
            }

            $this->audit('depreciation_run', 'fixed_asset', sprintf('%04d-%02d', $year, $month), null, ['entry_id' => $entryId]);

            return $entryId;
        }, lang('Accounting.depreciation_posted'));
    }
}
