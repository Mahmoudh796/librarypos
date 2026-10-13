<?php

namespace Tests\helpers;

use CodeIgniter\Test\CIUnitTestCase;

class VariantHelperTest extends CIUnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        helper('variant');
    }

    public function testSplitOptionsSplitsNewlinesAndCommas(): void
    {
        $result = variant_split_options("Red\nOrange,  Blue  ");
        $this->assertEquals(['Red', 'Orange', 'Blue'], $result);
    }

    public function testSplitOptionsDedupesCaseInsensitively(): void
    {
        $result = variant_split_options("Black, black, WHITE");
        $this->assertEquals(['Black', 'WHITE'], $result);
    }

    public function testBuildSkuFromPrefixAndOptions(): void
    {
        $sku = variant_build_sku('Shirt-001', ['Black', 'M']);
        $this->assertSame('SHIRT-001-BLACK-M', $sku);
    }

    public function testBuildSkuHandlesNonAsciiByHashing(): void
    {
        $sku = variant_build_sku('ITEM', ['قميص', 'XL']);
        $this->assertNotSame('ITEM--XL', $sku);
        $this->assertSame(14, strlen($sku)); // ITEM-XXXXXX-XL (ITEM + hyphen + 6-char hash + hyphen + XL)
    }

    public function testBuildNameUsesSeparator(): void
    {
        $name = variant_build_name('Classic Shirt', ['Black', 'M']);
        $this->assertSame('Classic Shirt / Black / M', $name);
    }

    public function testBuildCombinationsProducesMatrix(): void
    {
        $combinations = variant_build_combinations(['Black', 'White'], ['M', 'L']);
        $this->assertCount(4, $combinations);
        $this->assertSame(['Black', 'M'], $combinations[0]['options']);
    }

    public function testBuildCombinationsWithSingleDimension(): void
    {
        $combinations = variant_build_combinations(['Black'], []);
        $this->assertCount(1, $combinations);
        $this->assertSame(['Black'], $combinations[0]['options']);
        $this->assertNull($combinations[0]['size']);

        $combinations = variant_build_combinations([], ['M']);
        $this->assertCount(1, $combinations);
        $this->assertSame(['M'], $combinations[0]['options']);
        $this->assertNull($combinations[0]['color']);
    }

    public function testBuildCombinationsEmptyReturnsSingleRow(): void
    {
        $combinations = variant_build_combinations([], []);
        $this->assertCount(1, $combinations);
        $this->assertNull($combinations[0]['color']);
        $this->assertNull($combinations[0]['size']);
        $this->assertSame([], $combinations[0]['options']);
    }
}