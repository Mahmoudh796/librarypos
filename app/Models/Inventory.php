<?php

namespace App\Models;

use App\Libraries\Accounting\Posting;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;

/**
 * Inventory class
 *
 * @property employee employee
 *
 */
class Inventory extends Model
{
    protected $table = 'inventory';
    protected $primaryKey = 'trans_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'trans_items',
        'trans_user',
        'trans_date',
        'trans_comment',
        'trans_inventory',
        'trans_location'
    ];

    /**
     * @param $comment
     * @param $inventory_data
     * @return bool
     */
    public function update($comment = null, $inventory_data = null): bool
    {
        $builder = $this->db->table('inventory');
        $builder->where('trans_comment', $comment);

        return $builder->update($inventory_data);
    }

    /**
     * Retrieves inventory data given an item_id.
     *
     * @param int $item_id
     * @param bool $location_id
     * @return ResultInterface
     */
    public function get_inventory_data_for_item(int $item_id, bool $location_id = false): ResultInterface
    {
        $builder = $this->db->table('inventory');
        $builder->where('trans_items', $item_id);

        if ($location_id) {
            $builder->where('trans_location', $location_id);
        }

        $builder->orderBy('trans_date', 'desc');

        return $builder->get();
    }

    /**
     * @param int $item_id ID number for the item to have quantity reset.
     * @return bool|int|string The row id of the inventory table on insert or false on failure
     */
    public function reset_quantity(int $item_id): bool|int|string
    {
        $employeeId = (int) model(Employee::class)->get_logged_in_employee_info()->person_id;
        $success = true;

        // Every location is written off; returning after the first one left the
        // stock history of the other locations out of step with item_quantities.
        foreach ($this->get_inventory_sum($item_id) as $inventory_sum) {
            if ($inventory_sum['sum'] > 0) {
                $quantity = -1 * (float) $inventory_sum['sum'];
                $inserted = $this->insert([
                    'trans_inventory' => $quantity,
                    'trans_items'     => $item_id,
                    'trans_location'  => $inventory_sum['location_id'],
                    'trans_comment'   => lang('Items.is_deleted'),
                    'trans_user'      => $employeeId
                ]);
                $success = $success && (bool) $inserted;

                if ($inserted && Posting::enabled()) {
                    (new Posting($this->db))->postInventoryAdjustment((int) $inserted, $item_id, $quantity, $employeeId, lang('Items.is_deleted'));
                }
            }
        }

        return $success;
    }

    /**
     * @param int $item_id
     * @return array
     */
    public function get_inventory_sum(int $item_id): array
    {
        $builder = $this->db->table('inventory');
        $builder->select('SUM(trans_inventory) AS sum, MAX(trans_location) AS location_id');
        $builder->where('trans_items', $item_id);
        $builder->groupBy('trans_location');

        return $builder->get()->getResultArray();
    }
}
