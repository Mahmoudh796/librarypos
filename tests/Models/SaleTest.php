<?php

namespace Tests\Models;

use App\Models\Sale;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * Regression tests: Sale::save_value() must reject
 * (and roll back) a payment that would overdraw a gift card or a customer's
 * reward points, and must reject (and roll back) a completed-sale stock
 * deduction that would take an item below zero or fabricate a negative
 * quantity row, instead of silently overselling.
 */
class SaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ConcurrentDbRaceTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private const LOCATION_ID = 1;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function createGiftcard(float $value): int
    {
        $giftcardNumber = random_int(1000000, 9999999);

        \Config\Database::connect()->table('giftcards')->insert([
            'giftcard_number' => $giftcardNumber,
            'value'           => $value,
            'deleted'         => 0,
            'person_id'       => null,
        ]);

        return $giftcardNumber;
    }

    protected function createCustomerWithPoints(int $points): int
    {
        $db = \Config\Database::connect();

        $db->table('people')->insert([
            'first_name'   => 'Reward',
            'last_name'    => 'Customer',
            'phone_number' => '555-0300',
            'email'        => 'customer-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('customers')->insert([
            'person_id'   => $personId,
            'employee_id' => 1,
            'points'      => $points,
        ]);

        return $personId;
    }

    protected function buildCartLine(int $itemId, float $quantity, float $price): array
    {
        return [
            0 => [
                'item_id'       => $itemId,
                'line'          => 0,
                'description'   => 'Test Item',
                'serialnumber'  => '',
                'quantity'      => $quantity,
                'discount'      => 0,
                'discount_type' => 0,
                'cost_price'    => 1.00,
                'price'         => $price,
                'item_location' => self::LOCATION_ID,
                'print_option'  => 0,
            ],
        ];
    }

    /**
     * Builds a two-line cart (item A at line 0, item B at line 1) so a single
     * finalized sale exercises stock deductions for two distinct items.
     */
    protected function buildTwoLineCart(int $itemIdA, int $itemIdB, float $quantityA, float $quantityB, float $price): array
    {
        return [
            0 => [
                'item_id'       => $itemIdA,
                'line'          => 0,
                'description'   => 'Test Item A',
                'serialnumber'  => '',
                'quantity'      => $quantityA,
                'discount'      => 0,
                'discount_type' => 0,
                'cost_price'    => 1.00,
                'price'         => $price,
                'item_location' => self::LOCATION_ID,
                'print_option'  => 0,
            ],
            1 => [
                'item_id'       => $itemIdB,
                'line'          => 1,
                'description'   => 'Test Item B',
                'serialnumber'  => '',
                'quantity'      => $quantityB,
                'discount'      => 0,
                'discount_type' => 0,
                'cost_price'    => 1.00,
                'price'         => $price,
                'item_location' => self::LOCATION_ID,
                'print_option'  => 0,
            ],
        ];
    }

    protected function buildCashPayment(float $amount): array
    {
        return [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => $amount,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
    }

    protected function createQuantityRow(int $itemId, float $quantity, int $locationId = self::LOCATION_ID): void
    {
        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => $locationId,
            'quantity'    => $quantity,
        ]);
    }

    protected function countInventoryRowsForItem(int $itemId): int
    {
        return (int) \Config\Database::connect()->table('inventory')
            ->where('trans_items', $itemId)
            ->countAllResults();
    }

    protected function createStockLocation(string $name = null): int
    {
        $db = \Config\Database::connect();
        $db->table('stock_locations')->insert([
            'location_name' => $name ?? 'loc-' . uniqid(),
            'deleted'       => 0,
        ]);

        return (int) $db->insertID();
    }

    protected function countSalesRows(): int
    {
        return \Config\Database::connect()->table('sales')->countAllResults();
    }

    protected function getGiftcardValue(int $giftcardNumber): float
    {
        $row = \Config\Database::connect()->table('giftcards')
            ->where('giftcard_number', $giftcardNumber)
            ->get()
            ->getRow();

        return (float) $row->value;
    }

    protected function getCustomerPoints(int $personId): int
    {
        $row = \Config\Database::connect()->table('customers')
            ->where('person_id', $personId)
            ->get()
            ->getRow();

        return (int) $row->points;
    }

    /**
     * Every report total is net of the invoice discount: the sales history, the
     * sale info and the line SQL all subtract each line's allocated share.
     */
    public function testInvoiceDiscountIsSubtractedFromSaleTotals(): void
    {
        $db = \Config\Database::connect();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $db->table('sales')->insert(['sale_time' => '2001-04-05 10:00:00', 'employee_id' => 1, 'comment' => '', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS]);
        $saleId = (int) $db->insertID();
        $db->table('sales_items')->insert([
            'sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 2, 'item_cost_price' => 1,
            'item_unit_price' => 50, 'discount' => 0, 'discount_type' => PERCENT, 'invoice_discount' => 10, 'item_location' => self::LOCATION_ID,
        ]);

        $rows = model(Sale::class)->search('', ['start_date' => '2001-04-05', 'end_date' => '2001-04-05', 'sale_type' => 'all', 'location_id' => 'all', 'only_invoices' => false, 'only_cash' => false, 'only_due' => false, 'only_check' => false, 'only_creditcard' => false, 'only_debit' => false, 'only_bank_transfer' => false, 'only_wallet' => false, 'selected_customer' => false, 'is_valid_receipt' => false], 10, 0, 'sales.sale_time', 'desc')->getResultArray();
        $row = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['sale_id'] === $saleId))[0];

        $this->assertEqualsWithDelta(90.0, (float) $row['amount_due'], 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $db->query('SELECT SUM(' . Sale::lineNetSql(2, $db->prefixTable('sales_items')) . ') AS net FROM ' . $db->prefixTable('sales_items') . ' WHERE sale_id = ?', [$saleId])->getRow()->net, 0.001);
    }

    public function testSaveValueSucceedsWithSufficientGiftcardBalance(): void
    {
        $employeeId     = $this->createEmployee();
        $giftcardNumber = $this->createGiftcard(100.00);
        $itemId         = $this->createTestItem(HAS_NO_STOCK);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
                'payment_amount'  => 60.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(40.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testSaveValueDoesNotMatchGiftcardSinkOnSubstringOnly(): void
    {
        $employeeId     = $this->createEmployee();
        $giftcardNumber = $this->createGiftcard(100.00);
        $itemId         = $this->createTestItem(HAS_NO_STOCK);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => 'Foo ' . lang('Sales.giftcard') . ':' . $giftcardNumber,
                'payment_amount'  => 60.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testSaveValueReturnsInsufficientGiftcardBalanceSentinel(): void
    {
        $employeeId     = $this->createEmployee();
        $giftcardNumber = $this->createGiftcard(50.00);
        $itemId         = $this->createTestItem(HAS_NO_STOCK);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
                'payment_amount'  => 60.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_GIFTCARD_BALANCE, $result);
        $this->assertEqualsWithDelta(50.00, $this->getGiftcardValue($giftcardNumber), 0.001);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
    }

    public function testSaveValueReturnsInsufficientRewardPointsSentinel(): void
    {
        $employeeId = $this->createEmployee();
        $customerId = $this->createCustomerWithPoints(20);
        $itemId     = $this->createTestItem(HAS_NO_STOCK);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 60.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.rewards'),
                'payment_amount'  => 40.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            $customerId,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_REWARD_POINTS, $result);
        $this->assertSame(20, $this->getCustomerPoints($customerId));
        $this->assertSame($salesCountBefore, $this->countSalesRows());
    }

    public function testSaveValueDecrementsStockOnCompletedSale(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => 10,
        ]);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => 15.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testSaveValueRejectsStockItemWhenNoQuantityRowExists(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        // Deliberately no item_quantities row inserted for this item/location.
        // Previously the sale succeeded and fabricated a negative quantity row;
        // the floor guard now rejects it as insufficient stock.

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = $this->buildCashPayment(15.00);
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_STOCK, $result);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
        $this->assertSame(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID));
        $this->assertSame(0, $this->countInventoryRowsForItem($itemId));
    }

    public function testSaveValueReturnsInsufficientStockSentinelOnOversell(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 1.0);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 2, 5.00);
        $payments   = $this->buildCashPayment(10.00);
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_STOCK, $result);
        $this->assertEqualsWithDelta(1.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
        $this->assertSame(0, $this->countInventoryRowsForItem($itemId));
    }

    public function testSaveValueCompletesWhenStockExactlyMatches(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 1.0);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 1, 5.00);
        $payments   = $this->buildCashPayment(5.00);
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testSaveValueRollsBackWholeSaleWhenLaterLineInsufficient(): void
    {
        $employeeId = $this->createEmployee();
        $itemIdA    = $this->createTestItem(HAS_STOCK);
        $itemIdB    = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemIdA, 1.0);
        $this->createQuantityRow($itemIdB, 1.0);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildTwoLineCart($itemIdA, $itemIdB, 1, 2, 5.00);
        $payments   = $this->buildCashPayment(15.00);
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(INSUFFICIENT_STOCK, $result);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
        $this->assertEqualsWithDelta(1.0, $this->getItemQuantity($itemIdA, self::LOCATION_ID), 0.001);
        $this->assertEqualsWithDelta(1.0, $this->getItemQuantity($itemIdB, self::LOCATION_ID), 0.001);
        $this->assertSame(0, $this->countInventoryRowsForItem($itemIdA));
        $this->assertSame(0, $this->countInventoryRowsForItem($itemIdB));
    }

    public function testSaveValueRollsBackEntireUnitWhenWrappedInTestModeTransaction(): void
    {
        // Prove the whole logical unit (sale header, payments, sale_items,
        // stock deduction, inventory movement) is committed by a single DB
        // transaction: when an outer test-mode transaction forces a rollback,
        // none of it survives.
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 5.0);

        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 2, 5.00);
        $payments   = $this->buildCashPayment(10.00);
        $salesTaxes = [[], []];

        $db     = \Config\Database::connect();
        $db->transStart(true);

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $db->transComplete();

        $this->assertGreaterThan(0, $result);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
        $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame(0, $this->countInventoryRowsForItem($itemId));
    }

    public function testSaveValueEmptyCartReturnsNewEntryWithoutWriting(): void
    {
        $employeeId = $this->createEmployee();
        $salesCountBefore = $this->countSalesRows();

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = [];
        $payments   = [];
        $salesTaxes = [[], []];

        // A double-submitted "complete" after the first request cleared the
        // cart must not create a duplicate sale.
        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertSame(NEW_ENTRY, $result);
        $this->assertSame($salesCountBefore, $this->countSalesRows());
    }

    public function testSaveValueSuspendedSaleDoesNotDeductStock(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 5.0);

        $saleModel  = model(Sale::class);
        $saleStatus = SUSPENDED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = $this->buildCashPayment(15.00);
        $salesTaxes = [[], []];

        $result = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $result);
        $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDeleteRestoresStockOnCanceledSale(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => 10,
        ]);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => 15.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $saleId = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $saleId);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $deleteResult = $saleModel->delete($saleId, false, true, $employeeId);

        $this->assertTrue($deleteResult);
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDeleteVoidRestoresStockExactlyOnce(): void
    {
        // A second void of the same sale must be a no-op, not a second
        // stock restore.
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 10);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = $this->buildCashPayment(15.00);
        $salesTaxes = [[], []];

        $saleId = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $saleId);

        $this->assertTrue($saleModel->delete($saleId, false, true, $employeeId));
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $this->assertTrue($saleModel->delete($saleId, false, true, $employeeId));
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testReturnSaleRestoresStockAndVoidOfReturnReDeductsExactlyOnce(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 10);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, -3, 5.00);    // return line: negative quantity
        $payments   = [
            0 => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => -15.00,
                'cash_refund'     => 0,
                'cash_adjustment' => 0,
                'reference_code'  => null,
            ],
        ];
        $salesTaxes = [[], []];

        $returnSaleId = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test return',
            null,
            null,
            null,
            SALE_TYPE_RETURN,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $returnSaleId);
        $this->assertEqualsWithDelta(13.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // Voiding the return re-deducts the returned goods exactly once.
        $this->assertTrue($saleModel->delete($returnSaleId, false, true, $employeeId));
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testConcurrentSalesOfLastUnitOnlyOneSucceeds(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 1);

        $salesCountBefore = $this->countSalesRows();

        [$result1, $result2] = $this->raceTwoProcesses(
            'finalizeSale',
            [$itemId, self::LOCATION_ID, 1, 5, $employeeId],
            [$itemId, self::LOCATION_ID, 1, 5, $employeeId]
        );

        $this->assertNotSame($result1, $result2);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame($salesCountBefore + 1, $this->countSalesRows());
    }

    public function testConcurrentKitComponentSalesAreAllOrNothing(): void
    {
        $employeeId = $this->createEmployee();
        $itemIdA    = $this->createTestItem(HAS_STOCK);
        $itemIdB    = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemIdA, 2);
        $this->createQuantityRow($itemIdB, 1);

        $salesCountBefore = $this->countSalesRows();

        // Both finalizations want 2 x A + 1 x B; stock allows only one of them.
        [$result1, $result2] = $this->raceTwoProcesses(
            'finalizeTwoItemSale',
            [$itemIdA, $itemIdB, self::LOCATION_ID, 2, 1, 5, $employeeId],
            [$itemIdA, $itemIdB, self::LOCATION_ID, 2, 1, 5, $employeeId]
        );

        $this->assertNotSame($result1, $result2);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemIdA, self::LOCATION_ID), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemIdB, self::LOCATION_ID), 0.001);
        $this->assertSame($salesCountBefore + 1, $this->countSalesRows());
    }

    public function testConcurrentDifferentLineOrderSalesDoNotCorrupt(): void
    {
        $employeeId = $this->createEmployee();
        $itemIdA    = $this->createTestItem(HAS_STOCK);
        $itemIdB    = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemIdA, 10);
        $this->createQuantityRow($itemIdB, 10);

        // Same two items, listed in opposite line order. The model locks
        // item_quantities rows in item/location order regardless of cart
        // order, so neither transaction deadlocks and both may complete.
        [$result1, $result2] = $this->raceTwoProcesses(
            'finalizeTwoItemSale',
            [$itemIdA, $itemIdB, self::LOCATION_ID, 1, 1, 5, $employeeId],
            [$itemIdB, $itemIdA, self::LOCATION_ID, 1, 1, 5, $employeeId]
        );

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEqualsWithDelta(8.0, $this->getItemQuantity($itemIdA, self::LOCATION_ID), 0.001);
        $this->assertEqualsWithDelta(8.0, $this->getItemQuantity($itemIdB, self::LOCATION_ID), 0.001);
    }

    public function testConcurrentTwoLocationSalesAreIndependent(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);

        $secondLocationId = $this->createStockLocation();
        $this->createQuantityRow($itemId, 1, self::LOCATION_ID);
        $this->createQuantityRow($itemId, 1, $secondLocationId);

        [$result1, $result2] = $this->raceTwoProcesses(
            'finalizeSale',
            [$itemId, self::LOCATION_ID, 1, 5, $employeeId],
            [$itemId, $secondLocationId, 1, 5, $employeeId]
        );

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, $secondLocationId), 0.001);
    }

    public function testConcurrentVoidsRestoreStockExactlyOnce(): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 10);

        $saleModel  = model(Sale::class);
        $saleStatus = COMPLETED;
        $items      = $this->buildCartLine($itemId, 3, 5.00);
        $payments   = $this->buildCashPayment(15.00);
        $salesTaxes = [[], []];

        $saleId = $saleModel->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $employeeId,
            'test sale',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $salesTaxes
        );

        $this->assertGreaterThan(0, $saleId);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // Two cashiers (or a retried request) voiding the same sale at once:
        // the row lock serializes them and stock is restored exactly once.
        [$voidResult1, $voidResult2] = $this->raceTwoProcesses(
            'voidSale',
            [$saleId, $employeeId],
            [$saleId, $employeeId]
        );

        $this->assertTrue($voidResult1);
        $this->assertTrue($voidResult2);
        $this->assertEqualsWithDelta(10.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }
}
