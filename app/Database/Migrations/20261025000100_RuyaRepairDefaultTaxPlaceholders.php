<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Saving the tax settings while the default-tax inputs were disabled stored the
 * literal "0" as both default tax names. Every new item form then posted two
 * identical "0" / 0% tax rows; the second insert broke the items_taxes primary
 * key and rolled back the whole item save, so no new item could be added.
 *
 * A default tax named "0" at 0% is a placeholder, not a tax: it is cleared, and
 * the 0% "0" rows it put on items are removed. Neither changes any amount.
 */
class RuyaRepairDefaultTaxPlaceholders extends Migration
{
    public function up(): void
    {
        foreach ([1, 2] as $index) {
            $name = $this->setting("default_tax_{$index}_name");
            $rate = $this->setting("default_tax_{$index}_rate");

            if ($name === '0' && in_array($rate, ['0', '', null], true)) {
                $this->db->table('app_config')->where('key', "default_tax_{$index}_name")->update(['value' => '']);
                $this->db->table('app_config')->where('key', "default_tax_{$index}_rate")->update(['value' => '']);
            }
        }

        $this->db->table('items_taxes')->where('name', '0')->where('percent', 0)->delete();
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->table('app_config')->select('value')->where('key', $key)->get()->getRow();

        return $row === null ? null : (string) $row->value;
    }

    public function down(): void
    {
        // Restoring a placeholder that blocks item creation is never wanted.
    }
}
