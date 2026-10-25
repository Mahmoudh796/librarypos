<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Manual treasury operations. Each voucher is a document with exactly one
 * journal entry; reversing it posts the mirror entry and marks it reversed.
 *
 *   customer_receipt  treasury     <- customer receivable
 *   supplier_payment  supplier payable <- treasury
 *   other_receipt     treasury     <- chosen account (other income, card settlement, ...)
 *   other_payment     chosen account <- treasury
 *   transfer          target treasury <- source treasury
 *   opening_balance   treasury     <- opening balance equity
 */
class Vouchers
{
    public const TYPES = ['customer_receipt', 'supplier_payment', 'other_receipt', 'other_payment', 'transfer', 'opening_balance'];
    public const SOURCE = 'voucher';

    private BaseConnection $db;
    private Ledger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->ledger = new Ledger($this->db);
    }

    /**
     * @param array{voucher_type: string, voucher_date?: string, treasury_id: int, target_treasury_id?: ?int, amount: float,
     *              party_id?: ?int, account_id?: ?int, description?: string, reference?: string, cost_center_id?: ?int} $data
     * @return int voucher id
     */
    public function create(array $data, int $employeeId): int
    {
        $type = $data['voucher_type'];
        $amount = round((float) $data['amount'], 2);

        if (!in_array($type, self::TYPES, true)) {
            throw new AccountingException(lang('Treasury.invalid_voucher_type'));
        }

        if ($amount <= 0) {
            throw new AccountingException(lang('Treasury.amount_must_be_positive'));
        }

        $treasury = $this->treasury((int) $data['treasury_id']);
        $date = $data['voucher_date'] ?? date('Y-m-d H:i:s');
        $partyId = !empty($data['party_id']) ? (int) $data['party_id'] : null;

        [$partyType, $counterLine] = $this->counterLine($type, $data, $partyId, $amount);

        $treasuryLine = ['account_id' => (int) $treasury->account_id, 'payment_method' => $treasury->kind === 'bank' ? 'bank' : 'cash'];
        $treasuryIsDebit = in_array($type, ['customer_receipt', 'other_receipt', 'opening_balance'], true);

        if ($type === 'transfer') {
            // The source treasury pays out; the counter line is the target treasury.
            $treasuryIsDebit = false;
        }

        $this->db->transStart();

        $this->db->table('treasury_vouchers')->insert([
            'voucher_number'     => 'TMP-' . bin2hex(random_bytes(6)),
            'voucher_type'       => $type,
            'voucher_date'       => $date,
            'treasury_id'        => (int) $treasury->treasury_id,
            'target_treasury_id' => $type === 'transfer' ? (int) $data['target_treasury_id'] : null,
            'amount'             => $amount,
            'party_type'         => $partyType,
            'party_id'           => $partyId,
            'account_id'         => $data['account_id'] ?? null,
            'description'        => mb_substr((string) ($data['description'] ?? ''), 0, 255),
            'reference'          => mb_substr((string) ($data['reference'] ?? ''), 0, 64),
            'cost_center_id'     => $data['cost_center_id'] ?? null,
            'status'             => 'posted',
            'employee_id'        => $employeeId,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
        $voucherId = (int) $this->db->insertID();
        $number = sprintf('%s-%06d', $this->prefix($type), $voucherId);
        $this->db->table('treasury_vouchers')->where('voucher_id', $voucherId)->update(['voucher_number' => $number]);

        $description = trim(lang('Treasury.type_' . $type) . ' ' . ($data['description'] ?? ''));

        $this->ledger->post([
            'entry_date'  => $date,
            'description' => $description,
            'source_type' => self::SOURCE,
            'source_id'   => $voucherId,
            'reference'   => $number,
            'created_by'  => $employeeId,
        ], [
            $treasuryLine + ['amount' => $treasuryIsDebit ? $amount : -$amount, 'description' => $description, 'party_type' => $partyType, 'party_id' => $partyId],
            $counterLine + ['amount' => $treasuryIsDebit ? -$amount : $amount, 'description' => $description],
        ]);

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new AccountingException(lang('Treasury.voucher_failed'));
        }

        return $voucherId;
    }

    public function reverse(int $voucherId, int $employeeId, string $reason = ''): int
    {
        $voucher = $this->db->table('treasury_vouchers')->where('voucher_id', $voucherId)->get()->getRow();

        if ($voucher === null || $voucher->status !== 'posted') {
            throw new AccountingException(lang('Treasury.voucher_not_reversible'));
        }

        $live = $this->ledger->liveEntry(self::SOURCE, $voucherId);

        $this->db->transStart();
        $reversalId = $this->ledger->reverse((int) $live->entry_id, null, trim(lang('Accounting.reversal_of', [$voucher->voucher_number]) . ' ' . $reason), $employeeId);
        $this->db->table('treasury_vouchers')->where('voucher_id', $voucherId)->update(['status' => 'reversed']);
        $this->db->transComplete();

        return $reversalId;
    }

    /**
     * @return array{0: ?string, 1: array}
     */
    private function counterLine(string $type, array $data, ?int $partyId, float $amount): array
    {
        switch ($type) {
            case 'customer_receipt':
                if ($partyId === null) {
                    throw new AccountingException(lang('Treasury.customer_required'));
                }

                return ['customer', ['account' => 'accounts_receivable', 'party_type' => 'customer', 'party_id' => $partyId]];

            case 'supplier_payment':
                if ($partyId === null) {
                    throw new AccountingException(lang('Treasury.supplier_required'));
                }

                return ['supplier', ['account' => 'accounts_payable', 'party_type' => 'supplier', 'party_id' => $partyId]];

            case 'other_receipt':
            case 'other_payment':
                $accountId = (int) ($data['account_id'] ?? 0);
                $account = $accountId > 0 ? $this->ledger->account($accountId) : null;

                if ($account === null || (int) $account->is_treasury === 1 || $account->party_type !== null) {
                    throw new AccountingException(lang('Treasury.account_required'));
                }

                $line = ['account_id' => $accountId];
                if (!empty($data['cost_center_id'])) {
                    $line['dimensions'] = [$this->dimensionId('cost_center') => (int) $data['cost_center_id']];
                }

                return [null, $line];

            case 'transfer':
                $target = $this->treasury((int) ($data['target_treasury_id'] ?? 0));

                if ((int) $target->treasury_id === (int) $data['treasury_id']) {
                    throw new AccountingException(lang('Treasury.transfer_same_treasury'));
                }

                return [null, ['account_id' => (int) $target->account_id, 'payment_method' => $target->kind === 'bank' ? 'bank' : 'cash']];

            case 'opening_balance':
            default:
                return [null, ['account' => 'opening_balance']];
        }
    }

    private function treasury(int $treasuryId): object
    {
        $treasury = $this->db->table('treasuries')->where('treasury_id', $treasuryId)->where('active', 1)->get()->getRow();

        if ($treasury === null) {
            throw new AccountingException(lang('Treasury.treasury_required'));
        }

        return $treasury;
    }

    private function dimensionId(string $code): int
    {
        return (int) $this->db->table('acc_dimensions')->where('code', $code)->get()->getRow()->dimension_id;
    }

    private function prefix(string $type): string
    {
        return match ($type) {
            'customer_receipt', 'other_receipt' => 'RV',
            'supplier_payment', 'other_payment' => 'PV',
            'transfer'        => 'TR',
            'opening_balance' => 'OB',
        };
    }
}
