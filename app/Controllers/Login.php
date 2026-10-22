<?php

namespace App\Controllers;

use App\Libraries\MY_Migration;
use App\Models\Employee;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use Config\Services;

/**
 * @property employee employee
 */
class Login extends BaseController
{
    public Model $employee;

    /**
     * @return RedirectResponse|ResponseInterface|string
     */
    public function index(): string|RedirectResponse|ResponseInterface
    {
        $this->employee = model(Employee::class);
        if (!$this->employee->is_logged_in()) {
            $migration = new MY_Migration(config('Migrations'));
            $config = config(OSPOS::class)->settings;

            $gcaptchaEnabled = array_key_exists('gcaptcha_enable', $config)
                ? $config['gcaptcha_enable']
                : false;

            $migration->migrateToCI4();

            $currentVersion = MY_Migration::getCurrentVersion();

            if ($currentVersion === null) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Login.migration_error_connection')
                ])->setStatusCode(503);
            }

            $latestVersion = $migration->getLatestMigration();

            $validation = Services::validation();

            $data = [
                'hasErrors'       => false,
                'isNewInstall'   => $currentVersion === 0,
                'isLatest'        => $latestVersion === $currentVersion,
                'latestVersion'   => $latestVersion,
                'gcaptchaEnabled' => $gcaptchaEnabled,
                'config'           => $config,
                'validation'       => $validation
            ];

            if ($this->request->getMethod() !== 'POST') {
                return view('login', $data);
            }

            if (!$data['isLatest'] || $data['isNewInstall']) {
                set_time_limit(3600);

                $migration->setNamespace('App')->latest();
                return redirect()->to('login');
            }

            $rules = ['username' => 'required|login_check[data]'];
            $messages = [
                'username' => [
                    'required'    => lang('Login.required_username'),
                    'login_check' => lang('Login.invalid_username_and_password'),
                ]
            ];

            if (!$this->validate($rules, $messages)) {
                $data['hasErrors'] = !empty($validation->getErrors());

                return view('login', $data);
            }

            $this->reconcileLanguagePreference();

            return redirect()->to('home')->withCookies();
        }

        return redirect()->to('home');
    }

    /**
     * Reconciles the sign-in locale with the signed-in employee's account
     * preference at the one point where authentication succeeds.
     *
     * The pre-login locale lives in the "language" cookie while the account
     * preference lives on the employee row. Without a handoff here the two are
     * independent lookups: a locale chosen on the sign-in screen is silently
     * dropped for the stored preference the moment authentication completes.
     *
     * An explicit choice on the sign-in screen therefore becomes the account's
     * persisted preference. When no explicit choice was made, the account
     * preference is mirrored into the cookie so the sign-in screen and the
     * application agree again after sign-out. Both paths keep the cookie as the
     * single active-locale authority, so no second preference store is
     * introduced and the employee row stays a durable copy of the same choice.
     */
    private function reconcileLanguagePreference(): void
    {
        $employeeInfo = $this->employee->get_logged_in_employee_info();

        $preferredCode = get_preferred_language_code();

        if ($preferredCode !== null) {
            $languageName = get_language_name($preferredCode);
            $storedCode   = property_exists($employeeInfo, 'language_code') ? (string)$employeeInfo->language_code : '';

            if ($languageName !== null && $storedCode !== $preferredCode) {
                $this->employee->update((int)$employeeInfo->person_id, [
                    'language'      => $languageName,
                    'language_code' => $preferredCode,
                ]);
            }

            return;
        }

        if (
            property_exists($employeeInfo, 'language_code')
            && !empty($employeeInfo->language_code)
            && in_array($employeeInfo->language_code, config('App')->supportedLocales, true)
        ) {
            set_cookie('language', $employeeInfo->language_code, YEAR, '', '/', '', $this->request->isSecure(), true, 'Lax');
        }
    }

    /**
     * Account-recovery guidance screen.
     *
     * This deployment has no expiring reset-token store and cannot resolve a
     * trustworthy unique employee e-mail, so no credential lookup or reset is
     * offered here. The screen routes the employee to the authorized
     * administrator without disclosing whether any account exists.
     *
     * @return RedirectResponse|string
     */
    public function recovery(): RedirectResponse|string
    {
        $this->employee = model(Employee::class);

        if ($this->employee->is_logged_in()) {
            return redirect()->to('home');
        }

        return view('login_recovery', [
            'config' => config(OSPOS::class)->settings
        ]);
    }

    public function migrate(): ResponseInterface
    {
        $currentVersion = MY_Migration::getCurrentVersion();

        if ($currentVersion === null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Login.migration_error_connection')
            ])->setStatusCode(503);
        }

        // Only a confirmed empty database counts as a new install with no credentials to check.
        $isNewInstall = $currentVersion === 0;

        if (!$isNewInstall) {
            $rules = ['username' => 'required|login_check[data]'];
            $messages = [
                'username' => [
                    'required'    => lang('Login.required_username'),
                    'login_check' => lang('Login.invalid_username_and_password'),
                ]
            ];

            if (!$this->validate($rules, $messages)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Login.invalid_username_and_password')
                ])->setStatusCode(401);
            }
        }

        try {
            $migration = new MY_Migration(config('Migrations'));
            $migration->migrateToCI4();

            set_time_limit(3600);
            $migration->setNamespace('App')->latest();

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Login.migration_complete_migrate')
            ]);

        } catch (\Exception $e) {
            // Keep the technical detail (SQL, paths) in the server log for
            // administrators instead of returning it to the browser.
            log_message('error', 'Migration failed: ' . $e->getMessage());

            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Login.migration_failed')
            ])->setStatusCode(500);
        }
    }
}
