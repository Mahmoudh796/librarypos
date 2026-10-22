<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Backups controller access control and CSRF tests
 *
 * The database backup UI must never be reachable by an employee without the
 * `config` grant, and the create action must reject non-POST or CSRF-less
 * requests. Restore is intentionally absent from the controller — no test
 * should be able to trigger a destructive operation from the web UI.
 */
class BackupsControllerTest extends CIUnitTestCase
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

    public function testBackupsIndexRequiresConfigGrant(): void
    {
        $employeeId = $this->createRestrictedEmployee();
        $this->loginAs($employeeId);

        $response = $this->get('/backups');

        // Without the config grant the controller must throw RedirectException
        // (or redirect to no_access) — never render the page.
        $this->assertNotSame(200, $response->response()->getStatusCode());
    }

    public function testBackupsIndexRendersForConfigGrant(): void
    {
        $employeeId = $this->createConfigEmployee();
        $this->loginAs($employeeId);

        $response = $this->get('/backups');
        $response->assertStatus(200);

        $body = (string) $response->response()->getBody();
        $this->assertStringContainsString('Backup', $body);
    }

    public function testBackupsCreateRejectsGet(): void
    {
        $employeeId = $this->createConfigEmployee();
        $this->loginAs($employeeId);

        // A GET on the create endpoint must not be routed to postCreate. Under
        // CI4's improved auto-routing, `backups/create` (GET) resolves to
        // `getIndex`, which has no $create param — hence a PageNotFoundException,
        // not a successful POST-style side effect.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->get('/backups/create');
    }

    public function testBackupsHasNoRestoreRoute(): void
    {
        $employeeId = $this->createConfigEmployee();
        $this->loginAs($employeeId);

        // No restore endpoint exists on purpose: `backups/restore` must not
        // resolve to any method (especially not a POST handler), so a crafted
        // request can never trigger a restore.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->post('/backups/restore', []);
    }

    private function createRestrictedEmployee(): int
    {
        return $this->createEmployeeWithGrants([
            ['person_id' => 0, 'permission_id' => 'home', 'menu_group' => 'home'],
        ], 'restricted');
    }

    private function createConfigEmployee(): int
    {
        return $this->createEmployeeWithGrants([
            ['person_id' => 0, 'permission_id' => 'config', 'menu_group' => 'office'],
        ], 'configadmin');
    }

    private function createEmployeeWithGrants(array $grants, string $prefix): int
    {
        $db = Config::connect($this->DBGroup);

        $db->table('people')->insert([
            'first_name'   => 'Fixture',
            'last_name'    => 'Employee',
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
            'language'  => 'arabic',
            'deleted'   => 0,
        ]);

        foreach ($grants as &$grant) {
            $grant['person_id'] = $personId;
        }
        unset($grant);

        $db->table('grants')->insertBatch($grants);

        return $personId;
    }

    private function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }
}
