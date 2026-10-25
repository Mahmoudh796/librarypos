<?php

namespace App\Controllers;

use App\Libraries\Accounting\Ledger;
use App\Libraries\Accounting\Reports;
use App\Libraries\MY_Migration;
use App\Models\Employee;
use App\Models\Reports\Management_report;
use App\Models\Stock_location;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

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
     * Operational dashboard: a decision page ordered by the owner's questions
     * (sales, profit, cash, who owes whom, stock), each figure linking to the
     * report that explains it.
     *
     * Sales cards need reports_sales, stock cards reports_inventory, cash cards
     * treasury_view and ledger figures accounting_view. Everything is limited to
     * the stock locations the employee is granted.
     */
    public function getIndex(): string
    {
        $personId = (int) $this->session->get('person_id');
        $period = (string) $this->request->getGet('period');
        $period = in_array($period, ['today', 'week', 'month'], true) ? $period : 'today';
        $data = ['period' => $period];

        if ($this->employee->has_grant('reports_sales', $personId)) {
            $data['sales_dashboard'] = $this->buildSalesDashboard($period);
        }

        if ($this->employee->has_grant('reports_inventory', $personId)) {
            $data['inventory_dashboard'] = $this->buildInventoryDashboard();
        }

        // Sample store details from the installer are hidden on receipts; ask whoever can fix them.
        if ($this->employee->has_grant('config', $personId)) {
            helper('store');
            $data['store_placeholders'] = store_info_placeholders();
        }

        $finance = $this->buildFinanceDashboard($personId, $period);
        if ($finance !== []) {
            $data['finance_dashboard'] = $finance;
        }

        return view('home/home', $data);
    }

    /**
     * The selected period and the one it is compared with, as [start, end] dates.
     * Month-to-date is compared with the same number of days of last month.
     *
     * @return array{current: array{0: string, 1: string}, previous: array{0: string, 1: string}}
     */
    public static function periodRanges(string $period, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $day = static fn (string $modifier): string => date('Y-m-d', strtotime($modifier, $now));

        switch ($period) {
            case 'week':
                return ['current' => [$day('-6 days'), $today], 'previous' => [$day('-13 days'), $day('-7 days')]];
            case 'month':
                $previousStart = date('Y-m-01', strtotime('first day of last month', $now));
                $previousEnd = date('Y-m-d', min(
                    strtotime(date('Y-m-t', strtotime($previousStart))),
                    strtotime($previousStart . ' +' . ((int) date('j', $now) - 1) . ' days')
                ));

                return ['current' => [date('Y-m-01', $now), $today], 'previous' => [$previousStart, $previousEnd]];
            default:
                return ['current' => [$today, $today], 'previous' => [$day('-1 day'), $day('-1 day')]];
        }
    }

    /**
     * Cash position and obligations. Treasury balances need treasury_view; the
     * ledger figures need accounting_view. Nothing financial is shown otherwise.
     *
     * @return array<string, mixed>
     */
    private function buildFinanceDashboard(int $personId, string $period): array
    {
        $finance = [];
        $db = Database::connect();

        if (!$db->tableExists('acc_journal_entries')) {
            return $finance;
        }

        if ($this->employee->has_grant('treasury_view', $personId)) {
            $finance['treasuries'] = [];
            foreach ($db->table('treasuries')->where('active', 1)->orderBy('is_default', 'DESC')->orderBy('treasury_id')->get()->getResultArray() as $treasury) {
                $last = $db->table('treasury_movements')->select('balance_after')->where('treasury_id', $treasury['treasury_id'])->orderBy('movement_id', 'DESC')->limit(1)->get()->getRow();
                $finance['treasuries'][] = $treasury + ['balance' => (float) ($last->balance_after ?? 0)];
            }
        }

        if ($this->employee->has_grant('accounting_view', $personId)) {
            $reports = new Reports($db);
            $ledger = new Ledger($db);
            $today = date('Y-m-d');
            [$from, $to] = self::periodRanges($period)['current'];

            $finance['receivables'] = $reports->accountBalance($ledger->mapped('accounts_receivable'), $today);
            $finance['payables'] = 0.0 - $reports->accountBalance($ledger->mapped('accounts_payable'), $today);
            $finance['expenses'] = round((float) ($db->table('expenses')->selectSum('amount')->where('deleted', 0)
                ->where('date >=', $from)->where('date <', date('Y-m-d', strtotime($to . ' +1 day')))->get()->getRow()->amount ?? 0), 2);
            $finance['expenses_range'] = [$from, $to];
            $finance['month_profit'] = $reports->incomeStatement(date('Y-m-01'), $today)['totals']['net_profit'];
            $finance['unmapped_payments'] = $reports->unmappedPaymentLabels();
        }

        return $finance;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSalesDashboard(string $period): array
    {
        $management = model(Management_report::class);
        $locationIds = $this->allowedLocationIds('sales');
        $ranges = self::periodRanges($period);
        [$from, $to] = $ranges['current'];
        $trendFrom = $period === 'month' ? $from : date('Y-m-d', strtotime('-6 days'));

        return [
            'range'     => $ranges['current'],
            'current'   => $management->getSalesTotals($from, $to, $locationIds),
            'previous'  => $management->getSalesTotals($ranges['previous'][0], $ranges['previous'][1], $locationIds),
            'trend'     => $management->getDailyTrend($trendFrom, date('Y-m-d'), $locationIds),
            'top_items' => $management->getTopItems($from, $to, $locationIds, 5),
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
