<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\Ledger;
use App\Libraries\Accounting\Periods;
use App\Libraries\Accounting\Reports;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

class ReportsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private Reports $reports;
    private Ledger $ledger;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->reports = new Reports($this->db);
        $this->ledger = new Ledger($this->db);
    }

    /**
     * Posts a small, self-contained year: capital, stock purchase, a sale with
     * COGS, an expense and depreciation, all dated in an otherwise empty year.
     */
    private function postYear(int $year): void
    {
        $d = static fn (string $md): string => "$year-$md 10:00:00";
        $this->ledger->post(['entry_date' => $d('01-02')], [['account_id' => $this->cashAccountId(), 'amount' => 10000], ['account' => 'opening_balance', 'amount' => -10000]]);
        $this->ledger->post(['entry_date' => $d('02-01')], [['account' => 'inventory', 'amount' => 4000], ['account' => 'accounts_payable', 'amount' => -4000, 'party_type' => 'supplier', 'party_id' => 5]]);
        $this->ledger->post(['entry_date' => $d('03-01')], [
            ['account_id' => $this->cashAccountId(), 'amount' => 2700],
            ['account' => 'sales_discounts', 'amount' => 300],
            ['account' => 'sales_revenue', 'amount' => -3000],
            ['account' => 'cogs', 'amount' => 1800],
            ['account' => 'inventory', 'amount' => -1800],
        ]);
        $this->ledger->post(['entry_date' => $d('04-01')], [['account' => 'general_expense', 'amount' => 250], ['account_id' => $this->cashAccountId(), 'amount' => -250]]);
        $this->ledger->post(['entry_date' => $d('05-01')], [['account' => 'depreciation_expense', 'amount' => 50], ['account' => 'accumulated_depreciation', 'amount' => -50]]);
        $this->ledger->post(['entry_date' => $d('06-01')], [['account_id' => $this->cashAccountId(), 'amount' => 20], ['account' => 'other_income', 'amount' => -20]]);
    }

    public function testIncomeStatementSections(): void
    {
        $this->postYear(2011);

        $pl = $this->reports->incomeStatement('2011-01-01', '2011-12-31');

        $this->assertEqualsWithDelta(2700.0, $pl['totals']['revenue'], 0.001, 'Net sales = 3000 - 300 discount.');
        $this->assertEqualsWithDelta(1800.0, $pl['totals']['cost_of_sales'], 0.001);
        $this->assertEqualsWithDelta(900.0, $pl['totals']['gross_profit'], 0.001);
        $this->assertEqualsWithDelta(250.0, $pl['totals']['operating_expenses'], 0.001);
        $this->assertEqualsWithDelta(50.0, $pl['totals']['depreciation'], 0.001);
        $this->assertEqualsWithDelta(20.0, $pl['totals']['other_income'], 0.001);
        $this->assertEqualsWithDelta(620.0, $pl['totals']['net_profit'], 0.001);
    }

    public function testBalanceSheetBalancesAndShowsCurrentProfit(): void
    {
        $this->postYear(2012);

        $sheet = $this->reports->balanceSheet(date('Y-m-d'));

        $this->assertEqualsWithDelta(0.0, $sheet['difference'], 0.001, 'Assets must equal liabilities plus equity.');
    }

    public function testYearCloseMovesProfitToRetainedEarningsButKeepsPl(): void
    {
        $this->postYear(2013);
        $retainedBefore = $this->mappedBalance('retained_earnings');

        (new Periods($this->db))->closeYear(2013, 1);

        $this->assertEqualsWithDelta($retainedBefore - 620.0, $this->mappedBalance('retained_earnings'), 0.001);
        $this->assertEqualsWithDelta(620.0, $this->reports->incomeStatement('2013-01-01', '2013-12-31')['totals']['net_profit'], 0.001, 'The closing entry is not part of the P&L.');
        $this->assertEqualsWithDelta(0.0, $this->reports->balanceSheet('2013-12-31')['difference'], 0.001);

        // Re-closing replaces the entry instead of doubling it.
        (new Periods($this->db))->closeYear(2013, 1);
        $this->assertEqualsWithDelta($retainedBefore - 620.0, $this->mappedBalance('retained_earnings'), 0.001);
    }

    public function testTrialBalanceTotalsMatch(): void
    {
        $this->postYear(2014);

        $rows = $this->reports->trialBalance('2014-01-01', '2014-12-31');

        $this->assertEqualsWithDelta(array_sum(array_column($rows, 'debit')), array_sum(array_column($rows, 'credit')), 0.001);
        $this->assertEqualsWithDelta(0.0, round(array_sum(array_column($rows, 'closing')), 2), 0.001);
    }

    public function testLedgerRunningBalanceAndPartyStatement(): void
    {
        $supplierId = 660000 + random_int(1, 9999);
        $this->ledger->post(['entry_date' => '2015-01-10 09:00:00'], [['account' => 'inventory', 'amount' => 100], ['account' => 'accounts_payable', 'amount' => -100, 'party_type' => 'supplier', 'party_id' => $supplierId]]);
        $this->ledger->post(['entry_date' => '2015-02-10 09:00:00'], [['account' => 'accounts_payable', 'amount' => 60, 'party_type' => 'supplier', 'party_id' => $supplierId], ['account' => 'bank', 'amount' => -60]]);

        $statement = $this->reports->partyStatement('supplier', $supplierId, '2015-01-01', '2015-12-31');

        $this->assertSame(0.0, $statement['opening']);
        $this->assertCount(2, $statement['rows']);
        $this->assertEqualsWithDelta(-100.0, $statement['rows'][0]['balance'], 0.001);
        $this->assertEqualsWithDelta(-40.0, $statement['rows'][1]['balance'], 0.001);
        $this->assertEqualsWithDelta(-40.0, $statement['closing'], 0.001);
    }

    public function testAgingBucketsOutstandingReceivables(): void
    {
        $customerId = 550000 + random_int(1, 9999);
        $this->ledger->post(['entry_date' => '2016-01-01 09:00:00'], [['account' => 'accounts_receivable', 'amount' => 100, 'party_type' => 'customer', 'party_id' => $customerId], ['account' => 'sales_revenue', 'amount' => -100]]);
        $this->ledger->post(['entry_date' => '2016-03-20 09:00:00'], [['account' => 'accounts_receivable', 'amount' => 50, 'party_type' => 'customer', 'party_id' => $customerId], ['account' => 'sales_revenue', 'amount' => -50]]);
        $this->ledger->post(['entry_date' => '2016-03-25 09:00:00'], [['account_id' => $this->cashAccountId(), 'amount' => 30], ['account' => 'accounts_receivable', 'amount' => -30, 'party_type' => 'customer', 'party_id' => $customerId]]);

        $row = array_values(array_filter($this->reports->aging('customer', '2016-03-31'), static fn ($r) => $r['party_id'] === $customerId))[0];

        $this->assertEqualsWithDelta(120.0, $row['balance'], 0.001);
        $this->assertEqualsWithDelta(50.0, $row['current'], 0.001);
        $this->assertEqualsWithDelta(70.0, $row['d60'], 0.001, 'The oldest charge is settled first; 70 of it is 90 days old.');
    }
}
