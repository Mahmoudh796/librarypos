<?php

namespace App\Controllers;

use App\Libraries\Accounting\Vouchers;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * الخزنة: balances, the movement ledger (derived from journal postings, never
 * edited), and treasury vouchers (receipts, payments, transfers, opening balance).
 */
class Treasury extends Ledger_Controller
{
    private const PAGE_SIZE = 50;

    public function __construct()
    {
        parent::__construct('treasury', 'treasury_view');
    }

    public function getIndex(): string
    {
        $db = Database::connect();
        [$from, $to] = $this->dateRange(date('Y-m-01'));
        $treasuryId = (int) $this->request->getGet('treasury_id');
        $direction = in_array($this->request->getGet('direction'), ['in', 'out'], true) ? (string) $this->request->getGet('direction') : '';
        $page = max(1, (int) $this->request->getGet('page'));

        $filtered = function () use ($db, $from, $to, $treasuryId, $direction) {
            $builder = $db->table('treasury_movements')
                ->where('movement_time >=', $from)
                ->where('movement_time <', date('Y-m-d', strtotime("$to +1 day")));

            if ($treasuryId > 0) {
                $builder->where('treasury_movements.treasury_id', $treasuryId);
            }
            if ($direction !== '') {
                $builder->where('direction', $direction);
            }

            return $builder;
        };

        $totals = $filtered()
            ->select("COUNT(*) AS row_count, SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) AS total_in, SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) AS total_out", false)
            ->get()->getRow();
        $total = (int) ($totals->row_count ?? 0);

        $movements = $filtered()
            ->select('treasury_movements.*, treasuries.name AS treasury_name, people.first_name, people.last_name')
            ->join('treasuries', 'treasuries.treasury_id = treasury_movements.treasury_id')
            ->join('people', 'people.person_id = treasury_movements.employee_id', 'left')
            ->orderBy('treasury_movements.movement_time', 'DESC')
            ->orderBy('treasury_movements.movement_id', 'DESC')
            ->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)
            ->get()->getResultArray();
        $movements = $this->withRunningBalance($movements);

        // The selected cash box's position over the period: opening + in - out = closing.
        $position = null;
        if ($treasuryId > 0 && $direction === '') {
            $opening = (float) ($db->table('treasury_movements')
                ->select("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS balance", false)
                ->where('treasury_id', $treasuryId)->where('movement_time <', $from)
                ->get()->getRow()->balance ?? 0);
            $position = [
                'opening' => $opening,
                'closing' => $opening + (float) ($totals->total_in ?? 0) - (float) ($totals->total_out ?? 0),
            ];
        }

        return view('treasury/index', [
            'position'     => $position,
            'treasuries'   => $this->treasuriesWithBalance(),
            'movements'    => $movements,
            'parties'      => $this->partyNames($movements),
            'total_in'     => (float) ($totals->total_in ?? 0),
            'total_out'    => (float) ($totals->total_out ?? 0),
            'from'         => $from,
            'to'           => $to,
            'treasury_id'  => $treasuryId,
            'direction'    => $direction,
            'page'         => $page,
            'pages'        => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'can_vouchers' => $this->can('treasury_vouchers'),
        ]);
    }

    public function getVoucher(): string
    {
        $this->requireGrant('treasury_vouchers');
        $db = Database::connect();
        $type = in_array($this->request->getGet('type'), Vouchers::TYPES, true) ? (string) $this->request->getGet('type') : 'customer_receipt';

        return view('treasury/voucher_form', [
            'type'         => $type,
            'types'        => array_combine(Vouchers::TYPES, array_map(static fn ($t) => lang('Treasury.type_' . $t), Vouchers::TYPES)),
            'treasuries'   => array_column($this->treasuriesWithBalance(), 'name', 'treasury_id'),
            'customers'    => $this->peopleOptions('customers'),
            'suppliers'    => $this->peopleOptions('suppliers'),
            'accounts'     => $this->counterAccountOptions(),
            'cost_centers' => array_column($db->table('acc_dimension_values')->select('acc_dimension_values.value_id, acc_dimension_values.name')
                ->join('acc_dimensions', 'acc_dimensions.dimension_id = acc_dimension_values.dimension_id')
                ->where('acc_dimensions.code', 'cost_center')->where('acc_dimension_values.active', 1)->get()->getResultArray(), 'name', 'value_id'),
            'party_id'     => (int) $this->request->getGet('party_id'),
        ]);
    }

    public function postVoucher(): ResponseInterface
    {
        if ($denied = $this->denied('treasury_vouchers')) {
            return $denied;
        }

        $data = [
            'voucher_type'       => (string) $this->request->getPost('voucher_type'),
            'voucher_date'       => $this->postedDateTime('voucher_date'),
            'treasury_id'        => (int) $this->request->getPost('treasury_id'),
            'target_treasury_id' => (int) $this->request->getPost('target_treasury_id') ?: null,
            'amount'             => $this->postedAmount('amount'),
            'party_id'           => (int) $this->request->getPost('party_id') ?: null,
            'account_id'         => (int) $this->request->getPost('account_id') ?: null,
            'cost_center_id'     => (int) $this->request->getPost('cost_center_id') ?: null,
            'description'        => trim((string) $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'reference'          => trim((string) $this->request->getPost('reference', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
        ];

        return $this->act(function () use ($data) {
            $voucherId = (new Vouchers(Database::connect()))->create($data, $this->personId());
            $this->audit('voucher_create', 'treasury_voucher', $voucherId, null, $data);

            return $voucherId;
        }, lang('Treasury.saved'));
    }

    public function getVouchers(): string
    {
        $db = Database::connect();
        [$from, $to] = $this->dateRange(date('Y-m-01'));
        $page = max(1, (int) $this->request->getGet('page'));

        $builder = $db->table('treasury_vouchers')
            ->select('treasury_vouchers.*, treasuries.name AS treasury_name, acc_accounts.name AS account_name')
            ->join('treasuries', 'treasuries.treasury_id = treasury_vouchers.treasury_id')
            ->join('acc_accounts', 'acc_accounts.account_id = treasury_vouchers.account_id', 'left')
            ->where('voucher_date >=', $from)
            ->where('voucher_date <', date('Y-m-d', strtotime("$to +1 day")));

        $total = $builder->countAllResults(false);
        $vouchers = $builder->orderBy('voucher_id', 'DESC')->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)->get()->getResultArray();

        return view('treasury/vouchers', [
            'vouchers'    => $vouchers,
            'parties'     => $this->partyNames($vouchers),
            'from'        => $from,
            'to'          => $to,
            'page'        => $page,
            'pages'       => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'can_reverse' => $this->can('treasury_reverse'),
        ]);
    }

    public function postReverse(int $voucherId): ResponseInterface
    {
        if ($denied = $this->denied('treasury_reverse')) {
            return $denied;
        }

        $reason = trim((string) $this->request->getPost('reason', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        return $this->act(function () use ($voucherId, $reason) {
            $entryId = (new Vouchers(Database::connect()))->reverse($voucherId, $this->personId(), $reason);
            $this->audit('voucher_reverse', 'treasury_voucher', $voucherId, ['status' => 'posted'], ['status' => 'reversed'], $reason);

            return $entryId;
        }, lang('Treasury.reversed'));
    }

    /**
     * @return list<array{treasury_id: int, name: string, kind: string, balance: float}>
     */
    private function treasuriesWithBalance(): array
    {
        $db = Database::connect();
        $rows = $db->table('treasuries')->where('active', 1)->orderBy('is_default', 'DESC')->orderBy('treasury_id')->get()->getResultArray();

        foreach ($rows as &$row) {
            $last = $db->table('treasury_movements')->select('balance_after')->where('treasury_id', $row['treasury_id'])->orderBy('movement_id', 'DESC')->limit(1)->get()->getRow();
            $row['balance'] = (float) ($last->balance_after ?? 0);
        }
        unset($row);

        return $rows;
    }

    /**
     * Adds each movement's balance in date order (per treasury). The stored
     * balance_after follows posting order, which differs from the date order
     * once something is posted with an earlier date; the cash book is read by date.
     *
     * @param list<array> $movements
     * @return list<array>
     */
    private function withRunningBalance(array $movements): array
    {
        if ($movements === []) {
            return $movements;
        }

        $db = Database::connect();
        $running = [];
        $rows = $db->query(
            'SELECT movement_id, running FROM (
                SELECT movement_id, SUM(CASE WHEN direction = \'in\' THEN amount ELSE -amount END)
                    OVER (PARTITION BY treasury_id ORDER BY movement_time, movement_id) AS running
                FROM ' . $db->prefixTable('treasury_movements') . ' WHERE treasury_id IN ?
            ) ordered WHERE movement_id IN ?',
            [array_values(array_unique(array_map('intval', array_column($movements, 'treasury_id')))), array_map('intval', array_column($movements, 'movement_id'))]
        )->getResult();
        foreach ($rows as $row) {
            $running[(int) $row->movement_id] = (float) $row->running;
        }

        foreach ($movements as &$movement) {
            $movement['running_balance'] = $running[(int) $movement['movement_id']] ?? (float) $movement['balance_after'];
        }

        return $movements;
    }

    /**
     * @return array<string, string> "type:id" => display name for the parties in $rows
     */
    private function partyNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row['party_type']) && !empty($row['party_id'])) {
                $ids[$row['party_type']][] = (int) $row['party_id'];
            }
        }

        $db = Database::connect();
        $names = [];

        foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $type => $table) {
            if (!empty($ids[$type])) {
                $builder = $db->table('people')->select('people.person_id, people.first_name, people.last_name')->whereIn('people.person_id', array_unique($ids[$type]));
                if ($type === 'supplier') {
                    $builder->select('suppliers.company_name')->join('suppliers', 'suppliers.person_id = people.person_id', 'left');
                }
                foreach ($builder->get()->getResult() as $person) {
                    $names["$type:$person->person_id"] = trim(($person->company_name ?? '') !== '' ? $person->company_name : $person->first_name . ' ' . $person->last_name);
                }
            }
        }

        if (!empty($ids['shareholder'])) {
            foreach ($db->table('shareholders')->select('shareholder_id, name')->whereIn('shareholder_id', array_unique($ids['shareholder']))->get()->getResult() as $shareholder) {
                $names["shareholder:$shareholder->shareholder_id"] = $shareholder->name;
            }
        }

        return $names;
    }

    /**
     * Accounts a receipt/payment voucher may target: postable, not treasury, no party control account.
     */
    private function counterAccountOptions(): array
    {
        $rows = Database::connect()->table('acc_accounts')
            ->where('is_group', 0)->where('is_treasury', 0)->where('party_type', null)->where('active', 1)
            ->orderBy('code')->get()->getResult();

        // Grouped by account type, expenses and revenues first: those are what a
        // cash payment or receipt usually is, and the group heading makes a wrong
        // pick (an asset account for rent) visible.
        $groups = [];
        foreach (['expense', 'revenue', 'liability', 'equity', 'asset'] as $type) {
            $groups[lang('Accounting.type_' . $type)] = [];
        }
        foreach ($rows as $row) {
            $groups[lang('Accounting.type_' . $row->account_type)][$row->account_id] = $row->code . ' - ' . $row->name;
        }

        return ['' => lang('Common.none_selected_text')] + array_filter($groups);
    }
}
