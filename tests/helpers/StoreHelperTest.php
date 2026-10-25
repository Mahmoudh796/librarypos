<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

class StoreHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../app/Helpers/store_helper.php';
    }

    protected function tearDown(): void
    {
        Factories::reset();
        parent::tearDown();
    }

    private function useSettings(array $settings): void
    {
        $config           = new OSPOS();
        $config->settings = $settings;
        Factories::injectMock('config', OSPOS::class, $config);
    }

    public function testInstallerSampleValuesAreNotConfigured(): void
    {
        $this->useSettings(['address' => '123 Nowhere street', 'phone' => '555-555-5555', 'email' => 'changeme@example.com', 'return_policy' => 'Test']);

        $this->assertSame('', store_info('address'));
        $this->assertSame('', store_info('phone'));
        $this->assertSame('', store_info('email'));
        $this->assertSame('', store_info('return_policy'));
        $this->assertSame(['address', 'phone', 'email', 'return_policy'], store_info_placeholders());
    }

    public function testOwnerValuesAreReturnedAsTyped(): void
    {
        $this->useSettings(['address' => 'شارع التحرير، القاهرة', 'phone' => '0100 000 0000', 'return_policy' => 'الاستبدال خلال 14 يومًا']);

        $this->assertSame('شارع التحرير، القاهرة', store_info('address'));
        $this->assertSame('0100 000 0000', store_info('phone'));
        $this->assertSame('الاستبدال خلال 14 يومًا', store_info('return_policy'));
        $this->assertSame('', store_info('email'));
        $this->assertSame([], store_info_placeholders());
    }
}
