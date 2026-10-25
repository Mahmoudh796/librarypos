<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Ledger;
use App\Libraries\Accounting\Shareholding;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

class ShareholdingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private Shareholding $shareholding;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->shareholding = new Shareholding($this->db);
    }

    protected function tearDown(): void
    {
        $this->assertJournalBalances();
        $this->assertTreasuryReconciles();

        parent::tearDown();
    }

    public function testNewShareholderGetsThreeEquityAccounts(): void
    {
        $id = $this->shareholding->save(['name' => 'Partner ' . uniqid(), 'ownership_percent' => 60, 'profit_share_percent' => 50]);
        $shareholder = $this->shareholding->get($id);

        foreach (['capital_account_id' => '3100', 'drawings_account_id' => '3200', 'current_account_id' => '3300'] as $field => $parentCode) {
            $account = $this->db->table('acc_accounts')->where('account_id', $shareholder->$field)->get()->getRow();
            $parent = $this->db->table('acc_accounts')->where('account_id', $account->parent_id)->get()->getRow();
            $this->assertSame($parentCode, $parent->code);
            $this->assertSame('equity', $account->account_type);
        }

        $this->assertEqualsWithDelta(60.0, (float) $shareholder->ownership_percent, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $shareholder->profit_share_percent, 0.0001, 'Profit share is kept separately from ownership.');
    }

    public function testContributionAndWithdrawalMoveTreasuryAndEquity(): void
    {
        $id = $this->shareholding->save(['name' => 'Partner ' . uniqid(), 'profit_share_percent' => 0]);
        $cash = $this->accountBalance($this->cashAccountId());

        $this->shareholding->recordCash($id, 'contribution', 5000, 1, date('Y-m-d H:i:s'), '', '', 1);
        $this->shareholding->recordCash($id, 'additional_contribution', 1000, 1, date('Y-m-d H:i:s'), '', '', 1);
        $this->shareholding->recordCash($id, 'withdrawal', 750, 1, date('Y-m-d H:i:s'), '', 'personal', 1);

        $this->assertEqualsWithDelta($cash + 5250, $this->accountBalance($this->cashAccountId()), 0.001);
        $this->assertSame(['capital' => 6000.0, 'drawings' => 750.0, 'current' => 0.0, 'equity' => 5250.0], $this->shareholding->position($id));

        $statement = $this->shareholding->statement($id, date('Y-m-d'), date('Y-m-d'));
        $this->assertCount(3, $statement['rows']);
        $this->assertEqualsWithDelta(5250.0, $statement['closing'], 0.001);
    }

    public function testWithdrawalIsNotAnExpense(): void
    {
        $id = $this->shareholding->save(['name' => 'Partner ' . uniqid()]);
        $expensesBefore = (float) $this->db->query("SELECT SUM(l.debit - l.credit) s FROM {$this->db->prefixTable('acc_journal_lines')} l JOIN {$this->db->prefixTable('acc_accounts')} a ON a.account_id = l.account_id WHERE a.account_type = 'expense'")->getRow()->s;

        $this->shareholding->recordCash($id, 'withdrawal', 100, 1, date('Y-m-d H:i:s'), '', '', 1);

        $expensesAfter = (float) $this->db->query("SELECT SUM(l.debit - l.credit) s FROM {$this->db->prefixTable('acc_journal_lines')} l JOIN {$this->db->prefixTable('acc_accounts')} a ON a.account_id = l.account_id WHERE a.account_type = 'expense'")->getRow()->s;
        $this->assertEqualsWithDelta($expensesBefore, $expensesAfter, 0.001);
    }

    public function testProfitAllocationSplitsByProfitShareOncePerYear(): void
    {
        $year = 2021;
        $this->db->table('shareholders')->update(['active' => 0]);
        $a = $this->shareholding->save(['name' => 'A ' . uniqid(), 'ownership_percent' => 50, 'profit_share_percent' => 70]);
        $b = $this->shareholding->save(['name' => 'B ' . uniqid(), 'ownership_percent' => 50, 'profit_share_percent' => 30]);

        $ledger = new Ledger($this->db);
        $ledger->post(['entry_date' => "$year-06-01 10:00:00"], [['account' => 'bank', 'amount' => 1000], ['account' => 'other_income', 'amount' => -1000]]);

        $preview = $this->shareholding->allocationPreview($year);
        $this->assertEqualsWithDelta(1000.0, $preview['net_profit'], 0.001);

        $this->shareholding->allocate($year, 1);

        $this->assertEqualsWithDelta(700.0, $this->shareholding->position($a)['current'], 0.001);
        $this->assertEqualsWithDelta(300.0, $this->shareholding->position($b)['current'], 0.001);

        $this->expectException(AccountingException::class);
        $this->shareholding->allocate($year, 1);
    }

    public function testAllocationRefusedUnlessSharesTotalExactly100(): void
    {
        $year = 2019;
        $this->db->table('shareholders')->update(['active' => 0]);
        $this->shareholding->save(['name' => 'C ' . uniqid(), 'ownership_percent' => 50, 'profit_share_percent' => 60]);

        $ledger = new Ledger($this->db);
        $ledger->post(['entry_date' => "$year-06-01 10:00:00"], [['account' => 'bank', 'amount' => 500], ['account' => 'other_income', 'amount' => -500]]);

        try {
            $this->shareholding->allocate($year, 1);
            $this->fail('An allocation with shares totalling 60% must be refused.');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('60', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('shareholder_transactions')->where('period_year', $year)->countAllResults());
    }

    public function testPercentOutsideRangeIsRefused(): void
    {
        $this->expectException(AccountingException::class);

        $this->shareholding->save(['name' => 'Bad', 'profit_share_percent' => 120]);
    }
}
