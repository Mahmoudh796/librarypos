<?php

namespace App\Models;

use App\Libraries\Accounting\PaymentMethod;
use App\Libraries\Accounting\Posting;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use stdClass;
use Throwable;

/**
 * Cashup class
 * Cashups are used to report actual cash on hand, expenses and transactions at the end of a period.
 */
class Cashup extends Model
{
    protected $table = 'cash_up';
    protected $primaryKey = 'cashup_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'open_date',
        'close_date',
        'open_cash_amount',
        'transfer_cash_amount',
        'note',
        'closed_amount_cash',
        'closed_amount_card',
        'closed_amount_check',
        'closed_amount_total',
        'description',
        'open_employee_id',
        'close_employee_id',
        'deleted',
        'closed_amount_due'
    ];

    /**
     * Determines if a given Cashup_id is a Cashup
     */
    public function exists(int $cashup_id): bool
    {
        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets employee info
     */
    public function get_employee(int $cashup_id): object    // TODO: This function is never called and if it were called, would not yield proper results.  There is no employee_id field in the cash_up table.
    {
        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        $employee = model(Employee::class);

        return $employee->get_info($builder->get()->getRow()->employee_id);
    }

    /**
     * @param string $cashup_ids
     * @return ResultInterface
     */
    public function get_multiple_info(string $cashup_ids): ResultInterface
    {
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashup_ids);
        $builder->orderBy('cashup_id', 'asc');

        return $builder->get();
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search, array $filters): int
    {
        return $this->search($search, $filters, 0, 0, 'cashup_id', 'asc', true);
    }

    /**
     * Searches cashups
     */
    public function search(string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'cashup_id', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'cashup_id';
        if ($order == null) $order = 'asc';
        if ($count_only == null) $count_only = false;

        $config = config(OSPOS::class)->settings;
        $builder = $this->db->table('cash_up AS cash_up');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(cash_up.cashup_id) as count');
        } else {
            $builder->select('
            cash_up.cashup_id,
            MAX(cash_up.open_date) AS open_date,
            MAX(cash_up.close_date) AS close_date,
            MAX(cash_up.open_amount_cash) AS open_amount_cash,
            MAX(cash_up.transfer_amount_cash) AS transfer_amount_cash,
            MAX(cash_up.closed_amount_cash) AS closed_amount_cash,
            MAX(cash_up.closed_amount_due) AS closed_amount_due,
            MAX(cash_up.closed_amount_card) AS closed_amount_card,
            MAX(cash_up.closed_amount_check) AS closed_amount_check,
            MAX(cash_up.closed_amount_total) AS closed_amount_total,
            MAX(cash_up.description) AS description,
            MAX(cash_up.note) AS note,
            MAX(cash_up.open_employee_id) AS open_employee_id,
            MAX(cash_up.close_employee_id) AS close_employee_id,
            MAX(open_employees.first_name) AS open_first_name,
            MAX(open_employees.last_name) AS open_last_name,
            MAX(close_employees.first_name) AS close_first_name,
            MAX(close_employees.last_name) AS close_last_name
        ');
        }

        $builder->join('people AS open_employees', 'open_employees.person_id = cash_up.open_employee_id', 'LEFT');
        $builder->join('people AS close_employees', 'close_employees.person_id = cash_up.close_employee_id', 'LEFT');

        $builder->groupStart();
        $builder->like('cash_up.open_date', $search);
        $builder->orLike('open_employees.first_name', $search);
        $builder->orLike('open_employees.last_name', $search);
        $builder->orLike('close_employees.first_name', $search);
        $builder->orLike('close_employees.last_name', $search);
        $builder->orLike('cash_up.closed_amount_total', $search);
        $builder->orLike('CONCAT(open_employees.first_name, " ", open_employees.last_name)', $search);
        $builder->orLike('CONCAT(close_employees.first_name, " ", close_employees.last_name)', $search);
        $builder->groupEnd();

        $builder->where('cash_up.deleted', $filters['is_deleted']);

        if (empty($config['date_or_time_format'])) {    // TODO: convert this to ternary notation.
            $builder->where('DATE_FORMAT(cash_up.open_date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('cash_up.open_date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        } else {
            $builder->groupBy('cashup_id');
        }

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Gets information about a particular cashup
     */
    public function get_info(int $cashup_id): object
    {
        $builder = $this->db->table('cash_up AS cash_up');
        $builder->select('
            cash_up.cashup_id AS cashup_id,
            cash_up.open_date AS open_date,
            cash_up.close_date AS close_date,
            cash_up.open_amount_cash AS open_amount_cash,
            cash_up.transfer_amount_cash AS transfer_amount_cash,
            cash_up.closed_amount_cash AS closed_amount_cash,
            cash_up.closed_amount_due AS closed_amount_due,
            cash_up.closed_amount_card AS closed_amount_card,
            cash_up.closed_amount_check AS closed_amount_check,
            cash_up.closed_amount_total AS closed_amount_total,
            cash_up.description AS description,
            cash_up.note AS note,
            cash_up.open_employee_id AS open_employee_id,
            cash_up.close_employee_id AS close_employee_id,
            cash_up.deleted AS deleted,
            open_employees.first_name AS open_first_name,
            open_employees.last_name AS open_last_name,
            close_employees.first_name AS close_first_name,
            close_employees.last_name AS close_last_name
        ');
        $builder->join('people AS open_employees', 'open_employees.person_id = cash_up.open_employee_id', 'LEFT');
        $builder->join('people AS close_employees', 'close_employees.person_id = cash_up.close_employee_id', 'LEFT');
        $builder->where('cashup_id', $cashup_id);

        $query = $builder->get();
        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        } else {
            return $this->getEmptyObject('cash_up');
        }
    }

    /**
     * Initializes an empty object based on database definitions
     * @param string $table_name
     * @return object
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = new stdClass();

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'])) {
                $empty_obj->$field_name = ($field->primary_key == 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->$field_name = null;
            }
        }

        return $empty_obj;
    }


    /**
     * Inserts or updates a cashup
     */
    public function save_value(array &$cash_up_data, $cashup_id = NEW_ENTRY): bool
    {
        $this->db->transStart();

        $cash_up_data = array_merge($cash_up_data, $this->reconcile($cash_up_data));
        $builder = $this->db->table('cash_up');

        if ($cashup_id == NEW_ENTRY || !$this->exists((int) $cashup_id)) {
            $success = $builder->insert($cash_up_data);

            if ($success) {
                $cash_up_data['cashup_id'] = $this->db->insertID();
                $cashup_id = (int) $cash_up_data['cashup_id'];
            }
        } else {
            $builder->where('cashup_id', $cashup_id);
            $success = $builder->update($cash_up_data);
        }

        if ($success && !$this->postToLedger((int) $cashup_id)) {
            $this->db->transRollback();

            return false;
        }

        $this->db->transComplete();

        return $success && $this->db->transStatus();
    }

    /**
     * Deletes a list of cashups
     */
    public function delete_list(array $cashup_ids): bool
    {
        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashup_ids);
        $success = $builder->update(['deleted' => 1]);

        foreach ($cashup_ids as $cashupId) {
            if ($success && !$this->postToLedger((int) $cashupId)) {
                $this->db->transRollback();

                return false;
            }
        }

        $this->db->transComplete();

        return $success;
    }

    /**
     * Expected cash at close and the counted-minus-expected difference
     * (positive = overage, negative = shortage). A shift that is still open
     * (close date equal to open date) has neither.
     *
     * @return array{expected_amount_cash: ?float, difference_cash: ?float}
     */
    public function reconcile(array $cashup): array
    {
        $open = $cashup['open_date'] ?? null;
        $close = $cashup['close_date'] ?? null;

        if ($open === null || $close === null || $close <= $open) {
            return ['expected_amount_cash' => null, 'difference_cash' => null];
        }

        $expected = $this->expectedClosingCash(
            $open,
            $close,
            (float) ($cashup['open_amount_cash'] ?? 0),
            (float) ($cashup['transfer_amount_cash'] ?? 0),
            !empty($cashup['treasury_id']) ? (int) $cashup['treasury_id'] : null
        );

        return [
            'expected_amount_cash' => $expected,
            'difference_cash'      => round((float) ($cashup['closed_amount_cash'] ?? 0) - $expected, 2),
        ];
    }

    /**
     * Opening float + cash added/removed + every cash movement of the drawer's
     * treasury during the shift. Shifts from before the ledger existed fall back
     * to cash sale payments minus cash expenses, as OSPOS always computed it.
     */
    public function expectedClosingCash(string $openDate, string $closeDate, float $openAmount, float $transferAmount, ?int $treasuryId = null): float
    {
        if (Posting::enabled() && Posting::inScope($openDate)) {
            $treasuryId ??= (int) ($this->db->table('treasuries')->select('treasury_id')->where('kind', 'cash')->orderBy('is_default', 'DESC')->get()->getRow()->treasury_id ?? 0);

            $row = $this->db->table('treasury_movements')
                ->select("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net", false)
                ->where('treasury_id', $treasuryId)
                ->where('movement_time >=', $openDate)
                ->where('movement_time <=', $closeDate)
                ->where('source_type !=', Posting::SOURCE_CASHUP)
                ->get()
                ->getRow();

            return round($openAmount + $transferAmount + (float) ($row->net ?? 0), 2);
        }

        $cashLabels = array_keys(array_filter(
            array_column($this->db->table('sales_payments')->distinct()->select('payment_type')->get()->getResultArray(), 'payment_type', 'payment_type'),
            static fn (string $label): bool => PaymentMethod::resolve($label) === PaymentMethod::CASH
        ));

        $cashIn = 0.0;
        if ($cashLabels !== []) {
            $cashIn = (float) ($this->db->table('sales_payments')
                ->select('SUM(sales_payments.payment_amount - sales_payments.cash_refund) AS amount', false)
                ->join('sales', 'sales.sale_id = sales_payments.sale_id')
                ->where('sales.sale_status', COMPLETED)
                ->whereIn('sales_payments.payment_type', $cashLabels)
                ->where('sales.sale_time >=', $openDate)
                ->where('sales.sale_time <=', $closeDate)
                ->get()
                ->getRow()->amount ?? 0);
        }

        $cashOut = 0.0;
        foreach ($this->db->table('expenses')->select('payment_type, SUM(amount) AS amount')->where('deleted', 0)->where('date >=', $openDate)->where('date <=', $closeDate)->groupBy('payment_type')->get()->getResult() as $row) {
            if (PaymentMethod::resolve((string) $row->payment_type) === PaymentMethod::CASH) {
                $cashOut += (float) $row->amount;
            }
        }

        return round($openAmount + $transferAmount + $cashIn - $cashOut, 2);
    }

    private function postToLedger(int $cashupId): bool
    {
        if (!Posting::enabled()) {
            return true;
        }

        try {
            (new Posting($this->db))->postCashup($cashupId);

            return true;
        } catch (Throwable $e) {
            log_message('error', "Posting cash-up $cashupId failed: " . $e->getMessage());

            return false;
        }
    }
}
