<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the sales sub-permissions used by the Phase 3 authorization model:
 *
 * - `sales_discount` gates manual discount overrides on a sale line.
 * - `sales_return`   gates completing a return (refund) transaction.
 *
 * Both are granted to the default admin account (person_id = 1) so an
 * existing installation keeps full administrative capability after upgrade.
 * The permission labels are rendered from the existing `Sales.discount` and
 * `Sales.return` language keys, so no new language strings are required.
 */
class Phase3SalesAuthorizationGrants extends Migration
{
    private const PERMISSIONS = [
        ['permission_id' => 'sales_discount', 'module_id' => 'sales'],
        ['permission_id' => 'sales_return',   'module_id' => 'sales'],
    ];

    public function up(): void
    {
        // INSERT IGNORE keeps this safe on a fresh install and on an install
        // where an operator has already created the permission manually.
        $this->db->table('permissions')->ignore(true)->insertBatch(self::PERMISSIONS);

        $grants = [];
        foreach (self::PERMISSIONS as $permission) {
            $grants[] = [
                'permission_id' => $permission['permission_id'],
                'person_id'     => 1,
                'menu_group'    => '--',
            ];
        }

        $this->db->table('grants')->ignore(true)->insertBatch($grants);
    }

    public function down(): void
    {
        $permissionIds = array_column(self::PERMISSIONS, 'permission_id');

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
    }
}
