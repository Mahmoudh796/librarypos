<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\FixedAssets;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

class FixedAssetsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private FixedAssets $assets;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->assets = new FixedAssets($this->db);
        $this->db->table('fixed_assets')->where('status', 'active')->update(['status' => 'disposed']);
    }

    protected function tearDown(): void
    {
        $this->assertJournalBalances();
        $this->assertTreasuryReconciles();

        parent::tearDown();
    }

    private function buy(float $cost = 12000, float $salvage = 0, int $life = 36, string $date = '2030-01-15'): int
    {
        return $this->assets->purchase([
            'name' => 'Cashier PC', 'purchase_cost' => $cost, 'salvage_value' => $salvage, 'useful_life_months' => $life,
            'purchase_date' => $date, 'payment_source' => 'treasury', 'treasury_id' => 1, 'category' => 'Computers',
        ], 1);
    }

    public function testPurchaseIsCapitalisedNotExpensed(): void
    {
        $fixedBefore = $this->mappedBalance('fixed_assets');
        $expenseBefore = $this->mappedBalance('general_expense');

        $this->buy(12000);

        $this->assertEqualsWithDelta($fixedBefore + 12000, $this->mappedBalance('fixed_assets'), 0.001);
        $this->assertEqualsWithDelta($expenseBefore, $this->mappedBalance('general_expense'), 0.001);
    }

    public function testStraightLineStartsTheMonthAfterPurchaseAndRunsOnce(): void
    {
        $assetId = $this->buy(12000, 0, 36, '2030-01-15');

        $this->assertNull($this->assets->depreciate(2030, 1, 1), 'No depreciation in the purchase month.');
        $this->assertNotNull($this->assets->depreciate(2030, 2, 1));
        $this->assertNull($this->assets->depreciate(2030, 2, 1), 'A month is depreciated once.');

        $this->assertEqualsWithDelta(333.33, $this->assets->accumulated($assetId), 0.001);
    }

    public function testDepreciationStopsAtSalvageValue(): void
    {
        $assetId = $this->buy(1000, 100, 3, '2031-01-10');

        foreach ([2, 3, 4, 5, 6] as $month) {
            $this->assets->depreciate(2031, $month, 1);
        }

        $this->assertEqualsWithDelta(900.0, $this->assets->accumulated($assetId), 0.001);
        $register = array_values(array_filter($this->assets->register(), static fn ($a) => (int) $a->asset_id === $assetId))[0];
        $this->assertEqualsWithDelta(100.0, $register->net_book_value, 0.001);
    }

    public function testInvalidAssetIsRefused(): void
    {
        $this->expectException(AccountingException::class);

        $this->buy(500, 500, 12);
    }
}
