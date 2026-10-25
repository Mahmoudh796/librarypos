<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Attribute;
use App\Models\Customer;
use App\Models\Dinner_table;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Sale;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * Whole-sale (invoice) discount: allocation over the cart lines and its effect
 * on the register totals.
 */
class Sale_libTest extends CIUnitTestCase
{
    private Sale_lib $saleLib;

    private int $priorBcscale;

    protected function setUp(): void
    {
        parent::setUp();

        // Same scale the application sets in Load_config (totals + tax decimals).
        $this->priorBcscale = bcscale();
        bcscale(4);

        $ospos           = new OSPOS();
        $ospos->settings = [
            'cash_rounding_code' => '',
            'cash_decimals'      => 2,
            'currency_decimals'  => 2,
            'tax_decimals'       => 2,
            'quantity_decimals'  => 2,
            'tax_included'       => '0',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);

        foreach ([Attribute::class, Customer::class, Dinner_table::class, Item::class, Item_kit_items::class, Item_quantity::class, Item_taxes::class, Sale::class, Stock_location::class] as $modelClass) {
            Factories::injectMock('models', $modelClass, $this->getMockBuilder($modelClass)->disableOriginalConstructor()->getMock());
        }

        session()->destroy();
        $this->saleLib = new Sale_lib();
    }

    protected function tearDown(): void
    {
        bcscale($this->priorBcscale);
        Factories::reset();
        parent::tearDown();
    }

    private function line(int $line, string $quantity, string $price, string $discount = '0', int $discountType = PERCENT, array $extra = []): array
    {
        return array_merge([
            'item_id' => 100 + $line, 'item_location' => 1, 'line' => $line, 'name' => "Item $line", 'quantity' => $quantity,
            'price' => $price, 'discount' => $discount, 'discount_type' => $discountType, 'stock_type' => HAS_STOCK,
            'print_option' => PRINT_YES, 'description' => '', 'serialnumber' => '',
        ], $extra);
    }

    private function allocations(): array
    {
        return array_map(static fn (array $line): string => $line['invoice_discount'], $this->saleLib->get_cart());
    }

    public function testPercentDiscountIsAllocatedInProportionAndAddsUpExactly(): void
    {
        // Line nets 10.00, 20.00, 33.33 = 63.33; 10% = 6.33 (rounded).
        $this->saleLib->set_cart([1 => $this->line(1, '1', '10'), 2 => $this->line(2, '2', '10'), 3 => $this->line(3, '3', '11.11')]);
        $this->saleLib->set_invoice_discount(PERCENT, '10', 'loyal customer');

        $allocations = $this->allocations();

        $this->assertSame('6.33', number_format(array_sum(array_map('floatval', $allocations)), 2, '.', ''));
        $this->assertSame(['1' => '1.00', '2' => '2.00', '3' => '3.33'], array_map('strval', $allocations));
    }

    public function testFixedDiscountIsCappedAtTheSaleNet(): void
    {
        $this->saleLib->set_cart([1 => $this->line(1, '1', '30')]);
        $this->saleLib->set_invoice_discount(FIXED, '50', 'test');

        $this->assertSame('30.00', $this->allocations()[1]);
    }

    public function testAllocationUsesNetAfterLineDiscount(): void
    {
        // Line 1: 100 less 50% = 50; line 2: 50. Fixed 10 splits 5 / 5.
        $this->saleLib->set_cart([1 => $this->line(1, '1', '100', '50', PERCENT), 2 => $this->line(2, '1', '50')]);
        $this->saleLib->set_invoice_discount(FIXED, '10', 'test');

        $this->assertSame(['1' => '5.00', '2' => '5.00'], array_map('strval', $this->allocations()));
    }

    public function testRemainderCentsGoToLargestRemainders(): void
    {
        // Three equal lines sharing 10.00: 3.33 + 3.33 + 3.34 in some order, never 9.99 or 10.02.
        $this->saleLib->set_cart([1 => $this->line(1, '1', '10'), 2 => $this->line(2, '1', '10'), 3 => $this->line(3, '1', '10')]);
        $this->saleLib->set_invoice_discount(FIXED, '10', 'test');

        $allocations = array_map('floatval', $this->allocations());
        $this->assertEqualsWithDelta(10.0, array_sum($allocations), 0.0001);
        $this->assertEqualsCanonicalizing([3.33, 3.33, 3.34], $allocations);
    }

    public function testTotalsSubtractTheInvoiceDiscount(): void
    {
        $this->saleLib->set_cart([1 => $this->line(1, '2', '25'), 2 => $this->line(2, '1', '50')]);
        $this->saleLib->set_invoice_discount(PERCENT, '10', 'test');

        $totals = $this->saleLib->get_totals([]);

        $this->assertEqualsWithDelta(10.0, (float) $totals['invoice_discount'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $totals['subtotal_before_invoice_discount'], 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $totals['subtotal'], 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $totals['total'], 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $this->saleLib->calculate_subtotal(true), 0.0001);
    }

    public function testReturnedLinesRefundTheOriginalPerUnitShare(): void
    {
        // Originally 4 units carried 8.00 of invoice discount (2.00 a unit); returning 1 unit refunds 2.00 of it.
        $this->saleLib->set_cart([1 => $this->line(1, '-1', '25', '0', PERCENT, ['invoice_discount_unit' => '2'])]);

        $this->assertSame('-2', $this->allocations()[1]);
        $this->assertEqualsWithDelta(-23.0, (float) $this->saleLib->get_totals([])['total'], 0.0001);
    }

    public function testClearAllDropsTheInvoiceDiscount(): void
    {
        $this->saleLib->set_invoice_discount(PERCENT, '5', 'test');
        $this->saleLib->clear_all();

        $this->assertSame([], $this->saleLib->get_invoice_discount());
    }

    public function testTaxBasisIncludesInvoiceDiscount(): void
    {
        $this->assertSame('90.0000', $this->saleLib->get_item_total('1', '100', '0', PERCENT, true, '10'));
        $this->assertSame('100.0000', $this->saleLib->get_item_total('1', '100', '0', PERCENT, false, '10'), 'The gross line total ignores discounts.');
    }
}
