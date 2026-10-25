<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Ledger;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * القيود اليومية: browse posted entries, post manual entries, reverse entries.
 * Entries created by documents (sales, receivings, ...) are reversed through
 * their document; only manual entries are reversed here.
 */
class Journal extends Ledger_Controller
{
    private const PAGE_SIZE = 50;

    public function __construct()
    {
        parent::__construct('accounting', 'accounting_view');
    }

    public function getIndex(): string
    {
        $db = Database::connect();
        [$from, $to] = $this->dateRange(date('Y-m-01'));
        $sourceType = (string) $this->request->getGet('source_type');
        $search = trim((string) $this->request->getGet('search'));
        $page = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('acc_journal_entries')
            ->select('acc_journal_entries.*, people.first_name, people.last_name')
            ->join('people', 'people.person_id = acc_journal_entries.created_by', 'left')
            ->where('entry_date >=', $from)
            ->where('entry_date <', date('Y-m-d', strtotime("$to +1 day")));

        if ($sourceType !== '') {
            $builder->where('source_type', $sourceType);
        }

        if ($search !== '') {
            $builder->groupStart()->like('entry_number', $search)->orLike('reference', $search)->orLike('acc_journal_entries.description', $search)->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $entries = $builder->orderBy('entry_date', 'DESC')->orderBy('entry_id', 'DESC')->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)->get()->getResultArray();

        return view('accounting/journal', [
            'entries'     => $entries,
            'from'        => $from,
            'to'          => $to,
            'source_type' => $sourceType,
            'search'      => $search,
            'page'        => $page,
            'pages'       => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'can_post'    => $this->can('accounting_journal'),
        ]);
    }

    public function getView(int $entryId = NEW_ENTRY): string
    {
        $db = Database::connect();
        $entry = $db->table('acc_journal_entries')
            ->select('acc_journal_entries.*, people.first_name, people.last_name')
            ->join('people', 'people.person_id = acc_journal_entries.created_by', 'left')
            ->where('entry_id', $entryId)->get()->getRowArray();

        if ($entry === null) {
            return view('accounting/journal_entry', ['entry' => null, 'lines' => [], 'can_reverse' => false]);
        }

        $lines = $db->table('acc_journal_lines')
            ->select('acc_journal_lines.*, acc_accounts.code, acc_accounts.name')
            ->join('acc_accounts', 'acc_accounts.account_id = acc_journal_lines.account_id')
            ->where('entry_id', $entryId)->orderBy('line_order')->get()->getResultArray();

        foreach ($lines as &$line) {
            $line['dimensions'] = $db->table('acc_journal_line_dimensions')
                ->select('acc_dimensions.name AS dimension, acc_dimension_values.name AS value')
                ->join('acc_dimensions', 'acc_dimensions.dimension_id = acc_journal_line_dimensions.dimension_id')
                ->join('acc_dimension_values', 'acc_dimension_values.value_id = acc_journal_line_dimensions.value_id')
                ->where('line_id', $line['line_id'])->get()->getResultArray();
        }
        unset($line);

        return view('accounting/journal_entry', [
            'entry'       => $entry,
            'lines'       => $lines,
            'can_reverse' => $this->can('accounting_journal') && $entry['source_type'] === 'manual' && $entry['reversed_by_entry_id'] === null && $entry['reverses_entry_id'] === null,
        ]);
    }

    public function getCreate(): string
    {
        $this->requireGrant('accounting_journal');
        $db = Database::connect();

        $accounts = [];
        foreach ($db->table('acc_accounts')->where('is_group', 0)->where('active', 1)->orderBy('code')->get()->getResult() as $account) {
            $accounts[] = ['id' => (int) $account->account_id, 'label' => $account->code . ' - ' . $account->name, 'party' => $account->party_type, 'type' => $account->account_type];
        }

        $costCenters = $db->table('acc_dimension_values')->select('acc_dimension_values.value_id, acc_dimension_values.name')
            ->join('acc_dimensions', 'acc_dimensions.dimension_id = acc_dimension_values.dimension_id')
            ->where('acc_dimensions.code', 'cost_center')->where('acc_dimension_values.active', 1)->get()->getResultArray();

        // Accounts kept per customer / supplier / partner take the person by name.
        $parties = [
            'customer'    => array_slice($this->peopleOptions('customers'), 1, null, true),
            'supplier'    => array_slice($this->peopleOptions('suppliers'), 1, null, true),
            'shareholder' => array_column($db->table('shareholders')->select('shareholder_id, name')->orderBy('name')->get()->getResultArray(), 'name', 'shareholder_id'),
        ];

        return view('accounting/journal_form', ['accounts' => $accounts, 'cost_centers' => $costCenters, 'parties' => $parties]);
    }

    public function postSave(int $entryId = NEW_ENTRY): ResponseInterface
    {
        if ($denied = $this->denied('accounting_journal')) {
            return $denied;
        }

        $accountIds = (array) $this->request->getPost('account_id');
        $debits = (array) $this->request->getPost('debit');
        $credits = (array) $this->request->getPost('credit');
        $memos = (array) $this->request->getPost('line_description');
        $partyIds = (array) $this->request->getPost('party_id');
        $costCenters = (array) $this->request->getPost('cost_center_id');

        $lines = [];
        foreach ($accountIds as $index => $accountId) {
            if ((int) $accountId <= 0) {
                continue;
            }

            $debit = parse_decimals((string) ($debits[$index] ?? ''));
            $credit = parse_decimals((string) ($credits[$index] ?? ''));
            $line = [
                'account_id'  => (int) $accountId,
                'debit'       => $debit === false || $debit === '' ? 0 : (float) $debit,
                'credit'      => $credit === false || $credit === '' ? 0 : (float) $credit,
                'description' => mb_substr(strip_tags((string) ($memos[$index] ?? '')), 0, 255),
            ];

            if (!empty($partyIds[$index])) {
                $line['party_id'] = (int) $partyIds[$index];
            }
            if (!empty($costCenters[$index])) {
                $line['dimensions'] = ['cost_center' => (int) $costCenters[$index]];
            }

            $lines[] = $line;
        }

        $header = [
            'entry_date'  => $this->postedDateTime('entry_date'),
            'description' => trim((string) $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'reference'   => trim((string) $this->request->getPost('reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'source_type' => 'manual',
            'created_by'  => $this->personId(),
        ];

        return $this->act(function () use ($header, $lines) {
            $ledger = new Ledger(Database::connect());

            foreach ($lines as $line) {
                $account = $ledger->account($line['account_id']);

                if ($account->party_type !== null && empty($line['party_id'])) {
                    throw new AccountingException(lang('Accounting.party_required_for_account', [$account->code]));
                }
            }

            $entryId = $ledger->post($header, $lines);
            $this->audit('journal_post', 'journal_entry', $entryId, null, ['header' => $header, 'lines' => $lines]);

            return $entryId;
        }, lang('Accounting.entry_posted'));
    }

    public function postReverse(int $entryId): ResponseInterface
    {
        if ($denied = $this->denied('accounting_journal')) {
            return $denied;
        }

        $reason = trim((string) $this->request->getPost('reason', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        return $this->act(function () use ($entryId, $reason) {
            $db = Database::connect();
            $entry = $db->table('acc_journal_entries')->where('entry_id', $entryId)->get()->getRow();

            // Document entries follow their document; reversing them here would desynchronise it.
            if ($entry === null || $entry->source_type !== 'manual') {
                throw new AccountingException(lang('Accounting.only_manual_reversible'));
            }

            $reversalId = (new Ledger($db))->reverse($entryId, null, $reason !== '' ? lang('Accounting.reversal_of', [$entry->entry_number]) . ' - ' . $reason : '', $this->personId());
            $this->audit('journal_reverse', 'journal_entry', $entryId, null, ['reversal_entry_id' => $reversalId], $reason);

            return $reversalId;
        }, lang('Accounting.entry_reversed'));
    }
}
