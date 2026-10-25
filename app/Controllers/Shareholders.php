<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Shareholding;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * المساهمون / حقوق الملكية: partners, their capital, drawings and current
 * accounts, cash contributions/withdrawals and yearly profit allocation.
 */
class Shareholders extends Ledger_Controller
{
    private Shareholding $shareholding;

    public function __construct()
    {
        parent::__construct('accounting', 'accounting_shareholders');
        $this->shareholding = new Shareholding(Database::connect());
    }

    public function getIndex(): string
    {
        $rows = Database::connect()->table('shareholders')->orderBy('active', 'DESC')->orderBy('shareholder_id')->get()->getResultArray();

        foreach ($rows as &$row) {
            $row += $this->shareholding->position((int) $row['shareholder_id']);
        }
        unset($row);

        return view('accounting/shareholders', [
            'shareholders' => $rows,
            'treasuries'   => array_column(Database::connect()->table('treasuries')->where('active', 1)->get()->getResultArray(), 'name', 'treasury_id'),
        ]);
    }

    public function postSave(int $shareholderId = NEW_ENTRY): ResponseInterface
    {
        $data = [
            'name'                 => trim((string) $this->request->getPost('name', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'ownership_percent'    => $this->postedAmount('ownership_percent'),
            'profit_share_percent' => $this->postedAmount('profit_share_percent'),
            'phone'                => trim((string) $this->request->getPost('phone', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'notes'                => trim((string) $this->request->getPost('notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'inactive'             => (bool) $this->request->getPost('inactive'),
        ];

        return $this->act(function () use ($data, $shareholderId) {
            $old = $shareholderId === NEW_ENTRY ? null : (array) $this->shareholding->get($shareholderId);
            $id = $this->shareholding->save($data, $shareholderId);
            $this->audit($shareholderId === NEW_ENTRY ? 'shareholder_create' : 'shareholder_update', 'shareholder', $id, $old, $data);

            return $id;
        }, lang('Accounting.shareholder_saved'));
    }

    public function postTransaction(int $shareholderId): ResponseInterface
    {
        $type = (string) $this->request->getPost('transaction_type');
        $amount = $this->postedAmount('amount');
        $treasuryId = (int) $this->request->getPost('treasury_id');
        $date = $this->postedDateTime('transaction_date');
        $reference = trim((string) $this->request->getPost('reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $notes = trim((string) $this->request->getPost('notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        return $this->act(function () use ($shareholderId, $type, $amount, $treasuryId, $date, $reference, $notes) {
            $id = $this->shareholding->recordCash($shareholderId, $type, $amount, $treasuryId, $date, $reference, $notes, $this->personId());
            $this->audit('shareholder_' . $type, 'shareholder', $shareholderId, null, compact('type', 'amount', 'treasuryId', 'date', 'reference', 'notes'));

            return $id;
        }, lang('Accounting.transaction_saved'));
    }

    public function getStatement(int $shareholderId): string
    {
        [$from, $to] = $this->dateRange(date('Y-01-01'));

        try {
            $shareholder = $this->shareholding->get($shareholderId);
        } catch (AccountingException $e) {
            throw PageNotFoundException::forPageNotFound($e->getMessage());
        }

        return view('accounting/shareholder_statement', [
            'shareholder' => $shareholder,
            'position'    => $this->shareholding->position($shareholderId, $to),
            'report'      => $this->shareholding->statement($shareholderId, $from, $to),
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    public function getAllocation(): string
    {
        $year = (int) ($this->request->getGet('year') ?: date('Y') - 1);

        return view('accounting/allocation', ['preview' => $this->shareholding->allocationPreview($year)]);
    }

    public function postAllocate(): ResponseInterface
    {
        $year = (int) $this->request->getPost('year');

        return $this->act(function () use ($year) {
            $entryId = $this->shareholding->allocate($year, $this->personId());

            if ($entryId === null) {
                throw new AccountingException(lang('Accounting.nothing_to_allocate'));
            }

            $this->audit('profit_allocation', 'shareholder', (string) $year, null, ['entry_id' => $entryId]);

            return $entryId;
        }, lang('Accounting.allocation_posted'));
    }
}
