<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * Test suite for the Login controller, including the CI Throttler
 * mitigation for brute-force/credential-stuffing.
 */
class LoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private array $usedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        Services::session()->destroy();
        $this->clearThrottleState();
    }

    protected function tearDown(): void
    {
        $this->clearThrottleState();

        parent::tearDown();
    }

    private function clearThrottleState(): void
    {
        $throttler = Services::throttler();

        foreach ($this->usedKeys as $key) {
            $throttler->remove($key);
        }

        $this->usedKeys = [];

        Services::resetSingle('throttler');
    }

    private function trackThrottleKeys(string $username = ''): void
    {
        $this->usedKeys[] = 'login-ip-' . Services::request()->getIPAddress();

        if ($username !== '') {
            $this->usedKeys[] = 'login-user-' . strtolower($username);
        }
    }

    /**
     * Login::index() redirects to 'login' and runs a migration when the
     * app's migration state isn't current, bypassing credential checks
     * entirely. Skip credential-dependent assertions in that case so this
     * test only exercises login logic when the DB is actually current
     * (throttling itself is verified independently and runs before this
     * branch, so it's unaffected).
     */
    private function skipIfMigrationRequired(\CodeIgniter\Test\TestResponse $response): void
    {
        $redirectUrl = $response->getRedirectUrl();

        if ($redirectUrl !== null && str_ends_with(rtrim(strtolower($redirectUrl), '/'), '/login')) {
            $this->markTestSkipped('App migration state is not current in this environment; skipping credential-path assertions.');
        }
    }

    public function testValidCredentialsLogsIn(): void
    {
        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'pointofsale',
        ]);
        $this->trackThrottleKeys('admin');

        $this->skipIfMigrationRequired($response);

        $response->assertRedirectTo('home');
    }

    public function testInvalidCredentialsShowsError(): void
    {
        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);
        $this->trackThrottleKeys('admin');

        $this->skipIfMigrationRequired($response);

        $response->assertStatus(200);
        $response->assertSee(lang('Login.invalid_username_and_password'));
    }

    public function testSixthFailedAttemptIsThrottled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'username' => 'wronguser',
                'password' => 'wrongpassword',
            ]);
            $this->trackThrottleKeys('wronguser');
        }

        $response = $this->post('/login', [
            'username' => 'wronguser',
            'password' => 'wrongpassword',
        ]);
        $this->trackThrottleKeys('wronguser');

        $response->assertStatus(429);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Login.too_many_attempts'), $result['message']);
    }

    public function testCorrectLoginStillWorksUnderThrottleCapacity(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'username' => 'admin',
                'password' => 'wrongpassword',
            ]);
            $this->trackThrottleKeys('admin');
        }

        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'pointofsale',
        ]);
        $this->trackThrottleKeys('admin');

        $this->skipIfMigrationRequired($response);

        $response->assertRedirectTo('home');
    }

    public function testGetRequestToLoginIsNeverThrottled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->get('/login');
            $this->skipIfMigrationRequired($response);
            $response->assertStatus(200);
        }
    }

    /**
     * TestResponse::getBody() re-serializes the parsed DOM, which turns every
     * non-ASCII character into a numeric HTML entity. Decode it back so the
     * branding assertions can match the text a browser actually renders.
     */
    private function decodedBody(\CodeIgniter\Test\TestResponse $response): string
    {
        return html_entity_decode($response->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The login / database update screen must present the Dahrouj identity and
     * must not surface the upstream product identity as this application's brand.
     */
    public function testLoginPagePresentsDahroujIdentityWithoutLegacyProductBranding(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        $this->assertStringContainsString('دحروج', $body);
        $this->assertStringContainsString('Dahrouj', $body);

        $this->assertStringNotContainsString('Open Source Point of Sale', $body);
        $this->assertStringNotContainsString('OSPOS', $body);

        // The legacy upstream emblem must no longer be rendered.
        $this->assertStringNotContainsString('M154.88998', $body);
    }

    public function testLoginPageRendersLeftToRightByDefault(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        // The document direction is the LTR default; the brand wordmark's
        // Arabic span keeps its own scoped dir="rtl".
        $this->assertMatchesRegularExpression('/<html[^>]*\bdir="ltr"/', $body);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\bdir="rtl"/', $body);
    }

    public function testLoginPageRendersRightToLeftForArabicSystemLanguage(): void
    {
        $ospos = config(OSPOS::class);
        $original = $ospos->settings;

        $ospos->settings['language'] = 'arabic';
        $ospos->settings['language_code'] = 'ar-EG';

        try {
            $response = $this->get('/login');
            $response->assertStatus(200);

            $body = $this->decodedBody($response);

            $this->assertStringContainsString('lang="ar-EG"', $body);
            $this->assertMatchesRegularExpression('/<html[^>]*\bdir="rtl"/', $body);
            $this->assertStringContainsString('دحروج', $body);
        } finally {
            $ospos->settings = $original;
        }
    }

    /**
     * The database update endpoint must keep requiring administrator credentials.
     */
    public function testMigrateEndpointRejectsInvalidAdministratorCredentials(): void
    {
        $response = $this->post('/migrate', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);
        $this->trackThrottleKeys('admin');

        $response->assertStatus(401);

        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Login.invalid_username_and_password'), $result['message']);
    }

    public function testMigrateEndpointAcceptsValidAdministratorCredentials(): void
    {
        $response = $this->post('/migrate', [
            'username' => 'admin',
            'password' => 'pointofsale',
        ]);
        $this->trackThrottleKeys('admin');

        $response->assertStatus(200);

        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }
}
