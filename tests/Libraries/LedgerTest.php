<?php

namespace Tests\Libraries;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Ledger;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

class LedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private Ledger $ledger;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        $this->ledger = new Ledger($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->table('acc_periods')->where('status', 'closed')->delete();

        parent::tearDown();
    }

    public function testPostWritesBalancedEntryWithNumber(): void
    {
        $entryId = $this->ledger->post(['description' => 'test'], [
            ['account' => 'general_expense', 'amount' => 100],
            ['account' => 'bank', 'amount' => -100],
        ]);

        $entry = $this->db->table('acc_journal_entries')->where('entry_id', $entryId)->get()->getRow();
        $this->assertSame(sprintf('JV-%06d', $entryId), $entry->entry_number);
        $this->assertEqualsWithDelta(100.0, (float) $entry->total, 0.001);
        $this->assertJournalBalances();
    }

    public function testUnbalancedEntryIsRefusedAndNothingIsWritten(): void
    {
        $before = $this->db->table('acc_journal_entries')->countAllResults();

        try {
            $this->ledger->post([], [
                ['account' => 'general_expense', 'amount' => 100],
                ['account' => 'bank', 'amount' => -99.99],
            ]);
            $this->fail('An unbalanced entry must be refused.');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('Unbalanced', $e->getMessage());
        }

        $this->assertSame($before, $this->db->table('acc_journal_entries')->countAllResults());
    }

    public function testNegativeDebitIsRefused(): void
    {
        $this->expectException(AccountingException::class);

        $this->ledger->post([], [
            ['account' => 'general_expense', 'debit' => -5],
            ['account' => 'bank', 'credit' => -5],
        ]);
    }

    public function testGroupAccountCannotBePosted(): void
    {
        $group = $this->ledger->accountByCode('6000');

        $this->expectException(AccountingException::class);

        $this->ledger->post([], [
            ['account_id' => (int) $group->account_id, 'amount' => 10],
            ['account' => 'bank', 'amount' => -10],
        ]);
    }

    public function testClosedPeriodRefusesPosting(): void
    {
        $this->db->table('acc_periods')->insert(['period_year' => 2001, 'period_month' => 3, 'status' => 'closed']);

        $this->expectException(AccountingException::class);

        $this->ledger->post(['entry_date' => '2001-03-15 10:00:00'], [
            ['account' => 'general_expense', 'amount' => 10],
            ['account' => 'bank', 'amount' => -10],
        ]);
    }

    public function testTreasuryLinesWriteMovementsWithRunningBalance(): void
    {
        $cash = $this->cashAccountId();
        $before = (float) ($this->db->table('treasury_movements')->where('treasury_id', 1)->orderBy('movement_id', 'DESC')->limit(1)->get()->getRow()->balance_after ?? 0);

        $this->ledger->post(['source_type' => 'test', 'description' => 'in'], [
            ['account_id' => $cash, 'amount' => 250],
            ['account' => 'other_income', 'amount' => -250],
        ]);
        $this->ledger->post(['source_type' => 'test', 'description' => 'out'], [
            ['account' => 'general_expense', 'amount' => 40],
            ['account_id' => $cash, 'amount' => -40],
        ]);

        $movements = $this->db->table('treasury_movements')->where('treasury_id', 1)->orderBy('movement_id', 'DESC')->limit(2)->get()->getResult();
        $this->assertSame('out', $movements[0]->direction);
        $this->assertEqualsWithDelta($before + 250, (float) $movements[1]->balance_after, 0.001);
        $this->assertEqualsWithDelta($before + 210, (float) $movements[0]->balance_after, 0.001);
        $this->assertEqualsWithDelta((float) $movements[1]->balance_after, (float) $movements[0]->balance_before, 0.001);
        $this->assertTreasuryReconciles();
    }

    public function testReverseMirrorsLinesAndCannotRunTwice(): void
    {
        $entryId = $this->ledger->post(['source_type' => 'test', 'source_id' => 999001], [
            ['account_id' => $this->cashAccountId(), 'amount' => 75],
            ['account' => 'other_income', 'amount' => -75],
        ]);

        $reversalId = $this->ledger->reverse($entryId);

        $this->assertNull($this->ledger->liveEntry('test', 999001));
        $this->assertSame((int) $entryId, (int) $this->db->table('acc_journal_entries')->where('entry_id', $reversalId)->get()->getRow()->reverses_entry_id);
        $this->assertJournalBalances();
        $this->assertTreasuryReconciles();

        $this->expectException(AccountingException::class);
        $this->ledger->reverse($entryId);
    }

    public function testZeroLinesAreDroppedAndPartyIsKept(): void
    {
        $entryId = $this->ledger->post([], [
            ['account' => 'accounts_receivable', 'amount' => 30, 'party_type' => 'customer', 'party_id' => 77],
            ['account' => 'sales_discounts', 'amount' => 0],
            ['account' => 'sales_revenue', 'amount' => -30],
        ]);

        $lines = $this->db->table('acc_journal_lines')->where('entry_id', $entryId)->get()->getResult();
        $this->assertCount(2, $lines);
        $this->assertSame('customer', $lines[0]->party_type);
        $this->assertSame('77', (string) $lines[0]->party_id);
    }

    public function testDimensionsAreResolvedAndCreatedOnFirstUse(): void
    {
        $code = 'test-' . uniqid();
        $entryId = $this->ledger->post([], [
            ['account' => 'general_expense', 'amount' => 12, 'dimensions' => ['cost_center' => 'main_store', 'cashier' => ['code' => $code, 'name' => 'Tester']]],
            ['account' => 'bank', 'amount' => -12],
        ]);

        $line = $this->db->table('acc_journal_lines')->where('entry_id', $entryId)->where('debit >', 0)->get()->getRow();
        $tags = $this->db->table('acc_journal_line_dimensions')->where('line_id', $line->line_id)->countAllResults();
        $this->assertSame(2, $tags);
        $this->assertSame(1, $this->db->table('acc_dimension_values')->where('code', $code)->where('name', 'Tester')->countAllResults());
    }
}
