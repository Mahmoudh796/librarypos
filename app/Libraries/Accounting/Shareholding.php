<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Partners and their equity. Each shareholder owns three accounts created with
 * them: capital (under 3100), drawings (under 3200) and current account (under 3300).
 *
 *   contribution / additional_contribution  treasury -> capital
 *   withdrawal                              drawings -> treasury
 *   profit_allocation (per year)            retained earnings -> current account, by profit share %
 *   loss_allocation                         current account -> retained earnings
 *
 * Ownership % and profit-share % are stored separately; allocation uses profit share.
 */
class Shareholding
{
    public const SOURCE = 'shareholder';
    public const CONTRIBUTION_TYPES = ['contribution', 'additional_contribution', 'withdrawal'];

    private BaseConnection $db;
    private Ledger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->ledger = new Ledger($this->db);
    }

    /**
     * Creates or updates a shareholder; new shareholders get their equity accounts.
     */
    public function save(array $data, int $shareholderId = NEW_ENTRY): int
    {
        $name = trim((string) $data['name']);

        if ($name === '') {
            throw new AccountingException(lang('Accounting.shareholder_name_required'));
        }

        $row = [
            'name'                 => mb_substr($name, 0, 150),
            'ownership_percent'    => round((float) ($data['ownership_percent'] ?? 0), 4),
            'profit_share_percent' => round((float) ($data['profit_share_percent'] ?? 0), 4),
            'phone'                => mb_substr((string) ($data['phone'] ?? ''), 0, 40),
            'notes'                => (string) ($data['notes'] ?? ''),
            'active'               => empty($data['inactive']) ? 1 : 0,
        ];

        foreach (['ownership_percent', 'profit_share_percent'] as $field) {
            if ($row[$field] < 0 || $row[$field] > 100) {
                throw new AccountingException(lang('Accounting.percent_out_of_range'));
            }
        }

        $this->db->transStart();

        if ($shareholderId === NEW_ENTRY) {
            $next = (int) ($this->db->table('shareholders')->selectMax('shareholder_id')->get()->getRow()->shareholder_id ?? 0) + 1;
            $code = sprintf('SH%02d', $next);

            $row['code'] = $code;
            $row['capital_account_id'] = $this->createAccount('capital_parent', $code, lang('Accounting.capital_of', [$name]), 'Capital - ' . $name);
            $row['drawings_account_id'] = $this->createAccount('drawings_parent', $code, lang('Accounting.drawings_of', [$name]), 'Drawings - ' . $name);
            $row['current_account_id'] = $this->createAccount('partner_current_parent', $code, lang('Accounting.current_account_of', [$name]), 'Current account - ' . $name);
            $row['created_at'] = date('Y-m-d H:i:s');

            $this->db->table('shareholders')->insert($row);
            $shareholderId = (int) $this->db->insertID();
        } else {
            $existing = $this->get($shareholderId);
            $this->db->table('shareholders')->where('shareholder_id', $shareholderId)->update($row);

            if ($existing->name !== $row['name']) {
                $this->db->table('acc_accounts')->where('account_id', $existing->capital_account_id)->update(['name' => lang('Accounting.capital_of', [$name])]);
                $this->db->table('acc_accounts')->where('account_id', $existing->drawings_account_id)->update(['name' => lang('Accounting.drawings_of', [$name])]);
                $this->db->table('acc_accounts')->where('account_id', $existing->current_account_id)->update(['name' => lang('Accounting.current_account_of', [$name])]);
            }
        }

        $this->db->transComplete();

        return $shareholderId;
    }

    public function get(int $shareholderId): object
    {
        $row = $this->db->table('shareholders')->where('shareholder_id', $shareholderId)->get()->getRow();

        if ($row === null) {
            throw new AccountingException(lang('Accounting.shareholder_not_found'));
        }

        return $row;
    }

    /**
     * Cash contribution or withdrawal through a treasury. Returns the transaction id.
     */
    public function recordCash(int $shareholderId, string $type, float $amount, int $treasuryId, string $date, string $reference, string $notes, int $employeeId): int
    {
        if (!in_array($type, self::CONTRIBUTION_TYPES, true)) {
            throw new AccountingException(lang('Accounting.invalid_transaction_type'));
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new AccountingException(lang('Treasury.amount_must_be_positive'));
        }

        $shareholder = $this->get($shareholderId);
        $treasury = $this->db->table('treasuries')->where('treasury_id', $treasuryId)->where('active', 1)->get()->getRow();

        if ($treasury === null) {
            throw new AccountingException(lang('Treasury.treasury_required'));
        }

        $isWithdrawal = $type === 'withdrawal';
        $equityAccount = $isWithdrawal ? (int) $shareholder->drawings_account_id : (int) $shareholder->capital_account_id;
        $description = lang('Accounting.sh_type_' . $type) . ' - ' . $shareholder->name . ($notes !== '' ? ' - ' . $notes : '');

        $this->db->transStart();
        $transactionId = $this->insertTransaction($shareholderId, $type, $amount, $treasuryId, $date, null, $reference, $notes, $employeeId);

        $this->ledger->post([
            'entry_date'  => $date,
            'description' => $description,
            'source_type' => self::SOURCE,
            'source_id'   => $transactionId,
            'reference'   => $reference !== '' ? $reference : 'SH-' . $transactionId,
            'created_by'  => $employeeId,
        ], [
            ['account_id' => (int) $treasury->account_id, 'amount' => $isWithdrawal ? -$amount : $amount, 'party_type' => 'shareholder', 'party_id' => $shareholderId, 'description' => $description],
            ['account_id' => $equityAccount, 'amount' => $isWithdrawal ? $amount : -$amount, 'party_type' => 'shareholder', 'party_id' => $shareholderId, 'description' => $description],
        ]);
        $this->db->transComplete();

        return $transactionId;
    }

    /**
     * Preview of a year's allocation: net profit of the fiscal (calendar) year
     * split by profit share %. Allocation needs the active shares to total 100%.
     *
     * @return array{year: int, net_profit: float, total_share: float, already_allocated: bool, rows: list<array{shareholder_id: int, name: string, share: float, amount: float}>}
     */
    public function allocationPreview(int $year): array
    {
        $netProfit = (new Reports($this->db))->incomeStatement("$year-01-01", "$year-12-31")['totals']['net_profit'];
        $shareholders = $this->db->table('shareholders')->where('active', 1)->orderBy('shareholder_id')->get()->getResult();
        $totalShare = round(array_sum(array_map(static fn ($s) => (float) $s->profit_share_percent, $shareholders)), 4);

        $rows = [];
        foreach ($shareholders as $shareholder) {
            $share = (float) $shareholder->profit_share_percent;
            $rows[] = [
                'shareholder_id' => (int) $shareholder->shareholder_id,
                'name'           => $shareholder->name,
                'share'          => $share,
                'amount'         => round($netProfit * $share / 100, 2),
            ];
        }

        $alreadyAllocated = $this->db->table('shareholder_transactions')
            ->whereIn('transaction_type', ['profit_allocation', 'loss_allocation'])
            ->where('period_year', $year)
            ->where('status', 'posted')
            ->countAllResults() > 0;

        return ['year' => $year, 'net_profit' => $netProfit, 'total_share' => $totalShare, 'already_allocated' => $alreadyAllocated, 'rows' => $rows];
    }

    /**
     * Posts the year's allocation as one entry. Refused when the year was
     * already allocated or the active profit shares do not total exactly 100%:
     * shares are never rescaled behind the owners' back.
     */
    public function allocate(int $year, int $employeeId): ?int
    {
        $preview = $this->allocationPreview($year);

        if ($preview['already_allocated']) {
            throw new AccountingException(lang('Accounting.year_already_allocated', [$year]));
        }

        if (abs($preview['total_share'] - 100) > 0.0001) {
            throw new AccountingException(lang('Accounting.profit_shares_must_total_100', [$preview['total_share']]));
        }

        $lines = [];
        $total = 0.0;
        $isLoss = $preview['net_profit'] < 0;
        $type = $isLoss ? 'loss_allocation' : 'profit_allocation';

        $this->db->transStart();

        $transactionIds = [];
        foreach ($preview['rows'] as $row) {
            if ($row['amount'] == 0.0) {
                continue;
            }

            $shareholder = $this->get($row['shareholder_id']);
            $transactionIds[] = $this->insertTransaction($row['shareholder_id'], $type, abs($row['amount']), null, "$year-12-31 23:59:59", $year, '', '', $employeeId);
            $lines[] = ['account_id' => (int) $shareholder->current_account_id, 'amount' => -$row['amount'], 'party_type' => 'shareholder', 'party_id' => $row['shareholder_id'], 'description' => $shareholder->name];
            $total += $row['amount'];
        }

        if ($lines === []) {
            $this->db->transComplete();

            return null;
        }

        $lines[] = ['account' => 'retained_earnings', 'amount' => round($total, 2), 'description' => lang('Accounting.profit_allocation_year', [$year])];

        $entryId = $this->ledger->post([
            'entry_date'  => "$year-12-31 23:59:59",
            'description' => lang('Accounting.profit_allocation_year', [$year]),
            'source_type' => self::SOURCE,
            'source_id'   => $transactionIds[0],
            'reference'   => 'ALLOC-' . $year,
            'created_by'  => $employeeId,
        ], $lines);

        $this->db->transComplete();

        return $entryId;
    }

    /**
     * Equity position of a shareholder: capital, drawings, current account and total.
     */
    public function position(int $shareholderId, ?string $asOf = null): array
    {
        $shareholder = $this->get($shareholderId);
        $reports = new Reports($this->db);
        $asOf ??= date('Y-m-d');

        // Equity accounts carry credit balances; shown positive.
        $capital = -$reports->accountBalance((int) $shareholder->capital_account_id, $asOf);
        $drawings = $reports->accountBalance((int) $shareholder->drawings_account_id, $asOf);
        $current = -$reports->accountBalance((int) $shareholder->current_account_id, $asOf);

        return [
            'capital'  => round($capital, 2),
            'drawings' => round($drawings, 2),
            'current'  => round($current, 2),
            'equity'   => round($capital - $drawings + $current, 2),
        ];
    }

    /**
     * Statement over all three accounts of a shareholder, oldest first, with running equity balance.
     *
     * @return list<array>
     */
    public function statement(int $shareholderId, string $from, string $to): array
    {
        $shareholder = $this->get($shareholderId);
        $accounts = [(int) $shareholder->capital_account_id, (int) $shareholder->drawings_account_id, (int) $shareholder->current_account_id];

        $rows = $this->db->table('acc_journal_lines')
            ->select('acc_journal_entries.entry_id, entry_number, entry_date, acc_journal_entries.description, reference, acc_journal_lines.account_id, debit, credit')
            ->join('acc_journal_entries', 'acc_journal_entries.entry_id = acc_journal_lines.entry_id')
            ->whereIn('acc_journal_lines.account_id', $accounts)
            ->where('entry_date >=', $from)
            ->where('entry_date <', date('Y-m-d', strtotime($to . ' +1 day')))
            ->orderBy('entry_date')->orderBy('acc_journal_entries.entry_id')
            ->get()->getResultArray();

        $opening = $this->position($shareholderId, date('Y-m-d', strtotime($from . ' -1 day')))['equity'];
        $balance = $opening;

        foreach ($rows as &$row) {
            // Credits raise a partner's equity.
            $balance = round($balance + (float) $row['credit'] - (float) $row['debit'], 2);
            $row['balance'] = $balance;
            $row['account_kind'] = match ((int) $row['account_id']) {
                (int) $shareholder->capital_account_id  => 'capital',
                (int) $shareholder->drawings_account_id => 'drawings',
                default                                  => 'current',
            };
        }
        unset($row);

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $balance];
    }

    private function insertTransaction(int $shareholderId, string $type, float $amount, ?int $treasuryId, string $date, ?int $year, string $reference, string $notes, int $employeeId): int
    {
        $this->db->table('shareholder_transactions')->insert([
            'shareholder_id'   => $shareholderId,
            'transaction_type' => $type,
            'transaction_date' => $date,
            'amount'           => $amount,
            'treasury_id'      => $treasuryId,
            'period_year'      => $year,
            'reference'        => mb_substr($reference, 0, 64),
            'notes'            => mb_substr($notes, 0, 255),
            'status'           => 'posted',
            'employee_id'      => $employeeId,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function createAccount(string $parentMapping, string $code, string $name, string $nameEn): int
    {
        $parent = $this->ledger->account($this->ledger->mapped($parentMapping));
        $siblings = $this->db->table('acc_accounts')->where('parent_id', $parent->account_id)->countAllResults();
        $accountCode = $parent->code . '-' . str_pad((string) ($siblings + 1), 2, '0', STR_PAD_LEFT);

        while ($this->ledger->accountByCode($accountCode) !== null) {
            $siblings++;
            $accountCode = $parent->code . '-' . str_pad((string) ($siblings + 1), 2, '0', STR_PAD_LEFT);
        }

        $this->db->table('acc_accounts')->insert([
            'code'         => $accountCode,
            'name'         => mb_substr($name, 0, 150),
            'name_en'      => mb_substr($nameEn, 0, 150),
            'account_type' => 'equity',
            'parent_id'    => $parent->account_id,
            'is_group'     => 0,
            'is_system'    => 1,
            'is_treasury'  => 0,
            'party_type'   => null,
            'active'       => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }
}
