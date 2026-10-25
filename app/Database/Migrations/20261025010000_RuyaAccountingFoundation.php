<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Double-entry accounting, treasury, costing history, shareholders, fixed assets,
 * analytical dimensions and the audit trail. See docs/ruya/ACCOUNTING_DESIGN.md.
 *
 * Only new tables and new nullable columns are added; no existing row changes.
 * Existing installations start posting from the migration date; history can be
 * posted later with `php spark accounting:backfill`.
 */
class RuyaAccountingFoundation extends Migration
{
    public function up(): void
    {
        $this->createAccounts();
        $this->createJournal();
        $this->createDimensions();
        $this->createTreasury();
        $this->createCosting();
        $this->createShareholders();
        $this->createFixedAssets();
        $this->createAuditLog();
        $this->extendExistingTables();

        $this->seedChartOfAccounts();
        $this->seedDimensions();
        $this->seedTreasury();
        $this->seedModulesAndPermissions();
        $this->seedSettings();
    }

    private function createAccounts(): void
    {
        $this->forge->addField([
            'account_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 150],
            'name_en'        => ['type' => 'VARCHAR', 'constraint' => 150, 'default' => ''],
            'account_type'   => ['type' => 'VARCHAR', 'constraint' => 16],
            'parent_id'      => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'is_group'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_system'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_treasury'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'party_type'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'active'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('account_id');
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('parent_id');
        $this->forge->createTable('acc_accounts', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'mapping_key' => ['type' => 'VARCHAR', 'constraint' => 50],
            'account_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey('mapping_key');
        $this->forge->addForeignKey('account_id', 'acc_accounts', 'account_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('acc_account_mappings', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'period_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'period_year'  => ['type' => 'SMALLINT', 'constraint' => 4],
            'period_month' => ['type' => 'TINYINT', 'constraint' => 2],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'open'],
            'closed_by'    => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'closed_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('period_id');
        $this->forge->addUniqueKey(['period_year', 'period_month']);
        $this->forge->createTable('acc_periods', true, ['ENGINE' => 'InnoDB']);
    }

    private function createJournal(): void
    {
        $this->forge->addField([
            'entry_id'             => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'entry_number'         => ['type' => 'VARCHAR', 'constraint' => 24],
            'entry_date'           => ['type' => 'DATETIME'],
            'description'          => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'source_type'          => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'source_id'            => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'reference'            => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'reverses_entry_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'reversed_by_entry_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'total'                => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'created_by'           => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('entry_id');
        $this->forge->addUniqueKey('entry_number');
        $this->forge->addKey(['source_type', 'source_id']);
        $this->forge->addKey('entry_date');
        $this->forge->createTable('acc_journal_entries', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'line_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'entry_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'line_order'  => ['type' => 'SMALLINT', 'constraint' => 5, 'default' => 0],
            'account_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'debit'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'credit'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'party_type'  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'party_id'    => ['type' => 'INT', 'constraint' => 10, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('line_id');
        $this->forge->addKey('entry_id');
        $this->forge->addKey('account_id');
        $this->forge->addKey(['party_type', 'party_id']);
        $this->forge->addForeignKey('entry_id', 'acc_journal_entries', 'entry_id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('account_id', 'acc_accounts', 'account_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('acc_journal_lines', true, ['ENGINE' => 'InnoDB']);
    }

    private function createDimensions(): void
    {
        $this->forge->addField([
            'dimension_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'code'         => ['type' => 'VARCHAR', 'constraint' => 30],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'name_en'      => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'is_system'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('dimension_id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('acc_dimensions', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'value_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'dimension_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'code'         => ['type' => 'VARCHAR', 'constraint' => 60],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 150],
            'active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('value_id');
        $this->forge->addUniqueKey(['dimension_id', 'code']);
        $this->forge->addForeignKey('dimension_id', 'acc_dimensions', 'dimension_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('acc_dimension_values', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'line_id'      => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'dimension_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'value_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['line_id', 'dimension_id']);
        $this->forge->addKey(['dimension_id', 'value_id']);
        $this->forge->addForeignKey('line_id', 'acc_journal_lines', 'line_id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('value_id', 'acc_dimension_values', 'value_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('acc_journal_line_dimensions', true, ['ENGINE' => 'InnoDB']);
    }

    private function createTreasury(): void
    {
        $this->forge->addField([
            'treasury_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'kind'        => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'cash'],
            'account_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'is_default'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('treasury_id');
        $this->forge->addUniqueKey('account_id');
        $this->forge->addForeignKey('account_id', 'acc_accounts', 'account_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('treasuries', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'voucher_id'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'voucher_number'     => ['type' => 'VARCHAR', 'constraint' => 24],
            'voucher_type'       => ['type' => 'VARCHAR', 'constraint' => 30],
            'voucher_date'       => ['type' => 'DATETIME'],
            'treasury_id'        => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'target_treasury_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'amount'             => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'party_type'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'party_id'           => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'account_id'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'description'        => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'reference'          => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'cost_center_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'posted'],
            'employee_id'        => ['type' => 'INT', 'constraint' => 10],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('voucher_id');
        $this->forge->addUniqueKey('voucher_number');
        $this->forge->addKey(['party_type', 'party_id']);
        $this->forge->addForeignKey('treasury_id', 'treasuries', 'treasury_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('treasury_vouchers', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'movement_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'treasury_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'movement_time'  => ['type' => 'DATETIME'],
            'direction'      => ['type' => 'VARCHAR', 'constraint' => 3],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'balance_before' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'balance_after'  => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'movement_type'  => ['type' => 'VARCHAR', 'constraint' => 30],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'cash'],
            'source_type'    => ['type' => 'VARCHAR', 'constraint' => 30],
            'source_id'      => ['type' => 'INT', 'constraint' => 10],
            'reference'      => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'description'    => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'party_type'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'party_id'       => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'employee_id'    => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'entry_id'       => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('movement_id');
        $this->forge->addKey(['treasury_id', 'movement_id']);
        $this->forge->addKey(['treasury_id', 'movement_time']);
        $this->forge->addKey(['source_type', 'source_id']);
        $this->forge->addForeignKey('treasury_id', 'treasuries', 'treasury_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('treasury_movements', true, ['ENGINE' => 'InnoDB']);
    }

    private function createCosting(): void
    {
        $this->forge->addField([
            'history_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'item_id'      => ['type' => 'INT', 'constraint' => 10],
            'changed_at'   => ['type' => 'DATETIME'],
            'source_type'  => ['type' => 'VARCHAR', 'constraint' => 30],
            'source_id'    => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'qty_before'   => ['type' => 'DECIMAL', 'constraint' => '15,3'],
            'cost_before'  => ['type' => 'DECIMAL', 'constraint' => '15,4'],
            'qty_change'   => ['type' => 'DECIMAL', 'constraint' => '15,3', 'default' => 0],
            'unit_cost_in' => ['type' => 'DECIMAL', 'constraint' => '15,4', 'null' => true],
            'cost_after'   => ['type' => 'DECIMAL', 'constraint' => '15,4'],
            'employee_id'  => ['type' => 'INT', 'constraint' => 10, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('history_id');
        $this->forge->addKey(['item_id', 'changed_at']);
        $this->forge->createTable('item_cost_history', true, ['ENGINE' => 'InnoDB']);
    }

    private function createShareholders(): void
    {
        $this->forge->addField([
            'shareholder_id'       => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'code'                 => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'                 => ['type' => 'VARCHAR', 'constraint' => 150],
            'ownership_percent'    => ['type' => 'DECIMAL', 'constraint' => '7,4', 'default' => 0],
            'profit_share_percent' => ['type' => 'DECIMAL', 'constraint' => '7,4', 'default' => 0],
            'capital_account_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'drawings_account_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'current_account_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'phone'                => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => ''],
            'notes'                => ['type' => 'TEXT', 'null' => true],
            'active'               => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('shareholder_id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('shareholders', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'transaction_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'shareholder_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'transaction_type' => ['type' => 'VARCHAR', 'constraint' => 30],
            'transaction_date' => ['type' => 'DATETIME'],
            'amount'           => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'treasury_id'      => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'period_year'      => ['type' => 'SMALLINT', 'constraint' => 4, 'null' => true],
            'reference'        => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'notes'            => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'posted'],
            'employee_id'      => ['type' => 'INT', 'constraint' => 10],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('transaction_id');
        $this->forge->addKey('shareholder_id');
        $this->forge->addForeignKey('shareholder_id', 'shareholders', 'shareholder_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('shareholder_transactions', true, ['ENGINE' => 'InnoDB']);
    }

    private function createFixedAssets(): void
    {
        $this->forge->addField([
            'asset_id'           => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'code'               => ['type' => 'VARCHAR', 'constraint' => 30],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 150],
            'category'           => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'purchase_date'      => ['type' => 'DATE'],
            'purchase_cost'      => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'salvage_value'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'useful_life_months' => ['type' => 'SMALLINT', 'constraint' => 5],
            'depreciation_method' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'straight_line'],
            'supplier_id'        => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'payment_source'     => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'treasury'],
            'treasury_id'        => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'location'           => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'active'],
            'notes'              => ['type' => 'TEXT', 'null' => true],
            'employee_id'        => ['type' => 'INT', 'constraint' => 10],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('asset_id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('fixed_assets', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'depreciation_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'asset_id'        => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'period_year'     => ['type' => 'SMALLINT', 'constraint' => 4],
            'period_month'    => ['type' => 'TINYINT', 'constraint' => 2],
            'amount'          => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'entry_id'        => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('depreciation_id');
        $this->forge->addUniqueKey(['asset_id', 'period_year', 'period_month']);
        $this->forge->addForeignKey('asset_id', 'fixed_assets', 'asset_id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('fixed_asset_depreciation', true, ['ENGINE' => 'InnoDB']);
    }

    private function createAuditLog(): void
    {
        $this->forge->addField([
            'audit_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'logged_at'   => ['type' => 'DATETIME'],
            'employee_id' => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            'action'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'entity_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'old_values'  => ['type' => 'TEXT', 'null' => true],
            'new_values'  => ['type' => 'TEXT', 'null' => true],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => ''],
        ]);
        $this->forge->addPrimaryKey('audit_id');
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey('logged_at');
        $this->forge->createTable('audit_log', true, ['ENGINE' => 'InnoDB']);
    }

    private function extendExistingTables(): void
    {
        $this->addColumnIfMissing('expense_categories', 'account_id', ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true]);
        $this->addColumnIfMissing('expenses', 'treasury_id', ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true]);
        $this->addColumnIfMissing('expenses', 'cost_center_id', ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true]);
        $this->addColumnIfMissing('expenses', 'reference', ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true]);
        $this->addColumnIfMissing('cash_up', 'treasury_id', ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true]);
        $this->addColumnIfMissing('cash_up', 'approved_by', ['type' => 'INT', 'constraint' => 10, 'null' => true]);
        $this->addColumnIfMissing('cash_up', 'expected_amount_cash', ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true]);
        $this->addColumnIfMissing('cash_up', 'difference_cash', ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true]);
    }

    private function addColumnIfMissing(string $table, string $column, array $definition): void
    {
        if (!$this->db->fieldExists($column, $table)) {
            $this->forge->addColumn($table, [$column => $definition]);
        }
    }

    /**
     * [code, Arabic name, English name, type, parent code, is_group, mapping key, flags]
     */
    private function chart(): array
    {
        return [
            ['1000', 'الأصول', 'Assets', 'asset', null, 1, null, []],
            ['1100', 'النقدية والخزائن', 'Cash and treasuries', 'asset', '1000', 1, null, []],
            ['1110', 'الخزنة الرئيسية', 'Main cash box', 'asset', '1100', 0, null, ['treasury' => 1]],
            ['1120', 'البنك', 'Bank', 'asset', '1100', 0, 'bank', ['treasury' => 1]],
            ['1130', 'مدفوعات البطاقات تحت التحصيل', 'Card payments clearing', 'asset', '1100', 0, 'card_clearing', []],
            ['1140', 'شيكات تحت التحصيل', 'Cheques receivable', 'asset', '1100', 0, 'cheques_receivable', []],
            ['1200', 'العملاء (المدينون)', 'Accounts receivable', 'asset', '1000', 0, 'accounts_receivable', ['party' => 'customer']],
            ['1300', 'المخزون', 'Inventory', 'asset', '1000', 0, 'inventory', []],
            ['1500', 'الأصول الثابتة', 'Fixed assets', 'asset', '1000', 0, 'fixed_assets', []],
            ['1590', 'مجمع الإهلاك', 'Accumulated depreciation', 'asset', '1000', 0, 'accumulated_depreciation', []],
            ['2000', 'الخصوم', 'Liabilities', 'liability', null, 1, null, []],
            ['2100', 'الموردون (الدائنون)', 'Accounts payable', 'liability', '2000', 0, 'accounts_payable', ['party' => 'supplier']],
            ['2200', 'ضرائب مستحقة', 'Tax payable', 'liability', '2000', 0, 'tax_payable', []],
            ['2300', 'أرصدة كروت الهدايا', 'Gift card liability', 'liability', '2000', 0, 'giftcard_liability', []],
            ['2400', 'نقاط مكافآت العملاء', 'Reward points liability', 'liability', '2000', 0, 'rewards_liability', []],
            ['3000', 'حقوق الملكية', 'Equity', 'equity', null, 1, null, []],
            ['3100', 'رأس المال', 'Capital', 'equity', '3000', 1, null, []],
            ['3200', 'المسحوبات الشخصية', 'Drawings', 'equity', '3000', 1, null, []],
            ['3300', 'جاري الشركاء', 'Partner current accounts', 'equity', '3000', 1, null, []],
            ['3400', 'الأرباح المحتجزة', 'Retained earnings', 'equity', '3000', 0, 'retained_earnings', []],
            ['3900', 'أرصدة افتتاحية', 'Opening balance equity', 'equity', '3000', 0, 'opening_balance', []],
            ['4000', 'الإيرادات', 'Revenue', 'revenue', null, 1, null, []],
            ['4100', 'المبيعات', 'Sales', 'revenue', '4000', 0, 'sales_revenue', []],
            ['4150', 'خصومات المبيعات', 'Sales discounts', 'revenue', '4000', 0, 'sales_discounts', []],
            ['4900', 'إيرادات أخرى', 'Other income', 'revenue', '4000', 0, 'other_income', []],
            ['5000', 'تكلفة المبيعات', 'Cost of sales', 'expense', null, 1, null, []],
            ['5100', 'تكلفة البضاعة المباعة', 'Cost of goods sold', 'expense', '5000', 0, 'cogs', []],
            ['5150', 'فروق تكلفة المخزون', 'Inventory cost variance', 'expense', '5000', 0, 'cost_variance', []],
            ['5200', 'عجز وزيادة المخزون', 'Inventory adjustments', 'expense', '5000', 0, 'inventory_adjustment', []],
            ['6000', 'المصروفات', 'Operating expenses', 'expense', null, 1, null, []],
            ['6100', 'مصروفات عامة', 'General expenses', 'expense', '6000', 0, 'general_expense', []],
            ['6200', 'الإهلاك', 'Depreciation expense', 'expense', '6000', 0, 'depreciation_expense', []],
            ['6300', 'عجز وزيادة الخزنة', 'Cash over and short', 'expense', '6000', 0, 'cash_over_short', []],
            ['6900', 'مصروفات أخرى', 'Other expenses', 'expense', '6000', 0, 'other_expense', []],
        ];
    }

    private function seedChartOfAccounts(): void
    {
        $now = date('Y-m-d H:i:s');
        $idByCode = [];

        foreach ($this->chart() as [$code, $name, $nameEn, $type, $parentCode, $isGroup, $mapping, $flags]) {
            $existing = $this->db->table('acc_accounts')->where('code', $code)->get()->getRow();

            if ($existing === null) {
                $this->db->table('acc_accounts')->insert([
                    'code'         => $code,
                    'name'         => $name,
                    'name_en'      => $nameEn,
                    'account_type' => $type,
                    'parent_id'    => $parentCode === null ? null : $idByCode[$parentCode],
                    'is_group'     => $isGroup,
                    'is_system'    => 1,
                    'is_treasury'  => $flags['treasury'] ?? 0,
                    'party_type'   => $flags['party'] ?? null,
                    'active'       => 1,
                    'created_at'   => $now,
                ]);
                $idByCode[$code] = (int) $this->db->insertID();
            } else {
                $idByCode[$code] = (int) $existing->account_id;
            }

            if ($mapping !== null) {
                $this->db->table('acc_account_mappings')->ignore(true)->insert(['mapping_key' => $mapping, 'account_id' => $idByCode[$code]]);
            }
        }

        $this->db->table('acc_account_mappings')->ignore(true)->insertBatch([
            ['mapping_key' => 'capital_parent', 'account_id' => $idByCode['3100']],
            ['mapping_key' => 'drawings_parent', 'account_id' => $idByCode['3200']],
            ['mapping_key' => 'partner_current_parent', 'account_id' => $idByCode['3300']],
        ]);
    }

    private function seedDimensions(): void
    {
        $dimensions = [
            ['cost_center', 'مركز التكلفة', 'Cost center'],
            ['profit_center', 'مركز الربحية', 'Profit center'],
            ['cashier', 'الكاشير', 'Cashier'],
            ['sales_channel', 'قناة البيع', 'Sales channel'],
            ['product_category', 'تصنيف المنتج', 'Product category'],
        ];

        foreach ($dimensions as [$code, $name, $nameEn]) {
            $this->db->table('acc_dimensions')->ignore(true)->insert(['code' => $code, 'name' => $name, 'name_en' => $nameEn, 'is_system' => 1, 'active' => 1]);
        }

        $ids = array_column($this->db->table('acc_dimensions')->get()->getResultArray(), 'dimension_id', 'code');

        $values = [
            [$ids['cost_center'], 'main_store', 'المحل الرئيسي'],
            [$ids['cost_center'], 'administration', 'الإدارة'],
            [$ids['profit_center'], 'main_store', 'المحل الرئيسي'],
            [$ids['sales_channel'], 'pos', 'نقطة البيع'],
            [$ids['sales_channel'], 'invoice', 'فاتورة'],
        ];

        foreach ($values as [$dimensionId, $code, $name]) {
            $this->db->table('acc_dimension_values')->ignore(true)->insert(['dimension_id' => $dimensionId, 'code' => $code, 'name' => $name, 'active' => 1]);
        }
    }

    private function seedTreasury(): void
    {
        if ($this->db->table('treasuries')->countAllResults() > 0) {
            return;
        }

        $cash = $this->db->table('acc_accounts')->where('code', '1110')->get()->getRow();
        $bank = $this->db->table('acc_accounts')->where('code', '1120')->get()->getRow();

        $this->db->table('treasuries')->insertBatch([
            ['name' => 'الخزنة الرئيسية', 'kind' => 'cash', 'account_id' => $cash->account_id, 'is_default' => 1, 'active' => 1],
            ['name' => 'البنك', 'kind' => 'bank', 'account_id' => $bank->account_id, 'is_default' => 0, 'active' => 1],
        ]);
    }

    private function seedModulesAndPermissions(): void
    {
        $this->db->table('modules')->ignore(true)->insertBatch([
            ['name_lang_key' => 'module_treasury', 'desc_lang_key' => 'module_treasury_desc', 'sort' => 112, 'module_id' => 'treasury'],
            ['name_lang_key' => 'module_accounting', 'desc_lang_key' => 'module_accounting_desc', 'sort' => 115, 'module_id' => 'accounting'],
        ]);

        $permissions = [
            ['treasury', 'treasury'],
            ['treasury_view', 'treasury'],
            ['treasury_vouchers', 'treasury'],
            ['treasury_reverse', 'treasury'],
            ['accounting', 'accounting'],
            ['accounting_view', 'accounting'],
            ['accounting_journal', 'accounting'],
            ['accounting_periods', 'accounting'],
            ['accounting_setup', 'accounting'],
            ['accounting_shareholders', 'accounting'],
            ['accounting_assets', 'accounting'],
        ];

        foreach ($permissions as [$permissionId, $moduleId]) {
            $this->db->table('permissions')->ignore(true)->insert(['permission_id' => $permissionId, 'module_id' => $moduleId]);
        }

        // Administrators (holders of the config grant) and the default admin get every new grant.
        $admins = array_map('intval', array_column(
            $this->db->table('grants')->select('person_id')->where('permission_id', 'config')->get()->getResultArray(),
            'person_id'
        ));
        $admins = array_values(array_unique(array_merge([1], $admins)));
        $existing = array_map('intval', array_column(
            $this->db->table('employees')->select('person_id')->whereIn('person_id', $admins)->get()->getResultArray(),
            'person_id'
        ));

        foreach ($existing as $personId) {
            foreach ($permissions as [$permissionId, $moduleId]) {
                $this->db->table('grants')->ignore(true)->insert([
                    'permission_id' => $permissionId,
                    'person_id'     => $personId,
                    'menu_group'    => $permissionId === $moduleId ? 'office' : '--',
                ]);
            }
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            'accounting_enabled'                => '1',
            'accounting_start_date'             => date('Y-m-d'),
            // Moving weighted average is the approved costing policy.
            'receiving_calculate_average_price' => '1',
        ];

        foreach ($settings as $key => $value) {
            $exists = $this->db->table('app_config')->where('key', $key)->countAllResults() > 0;

            if (!$exists) {
                $this->db->table('app_config')->insert(['key' => $key, 'value' => $value]);
            } elseif ($key === 'receiving_calculate_average_price') {
                $this->db->table('app_config')->where('key', $key)->update(['value' => $value]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'audit_log', 'fixed_asset_depreciation', 'fixed_assets', 'shareholder_transactions', 'shareholders',
            'item_cost_history', 'treasury_movements', 'treasury_vouchers', 'treasuries',
            'acc_journal_line_dimensions', 'acc_dimension_values', 'acc_dimensions',
            'acc_journal_lines', 'acc_journal_entries', 'acc_periods', 'acc_account_mappings', 'acc_accounts',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }

        $this->db->table('grants')->like('permission_id', 'treasury', 'after')->delete();
        $this->db->table('grants')->like('permission_id', 'accounting', 'after')->delete();
        $this->db->table('permissions')->whereIn('module_id', ['treasury', 'accounting'])->delete();
        $this->db->table('modules')->whereIn('module_id', ['treasury', 'accounting'])->delete();
        $this->db->table('app_config')->whereIn('key', ['accounting_enabled', 'accounting_start_date'])->delete();
    }
}
