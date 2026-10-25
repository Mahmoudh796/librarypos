<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Invoice-level (whole-sale) discount.
 *
 * - sales_items.invoice_discount: the share of the invoice discount allocated to
 *   the line (money, same sign as the quantity). Returns made from a receipt
 *   reuse the original per-unit share, so a partial return refunds exactly what
 *   was paid for the returned units.
 * - sales.invoice_discount_*: how the cashier entered it (type, value, reason).
 * - sales_invoice_discount lets an employee apply one up to
 *   invoice_discount_max_percent of the sale; sales_invoice_discount_override
 *   lifts that limit. Both start with whoever could already change line
 *   discounts (sales_discount) and with administrators.
 */
class RuyaInvoiceDiscount extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('invoice_discount', 'sales_items')) {
            $this->forge->addColumn('sales_items', [
                'invoice_discount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false, 'default' => 0],
            ]);
        }

        $saleColumns = [
            'invoice_discount_type'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'invoice_discount_value'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'invoice_discount_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ];
        foreach ($saleColumns as $column => $definition) {
            if (!$this->db->fieldExists($column, 'sales')) {
                $this->forge->addColumn('sales', [$column => $definition]);
            }
        }

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => 'sales_invoice_discount', 'module_id' => 'sales'],
            ['permission_id' => 'sales_invoice_discount_override', 'module_id' => 'sales'],
        ]);

        $discounters = array_map('intval', array_column($this->db->table('grants')->select('person_id')->where('permission_id', 'sales_discount')->get()->getResultArray(), 'person_id'));
        $admins = array_map('intval', array_column($this->db->table('grants')->select('person_id')->where('permission_id', 'config')->get()->getResultArray(), 'person_id'));
        $existing = array_map('intval', array_column($this->db->table('employees')->select('person_id')->get()->getResultArray(), 'person_id'));

        foreach (array_intersect(array_unique(array_merge([1], $discounters, $admins)), $existing) as $personId) {
            $this->db->table('grants')->ignore(true)->insert(['permission_id' => 'sales_invoice_discount', 'person_id' => $personId, 'menu_group' => '--']);
        }
        foreach (array_intersect(array_unique(array_merge([1], $admins)), $existing) as $personId) {
            $this->db->table('grants')->ignore(true)->insert(['permission_id' => 'sales_invoice_discount_override', 'person_id' => $personId, 'menu_group' => '--']);
        }

        if ($this->db->table('app_config')->where('key', 'invoice_discount_max_percent')->countAllResults() === 0) {
            $this->db->table('app_config')->insert(['key' => 'invoice_discount_max_percent', 'value' => '10']);
        }
    }

    public function down(): void
    {
        $this->db->table('grants')->whereIn('permission_id', ['sales_invoice_discount', 'sales_invoice_discount_override'])->delete();
        $this->db->table('permissions')->whereIn('permission_id', ['sales_invoice_discount', 'sales_invoice_discount_override'])->delete();
        $this->db->table('app_config')->where('key', 'invoice_discount_max_percent')->delete();
        // The columns stay: dropping them would erase how past sales were discounted.
    }
}
