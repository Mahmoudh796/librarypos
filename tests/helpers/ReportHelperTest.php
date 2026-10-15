<?php

namespace Tests\Helpers;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * apply_report_location_filter() and get_report_location_where() are the shared
 * boundary that stops an employee who is only granted some stock locations from
 * reading the rest through a report. A regression here silently re-opens every
 * branch's data, so the contract is pinned directly:
 *
 *  - a concrete location selected by the controller is applied verbatim;
 *  - "all" without an allowed subset adds no constraint (unrestricted employee);
 *  - "all" with an allowed subset restricts to that subset;
 *  - an empty subset means "no accessible location" and must match no rows;
 *  - only integers survive, so the raw-SQL variant cannot be injected.
 */
class ReportHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('report');
    }

    public function testRawWhereIsEmptyWhenUnrestricted(): void
    {
        $this->assertSame('', get_report_location_where([]));
        $this->assertSame('', get_report_location_where(['location_id' => 'all']));
        $this->assertSame('', get_report_location_where(['location_id' => 'all', 'allowed_location_ids' => null]));
    }

    public function testRawWhereUsesConcreteLocation(): void
    {
        $this->assertSame(' AND item_location = 5', get_report_location_where(['location_id' => 5]));
        $this->assertSame(' AND item_location = 5', get_report_location_where(['location_id' => '5']));
    }

    public function testRawWhereRestrictsToAllowedSubset(): void
    {
        $this->assertSame(
            ' AND item_location IN (2,4)',
            get_report_location_where(['location_id' => 'all', 'allowed_location_ids' => [2, 4]])
        );
    }

    public function testRawWhereEmptySubsetMatchesNothing(): void
    {
        $this->assertSame(
            ' AND item_location IN (0)',
            get_report_location_where(['location_id' => 'all', 'allowed_location_ids' => []])
        );
    }

    public function testRawWhereCoercesToIntegersAndDropsInjectionText(): void
    {
        $this->assertSame(
            ' AND item_location IN (3)',
            get_report_location_where(['location_id' => 'all', 'allowed_location_ids' => ['3 OR 1=1 --']])
        );
    }

    public function testRawWhereHonoursQualifiedColumn(): void
    {
        $this->assertSame(
            ' AND item_quantities.location_id = 9',
            get_report_location_where(['location_id' => 9], 'item_quantities.location_id')
        );
    }

    public function testBuilderFilterUsesConcreteLocation(): void
    {
        $builder = $this->builder();

        apply_report_location_filter($builder, ['location_id' => '7'], 'item_location');

        $this->assertStringContainsString("item_location = '7'", $this->compiled($builder));
    }

    public function testBuilderFilterWithoutAllowedSubsetAddsNoConstraint(): void
    {
        $builder = $this->builder();

        apply_report_location_filter($builder, ['location_id' => 'all'], 'item_location');

        $this->assertStringNotContainsString('item_location', $this->compiled($builder));
    }

    public function testBuilderFilterRestrictsToAllowedSubset(): void
    {
        $builder = $this->builder();

        apply_report_location_filter($builder, ['location_id' => 'all', 'allowed_location_ids' => [3, 1]], 'item_location');

        $this->assertStringContainsString('item_location IN (3,1)', $this->compiled($builder));
    }

    public function testBuilderFilterEmptySubsetMatchesNothing(): void
    {
        $builder = $this->builder();

        apply_report_location_filter($builder, ['location_id' => 'all', 'allowed_location_ids' => []], 'item_location');

        $this->assertStringContainsString('item_location IN (0)', $this->compiled($builder));
    }

    public function testBuilderFilterDeduplicatesAllowedSubset(): void
    {
        $builder = $this->builder();

        apply_report_location_filter($builder, ['location_id' => 'all', 'allowed_location_ids' => [4, 4, 4]], 'item_location');

        $this->assertStringContainsString('item_location IN (4)', $this->compiled($builder));
    }

    private function builder(): BaseBuilder
    {
        return Database::connect()->table('item_quantities');
    }

    private function compiled(BaseBuilder $builder): string
    {
        return str_replace(['`', '"'], '', $builder->getCompiledSelect(false));
    }
}
