<?php

namespace Tests\Models;

use App\Models\Attribute;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class AttributeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = null;

    private Attribute $attribute;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->attribute = model(Attribute::class);
    }

    public function testEnsureDefinitionCreatesAndReturnsId(): void
    {
        $definitionId = $this->attribute->ensureDefinition('Test Size', DROPDOWN, Attribute::SHOW_IN_ITEMS | Attribute::SHOW_IN_SEARCH);
        $this->assertGreaterThan(0, $definitionId);

        // Ensure repeated call returns the same id
        $again = $this->attribute->ensureDefinition('Test Size', DROPDOWN, Attribute::SHOW_IN_ITEMS | Attribute::SHOW_IN_SEARCH);
        $this->assertSame($definitionId, $again);

        $info = $this->attribute->getAttributeInfo($definitionId);
        $this->assertSame('Test Size', $info->definition_name);
        $this->assertSame(DROPDOWN, $info->definition_type);
    }

    public function testEnsureDefinitionValueCreatesOption(): void
    {
        $definitionId = $this->attribute->ensureDefinition('Test Color', DROPDOWN, Attribute::SHOW_IN_ITEMS);
        $this->assertGreaterThan(0, $definitionId);

        $attributeId = $this->attribute->ensureDefinitionValue($definitionId, 'Navy');
        $this->assertGreaterThan(0, $attributeId);

        // Repeated call returns the same attribute id
        $again = $this->attribute->ensureDefinitionValue($definitionId, 'Navy');
        $this->assertSame($attributeId, $again);

        $values = $this->attribute->getDefinitionValues($definitionId);
        $this->assertArrayHasKey($attributeId, $values);
        $this->assertSame('Navy', $values[$attributeId]);
    }
}