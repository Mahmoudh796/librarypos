<?php

namespace App\Controllers;

use App\Models\Employee;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Persists the visitor's language choice on the unauthenticated screens
 * (sign-in and account recovery) and inside the authenticated application.
 *
 * The choice is stored in the "language" cookie, which current_language_code()
 * consults, so it survives a refresh, sign-in, sign-out and the recovery flow
 * without introducing a second, parallel language preference. When an employee
 * is signed in, the same choice is mirrored onto their account row so it also
 * survives a cleared cookie or a different browser.
 */
class Language extends BaseController
{
    /**
     * Stores a whitelisted locale and returns to the page the switcher was
     * submitted from.
     */
    public function set_language(): RedirectResponse
    {
        $selected     = (string) $this->request->getPost('language');
        $redirectPath = $this->safe_redirect_path((string) $this->request->getPost('redirect'));

        if (in_array($selected, config('App')->supportedLocales, true)) {
            set_cookie('language', $selected, YEAR, '', '/', '', $this->request->isSecure(), true, 'Lax');

            $this->mirrorToAccount($selected);
        }

        // set_cookie() queues the cookie on the global response; copy it onto
        // the redirect so it is actually sent with this response.
        return redirect()->to($redirectPath)->withCookies();
    }

    /**
     * Mirrors an explicit locale choice onto the signed-in employee's account
     * so the same preference is durable across sign-out and other browsers.
     *
     * A no-op on the unauthenticated sign-in / recovery screens, where no
     * account is signed in yet.
     */
    private function mirrorToAccount(string $selected): void
    {
        $employee = model(Employee::class);

        if (!$employee->is_logged_in()) {
            return;
        }

        $employeeInfo = $employee->get_logged_in_employee_info();
        $languageName = get_language_name($selected);

        if ($languageName === null || (string)($employeeInfo->language_code ?? '') === $selected) {
            return;
        }

        $employee->update((int)$employeeInfo->person_id, [
            'language'      => $languageName,
            'language_code' => $selected,
        ]);
    }

    /**
     * Reduces an arbitrary "return to" value to a same-site relative path.
     *
     * Anything that could leave the current host (absolute URLs, protocol
     * relative URLs, backslashes, schemes) falls back to the sign-in page. A
     * leading slash is rejected so a protocol-relative value cannot survive.
     */
    private function safe_redirect_path(string $candidate): string
    {
        $candidate = trim($candidate);

        if ($candidate === '' || preg_match('#^[A-Za-z0-9_~][A-Za-z0-9._~/%\-]*$#', $candidate) !== 1) {
            return 'login';
        }

        return $candidate;
    }
}
