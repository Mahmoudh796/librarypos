<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Periods;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

class PeriodsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private Periods $periods;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->periods = new Periods($this->db);
    }

    public function testFutureMonthCannotBeClosed(): void
    {
        $next = strtotime('first day of next month');

        $this->expectException(AccountingException::class);
        $this->periods->setStatus((int) date('Y', $next), (int) date('n', $next), 'closed', 1);
    }

    public function testRunningMonthCannotBeClosed(): void
    {
        $this->expectException(AccountingException::class);
        $this->periods->setStatus((int) date('Y'), (int) date('n'), 'closed', 1);
    }

    public function testEndedMonthClosesAndReopens(): void
    {
        $previous = strtotime('first day of last month');
        [$year, $month] = [(int) date('Y', $previous), (int) date('n', $previous)];

        $this->periods->setStatus($year, $month, 'closed', 1);
        $this->assertSame('closed', $this->statusOf($year, $month));

        $this->periods->setStatus($year, $month, 'open', 1);
        $this->assertSame('open', $this->statusOf($year, $month));
    }

    public function testYearListMarksWhichMonthsHaveEnded(): void
    {
        $months = $this->periods->year((int) date('Y'));

        $this->assertTrue($months[0]['ended'] || (int) date('n') === 1);
        $this->assertFalse($months[(int) date('n') - 1]['ended']);
    }

    public function testRunningYearCannotBeClosed(): void
    {
        $this->expectException(AccountingException::class);
        $this->periods->closeYear((int) date('Y'), 1);
    }

    private function statusOf(int $year, int $month): string
    {
        return (string) $this->db->table('acc_periods')->where('period_year', $year)->where('period_month', $month)->get()->getRow()->status;
    }
}
