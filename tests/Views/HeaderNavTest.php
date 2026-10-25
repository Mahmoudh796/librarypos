<?php

namespace Tests\Views;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Regression suite for the authenticated page shell (app/Views/partial/header.php).
 *
 * Locks the three structural contracts of the navigation rework:
 *   1. Dashboard is the first, explicit primary nav entry when the employee
 *      holds the grant.
 *   2. Low-frequency modules live in a labelled admin/system dropdown, not a
 *      vague "More" menu, and that dropdown only renders granted modules.
 *   3. The backup destination appears only for the `config` grant.
 *
 * Every fixture is built here so the suite does not depend on the dev
 * database's grant layout.
 */
class HeaderNavTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = null;

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

    /**
     * @param array<int, array{permission_id: string, menu_group: string}> $grants
     */
    private function createEmployeeWithGrants(array $grants, string $prefix = 'nav'): int
    {
        $db = Config::connect($this->DBGroup);

        $db->table('people')->insert([
            'first_name'   => 'Nav',
            'last_name'    => 'Test',
            'email'        => uniqid($prefix, true) . '@test.local',
            'phone_number' => '0000000000',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('employees')->insert([
            'person_id' => $personId,
            'username'  => $prefix . '_' . uniqid(),
            'password'  => password_hash('secret123', PASSWORD_DEFAULT),
            'language'  => 'english',
            'deleted'   => 0,
        ]);

        $grantRows = [];
        foreach ($grants as $grant) {
            $grantRows[] = [
                'person_id'     => $personId,
                'permission_id' => $grant['permission_id'],
                'menu_group'    => $grant['menu_group'],
            ];
        }
        $db->table('grants')->insertBatch($grantRows);

        return $personId;
    }

    private function renderHeaderAs(int $personId): string
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);

        $response = $this->get('/home');
        $response->assertStatus(200);

        return (string) $response->response()->getBody();
    }

    private function navBlock(string $body): string
    {
        $matched = preg_match('/<nav class="dj-nav collapse" id="dj-primary-nav">.*?<\/nav>/s', $body, $matches);

        $this->assertNotSame(0, $matched, 'The primary nav must be rendered in the header.');

        return $matches[0];
    }

    public function testDashboardEntryIsFirstPrimaryNavItemWhenGranted(): void
    {
        $personId = $this->createEmployeeWithGrants([
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'items', 'menu_group' => 'home'],
        ], 'dashfirst');

        $nav = $this->navBlock($this->renderHeaderAs($personId));

        preg_match_all('/<a[^>]+class="dj-nav-link[^"]*"[^>]+href="([^"]+)"/', $nav, $navLinks);

        $this->assertNotEmpty($navLinks[1], 'At least one primary nav link must exist.');
        $this->assertStringEndsWith(
            '/home',
            $navLinks[1][0],
            'The first primary nav link must always be the Dashboard (home) entry, regardless of module sort order.'
        );
        $this->assertStringContainsString(lang('Common.dashboard'), $nav);
    }

    public function testAdminMenuReplacesVagueMoreMenu(): void
    {
        $personId = $this->createEmployeeWithGrants([
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'config', 'menu_group' => 'office'],
            ['permission_id' => 'employees', 'menu_group' => 'office'],
        ], 'adminmenu');

        $nav = $this->navBlock($this->renderHeaderAs($personId));

        $this->assertStringNotContainsString(
            lang('Common.more'),
            $nav,
            'The vague "More" overflow label must be replaced by an explicit admin/system menu.'
        );
        $this->assertStringContainsString(
            lang('Common.admin'),
            $nav,
            'The admin/system menu must be labelled with lang("Common.admin").'
        );
        $this->assertMatchesRegularExpression(
            '/<li class="dj-nav-item dropdown[^"]*dj-nav-admin[^"]*">.*?data-toggle="dropdown".*?<\/ul>/s',
            $nav,
            'The admin/system dropdown must exist with its permission-filtered links.'
        );
    }

    public function testUnauthorizedModulesAreAbsentFromBothNavBands(): void
    {
        $personId = $this->createEmployeeWithGrants([
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home'],
        ], 'limited');

        $nav = $this->navBlock($this->renderHeaderAs($personId));

        $this->assertStringNotContainsString(base_url('config'), $nav, 'config must not render without its grant.');
        $this->assertStringNotContainsString(base_url('employees'), $nav, 'employees must not render without its grant.');
        $this->assertStringContainsString(base_url('sales'), $nav, 'Granted modules must still render.');
    }

    public function testAccountingGroupOffersOnlyGrantedScreens(): void
    {
        $personId = $this->createEmployeeWithGrants([
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'accounting', 'menu_group' => 'office'],
            ['permission_id' => 'accounting_view', 'menu_group' => '--'],
        ], 'acctview');

        $nav = $this->navBlock($this->renderHeaderAs($personId));

        $this->assertStringContainsString(base_url('accounting/trialBalance'), $nav);
        $this->assertStringContainsString(base_url('journal'), $nav);
        $this->assertStringNotContainsString(base_url('fixed_assets'), $nav, 'Fixed assets need accounting_assets.');
        $this->assertStringNotContainsString(base_url('shareholders'), $nav, 'Partners need accounting_shareholders.');
        $this->assertStringNotContainsString(base_url('accounts/periods'), $nav, 'Periods need accounting_periods.');
    }

    public function testBusinessGroupsAreLabelledAndOrdered(): void
    {
        $personId = $this->createEmployeeWithGrants([
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'receivings', 'menu_group' => 'home'],
            ['permission_id' => 'items', 'menu_group' => 'home'],
            ['permission_id' => 'expenses', 'menu_group' => 'office'],
        ], 'groups');

        $nav = $this->navBlock($this->renderHeaderAs($personId));

        $positions = array_map(static fn (string $key): int|false => strpos($nav, 'dj-nav-group--' . $key), ['sales', 'purchases', 'inventory', 'costs']);
        $this->assertNotContains(false, $positions, 'Each granted business group must render.');
        $this->assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Groups follow the business workflow order.');
        $this->assertStringNotContainsString('dj-nav-group--treasury', $nav, 'A group with no granted screen is not rendered.');
    }

    public function testBackupLinkOnlyShownWhenConfigGrantPresent(): void
    {
        $withoutConfig = $this->createEmployeeWithGrants([
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home'],
        ], 'noconfig');

        $this->assertStringNotContainsString(
            base_url('backups'),
            $this->navBlock($this->renderHeaderAs($withoutConfig)),
            'Backup link must not appear when the employee lacks the config grant.'
        );

        $withConfig = $this->createEmployeeWithGrants([
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'config', 'menu_group' => 'office'],
        ], 'withconfig');

        $this->assertStringContainsString(
            base_url('backups'),
            $this->navBlock($this->renderHeaderAs($withConfig)),
            'Backup link must appear when the employee holds the config grant.'
        );
    }
}
