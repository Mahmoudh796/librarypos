<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * Shared plumbing for the treasury and accounting screens: server-side grant
 * checks (hiding a button is not authorization), date range parsing, JSON
 * results for actions, and the audit trail for every financial action.
 */
abstract class Ledger_Controller extends Secure_Controller
{
    protected function personId(): int
    {
        return (int) $this->employee->get_logged_in_employee_info()->person_id;
    }

    protected function can(string $permissionId): bool
    {
        return $this->employee->has_grant($permissionId, $this->personId());
    }

    /**
     * Stops the request unless the employee holds the grant.
     */
    protected function requireGrant(string $permissionId): void
    {
        if (!$this->can($permissionId)) {
            throw new RedirectException('no_access/' . explode('_', $permissionId)[0] . '/' . $permissionId);
        }
    }

    protected function denied(string $permissionId): ?ResponseInterface
    {
        if ($this->can($permissionId)) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Accounting.not_authorized')]);
    }

    /**
     * @return array{0: string, 1: string} inclusive Y-m-d range from ?from=&to=, defaulting to the current month
     */
    protected function dateRange(?string $defaultFrom = null): array
    {
        $from = (string) $this->request->getGet('from');
        $to = (string) $this->request->getGet('to');

        $valid = static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false;

        $from = $valid($from) ? $from : ($defaultFrom ?? date('Y-m-01'));
        $to = $valid($to) ? $to : date('Y-m-d');

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    protected function asOfDate(): string
    {
        $date = (string) $this->request->getGet('as_of');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : date('Y-m-d');
    }

    /**
     * Runs a financial action, returning its result as JSON. Accounting rule
     * violations are reported to the user; anything else is logged.
     */
    protected function act(callable $action, string $successMessage): ResponseInterface
    {
        try {
            $result = $action();

            return $this->response->setJSON(['success' => true, 'message' => $successMessage, 'id' => $result]);
        } catch (AccountingException $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            log_message('error', static::class . ': ' . $e->getMessage());

            return $this->response->setJSON(['success' => false, 'message' => lang('Accounting.action_failed')]);
        }
    }

    protected function postedAmount(string $field): float
    {
        $value = parse_decimals((string) $this->request->getPost($field));

        return $value === false || $value === '' ? 0.0 : (float) $value;
    }

    protected function postedDateTime(string $field): string
    {
        $value = trim((string) $this->request->getPost($field));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value === date('Y-m-d') ? date('Y-m-d H:i:s') : "$value 12:00:00";
        }

        return date('Y-m-d H:i:s');
    }

    /**
     * Pick-list of customers or suppliers (person_id => display name), so a person
     * is chosen by name rather than by an internal id.
     *
     * @return array<int|string, string>
     */
    protected function peopleOptions(string $table): array
    {
        $db = Database::connect();
        $builder = $db->table($table)->select("$table.person_id, people.first_name, people.last_name")
            ->join('people', "people.person_id = $table.person_id")
            ->where("$table.deleted", 0)
            ->orderBy('people.first_name');

        if ($table === 'suppliers') {
            $builder->select('suppliers.company_name');
        }

        $options = ['' => lang('Common.none_selected_text')];
        foreach ($builder->get()->getResult() as $person) {
            $options[$person->person_id] = trim(($person->company_name ?? '') !== '' ? $person->company_name . ' - ' . $person->first_name . ' ' . $person->last_name : $person->first_name . ' ' . $person->last_name);
        }

        return $options;
    }
}
