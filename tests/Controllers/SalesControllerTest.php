<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Employee;
use App\Models\Item;
use App\Libraries\Sale_lib;
use Config\Database;
use Config\OSPOS;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\SaleFixtureTrait;

/**
 * Regression tests for the Sales per-endpoint access-control bypass.
 *
 * A cashier holding only the base "sales" grant (no "reports_sales") must
 * not be able to reach the per-sale endpoints that getManage() gates
 * behind reports_sales: getSearch, getRow, getEdit, postSave, getReceipt,
 * getInvoice, getSendPdf, getSendReceipt.
 *
 * Also covers: getSearch() (the AJAX endpoint that
 * supplies every row of the Sales Takings list) was the one sibling that
 * returned the full ledger and was missing the reports_sales check.
 */
class SalesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ItemFixtureTrait;
    use EmployeeFixtureTrait;
    use SaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        // CIUnitTestCase reuses the CodeIgniter application (and the services it
        // resolved) across tests in a class. The View service caches the shared
        // data set by the previous request (e.g. an authorization error), which
        // would otherwise render into the next test's response. Drop the cached
        // renderer so each test renders from its own request only.
        Services::resetSingle('renderer');

        // The sign-in language switcher writes a persistent cookie that outranks
        // the employee's own language preference. Drop it so the direction tests
        // reflect the employee record rather than whatever the previous suite
        // last selected.
        service('superglobals')->unsetCookie('language');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Cashier with bare "sales" + "sales_stock" grants. "sales_stock" is
     * required alongside "sales": Employee::has_module_grant('sales', ...)
     * treats the bare "sales" grant as insufficient once any sales_*
     * submodule permission exists in the permissions table (see
     * has_subpermissions()). Deliberately NOT "reports_sales" — that is the
     * grant this test exercises the gate on.
     */
    protected function createCashierEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoReports',
            email:      'cashier.' . uniqid() . '@test.com',
            username:   'cashier_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createReportsSalesEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Supervisor',
            last_name:  'WithReports',
            email:      'supervisor.' . uniqid() . '@test.com',
            username:   'supervisor_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'reports_sales', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithoutChangePriceGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoChangePrice',
            email:      'cashier-nochangeprice.' . uniqid() . '@test.com',
            username:   'cashier_nochangeprice_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithChangePriceGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'ChangePrice',
            email:      'cashier-changeprice.' . uniqid() . '@test.com',
            username:   'cashier_changeprice_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'sales_change_price', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithDiscountGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'Discount',
            email:      'cashier-discount.' . uniqid() . '@test.com',
            username:   'cashier_discount_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'sales_discount', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithReturnGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'Return',
            email:      'cashier-return.' . uniqid() . '@test.com',
            username:   'cashier_return_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'sales_return', 'menu_group' => 'home'],
            ],
        );
    }

    protected function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    /**
     * Seeds a single cart line directly in the session, mirroring the shape
     * Sale_lib::add_item() produces, so tests can target postEditItem's
     * authorization branch without exercising the full add-item flow.
     */
    protected function seedCartLine(int $line, string $price, int $itemId, string $discount = '0', int $discountType = PERCENT): void
    {
        $this->withSession(array_merge($this->session, [
            'sales_cart' => [
                $line => [
                    'item_id'               => $itemId,
                    'item_location'         => 1,
                    'stock_name'            => 'Test Location',
                    'line'                  => $line,
                    'name'                  => 'Test Item',
                    'item_number'           => 'TEST-1',
                    'attribute_values'      => null,
                    'attribute_dtvalues'    => null,
                    'description'           => 'Test Item',
                    'serialnumber'          => '',
                    'allow_alt_description' => false,
                    'is_serialized'         => false,
                    'quantity'              => '1',
                    'discount'              => $discount,
                    'discount_type'         => $discountType,
                    'in_stock'              => '10',
                    'price'                 => $price,
                    'cost_price'            => '1.00',
                    'total'                 => $price,
                    'discounted_total'      => $price,
                    'print_option'          => PRINT_YES,
                    'stock_type'            => HAS_NO_STOCK,
                    'item_type'             => ITEM,
                    'hsn_code'              => null,
                    'tax_category_id'       => null,
                ],
            ],
        ]));
    }

    public function testCashierWithoutReportsSalesCannotGetRow(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/row/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotGetSearch(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/search');

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.not_authorized'), $result['message']);
    }

    public function testCashierWithoutReportsSalesCannotGetEdit(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/edit/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotGetReceipt(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/receipt/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotGetInvoice(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/invoice/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotSendPdf(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/sendpdf/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotSendReceipt(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/sendreceipt/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotPostSave(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->post('/sales/save/' . $saleId, [
            'date'              => date('m/d/Y H:i:s'),
            'customer_id'       => '',
            'employee_id'       => $cashierId,
            'comment'           => 'tampered',
            'invoice_number'    => '',
            'number_of_payments'=> 0,
            'payment_type_new'  => '--',
            'payment_amount_new'=> ''
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.not_authorized'), $result['message']);
    }

    public function testEmployeeWithReportsSalesCanGetSearch(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/search');

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertArrayNotHasKey('success', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('payment_summary', $result);
    }

    public function testEmployeeWithReportsSalesCanGetRow(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/row/' . $saleId);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEmployeeWithReportsSalesCanGetEdit(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/edit/' . $saleId);

        $response->assertStatus(200);
    }

    public function testCashierWithoutGrantCannotChangePrice(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '0.01',
            'quantity'     => '1',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.not_authorized'));

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('5.00', $cart[1]['price']);
    }

    public function testCashierWithoutGrantCanEditQuantityAtSamePrice(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '5.00',
            'quantity'     => '3',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('3', $cart[1]['quantity']);
        $this->assertEquals('5.00', $cart[1]['price']);
    }

    public function testCashierWithGrantCanChangePrice(): void
    {
        $cashierId = $this->createCashierWithChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '0.01',
            'quantity'     => '1',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('0.01', $cart[1]['price']);
    }

    public function testCashierWithoutDiscountGrantCannotChangeDiscount(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'   => 'Test Item',
            'serialnumber'  => '',
            'price'         => '5.00',
            'quantity'      => '1',
            'discount'      => '2',
            'discount_type' => (string)FIXED,
            'location'      => '1',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.not_authorized'));

        $cart = Services::session()->get('sales_cart');
        $this->assertEquals(0.0, (float)$cart[1]['discount']);
    }

    public function testCashierWithoutDiscountGrantCanResubmitExistingDiscount(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId, '2', FIXED);

        $response = $this->post('/sales/editItem/1', [
            'description'   => 'Test Item',
            'serialnumber'  => '',
            'price'         => '5.00',
            'quantity'      => '2',
            'discount'      => '2',
            'discount_type' => (string)FIXED,
            'location'      => '1',
        ]);

        $response->assertStatus(200);
        $response->assertDontSee(lang('Sales.not_authorized'));

        $cart = Services::session()->get('sales_cart');
        $this->assertEquals('2', $cart[1]['quantity']);
        $this->assertEquals(2.0, (float)$cart[1]['discount']);
    }

    public function testCashierWithDiscountGrantCanChangeDiscount(): void
    {
        $cashierId = $this->createCashierWithDiscountGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'   => 'Test Item',
            'serialnumber'  => '',
            'price'         => '5.00',
            'quantity'      => '1',
            'discount'      => '2',
            'discount_type' => (string)FIXED,
            'location'      => '1',
        ]);

        $response->assertStatus(200);
        $response->assertDontSee(lang('Sales.not_authorized'));

        $cart = Services::session()->get('sales_cart');
        $this->assertEquals(2.0, (float)$cart[1]['discount']);
        $this->assertEquals(FIXED, (int)$cart[1]['discount_type']);
    }

    public function testCashierWithoutReturnGrantCannotCompleteReturn(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore, $salesCountAfter);
        $response->assertSee(lang('Sales.not_authorized'));
    }

    public function testCashierWithReturnGrantPassesReturnAuthorization(): void
    {
        $cashierId = $this->createCashierWithReturnGrant();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $response->assertDontSee(lang('Sales.not_authorized'));
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
    }

    public function testChangeModeRejectsStockLocationOutsideEmployeeGrants(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $response = $this->post('/sales/changeMode', [
            'mode'           => 'sale',
            'stock_location' => '999999',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, Services::session()->get('sales_location'));
    }

    public function testChangeModeAcceptsGrantedStockLocation(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->withSession(array_merge($this->session, ['sales_location' => 999999]));

        $response = $this->post('/sales/changeMode', [
            'mode'           => 'sale',
            'stock_location' => '1',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, Services::session()->get('sales_location'));
    }

    public function testPostAddPaymentRejectsNegativeAmountTendered(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem();
        $this->seedCartLine(1, '1.00', $itemId);

        $forgedPaymentType = lang('Sales.giftcard') . ':AUDIT-100';

        $response = $this->post('/sales/addPayment', [
            'payment_type'    => $forgedPaymentType,
            'amount_tendered' => '-500',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.must_enter_numeric'));

        // ... and the negative payment must NOT have been added to the cart.
        $session  = Services::session();
        $payments = $session->get('sales_payments');
        $this->assertArrayNotHasKey($forgedPaymentType, (array) $payments);
    }

    public function testPostAddPaymentRejectsMalformedAmountTendered(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem();
        $this->seedCartLine(1, '1.00', $itemId);

        $response = $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => 'ABC123',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.must_enter_numeric'));

        $session  = Services::session();
        $payments = $session->get('sales_payments');
        $this->assertArrayNotHasKey(lang('Sales.cash'), (array) $payments);
    }

    public function testCashierWithoutReportsSalesCannotUnsuspend(): void
    {
        $victimId = $this->createReportsSalesEmployee();
        $saleId = $this->createSuspendedSale($victimId);

        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $response = $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        $session = Services::session();
        $this->assertNotEquals($saleId, $session->get('sale_id'));
    }

    public function testEmployeeWithReportsSalesCannotUnsuspendCompletedSale(): void
    {
        $victimId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($victimId);

        $supervisorId = $this->createReportsSalesEmployee();
        $this->loginAs($supervisorId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $session = Services::session();
        $this->assertNotEquals($saleId, $session->get('sale_id'));
    }

    public function testEmployeeWithReportsSalesCanUnsuspendSale(): void
    {
        $ownerId = $this->createReportsSalesEmployee();
        $saleId = $this->createSuspendedSale($ownerId);

        $supervisorId = $this->createReportsSalesEmployee();
        $this->loginAs($supervisorId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $session = Services::session();
        $this->assertEquals($saleId, $session->get('sale_id'));
    }

    protected function createGiftcard(float $value): int
    {
        $giftcardNumber = random_int(1000000, 9999999);

        Database::connect()->table('giftcards')->insert([
            'giftcard_number' => $giftcardNumber,
            'value'           => $value,
            'deleted'         => 0,
            'person_id'       => null,
        ]);

        return $giftcardNumber;
    }

    protected function getGiftcardValue(int $giftcardNumber): float
    {
        $row = Database::connect()->table('giftcards')
            ->where('giftcard_number', $giftcardNumber)
            ->get()
            ->getRow();

        return (float) $row->value;
    }

    public function testCashierCannotForgeGiftcardPaymentTypeWithNegativeAmount(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $giftcardNumber = $this->createGiftcard(100.00);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
            'amount_tendered' => '-500',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testCashierCanUseLegitimateGiftcardPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $giftcardNumber = $this->createGiftcard(100.00);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.giftcard'),
            'amount_tendered' => (string) $giftcardNumber,
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.giftcard') . ':' . $giftcardNumber, $payments);
    }

    public function testCashierCannotSubmitNegativeAmountForReferenceCodePayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.debit'),
            'amount_tendered' => '-25',
            'reference_code'  => 'ABC123',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
    }

    public function testCashierCanUseLegitimateReferenceCodePayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.debit'),
            'amount_tendered' => '25.00',
            'reference_code'  => 'ABC123',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.debit'), $payments);
        $this->assertSame('ABC123', $payments[lang('Sales.debit')]['reference_code']);
    }

    public function testCashierCannotSubmitNegativeAmountForCashPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '-10',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
    }

    public function testCashierCanUseLegitimateCashPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '10.00',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.cash'), $payments);
    }

    public function testPostCompleteRejectsSaleWithInsufficientPayments(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore, $salesCountAfter);
    }

    public function testPostCompleteAllowsZeroPaymentQuoteCompletion(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_quote']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
        $response->assertDontSee(lang('Sales.amount_due_not_covered'));
    }

    public function testPostCompleteAllowsZeroPaymentInvoiceCompletion(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_invoice']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
        $response->assertDontSee(lang('Sales.amount_due_not_covered'));
    }

    public function testRegisterEscapesMaliciousTaxName(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem();

        Database::connect()->table('items_taxes')->insert([
            'item_id' => $itemId,
            'name'    => '<svg onload=alert(1)>',
            'percent' => 5,
        ]);

        $this->loginAs($cashierId);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $body = $response->getBody();
        $this->assertStringNotContainsString('<svg onload', $body);
        $this->assertStringContainsString('&lt;svg', $body);
    }

    public function testPostUnsuspendRejectsNonSuspendedSaleWithoutClearingActiveCart(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $notSuspendedSaleId = $this->createSale($employeeId);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '1.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => (string) $notSuspendedSaleId,
        ]);

        $cart = Services::session()->get('sales_cart');
        $this->assertNotEmpty($cart);
        $this->assertArrayHasKey(1, $cart);
        $this->assertSame($itemId, $cart[1]['item_id']);
        $this->assertSame(NEW_ENTRY, Services::session()->get('sale_id'));
    }

    public function testPostUnsuspendReplacesCartForValidSuspendedSale(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $suspendedSaleId = $this->createSuspendedSale($employeeId);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => (string) $suspendedSaleId,
        ]);

        $this->assertSame($suspendedSaleId, Services::session()->get('sale_id'));
        $cart = Services::session()->get('sales_cart');
        $this->assertNotEmpty($cart);
        $this->assertNotSame($itemId, array_values($cart)[0]['item_id']);
    }

    public function testRegisterRendersRtlDirectionForArabicEmployee(): void
    {
        $cashierId = $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'Arabic',
            email:      'cashier-arabic.' . uniqid() . '@test.com',
            username:   'cashier_arabic_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
            ],
            employee: ['language_code' => 'ar-EG', 'language' => 'arabic'],
        );
        $this->loginAs($cashierId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $this->assertStringContainsString('lang="ar-EG" dir="rtl"', $response->getBody());
    }

    public function testRegisterRendersLtrDirectionForEnglishEmployee(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $this->assertStringContainsString('dir="ltr"', $response->getBody());
    }

    public function testRegisterRendersRuyaBrandWordmark(): void
    {
        $ospos = config(OSPOS::class);
        $originalCompany = $ospos->settings['company'] ?? null;
        $ospos->settings['company'] = 'Test Co';

        try {
            $cashierId = $this->createCashierEmployee();
            $this->loginAs($cashierId);

            $response = $this->get('/sales');

            $response->assertStatus(200);
            // TestResponse::getBody() re-serializes the parsed DOM, which encodes
            // non-ASCII characters as numeric entities, so decode before matching.
            $body = html_entity_decode($response->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // The register follows the employee's language: English here, so the
            // Latin wordmark is shown and no Arabic brand text leaks in.
            $this->assertStringContainsString("Ru'ya", $body);
            $this->assertStringNotContainsString('رؤية', $body);
            $this->assertStringNotContainsString('دحروج', $body);
            $this->assertStringNotContainsString('Dahrouj', $body);
            $this->assertStringNotContainsString('Open Source Point of Sale', $body);
        } finally {
            if ($originalCompany === null) {
                unset($ospos->settings['company']);
            } else {
                $ospos->settings['company'] = $originalCompany;
            }
        }
    }

    public function testRegisterShowsReturnModeMarkerWhenInReturnMode(): void
    {
        $cashierId = $this->createCashierWithReturnGrant();
        $this->loginAs($cashierId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $body = $response->getBody();
        $this->assertStringContainsString('dj-return-banner', $body);
        $this->assertStringContainsString('dj-mode-return', $body);
    }

    public function testRegisterDoesNotShowReturnModeMarkerDuringSale(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale']));

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('dj-return-banner', $response->getBody());
    }

    /**
     * A USB HID barcode scanner behaves like a keyboard: characters arrive in
     * the "find or scan" field, then the terminating Enter submits the form
     * once. postAdd() must resolve the product by the exact barcode string
     * (leading zeros preserved — no numeric coercion) and place exactly one
     * cart line with quantity 1.
     */
    public function testAddByScannedBarcodeKeepsLeadingZeroBarcodeAndAddsOnce(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $barcode  = '0123456789';
        $itemData = [
            'item_id'               => null,
            'name'                  => 'Scanner Item',
            'description'           => 'Scanner Item',
            'category'              => 'Test Category',
            'cost_price'            => 1.00,
            'unit_price'            => 5.00,
            'reorder_level'         => 0,
            'item_number'           => $barcode,
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'stock_type'            => HAS_STOCK,
            'deleted'               => 0,
        ];
        model(Item::class)->save_value($itemData);
        $itemId = (int) $itemData['item_id'];

        $response = $this->post('/sales/add', ['item' => $barcode, 'quantity' => 1]);

        $response->assertOk();
        $this->assertStringContainsString($barcode, $response->getBody());

        $saleLib = new Sale_lib();
        $cart    = $saleLib->get_cart();

        $line = reset($cart);
        $this->assertCount(1, $cart, 'A single scan must produce exactly one cart line');
        $this->assertSame($itemId, (int) $line['item_id']);
        $this->assertSame($barcode, (string) $line['item_number'], 'Leading-zero barcode must not be coerced');
        $this->assertEquals(1.0, (float) $line['quantity']);
    }
}
