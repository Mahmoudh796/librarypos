<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

class ArabicEncodingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
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
    }

    private function render(string $path): TestResponse
    {
        service('renderer')->resetData();
        $this->withSession(['person_id' => 1]);
        return $this->get($path);
    }

    public function testArabicPagesRenderValidArabicAndNoMojibake(): void
    {
        foreach ([
            '/home'      => ['رؤية'],
            '/items'     => ['رؤية', 'الأصناف'],
            '/customers' => ['رؤية', 'العملاء'],
            '/suppliers' => ['رؤية', 'الموردين'],
            '/reports'   => ['رؤية'],
        ] as $path => $needles) {
            service('renderer')->resetData();
            service('superglobals')->setCookie('language', 'ar-EG');
            $this->withSession(['person_id' => 1]);
            $resp = $this->get($path);
            $resp->assertStatus(200);
            $body = html_entity_decode($resp->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertTrue(mb_check_encoding($body, 'UTF-8'), "[$path] Not valid UTF-8");
            $this->assertDoesNotMatchRegularExpression('/\x{0637}[\x{00A7}\x{201E}\x{0638}]/u', $body, "[$path] Contains CP1256 double-encoded pattern");
            $this->assertDoesNotMatchRegularExpression('/[\x{256A}\x{2556}\x{2518}\x{2551}\x{255E}\x{2566}\x{2592}]/u', $body, "[$path] Contains CP437 box-drawing");
            $this->assertStringNotContainsString('دحروج', $body, "[$path] Contains legacy Dahrouj");
            $this->assertStringNotContainsString('Dahrouj', $body, "[$path] Contains Dahrouj");
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $body, "[$path] Missing: $needle");
            }
        }
    }

    public function testItemsModalAndVariantsRenderValidArabic(): void
    {
        service('superglobals')->setCookie('language', 'ar-EG');
        $this->withSession(['person_id' => 1]);

        $view = $this->get('/items/view');
        $view->assertStatus(200);
        $body = html_entity_decode($view->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
        $this->assertStringContainsString('الباركود / ISBN', $body);
        $this->assertStringContainsString('اسم المنتج', $body);
        $this->assertStringNotContainsString('اسم الصنف', $body);
        $this->assertDoesNotMatchRegularExpression('/\x{0637}[\x{00A7}\x{201E}\x{0638}]/u', $body);

        $vars = $this->get('/items/variants');
        $vars->assertStatus(200);
        $vbody = html_entity_decode($vars->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertTrue(mb_check_encoding($vbody, 'UTF-8'));
        $this->assertDoesNotMatchRegularExpression('/\x{0637}[\x{00A7}\x{201E}\x{0638}]/u', $vbody);
    }

    public function testEnglishRemainsLtrAndValid(): void
    {
        service('superglobals')->setCookie('language', 'en');
        $this->withSession(['person_id' => 1]);

        $resp = $this->get('/items');
        $resp->assertStatus(200);
        $body = html_entity_decode($resp->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
        $this->assertDoesNotMatchRegularExpression('/\x{0637}[\x{00A7}\x{201E}\x{0638}]/u', $body);
        $this->assertStringContainsString('Items', $body);
        $this->assertStringContainsString('Ru\'ya', $body);
    }

    public function testSharedWorkspaceFoundationRendersEverywhere(): void
    {
        service('superglobals')->setCookie('language', 'ar-EG');
        $this->withSession(['person_id' => 1]);

        foreach ([
            '/items'      => ['dj-page-head', 'dj-page-title', 'dj-footer', 'modal-dlg-wide'],
            '/customers'  => ['dj-page-head', 'dj-page-title'],
            '/suppliers'  => ['dj-page-head', 'dj-page-title'],
            '/giftcards'  => ['dj-page-head', 'dj-page-title'],
            '/reports'    => ['dj-page-head', 'reports-hub'],
            '/sales'      => ['dj-page-head', 'dj-register'],
            '/receivings' => ['dj-page-head'],
            '/messages'   => ['dj-form-panel'],
        ] as $path => $needles) {
            service('renderer')->resetData();
            service('superglobals')->setCookie('language', 'ar-EG');
            $this->withSession(['person_id' => 1]);
            $resp = $this->get($path);
            $resp->assertStatus(200);
            $body = $resp->getBody();
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $body, "[$path] Missing shared component: $needle");
            }
        }
    }

    public function testSharedFormGridRendersOnCustomerSupplierAndItemForms(): void
    {
        foreach ([
            'ar-EG' => [
                '/customers/view' => ['customer_basic_info', 'dj-form-grid', 'dj-field--check', 'dj-check required'],
                '/suppliers/view' => ['supplier_basic_info', 'dj-form-grid', 'dj-field--full'],
                '/items/view'     => ['item_basic_info', 'dj-form-grid', 'dj-field--full', 'dj-field--split', 'dj-field--check'],
            ],
            'en' => [
                '/customers/view' => ['dj-form-grid'],
                '/suppliers/view' => ['dj-form-grid'],
                '/items/view'     => ['dj-form-grid'],
            ],
        ] as $locale => $paths) {
            foreach ($paths as $path => $needles) {
                service('renderer')->resetData();
                service('superglobals')->setCookie('language', $locale);
                $this->withSession(['person_id' => 1]);
                $resp = $this->get($path);
                $resp->assertStatus(200);
                $body = $resp->getBody();
                foreach ($needles as $needle) {
                    $this->assertStringContainsString($needle, $body, "[$locale $path] Missing shared form component: $needle");
                }
            }
        }
    }

    public function testCustomerFormUsesAmeelInsteadOfZaboun(): void
    {
        service('renderer')->resetData();
        service('superglobals')->setCookie('language', 'ar-EG');
        $this->withSession(['person_id' => 1]);

        $body = html_entity_decode($this->get('/customers/view')->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('معلومات العميل', $body);
        $this->assertStringNotContainsString('زبون', $body);
        $this->assertStringNotContainsString('الزبائن', $body);
    }

    public function testFooterIsLocalisedAndFreeOfLegacyBranding(): void
    {
        foreach (['ar-EG' => 'رؤية', 'en' => 'Ru\'ya'] as $locale => $wordmark) {
            service('renderer')->resetData();
            service('superglobals')->setCookie('language', $locale);
            $this->withSession(['person_id' => 1]);
            $resp = $this->get('/items');
            $resp->assertStatus(200);
            $body = html_entity_decode($resp->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->assertStringContainsString('dj-footer', $body, "[$locale] Footer is not the shared Ru'ya footer");
            $this->assertStringContainsString($wordmark, $body, "[$locale] Footer wordmark missing");

            // The legacy date-range presentation and upstream branding must not return.
            $this->assertStringNotContainsString('2010 -', $body, "[$locale] Legacy copyright range present");
            $this->assertStringNotContainsString('Open Source Point of Sale', $body, "[$locale] Upstream product name present");
            $this->assertStringNotContainsString('opensourcepos.org', $body, "[$locale] Upstream website present");
            $this->assertStringNotContainsString('OSPOS', $body, "[$locale] Upstream acronym present");
        }
    }
}
