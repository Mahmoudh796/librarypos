<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test suite for the sign-in / recovery language switcher endpoint.
 *
 * The switcher must only ever persist a whitelisted locale, must return the
 * visitor to a same-site path, and must never redirect off-site.
 */
class LanguageControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * The redirect and response services are shared, so a cookie queued by one
     * test would otherwise leak into the next one's assertions.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
    }

    public function testSwitcherStoresArabicLocaleAndReturnsToSignIn(): void
    {
        $result = $this->post('language/set', [
            'language' => 'ar-EG',
            'redirect' => 'login',
        ]);

        $result->assertRedirect();
        $result->assertRedirectTo('login');
        $result->assertCookie('language', 'ar-EG');
    }

    public function testSwitcherStoresEnglishLocaleAndReturnsToSignIn(): void
    {
        $result = $this->post('language/set', [
            'language' => 'en',
            'redirect' => 'login',
        ]);

        $result->assertRedirect();
        $result->assertRedirectTo('login');
        $result->assertCookie('language', 'en');
    }

    public function testSwitcherIgnoresLocaleOutsideTheWhitelist(): void
    {
        $result = $this->post('language/set', [
            'language' => 'xx-NOTALOCALE',
            'redirect' => 'login',
        ]);

        $result->assertRedirect();
        $result->assertRedirectTo('login');
        $result->assertCookieMissing('language');
    }

    public function testSwitcherIgnoresLocaleCarryingInjectionCharacters(): void
    {
        $result = $this->post('language/set', [
            'language' => '../etc/passwd',
            'redirect' => 'login',
        ]);

        $result->assertRedirect();
        $result->assertCookieMissing('language');
    }

    public function testSwitcherFollowsASafeRelativeRedirect(): void
    {
        $result = $this->post('language/set', [
            'language' => 'en',
            'redirect' => 'login/recovery',
        ]);

        $result->assertRedirectTo('login/recovery');
        $result->assertCookie('language', 'en');
    }

    public function testSwitcherNeverRedirectsOffSite(): void
    {
        foreach ([
            'https://evil.example/phish',
            'http://evil.example',
            '//evil.example',
            '/\\evil.example',
        ] as $external) {
            $result = $this->post('language/set', [
                'language' => 'en',
                'redirect' => $external,
            ]);

            $result->assertRedirect();
            $this->assertStringNotContainsString(
                'evil.example',
                (string) $result->getRedirectUrl(),
                "Redirect to '{$external}' escaped the current site."
            );
        }
    }
}
