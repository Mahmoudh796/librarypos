<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Moving weighted average cost (approved costing policy).
 *
 * receive(): on q units at net unit cost c with Q units on hand at average C,
 *   Q > 0  -> (Q*C + q*c) / (Q + q)
 *   Q <= 0 -> c   (nothing on hand to blend with)
 * Purchase returns, sales and sales returns never change the average.
 *
 * Every change is written to item_cost_history. Call receive() BEFORE the stock
 * quantity is increased, so "quantity on hand" is the pre-receipt quantity.
 */
class Costing
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function quantityOnHand(int $itemId): float
    {
        $row = $this->db->table('item_quantities')
            ->selectSum('item_quantities.quantity', 'quantity')
            ->join('stock_locations', 'stock_locations.location_id = item_quantities.location_id')
            ->where('item_quantities.item_id', $itemId)
            ->where('stock_locations.deleted', 0)
            ->get()
            ->getRow();

        return (float) ($row->quantity ?? 0);
    }

    /**
     * Locks the items' rows in id order. Call it first in a receiving's transaction:
     * concurrent receivings of the same item then queue here instead of deadlocking
     * on the cost update (each line insert takes a shared FK lock on the item row),
     * and because InnoDB takes the read snapshot at the first non-locking read, the
     * average-cost reads that follow see the other receiving's committed stock and cost.
     *
     * @param int[] $itemIds
     */
    public function lockItems(array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        sort($itemIds);

        if ($itemIds !== []) {
            $this->db->query(
                'SELECT item_id FROM ' . $this->db->prefixTable('items') . ' WHERE item_id IN ? ORDER BY item_id FOR UPDATE',
                [$itemIds]
            );
        }
    }

    public function currentCost(int $itemId): float
    {
        $row = $this->db->table('items')->select('cost_price')->where('item_id', $itemId)->get()->getRow();

        return (float) ($row->cost_price ?? 0);
    }

    /**
     * Net unit cost of a receiving line: line price per pack, less the line
     * discount, spread over the units in the pack.
     */
    public static function netUnitCost(float $price, float $quantity, float $packSize, float $discount, int $discountType): float
    {
        $packSize = $packSize > 0 ? $packSize : 1.0;
        $gross = $quantity * $price;
        $discountAmount = $discountType === PERCENT ? $gross * $discount / 100 : $quantity * $discount;
        $units = $quantity * $packSize;

        if ($units == 0.0) {
            return $price / $packSize;
        }

        return ($gross - $discountAmount) / $units;
    }

    public static function movingAverage(float $quantityOnHand, float $currentCost, float $quantityIn, float $unitCostIn): float
    {
        if ($quantityIn <= 0) {
            return $currentCost;
        }

        if ($quantityOnHand <= 0) {
            return $unitCostIn;
        }

        return (($quantityOnHand * $currentCost) + ($quantityIn * $unitCostIn)) / ($quantityOnHand + $quantityIn);
    }

    /**
     * Applies a receipt to the item's average cost and returns the new cost.
     */
    public function receive(int $itemId, float $unitsIn, float $unitCostIn, string $sourceType, ?int $sourceId, ?int $employeeId): float
    {
        $quantityBefore = $this->quantityOnHand($itemId);
        $costBefore = $this->currentCost($itemId);
        $costAfter = round(self::movingAverage($quantityBefore, $costBefore, $unitsIn, $unitCostIn), 4);

        if ($unitsIn > 0) {
            $this->db->table('items')->where('item_id', $itemId)->update(['cost_price' => round($costAfter, 2)]);
            $this->record($itemId, $sourceType, $sourceId, $quantityBefore, $costBefore, $unitsIn, $unitCostIn, $costAfter, $employeeId);
        }

        return $costAfter;
    }

    /**
     * Records a manual cost change and returns the revaluation of stock on hand
     * (positive = inventory value increased).
     */
    public function recordManualChange(int $itemId, float $costBefore, float $costAfter, ?int $employeeId): float
    {
        if (round($costBefore, 4) === round($costAfter, 4)) {
            return 0.0;
        }

        $quantity = $this->quantityOnHand($itemId);
        $this->record($itemId, 'manual', null, $quantity, $costBefore, 0, null, $costAfter, $employeeId);

        return round($quantity * ($costAfter - $costBefore), 2);
    }

    private function record(int $itemId, string $sourceType, ?int $sourceId, float $quantityBefore, float $costBefore, float $quantityChange, ?float $unitCostIn, float $costAfter, ?int $employeeId): void
    {
        $this->db->table('item_cost_history')->insert([
            'item_id'      => $itemId,
            'changed_at'   => date('Y-m-d H:i:s'),
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'qty_before'   => $quantityBefore,
            'cost_before'  => $costBefore,
            'qty_change'   => $quantityChange,
            'unit_cost_in' => $unitCostIn,
            'cost_after'   => $costAfter,
            'employee_id'  => $employeeId,
        ]);
    }
}
