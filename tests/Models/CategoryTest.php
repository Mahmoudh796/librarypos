<?php

namespace Tests\Models;

use App\Models\Category;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ItemFixtureTrait;

class CategoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private Category $category;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        $this->ensureCategoryDefinition();
        $this->category = model(Category::class);
    }

    private function ensureCategoryDefinition(): void
    {
        if ($this->db->table('attribute_definitions')->where('definition_id', CATEGORY_DEFINITION_ID)->countAllResults() === 0) {
            $this->db->table('attribute_definitions')->insert([
                'definition_id'    => CATEGORY_DEFINITION_ID,
                'definition_name'  => 'ospos_category',
                'definition_type'  => DROPDOWN,
                'definition_flags' => 0,
                'deleted'          => 0,
            ]);
        }
    }

    private function setItemCategory(int $itemId, string $category): void
    {
        $this->db->table('items')->where('item_id', $itemId)->update(['category' => $category]);
    }

    public function testNormalizeKeyFoldsArabicSpellingVariants(): void
    {
        $key = Category::normalizeKey('أقلام');

        $this->assertSame($key, Category::normalizeKey('اقلام'));
        $this->assertSame($key, Category::normalizeKey('  أقلام  '));
        $this->assertSame($key, Category::normalizeKey('إقلام'));
        $this->assertSame($key, Category::normalizeKey('أقـــلام'));
        $this->assertSame(Category::normalizeKey('هدية'), Category::normalizeKey('هديه'));
        $this->assertSame(Category::normalizeKey('Pens'), Category::normalizeKey('pens'));
        $this->assertNotSame($key, Category::normalizeKey('أقلام رصاص'));
    }

    public function testCleanNameCollapsesWhitespaceButKeepsSpelling(): void
    {
        $this->assertSame('أقلام رصاص', Category::cleanName("  أقلام \t  رصاص "));
    }

    public function testCreateRejectsSpellingVariantOfApprovedCategory(): void
    {
        $name = 'دفاتر ' . uniqid();
        $this->assertTrue($this->category->create($name));
        $this->assertTrue($this->category->isApproved($name));

        $this->assertFalse($this->category->create(' ' . $name . ' '));
        $this->assertSame($name, $this->category->findApprovedMatch($name . '  '));
    }

    public function testDropdownOptionsKeepUnapprovedCurrentCategory(): void
    {
        $legacy = 'Legacy ' . uniqid();
        $options = $this->category->getDropdownOptions('None', $legacy);

        $this->assertSame('None', $options['']);
        $this->assertSame($legacy, $options[$legacy] ?? null, 'Opening an item must never drop its current category from the dropdown.');
    }

    public function testRenameOntoExistingCategoryMergesItems(): void
    {
        $target = 'أدوات ' . uniqid();
        $variant = 'ادوات ' . substr($target, strlen('أدوات '));
        $this->category->create($target);

        $itemId = $this->createTestItem();
        $this->setItemCategory($itemId, $variant);

        $moved = $this->category->rename($variant, $variant . ' ');

        $this->assertSame(1, $moved);
        $this->assertSame($target, $this->db->table('items')->select('category')->where('item_id', $itemId)->get()->getRow()->category);
    }

    public function testRenameKeepsSharedAttributeValuesOfOtherDefinitions(): void
    {
        $shared = 'Shared ' . uniqid();
        $this->category->create($shared);

        $definitionId = model('Attribute')->ensureDefinition('Probe ' . uniqid());
        model('Attribute')->saveAttributeValue($shared, $definitionId);

        $this->category->rename($shared, 'Renamed ' . uniqid());

        $this->assertContains($shared, model('Attribute')->getDefinitionValues($definitionId));
        $this->assertFalse($this->category->isApproved($shared));
    }

    public function testRemoveRefusesCategoryInUse(): void
    {
        $name = 'InUse ' . uniqid();
        $this->category->create($name);
        $this->setItemCategory($this->createTestItem(), $name);

        $this->assertFalse($this->category->remove($name));
        $this->assertTrue($this->category->isApproved($name));
    }

    public function testRemoveDeletesUnusedCategory(): void
    {
        $name = 'Unused ' . uniqid();
        $this->category->create($name);

        $this->assertTrue($this->category->remove($name));
        $this->assertFalse($this->category->isApproved($name));
    }

    public function testAllWithCountsFlagsUnapprovedCategoriesInUse(): void
    {
        $name = 'Orphan ' . uniqid();
        $this->setItemCategory($this->createTestItem(), $name);

        $rows = array_column($this->category->getAllWithCounts(), null, 'name');

        $this->assertArrayHasKey($name, $rows);
        $this->assertFalse($rows[$name]['approved']);
        $this->assertSame(1, $rows[$name]['item_count']);
    }
}
