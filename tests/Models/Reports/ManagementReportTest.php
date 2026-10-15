<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Management_report;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Management KPIs must agree with the tabular reports and must never let
 * returns, canceled sales or suspended quotes inflate (or deflate) the
 * numbers:
 *
 *  - completed POS/invoice lines make up gross sales;
 *  - completed returns are reported separately as a positive magnitude;
 *  - canceled sales and suspended quotes/work orders are excluded;
 *  - cost is the persisted historical line cost, not the item's current cost;
 *  - location filters are honoured.
 */
class ManagementReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const LOCATION_A = 1;
    private const LOCATION_B = 2;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect();
        $db->table('sales_items_taxes')->emptyTable();
        $db->table('sales_items')->emptyTable();
        $db->table('sales_payments')->emptyTable();
        $db->table('sales')->emptyTable();
    }

    public function testGrossNetReturnsAndHistoricalCost(): void
    {
        $itemId = $this->createItem(cost: 4, price: 10);

        // Completed sale: 2 x 10 at a historical cost of 4 each.
        $this->createSale(date('Y-m-d 10:00:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 2, 'unit' => 10, 'cost' => 4],
        ]);

        // Completed return of 1 unit -> negative quantity, same historical cost.
        $this->createSale(date('Y-m-d 11:00:00'), SALE_TYPE_RETURN, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => -1, 'unit' => 10, 'cost' => 4],
        ]);

        // Restate the item's current cost to prove KPIs use the historical cost.
        Database::connect()->table('items')->where('item_id', $itemId)->update(['cost_price' => 99]);

        $kpi = model(Management_report::class)->getSalesTotals(date('Y-m-d'), date('Y-m-d'));

        $this->assertEqualsWithDelta(20.0, $kpi['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(10.0, $kpi['returns'], 0.001);
        $this->assertEqualsWithDelta(10.0, $kpi['net_sales'], 0.001);
        $this->assertEqualsWithDelta(4.0, $kpi['cost'], 0.001);
        $this->assertEqualsWithDelta(6.0, $kpi['gross_profit'], 0.001);
        $this->assertSame(1, $kpi['transaction_count']);
        $this->assertSame(1, $kpi['return_count']);
        $this->assertEqualsWithDelta(20.0, $kpi['average_transaction'], 0.001);
    }

    public function testCanceledAndSuspendedAreExcluded(): void
    {
        $itemId = $this->createItem(cost: 1, price: 10);

        $this->createSale(date('Y-m-d 09:00:00'), SALE_TYPE_POS, CANCELED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 5, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 09:30:00'), SALE_TYPE_QUOTE, SUSPENDED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 7, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 09:45:00'), SALE_TYPE_WORK_ORDER, SUSPENDED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 3, 'unit' => 10, 'cost' => 1],
        ]);

        $kpi = model(Management_report::class)->getSalesTotals(date('Y-m-d'), date('Y-m-d'));

        $this->assertEqualsWithDelta(0.0, $kpi['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $kpi['returns'], 0.001);
        $this->assertEqualsWithDelta(0.0, $kpi['net_sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $kpi['gross_profit'], 0.001);
        $this->assertSame(0, $kpi['transaction_count']);
        $this->assertSame(0, $kpi['return_count']);
    }

    public function testDiscountsAreSplitOutOfGrossSales(): void
    {
        $itemId = $this->createItem(cost: 2, price: 20);

        // 25% line discount: 20 - 5 = 15 net, 5 discount.
        $this->createSale(date('Y-m-d 12:00:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 1, 'unit' => 20, 'cost' => 2, 'discount' => 25, 'discount_type' => PERCENT],
        ]);
        // Fixed 3.00 per-unit discount on 2 x 10: 2 * (10 - 3) = 14 net, 6 discount.
        $this->createSale(date('Y-m-d 12:30:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 2, 'unit' => 10, 'cost' => 2, 'discount' => 3, 'discount_type' => FIXED],
        ]);

        $kpi = model(Management_report::class)->getSalesTotals(date('Y-m-d'), date('Y-m-d'));

        $this->assertEqualsWithDelta(29.0, $kpi['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(11.0, $kpi['discounts'], 0.001);
        $this->assertEqualsWithDelta(29.0, $kpi['net_sales'], 0.001);
        $this->assertSame(2, $kpi['transaction_count']);
    }

    public function testLocationFilterIsHonoured(): void
    {
        $itemId = $this->createItem(cost: 1, price: 10);
        $this->createLocation(self::LOCATION_B);

        $this->createSale(date('Y-m-d 08:00:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 2, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 08:30:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_B, [
            ['item_id' => $itemId, 'qty' => 5, 'unit' => 10, 'cost' => 1],
        ]);

        $report = model(Management_report::class);

        $locationA = $report->getSalesTotals(date('Y-m-d'), date('Y-m-d'), [self::LOCATION_A]);
        $locationB = $report->getSalesTotals(date('Y-m-d'), date('Y-m-d'), [self::LOCATION_B]);
        $noLocation = $report->getSalesTotals(date('Y-m-d'), date('Y-m-d'), []);
        $all = $report->getSalesTotals(date('Y-m-d'), date('Y-m-d'), null);

        $this->assertEqualsWithDelta(20.0, $locationA['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(50.0, $locationB['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $noLocation['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(70.0, $all['gross_sales'], 0.001);
    }

    public function testDailyTrendIsOrderedAndNetsReturns(): void
    {
        $itemId = $this->createItem(cost: 1, price: 10);

        $this->createSale(date('Y-m-d 00:10:00', strtotime('-1 day')), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 3, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 00:10:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => 1, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 00:20:00'), SALE_TYPE_RETURN, COMPLETED, self::LOCATION_A, [
            ['item_id' => $itemId, 'qty' => -1, 'unit' => 10, 'cost' => 1],
        ]);

        $trend = model(Management_report::class)->getDailyTrend(
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d'),
            [self::LOCATION_A]
        );

        $this->assertCount(2, $trend);
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), $trend[0]['date']);
        $this->assertSame(date('Y-m-d'), $trend[1]['date']);
        $this->assertEqualsWithDelta(30.0, $trend[0]['net_sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $trend[1]['net_sales'], 0.001);
    }

    public function testInventorySnapshotSeparatesLowFromOutOfStock(): void
    {
        $lowItem = $this->createItem(cost: 2, price: 5, reorderLevel: 10);
        $outItem = $this->createItem(cost: 3, price: 7, reorderLevel: 5);
        $healthyItem = $this->createItem(cost: 4, price: 9, reorderLevel: 5);

        $this->setStock($lowItem, self::LOCATION_A, 4);
        $this->setStock($outItem, self::LOCATION_A, 0);
        $this->setStock($healthyItem, self::LOCATION_A, 20);

        $snapshot = model(Management_report::class)->getInventorySnapshot([self::LOCATION_A]);

        $this->assertSame(1, $snapshot['low_stock_count']);
        $this->assertSame(1, $snapshot['out_of_stock_count']);
        // stock value: 4*2 + 0*3 + 20*4 = 88 cost; 4*5 + 0*7 + 20*9 = 200 retail.
        $this->assertEqualsWithDelta(88.0, $snapshot['cost_value'], 0.001);
        $this->assertEqualsWithDelta(200.0, $snapshot['retail_value'], 0.001);
    }

    public function testTopItemsNetsReturnsAndRanksByRevenue(): void
    {
        $a = $this->createItem(cost: 1, price: 10);
        $b = $this->createItem(cost: 1, price: 10);

        $this->createSale(date('Y-m-d 07:00:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $a, 'qty' => 4, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 07:10:00'), SALE_TYPE_RETURN, COMPLETED, self::LOCATION_A, [
            ['item_id' => $a, 'qty' => -1, 'unit' => 10, 'cost' => 1],
        ]);
        $this->createSale(date('Y-m-d 07:20:00'), SALE_TYPE_POS, COMPLETED, self::LOCATION_A, [
            ['item_id' => $b, 'qty' => 2, 'unit' => 10, 'cost' => 1],
        ]);

        $top = model(Management_report::class)->getTopItems(date('Y-m-d'), date('Y-m-d'), [self::LOCATION_A], 10);

        $this->assertCount(2, $top);
        $this->assertSame($a, $top[0]['item_id']);
        $this->assertEqualsWithDelta(3.0, $top[0]['quantity_sold'], 0.001);
        $this->assertEqualsWithDelta(30.0, $top[0]['revenue'], 0.001);
        $this->assertSame($b, $top[1]['item_id']);
    }

    private function createLocation(int $locationId): void
    {
        $db = Database::connect();

        if ($db->table('stock_locations')->where('location_id', $locationId)->countAllResults() > 0) {
            return;
        }

        $db->table('stock_locations')->insert([
            'location_id'   => $locationId,
            'location_name' => "Management Location $locationId",
            'deleted'       => 0,
        ]);
    }

    private function createItem(float $cost, float $price, float $reorderLevel = 0): int
    {
        $db = Database::connect();
        $unique = uniqid();

        $db->table('items')->insert([
            'name'          => "Management Item $unique",
            'category'      => 'Test',
            'description'   => 'Management test item',
            'cost_price'    => $cost,
            'unit_price'    => $price,
            'reorder_level' => $reorderLevel,
            'item_number'   => "MGMT-$unique",
            'deleted'       => 0,
            'stock_type'    => HAS_STOCK,
        ]);

        return (int) $db->insertID();
    }

    /**
     * @param array<int, array{item_id: int, qty: float|int, unit: float|int, cost: float|int, discount?: float|int, discount_type?: int, tax?: float|int, tax_type?: int}> $lines
     */
    private function createSale(string $saleTime, int $saleType, int $saleStatus, int $locationId, array $lines): int
    {
        $db = Database::connect();

        $db->table('sales')->insert([
            'sale_time'    => $saleTime,
            'employee_id'  => 1,
            'comment'      => 'management test',
            'sale_status'  => $saleStatus,
            'sale_type'    => $saleType,
        ]);
        $saleId = (int) $db->insertID();

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            $db->table('sales_items')->insert([
                'sale_id'            => $saleId,
                'item_id'            => $line['item_id'],
                'line'               => $lineNumber,
                'quantity_purchased' => $line['qty'],
                'item_cost_price'    => $line['cost'],
                'item_unit_price'    => $line['unit'],
                'discount'           => $line['discount'] ?? 0,
                'discount_type'      => $line['discount_type'] ?? PERCENT,
                'item_location'      => $locationId,
            ]);

            if (!empty($line['tax'])) {
                $db->table('sales_items_taxes')->insert([
                    'sale_id'          => $saleId,
                    'item_id'          => $line['item_id'],
                    'line'             => $lineNumber,
                    'name'             => 'VAT',
                    'percent'          => 10,
                    'tax_type'         => $line['tax_type'] ?? 1,
                    'rounding_code'    => 0,
                    'cascade_sequence' => 0,
                    'item_tax_amount'  => $line['tax'],
                ]);
            }
        }

        return $saleId;
    }

    private function setStock(int $itemId, int $locationId, float $quantity): void
    {
        Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => $locationId,
            'quantity'    => $quantity,
        ]);
    }
}
