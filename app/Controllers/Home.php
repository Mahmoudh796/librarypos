<?php

namespace App\Controllers;

use App\Libraries\MY_Migration;
use App\Models\Employee;
use App\Models\Reports\Management_report;
use App\Models\Stock_location;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class Home extends Secure_Controller
{
    public function __construct()
    {
        $methodName = urldecode(service('request')->getUri()->getSegment(2));

        if ($methodName === 'logout') {
            $this->employee = model(Employee::class);
            return;
        }

        parent::__construct('home', null, 'home');
    }

    /**
     * Operational dashboard.
     *
     * The Home module is the landing page, so the management KPIs live here
     * instead of a parallel dashboard app. Financial cards require the matching
     * reports grant, and the inventory cards require the inventory report grant.
     * Everything is constrained to the stock locations the employee is granted.
     *
     * @return string
     */
    public function getIndex(): string
    {
        $personId = (int) $this->session->get('person_id');
        $data = [];

        if ($this->employee->has_grant('reports_sales', $personId)) {
            $data['sales_dashboard'] = $this->buildSalesDashboard();
        }

        if ($this->employee->has_grant('reports_inventory', $personId)) {
            $data['inventory_dashboard'] = $this->buildInventoryDashboard();
        }

        return view('home/home', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSalesDashboard(): array
    {
        $management = model(Management_report::class);
        $locationIds = $this->allowedLocationIds('sales');

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $last7Start = date('Y-m-d', strtotime('-6 days'));
        $prev7Start = date('Y-m-d', strtotime('-13 days'));
        $prev7End = date('Y-m-d', strtotime('-7 days'));

        $monthStart = date('Y-m-01');
        $prevMonthStart = date('Y-m-01', strtotime('first day of last month'));
        // Compare month-to-date against the same number of elapsed days last month.
        $daysElapsed = (int) date('j');
        $prevMonthEnd = date('Y-m-d', min(
            strtotime(date('Y-m-t', strtotime($prevMonthStart))),
            strtotime($prevMonthStart . ' +' . ($daysElapsed - 1) . ' days')
        ));

        return [
            'today'       => $management->getSalesTotals($today, $today, $locationIds),
            'yesterday'   => $management->getSalesTotals($yesterday, $yesterday, $locationIds),
            'last_7'      => $management->getSalesTotals($last7Start, $today, $locationIds),
            'prev_7'      => $management->getSalesTotals($prev7Start, $prev7End, $locationIds),
            'this_month'  => $management->getSalesTotals($monthStart, $today, $locationIds),
            'prev_month'  => $management->getSalesTotals($prevMonthStart, $prevMonthEnd, $locationIds),
            'trend'       => $management->getDailyTrend($last7Start, $today, $locationIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInventoryDashboard(): array
    {
        return model(Management_report::class)->getInventorySnapshot($this->allowedLocationIds('items'));
    }

    /**
     * Stock locations the logged-in employee is granted for a module.
     *
     * Returns null when the employee can reach every location, an empty array
     * when they can reach none, or the granted subset otherwise.
     *
     * @param string $module_id
     * @return int[]|null
     */
    private function allowedLocationIds(string $module_id): ?array
    {
        $stockLocation = model(Stock_location::class);
        $allowed = array_map('intval', array_keys($stockLocation->get_allowed_locations($module_id)));
        $all = array_map('intval', array_column($stockLocation->get_all()->getResultArray(), 'location_id'));

        if (count($allowed) >= count($all)) {
            return null;
        }

        return $allowed;
    }

    /**
     * Logs the currently logged in employee out of the system.  Used in app/Views/partial/header.php
     *
     * @return RedirectResponse
     * @noinspection PhpUnused
     */
    public function getLogout(): RedirectResponse
    {
        $this->employee->logout();
        return redirect()->to('login');
    }

    /**
     * Load the "change employee password" form
     *
     * @param int $employeeId
     * @return ResponseInterface|string
     */
    public function getChangePassword(int $employeeId = NEW_ENTRY): ResponseInterface|string
    {
        $loggedInEmployee = $this->employee->get_logged_in_employee_info();
        $currentPersonId = (int) $loggedInEmployee->person_id;

        $employeeId = $employeeId === NEW_ENTRY ? $currentPersonId : $employeeId;

        if (!$this->employee->isAdmin($currentPersonId) && $employeeId !== $currentPersonId) {
            return $this->response->setStatusCode(403)->setBody(lang('Employees.unauthorized_modify'));
        }

        $person_info = $this->employee->get_info($employeeId);
        foreach (get_object_vars($person_info) as $property => $value) {
            $person_info->$property = $value;
        }
        $data['person_info'] = $person_info;

        return view('home/form_change_password', $data);
    }

    /**
     * Change employee password
     *
     * @return ResponseInterface
     */
    public function postSave(int $employeeId = NEW_ENTRY): ResponseInterface
    {
        $currentUser = $this->employee->get_logged_in_employee_info();
        $currentPersonId = (int) $currentUser->person_id;

        $employeeId = $employeeId === NEW_ENTRY ? $currentPersonId : $employeeId;

        if (!$this->employee->isAdmin($currentPersonId) && $employeeId !== $currentPersonId) {
            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => lang('Employees.unauthorized_modify')
            ]);
        }

        if (!empty($this->request->getPost('current_password')) && $employeeId != NEW_ENTRY) {
            if ($this->employee->check_password($this->request->getPost('username', FILTER_SANITIZE_FULL_SPECIAL_CHARS), $this->request->getPost('current_password'))) {
                // Validate password length BEFORE hashing
                $new_password = $this->request->getPost('password');

                if (strlen($new_password) < 8) {
                    return $this->response->setJSON([
                        'success' => false,
                        'message' => lang('Employees.password_minlength'),
                        'id'      => NEW_ENTRY
                    ]);
                }

                $employee_data = [
                    'username'     => $this->request->getPost('username', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                    'password'     => password_hash($new_password, PASSWORD_DEFAULT),
                    'hash_version' => 2
                ];

                if ($this->employee->change_password($employee_data, $employeeId)) {
                    return $this->response->setJSON([
                        'success' => true,
                        'message' => lang('Employees.successful_change_password'),
                        'id'      => $employeeId
                    ]);
                } else {
                    return $this->response->setJSON([
                        'success' => false,
                        'message' => lang('Employees.unsuccessful_change_password'),
                        'id'      => NEW_ENTRY
                    ]);
                }
            } else {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Employees.current_password_invalid'),
                    'id'      => NEW_ENTRY
                ]);
            }
        } else {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Employees.current_password_invalid'),
                'id'      => NEW_ENTRY
            ]);
        }
    }
}
