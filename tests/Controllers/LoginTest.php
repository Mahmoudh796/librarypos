<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
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
        service('superglobals')->unsetCookie('language');
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

    /**
     * A locale explicitly chosen on the sign-in switcher must become the
     * account's persisted preference once authentication succeeds, instead of
     * being dropped in favour of a stale stored value.
     */
    public function testSignInPromotesExplicitSwitcherChoiceToTheAccountPreference(): void
    {
        $db     = Database::connect();
        $before = $db->table('employees')->where('person_id', 1)->get()->getRow();

        try {
            // Clear the stored preference so the assertion proves the sign-in
            // choice, not a pre-existing value, drove the persisted locale.
            $db->table('employees')->where('person_id', 1)->update([
                'language'      => null,
                'language_code' => null,
            ]);
            service('superglobals')->setCookie('language', 'en');

            $response = $this->post('/login', [
                'username' => 'admin',
                'password' => 'pointofsale',
            ]);
            $this->trackThrottleKeys('admin');
            $this->skipIfMigrationRequired($response);
            $response->assertRedirectTo('home');

            $after = $db->table('employees')->where('person_id', 1)->get()->getRow();
            $this->assertSame('english', $after->language);
            $this->assertSame('en', $after->language_code);
        } finally {
            $db->table('employees')->where('person_id', 1)->update([
                'language'      => $before->language,
                'language_code' => $before->language_code,
            ]);
            service('superglobals')->unsetCookie('language');
        }
    }

    /**
     * When no choice was made before sign-in, the stored account preference is
     * mirrored into the language cookie so the next sign-in screen agrees with
     * the application after sign-out.
     */
    public function testSignInMirrorsAccountPreferenceIntoTheLanguageCookie(): void
    {
        $db     = Database::connect();
        $before = $db->table('employees')->where('person_id', 1)->get()->getRow();

        try {
            $db->table('employees')->where('person_id', 1)->update([
                'language'      => 'english',
                'language_code' => 'en',
            ]);

            $response = $this->post('/login', [
                'username' => 'admin',
                'password' => 'pointofsale',
            ]);
            $this->trackThrottleKeys('admin');
            $this->skipIfMigrationRequired($response);
            $response->assertRedirectTo('home');
            $response->assertCookie('language', 'en');
        } finally {
            $db->table('employees')->where('person_id', 1)->update([
                'language'      => $before->language,
                'language_code' => $before->language_code,
            ]);
            service('superglobals')->unsetCookie('language');
        }
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
     * The login / database update screen must present the Ru'ya identity and
     * must not surface the upstream product identity or the previous Dahrouj
     * brand as this application's brand.
     */
    public function testLoginPagePresentsRuyaIdentityWithoutLegacyProductBranding(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        // The default (English) sign-in screen shows the Latin wordmark, and no
        // Arabic brand text leaks into it.
        $this->assertStringContainsString("Ru'ya", $body);
        $this->assertStringNotContainsString('رؤية', $body);

        $this->assertStringNotContainsString('دحروج', $body);
        $this->assertStringNotContainsString('Dahrouj', $body);
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
            $this->assertStringContainsString('رؤية', $body);
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

    /**
     * The login card must expose a real, keyboard-reachable recovery action,
     * not a dead link.
     */
    public function testLoginPageLinksToRecoveryScreen(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        $this->assertStringContainsString('login/recovery', $body);
        $this->assertStringContainsString(lang('Login.forgot_username_or_password'), $body);
    }

    /**
     * Recovery must be reachable without a session.
     */
    public function testRecoveryPageIsAccessibleWhileLoggedOut(): void
    {
        $response = $this->get('/login/recovery');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        $this->assertStringContainsString(lang('Login.recovery_title'), $body);
        $this->assertStringContainsString(lang('Login.back_to_login'), $body);
    }

    public function testRecoveryPageRendersLeftToRightByDefault(): void
    {
        $response = $this->get('/login/recovery');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        $this->assertMatchesRegularExpression('/<html[^>]*\bdir="ltr"/', $body);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\bdir="rtl"/', $body);
    }

    public function testRecoveryPageRendersRightToLeftForArabicSystemLanguage(): void
    {
        $ospos = config(OSPOS::class);
        $original = $ospos->settings;

        $ospos->settings['language'] = 'arabic';
        $ospos->settings['language_code'] = 'ar-EG';

        try {
            $response = $this->get('/login/recovery');
            $response->assertStatus(200);

            $body = $this->decodedBody($response);

            $this->assertStringContainsString('lang="ar-EG"', $body);
            $this->assertMatchesRegularExpression('/<html[^>]*\bdir="rtl"/', $body);
            $this->assertStringContainsString('رؤية', $body);
        } finally {
            $ospos->settings = $original;
        }
    }

    /**
     * The recovery screen must not offer any credential lookup, so it cannot be
     * used to probe whether a username / e-mail belongs to a valid account.
     */
    public function testRecoveryPageDoesNotDiscloseAccountExistence(): void
    {
        $response = $this->get('/login/recovery');
        $response->assertStatus(200);

        $body = $this->decodedBody($response);

        $this->assertStringNotContainsString('name="username"', $body);
        $this->assertStringNotContainsString('name="password"', $body);
        $this->assertDoesNotMatchRegularExpression('/\b(reset[_-]?token|token)\b/i', $body);
    }
}
