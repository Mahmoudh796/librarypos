<?php

namespace Tests\Models;

use App\Models\Receiving;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * Regression tests: Receiving::delete_value() must
 * correctly reverse the stock quantity change it applied via
 * Item_quantity::changeQuantity(), using the same atomic upsert as the
 * sale-checkout and sale-cancel paths. save_value() applies its stock
 * change as an atomic delta so concurrent receivings cannot lose updates.
 */
class ReceivingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ConcurrentDbRaceTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;

    private const LOCATION_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function createSupplier(): int
    {
        $db = \Config\Database::connect();

        $db->table('people')->insert([
            'first_name'   => 'Test',
            'last_name'    => 'Supplier',
            'phone_number' => '555-0400',
            'email'        => 'supplier-' . uniqid() . '@test.com',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('suppliers')->insert([
            'person_id' => $personId,
            'deleted'   => 0,
        ]);

        return $personId;
    }

    protected function buildReceivingLine(int $itemId, float $quantity, float $costPrice): array
    {
        return [
            0 => [
                'item_id'            => $itemId,
                'line'               => 0,
                'description'        => 'Test Item',
                'serialnumber'       => '',
                'quantity'           => $quantity,
                'receiving_quantity' => 1,
                'discount'           => 0,
                'discount_type'      => 0,
                'price'              => $costPrice,
                'item_location'      => self::LOCATION_ID,
            ],
        ];
    }

    public function testDeleteValueRestoresStockAfterReceiving(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        // Deliberately no pre-existing item_quantities row for this item/location.

        $receivingModel = model(Receiving::class);
        $items          = $this->buildReceivingLine($itemId, 5, 1.00);

        $receivingId = $receivingModel->save_value(
            $items,
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId);
        $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        $deleteResult = $receivingModel->delete_value($receivingId, $employeeId, true);

        $this->assertTrue($deleteResult);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    protected function createQuantityRow(int $itemId, float $quantity): void
    {
        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => $quantity,
        ]);
    }

    public function testSaveValueAccumulatesDeltasAcrossSequentialReceivings(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        $receivingModel = model(Receiving::class);

        $receivingId1 = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, 5, 1.00),
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );
        $receivingId2 = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, 3, 1.00),
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId1);
        $this->assertGreaterThan(0, $receivingId2);
        $this->assertEqualsWithDelta(8.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // Voiding the first receiving reverses only its own delta.
        $this->assertTrue($receivingModel->delete_list([$receivingId1], $employeeId, true));
        $this->assertEqualsWithDelta(3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDeleteValueIsIdempotent(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        $receivingModel = model(Receiving::class);

        $receivingId = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, 5, 1.00),
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId);

        $this->assertTrue($receivingModel->delete_list([$receivingId], $employeeId, true));
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // A second void must be a no-op, not a second stock reversal.
        $this->assertTrue($receivingModel->delete_list([$receivingId], $employeeId, true));
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testSaveValueReturnReceivingRespectsFloor(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        $receivingModel = model(Receiving::class);

        $receivingId = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, 5, 1.00),
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId);
        $this->assertEqualsWithDelta(5.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // A return within stock succeeds.
        $returnId = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, -2, 1.00),
            $supplierId,
            $employeeId,
            'test return',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $returnId);
        $this->assertEqualsWithDelta(3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // A return that would drive the location below zero is rejected and
        // the whole receiving rolls back.
        $receivingsCountBefore = \Config\Database::connect()->table('receivings')->countAllResults();

        $blockedId = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, -4, 1.00),
            $supplierId,
            $employeeId,
            'test return',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertSame(-1, $blockedId);
        $this->assertEqualsWithDelta(3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
        $this->assertSame($receivingsCountBefore, \Config\Database::connect()->table('receivings')->countAllResults());
    }

    public function testConcurrentReceivingsAccumulateWithoutLostUpdate(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 10);

        [$result1, $result2] = $this->raceTwoProcesses(
            'receiving',
            [$itemId, self::LOCATION_ID, 5, $employeeId, $supplierId],
            [$itemId, self::LOCATION_ID, 5, $employeeId, $supplierId]
        );

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEqualsWithDelta(20.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // The second receipt must average against the first one's stock, not a stale snapshot.
        $quantitiesBefore = array_map('floatval', array_column(
            \Config\Database::connect()->table('item_cost_history')->select('qty_before')->where('item_id', $itemId)->orderBy('history_id')->get()->getResultArray(),
            'qty_before'
        ));
        $this->assertSame([10.0, 15.0], $quantitiesBefore);
    }

    public function testConcurrentReceivingAndSaleOfSameItemSerialize(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);
        $this->createQuantityRow($itemId, 5);

        [$result1, $result2] = $this->raceTwoProcesses(
            'receiving',
            [$itemId, self::LOCATION_ID, 3, $employeeId, $supplierId],
            [$itemId, self::LOCATION_ID, 2, 5, $employeeId],
            'finalizeSale'
        );

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEqualsWithDelta(6.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDeleteValueRefusesReversalBelowZero(): void
    {
        $employeeId = $this->createEmployee();
        $supplierId = $this->createSupplier();
        $itemId     = $this->createTestItem(HAS_STOCK);

        $receivingModel = model(Receiving::class);

        $receivingId = $receivingModel->save_value(
            $this->buildReceivingLine($itemId, 5, 1.00),
            $supplierId,
            $employeeId,
            'test receiving',
            'REF-' . uniqid(),
            'Cash'
        );

        $this->assertGreaterThan(0, $receivingId);

        // Simulate the goods already having been sold on: only 2 of the 5
        // received units are left. Reversing the whole receiving would drive
        // the location to -3, so the void must be refused and rolled back.
        \Config\Database::connect()->table('item_quantities')
            ->where('item_id', $itemId)
            ->where('location_id', self::LOCATION_ID)
            ->update(['quantity' => 2]);

        $this->assertFalse($receivingModel->delete_list([$receivingId], $employeeId, true));
        $this->assertEqualsWithDelta(2.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);

        // The receiving must still exist because the void rolled back.
        $this->assertSame(
            1,
            (int) \Config\Database::connect()->table('receivings')->where('receiving_id', $receivingId)->countAllResults()
        );
    }
}
