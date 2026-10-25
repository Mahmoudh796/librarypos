<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Monthly accounting periods (calendar fiscal year). A closed month refuses new
 * postings; reopening is an explicit, audited action. Year-end closing moves the
 * year's revenue and expense balances into retained earnings with one entry,
 * which the income statement ignores.
 */
class Periods
{
    public const SOURCE_YEAR_CLOSE = 'year_close';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Twelve months of a year with their status and posting counts.
     *
     * @return list<array{year: int, month: int, status: string, closed_at: ?string, entries: int}>
     */
    public function year(int $year): array
    {
        $stored = [];
        foreach ($this->db->table('acc_periods')->where('period_year', $year)->get()->getResult() as $row) {
            $stored[(int) $row->period_month] = $row;
        }

        $counts = [];
        $rows = $this->db->query(
            'SELECT MONTH(entry_date) AS m, COUNT(*) AS c FROM ' . $this->db->prefixTable('acc_journal_entries') . ' WHERE YEAR(entry_date) = ? GROUP BY MONTH(entry_date)',
            [$year]
        )->getResult();
        foreach ($rows as $row) {
            $counts[(int) $row->m] = (int) $row->c;
        }

        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[] = [
                'year'      => $year,
                'month'     => $month,
                'status'    => isset($stored[$month]) ? $stored[$month]->status : 'open',
                'closed_at' => isset($stored[$month]) ? $stored[$month]->closed_at : null,
                'entries'   => $counts[$month] ?? 0,
                'ended'     => self::hasEnded($year, $month),
            ];
        }

        return $months;
    }

    /**
     * Whether the month is over: its last day is before today.
     */
    public static function hasEnded(int $year, int $month): bool
    {
        return mktime(0, 0, 0, $month + 1, 1, $year) <= strtotime('today');
    }

    public function setStatus(int $year, int $month, string $status, int $employeeId): void
    {
        if ($month < 1 || $month > 12 || !in_array($status, ['open', 'closed'], true)) {
            throw new AccountingException(lang('Accounting.invalid_period'));
        }

        // Closing locks a finished month after review; a month still running (or in
        // the future) would block the sales and purchases that are still to come.
        if ($status === 'closed' && !self::hasEnded($year, $month)) {
            throw new AccountingException(lang('Accounting.period_not_ended', [sprintf('%04d-%02d', $year, $month)]));
        }

        $existing = $this->db->table('acc_periods')->where('period_year', $year)->where('period_month', $month)->get()->getRow();
        $data = [
            'status'    => $status,
            'closed_by' => $status === 'closed' ? $employeeId : null,
            'closed_at' => $status === 'closed' ? date('Y-m-d H:i:s') : null,
        ];

        if ($existing === null) {
            $this->db->table('acc_periods')->insert($data + ['period_year' => $year, 'period_month' => $month]);
        } else {
            $this->db->table('acc_periods')->where('period_id', $existing->period_id)->update($data);
        }
    }

    /**
     * Posts (or re-posts) the year-end closing entry. Returns its id, or null when
     * there was nothing to close.
     */
    public function closeYear(int $year, int $employeeId): ?int
    {
        if (!self::hasEnded($year, 12)) {
            throw new AccountingException(lang('Accounting.year_not_ended', [$year]));
        }

        $ledger = new Ledger($this->db);

        $balances = $this->db->query(
            'SELECT l.account_id, SUM(l.debit - l.credit) AS balance
             FROM ' . $this->db->prefixTable('acc_journal_lines') . ' l
             JOIN ' . $this->db->prefixTable('acc_journal_entries') . ' e ON e.entry_id = l.entry_id
             JOIN ' . $this->db->prefixTable('acc_accounts') . " a ON a.account_id = l.account_id
             WHERE a.account_type IN ('revenue', 'expense') AND YEAR(e.entry_date) = ?
               AND (e.source_type IS NULL OR e.source_type <> ?)
             GROUP BY l.account_id",
            [$year, self::SOURCE_YEAR_CLOSE]
        )->getResult();

        $lines = [];
        $net = 0.0;
        foreach ($balances as $row) {
            $balance = round((float) $row->balance, 2);

            if ($balance != 0.0) {
                $lines[] = ['account_id' => (int) $row->account_id, 'amount' => -$balance];
                $net += $balance;
            }
        }

        $this->db->transStart();

        $live = $ledger->liveEntry(self::SOURCE_YEAR_CLOSE, $year);
        if ($live !== null) {
            $ledger->reverse((int) $live->entry_id, "$year-12-31 23:59:59", '', $employeeId);
        }

        $entryId = null;
        if ($lines !== []) {
            $lines[] = ['account' => 'retained_earnings', 'amount' => round($net, 2)];
            $entryId = $ledger->post([
                'entry_date'  => "$year-12-31 23:59:59",
                'description' => lang('Accounting.year_close_entry', [$year]),
                'source_type' => self::SOURCE_YEAR_CLOSE,
                'source_id'   => $year,
                'reference'   => 'CLOSE-' . $year,
                'created_by'  => $employeeId,
            ], $lines);
        }

        $this->db->transComplete();

        return $entryId;
    }
}
