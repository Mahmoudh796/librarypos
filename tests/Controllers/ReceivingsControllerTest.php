<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class ReceivingsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ItemFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private array $originalSettings = [];

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        Services::resetSingle('renderer');
        $this->originalSettings = config(OSPOS::class)->settings;
    }

    protected function tearDown(): void
    {
        config(OSPOS::class)->settings = $this->originalSettings;

        parent::tearDown();
    }

    private function loginAsReceivingClerk(): void
    {
        $personId = $this->createEmployee(
            first_name: 'Receiving',
            last_name:  'Clerk',
            email:      'receiving.' . uniqid() . '@test.com',
            username:   'receiving_' . uniqid(),
            grants: [
                ['permission_id' => 'receivings', 'menu_group' => 'home'],
                ['permission_id' => 'receivings_stock', 'menu_group' => 'home'],
            ],
        );

        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    private function seedReceivingLine(int $itemId): void
    {
        $this->withSession(array_merge($this->session, [
            'recv_cart' => [
                1 => [
                    'item_id'                    => $itemId,
                    'item_location'              => 1,
                    'item_number'                => 'RECV-TEST',
                    'stock_name'                 => 'Test Location',
                    'line'                       => 1,
                    'name'                       => 'Test Item',
                    'description'                => '',
                    'serialnumber'               => '',
                    'attribute_values'           => null,
                    'attribute_dtvalues'         => null,
                    'allow_alt_description'      => false,
                    'is_serialized'              => false,
                    'quantity'                   => 1.0,
                    'discount'                   => 0.0,
                    'discount_type'              => FIXED,
                    'in_stock'                   => '0',
                    'price'                      => 5.0,
                    'receiving_quantity'         => 1.0,
                    'receiving_quantity_choices' => [1 => 'x1'],
                    'total'                      => 5.0,
                ],
            ],
        ]));
    }

    /**
     * The Arabic register renders cart inputs with Arabic-Indic digits and the
     * Arabic decimal separator, and the browser posts them back verbatim. The
     * discount used to go through FILTER_SANITIZE_NUMBER_FLOAT, which strips
     * every non-ASCII digit, so "٠٫٠٠" reached edit_item()'s float parameter as
     * "" and the request died with a TypeError (HTTP 500).
     */
    public function testEditItemAcceptsArabicIndicDigits(): void
    {
        config(OSPOS::class)->settings['number_locale'] = 'ar-EG';
        $this->loginAsReceivingClerk();
        $this->seedReceivingLine($this->createTestItem());

        $response = $this->post('/receivings/editItem/1', [
            'location'           => '1',
            'price'              => '٧٫٥٠',
            'quantity'           => '٣',
            'receiving_quantity' => '1',
            'discount'           => '٠٫٠٠',
            'description'        => 'Test Item',
        ]);

        $response->assertStatus(200);
        $line = Services::session()->get('recv_cart')[1];
        $this->assertEqualsWithDelta(3.0, $line['quantity'], 0.0001);
        $this->assertEqualsWithDelta(7.5, $line['price'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $line['discount'], 0.0001);
        $this->assertEqualsWithDelta(22.5, $line['total'], 0.0001);
    }

    public function testEditItemAcceptsWesternDigitsAndPercentDiscount(): void
    {
        config(OSPOS::class)->settings['number_locale'] = 'en_US';
        $this->loginAsReceivingClerk();
        $this->seedReceivingLine($this->createTestItem());

        $response = $this->post('/receivings/editItem/1', [
            'location'           => '1',
            'price'              => '10.00',
            'quantity'           => '2',
            'receiving_quantity' => '1',
            'discount'           => '10',
            'discount_type'      => (string) PERCENT,
            'description'        => 'Test Item',
        ]);

        $response->assertStatus(200);
        $line = Services::session()->get('recv_cart')[1];
        $this->assertEqualsWithDelta(10.0, $line['discount'], 0.0001);
        $this->assertSame(PERCENT, $line['discount_type']);
        $this->assertEqualsWithDelta(18.0, $line['total'], 0.0001);
    }

    public function testEditItemWithMissingPriceShowsErrorInsteadOfCrashing(): void
    {
        $this->loginAsReceivingClerk();
        $this->seedReceivingLine($this->createTestItem());

        $response = $this->post('/receivings/editItem/1', [
            'location' => '1',
            'quantity' => '2',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Receivings.error_editing_item'));
        $this->assertEqualsWithDelta(1.0, Services::session()->get('recv_cart')[1]['quantity'], 0.0001);
    }
}
