<?php

namespace App\Models;

use CodeIgniter\Database\RawSql;
use CodeIgniter\Model;
use stdClass;

/**
 * Item_quantity class
 */
class Item_quantity extends Model
{
    protected $table = 'item_quantities';
    protected $primaryKey = 'item_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'quantity'
    ];

    protected $item_id;
    protected $location_id;
    protected $quantity;

    /**
     * @param int $item_id
     * @param int $location_id
     * @return bool
     */
    public function exists(int $item_id, int $location_id): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * @param array $location_detail
     * @param int $item_id
     * @param int $location_id
     * @return bool
     */
    public function save_value(array $location_detail, int $item_id, int $location_id): bool
    {
        if (!$this->exists($item_id, $location_id)) {
            $builder = $this->db->table('item_quantities');
            return $builder->insert($location_detail);
        }

        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);

        return $builder->update($location_detail);
    }

    /**
     * @param int $item_id
     * @param int $location_id
     * @return array|Item_quantity|stdClass|null
     */
    public function get_item_quantity(int $item_id, int $location_id): array|Item_quantity|StdClass|null
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);
        $result = $builder->get()->getRow();

        if (empty($result)) {
            // Get empty base parent object, as $item_id is NOT an item
            $result = model(Item_quantity::class);

            // Get all the fields from items table (TODO: to be reviewed)
            foreach ($this->db->getFieldNames('item_quantities') as $field) {
                $result->$field = '';
            }

            $result->quantity = 0;
        }

        return $result;
    }

    /**
     * Atomically changes an item's quantity at a location by a signed delta.
     * Positive delta adds; negative delta subtracts. Creates the
     * item_quantities row if it doesn't yet exist.
     *
     * With no $minimumQuantity the change is unrestricted (negative results
     * are allowed, legacy behaviour). When a minimum is supplied the caller
     * must already be inside a DB transaction: the row is locked with
     * SELECT ... FOR UPDATE, the floor is enforced against the locked value
     * and the write is applied before the transaction commits. Concurrent
     * changes to the same item/location therefore serialize against this one,
     * so two transactions can never both consume the same last units.
     */
    public function changeQuantity(int $itemId, int $locationId, float $quantityChange, ?float $minimumQuantity = null): bool
    {
        if ($minimumQuantity === null) {
            $builder = $this->db->table('item_quantities');
            $builder->set([
                'item_id'     => $itemId,
                'location_id' => $locationId,
                'quantity'    => $quantityChange,
            ]);
            $builder->updateFields(['quantity' => new RawSql('quantity + ' . $this->db->escape($quantityChange))]);

            return $builder->upsert() !== false;
        }

        $tableName = $this->db->prefixTable('item_quantities');

        $row = $this->db->query(
            'SELECT quantity FROM ' . $tableName . ' WHERE item_id = ? AND location_id = ? FOR UPDATE',
            [$itemId, $locationId]
        )->getRow();

        if ($row === null) {
            // No row yet: only a change that already satisfies the floor may
            // create one. A negative delta here would fabricate stock.
            if ($quantityChange < $minimumQuantity) {
                return false;
            }

            // Create-or-add in one statement; a concurrent transaction may
            // have inserted the row after our (gap-locked) read.
            $builder = $this->db->table('item_quantities');
            $builder->set([
                'item_id'     => $itemId,
                'location_id' => $locationId,
                'quantity'    => $quantityChange,
            ]);
            $builder->updateFields(['quantity' => new RawSql('quantity + ' . $this->db->escape($quantityChange))]);
            $builder->upsert();

            // Re-read under the lock and verify the floor still holds.
            $row = $this->db->query(
                'SELECT quantity FROM ' . $tableName . ' WHERE item_id = ? AND location_id = ? FOR UPDATE',
                [$itemId, $locationId]
            )->getRow();

            return $row !== null && (float) $row->quantity >= $minimumQuantity;
        }

        $newQuantity = (float) $row->quantity + $quantityChange;
        if ($newQuantity < $minimumQuantity) {
            return false;
        }

        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $itemId);
        $builder->where('location_id', $locationId);

        return $builder->update(['quantity' => $newQuantity]);
    }

    /**
     * Set to 0 all quantity in the given item
     */
    public function reset_quantity(int $item_id): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);

        return $builder->update(['quantity' => 0]);
    }

    /**
     * Set to 0 all quantity in the given list of items
     */
    public function reset_quantity_list(array $item_ids): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->whereIn('item_id', $item_ids);

        return $builder->update(['quantity' => 0]);
    }
}
