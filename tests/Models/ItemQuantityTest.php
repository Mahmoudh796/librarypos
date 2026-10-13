<?php

namespace Tests\Models;

use App\Models\Item_quantity;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * Regression tests: changeQuantity() must apply
 * its write in a single atomic upsert, so that two concurrent sales of the
 * same item/location can never both read the same stale quantity and
 * oversell stock. The unrestricted call keeps the legacy behaviour (negative
 * stock allowed); the floor-guarded call additionally serializes on the row
 * lock so a floor (e.g. zero) is enforced exactly and only one concurrent
 * transaction may consume the last units.
 */
class ItemQuantityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ConcurrentDbRaceTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const LOCATION_ID = 1;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function createItemQuantityRow(int $itemId, float $quantity): void
    {
        \Config\Database::connect()->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION_ID,
            'quantity'    => $quantity,
        ]);
    }

    public function testDecrementQuantitySucceeds(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 10);
        $itemQuantityModel = model(Item_quantity::class);

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -3);

        $this->assertTrue($result);
        $this->assertEqualsWithDelta(7.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDecrementQuantityAllowsGoingNegative(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 5);
        $itemQuantityModel = model(Item_quantity::class);

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -8);

        $this->assertTrue($result);
        $this->assertEqualsWithDelta(-3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testDecrementQuantityConcurrentDecrementsBothApply(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 10);

        [$result1, $result2] = $this->raceTwoProcesses(
            'itemQuantity',
            [$itemId, self::LOCATION_ID, -3.0],
            [$itemId, self::LOCATION_ID, -3.0]
        );

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertEqualsWithDelta(4.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testChangeQuantityGuardedRejectsBelowFloor(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 1);
        $itemQuantityModel = model(Item_quantity::class);

        $db = \Config\Database::connect();
        $db->transStart();

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -2, 0.0);

        $db->transComplete();

        $this->assertFalse($result);
        $this->assertEqualsWithDelta(1.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testChangeQuantityGuardedAllowsExactlyAtFloor(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 1);
        $itemQuantityModel = model(Item_quantity::class);

        $db = \Config\Database::connect();
        $db->transStart();

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -1, 0.0);

        $db->transComplete();

        $this->assertTrue($result);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testChangeQuantityGuardedRejectsRowCreationBelowFloor(): void
    {
        $itemId = $this->createTestItem();
        $itemQuantityModel = model(Item_quantity::class);

        $db = \Config\Database::connect();
        $db->transStart();

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, -1, 0.0);

        $db->transComplete();

        $this->assertFalse($result);
        $this->assertSame(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID));
    }

    public function testChangeQuantityGuardedCreatesRowAboveFloor(): void
    {
        $itemId = $this->createTestItem();
        $itemQuantityModel = model(Item_quantity::class);

        $db = \Config\Database::connect();
        $db->transStart();

        $result = $itemQuantityModel->changeQuantity($itemId, self::LOCATION_ID, 3, 0.0);

        $db->transComplete();

        $this->assertTrue($result);
        $this->assertEqualsWithDelta(3.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }

    public function testChangeQuantityGuardedConcurrentDecrementsConsumeLastUnitOnce(): void
    {
        $itemId = $this->createTestItem();
        $this->createItemQuantityRow($itemId, 1);

        [$result1, $result2] = $this->raceTwoProcesses(
            'itemQuantityGuarded',
            [$itemId, self::LOCATION_ID, -1.0],
            [$itemId, self::LOCATION_ID, -1.0]
        );

        $this->assertNotSame($result1, $result2);
        $this->assertEqualsWithDelta(0.0, $this->getItemQuantity($itemId, self::LOCATION_ID), 0.001);
    }
}
