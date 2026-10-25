<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The only writer of journal entries.
 *
 * - An entry is posted only when it balances to the cent and every line targets
 *   an active, postable account in an open period.
 * - Lines on treasury accounts write the matching treasury movement in the same
 *   transaction, so a treasury balance is always its account's GL balance.
 * - Entries are never edited: reverse() posts the mirror image and links both.
 */
class Ledger
{
    private BaseConnection $db;

    /** @var array<string, int> */
    private array $mappingCache = [];

    /** @var array<int, object> */
    private array $accountCache = [];

    /** @var array<string, int> */
    private array $dimensionCache = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Account id for an engine mapping key such as 'sales_revenue'.
     */
    public function mapped(string $key): int
    {
        if (!isset($this->mappingCache[$key])) {
            $row = $this->db->table('acc_account_mappings')->select('account_id')->where('mapping_key', $key)->get()->getRow();

            if ($row === null) {
                throw new AccountingException("No account is mapped to '$key'.");
            }

            $this->mappingCache[$key] = (int) $row->account_id;
        }

        return $this->mappingCache[$key];
    }

    public function account(int $accountId): object
    {
        if (!isset($this->accountCache[$accountId])) {
            $row = $this->db->table('acc_accounts')->where('account_id', $accountId)->get()->getRow();

            if ($row === null) {
                throw new AccountingException("Account $accountId does not exist.");
            }

            $this->accountCache[$accountId] = $row;
        }

        return $this->accountCache[$accountId];
    }

    public function accountByCode(string $code): ?object
    {
        return $this->db->table('acc_accounts')->where('code', $code)->get()->getRow();
    }

    public function isPeriodOpen(string $date): bool
    {
        $timestamp = strtotime($date);
        $row = $this->db->table('acc_periods')
            ->where('period_year', (int) date('Y', $timestamp))
            ->where('period_month', (int) date('n', $timestamp))
            ->get()
            ->getRow();

        return $row === null || $row->status !== 'closed';
    }

    /**
     * Posts a balanced entry and returns its id.
     *
     * $header: entry_date, description, source_type, source_id, reference, created_by
     * $lines:  each ['account_id' => int] or ['account' => mapping key], plus either
     *          'amount' (positive = debit, negative = credit) or 'debit'/'credit';
     *          optional description, party_type, party_id, payment_method,
     *          dimensions => [dimension code => value code | ['code' => ..., 'name' => ...]]
     *
     * Zero-amount lines are dropped. Throws AccountingException instead of
     * writing anything that would break an invariant.
     */
    public function post(array $header, array $lines): int
    {
        $entryDate = $header['entry_date'] ?? date('Y-m-d H:i:s');

        if (!$this->isPeriodOpen($entryDate)) {
            throw new AccountingException(lang('Accounting.period_closed_error', [date('Y-m', strtotime($entryDate))]));
        }

        $normalized = $this->normalizeLines($lines);

        if (count($normalized) < 2) {
            throw new AccountingException('A journal entry needs at least two non-zero lines.');
        }

        $debitCents = array_sum(array_column($normalized, 'debit_cents'));
        $creditCents = array_sum(array_column($normalized, 'credit_cents'));

        if ($debitCents !== $creditCents) {
            throw new AccountingException(sprintf('Unbalanced entry: debit %.2f, credit %.2f.', $debitCents / 100, $creditCents / 100));
        }

        $this->db->transStart();

        $this->db->table('acc_journal_entries')->insert([
            'entry_number'      => 'TMP-' . bin2hex(random_bytes(8)),
            'entry_date'        => $entryDate,
            'description'       => mb_substr((string) ($header['description'] ?? ''), 0, 255),
            'source_type'       => $header['source_type'] ?? 'manual',
            'source_id'         => $header['source_id'] ?? null,
            'reference'         => mb_substr((string) ($header['reference'] ?? ''), 0, 64),
            'reverses_entry_id' => $header['reverses_entry_id'] ?? null,
            'total'             => $debitCents / 100,
            'created_by'        => $header['created_by'] ?? null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $entryId = (int) $this->db->insertID();
        $this->db->table('acc_journal_entries')->where('entry_id', $entryId)->update(['entry_number' => sprintf('JV-%06d', $entryId)]);

        foreach ($normalized as $order => $line) {
            $this->db->table('acc_journal_lines')->insert([
                'entry_id'    => $entryId,
                'line_order'  => $order,
                'account_id'  => $line['account_id'],
                'debit'       => $line['debit_cents'] / 100,
                'credit'      => $line['credit_cents'] / 100,
                'description' => mb_substr((string) $line['description'], 0, 255),
                'party_type'  => $line['party_type'],
                'party_id'    => $line['party_id'],
            ]);
            $lineId = (int) $this->db->insertID();

            foreach ($line['dimensions'] as $dimensionId => $valueId) {
                $this->db->table('acc_journal_line_dimensions')->insert(['line_id' => $lineId, 'dimension_id' => $dimensionId, 'value_id' => $valueId]);
            }

            if ((int) $this->account($line['account_id'])->is_treasury === 1) {
                $this->writeTreasuryMovement($entryId, $entryDate, $header, $line);
            }
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new AccountingException('The journal entry could not be written.');
        }

        return $entryId;
    }

    /**
     * Posts the mirror image of an entry and links the two. Returns the new entry id.
     */
    public function reverse(int $entryId, ?string $date = null, string $description = '', ?int $employeeId = null): int
    {
        $entry = $this->db->table('acc_journal_entries')->where('entry_id', $entryId)->get()->getRow();

        if ($entry === null) {
            throw new AccountingException("Entry $entryId does not exist.");
        }

        if ($entry->reversed_by_entry_id !== null) {
            throw new AccountingException("Entry $entry->entry_number is already reversed.");
        }

        $lines = [];
        foreach ($this->db->table('acc_journal_lines')->where('entry_id', $entryId)->orderBy('line_order')->get()->getResult() as $line) {
            $dimensions = [];
            foreach ($this->db->table('acc_journal_line_dimensions')->where('line_id', $line->line_id)->get()->getResult() as $dimension) {
                $dimensions[(int) $dimension->dimension_id] = (int) $dimension->value_id;
            }

            $lines[] = [
                'account_id'  => (int) $line->account_id,
                'debit'       => (float) $line->credit,
                'credit'      => (float) $line->debit,
                'description' => $line->description,
                'party_type'  => $line->party_type,
                'party_id'    => $line->party_id,
                'dimensions'  => $dimensions,
            ];
        }

        $this->db->transStart();

        $reversalId = $this->post([
            'entry_date'        => $date ?? date('Y-m-d H:i:s'),
            'description'       => $description !== '' ? $description : lang('Accounting.reversal_of', [$entry->entry_number]),
            'source_type'       => $entry->source_type,
            'source_id'         => $entry->source_id,
            'reference'         => $entry->entry_number,
            'reverses_entry_id' => $entryId,
            'created_by'        => $employeeId,
        ], $lines);

        $this->db->table('acc_journal_entries')->where('entry_id', $entryId)->update(['reversed_by_entry_id' => $reversalId]);

        $this->db->transComplete();

        return $reversalId;
    }

    /**
     * The entry currently representing a source document (posted, not reversed, not itself a reversal).
     */
    public function liveEntry(string $sourceType, int $sourceId): ?object
    {
        return $this->db->table('acc_journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('reverses_entry_id', null)
            ->where('reversed_by_entry_id', null)
            ->orderBy('entry_id', 'DESC')
            ->get()
            ->getRow();
    }

    /**
     * Lines of an entry keyed for comparison, so an unchanged repost can be skipped.
     *
     * @return list<string>
     */
    public function lineSignature(int $entryId): array
    {
        $signature = [];
        foreach ($this->db->table('acc_journal_lines')->where('entry_id', $entryId)->get()->getResult() as $line) {
            $signature[] = implode('|', [$line->account_id, number_format((float) $line->debit, 2, '.', ''), number_format((float) $line->credit, 2, '.', ''), $line->party_type, $line->party_id]);
        }
        sort($signature);

        return $signature;
    }

    /**
     * @return list<string>
     */
    public function proposedSignature(array $lines): array
    {
        $signature = [];
        foreach ($this->normalizeLines($lines) as $line) {
            $signature[] = implode('|', [$line['account_id'], number_format($line['debit_cents'] / 100, 2, '.', ''), number_format($line['credit_cents'] / 100, 2, '.', ''), $line['party_type'], $line['party_id']]);
        }
        sort($signature);

        return $signature;
    }

    /**
     * Resolves a dimension value by code, creating it on first use.
     */
    public function dimensionValue(string $dimensionCode, string $valueCode, string $name = ''): ?int
    {
        $cacheKey = "$dimensionCode|$valueCode";

        if (isset($this->dimensionCache[$cacheKey])) {
            return $this->dimensionCache[$cacheKey];
        }

        $dimension = $this->db->table('acc_dimensions')->where('code', $dimensionCode)->where('active', 1)->get()->getRow();

        if ($dimension === null || $valueCode === '') {
            return null;
        }

        $value = $this->db->table('acc_dimension_values')->where('dimension_id', $dimension->dimension_id)->where('code', $valueCode)->get()->getRow();

        if ($value !== null) {
            return $this->dimensionCache[$cacheKey] = (int) $value->value_id;
        }

        // Two concurrent documents can create the same value; the upsert waits for the other
        // writer and returns its id instead of failing on the unique key. A plain re-select
        // would not see that row inside this transaction's repeatable-read snapshot.
        $this->db->query(
            'INSERT INTO ' . $this->db->prefixTable('acc_dimension_values') . ' (dimension_id, code, name, active) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE value_id = LAST_INSERT_ID(value_id)',
            [(int) $dimension->dimension_id, mb_substr($valueCode, 0, 60), mb_substr($name !== '' ? $name : $valueCode, 0, 150)]
        );

        return $this->dimensionCache[$cacheKey] = (int) $this->db->insertID();
    }

    private function dimensionId(string $code): ?int
    {
        $row = $this->db->table('acc_dimensions')->select('dimension_id')->where('code', $code)->get()->getRow();

        return $row === null ? null : (int) $row->dimension_id;
    }

    /**
     * @return list<array{account_id: int, debit_cents: int, credit_cents: int, description: string, party_type: ?string, party_id: ?int, payment_method: string, dimensions: array<int, int>}>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $accountId = isset($line['account_id']) ? (int) $line['account_id'] : $this->mapped((string) $line['account']);
            $account = $this->account($accountId);

            if (array_key_exists('amount', $line)) {
                $cents = (int) round(((float) $line['amount']) * 100);
                $debitCents = max($cents, 0);
                $creditCents = max(-$cents, 0);
            } else {
                $debitCents = (int) round(((float) ($line['debit'] ?? 0)) * 100);
                $creditCents = (int) round(((float) ($line['credit'] ?? 0)) * 100);

                if ($debitCents < 0 || $creditCents < 0) {
                    throw new AccountingException('Debit and credit amounts cannot be negative.');
                }

                $net = $debitCents - $creditCents;
                $debitCents = max($net, 0);
                $creditCents = max(-$net, 0);
            }

            if ($debitCents === 0 && $creditCents === 0) {
                continue;
            }

            if ((int) $account->is_group === 1) {
                throw new AccountingException("Account $account->code is a group account and cannot be posted to.");
            }

            if ((int) $account->active !== 1) {
                throw new AccountingException("Account $account->code is inactive.");
            }

            $partyType = $line['party_type'] ?? null;
            $partyId = isset($line['party_id']) && $line['party_id'] !== '' ? (int) $line['party_id'] : null;

            if ($account->party_type !== null && $partyType !== null && $partyType !== $account->party_type) {
                throw new AccountingException("Account $account->code only accepts $account->party_type parties.");
            }

            $dimensions = [];
            foreach ($line['dimensions'] ?? [] as $key => $value) {
                if (is_int($key)) {
                    $dimensions[$key] = (int) $value;
                    continue;
                }

                $dimensionId = $this->dimensionId($key);
                $valueId = is_array($value)
                    ? $this->dimensionValue($key, (string) $value['code'], (string) ($value['name'] ?? ''))
                    : (is_int($value) ? $value : $this->dimensionValue($key, (string) $value));

                if ($dimensionId !== null && $valueId !== null) {
                    $dimensions[$dimensionId] = $valueId;
                }
            }

            $normalized[] = [
                'account_id'     => $accountId,
                'debit_cents'    => $debitCents,
                'credit_cents'   => $creditCents,
                'description'    => (string) ($line['description'] ?? ''),
                'party_type'     => $partyType ?? ($partyId !== null ? $account->party_type : null),
                'party_id'       => $partyId,
                'payment_method' => (string) ($line['payment_method'] ?? 'cash'),
                'dimensions'     => $dimensions,
            ];
        }

        return $normalized;
    }

    private function writeTreasuryMovement(int $entryId, string $entryDate, array $header, array $line): void
    {
        $treasury = $this->db->query(
            'SELECT treasury_id FROM ' . $this->db->prefixTable('treasuries') . ' WHERE account_id = ? FOR UPDATE',
            [$line['account_id']]
        )->getRow();

        if ($treasury === null) {
            throw new AccountingException('Treasury account ' . $this->account($line['account_id'])->code . ' has no treasury.');
        }

        $last = $this->db->table('treasury_movements')
            ->select('balance_after')
            ->where('treasury_id', $treasury->treasury_id)
            ->orderBy('movement_id', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        $beforeCents = $last === null ? 0 : (int) round(((float) $last->balance_after) * 100);
        $isIn = $line['debit_cents'] > 0;
        $amountCents = $isIn ? $line['debit_cents'] : $line['credit_cents'];
        $afterCents = $beforeCents + ($isIn ? $amountCents : -$amountCents);

        $this->db->table('treasury_movements')->insert([
            'treasury_id'    => $treasury->treasury_id,
            'movement_time'  => $entryDate,
            'direction'      => $isIn ? 'in' : 'out',
            'amount'         => $amountCents / 100,
            'balance_before' => $beforeCents / 100,
            'balance_after'  => $afterCents / 100,
            'movement_type'  => isset($header['reverses_entry_id']) ? 'reversal' : (string) ($header['source_type'] ?? 'manual'),
            'payment_method' => $line['payment_method'],
            'source_type'    => (string) ($header['source_type'] ?? 'manual'),
            'source_id'      => (int) ($header['source_id'] ?? $entryId),
            'reference'      => mb_substr((string) ($header['reference'] ?? ''), 0, 64),
            // What the movement was for (the entry), plus the line's own detail when it adds something.
            'description'    => mb_substr(trim((string) ($header['description'] ?? '') . ($line['description'] !== '' && $line['description'] !== ($header['description'] ?? '') ? ' - ' . $line['description'] : ''), ' -'), 0, 255),
            'party_type'     => $line['party_type'],
            'party_id'       => $line['party_id'],
            'employee_id'    => $header['created_by'] ?? null,
            'entry_id'       => $entryId,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
