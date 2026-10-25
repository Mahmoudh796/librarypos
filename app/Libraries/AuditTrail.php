<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Append-only record of sensitive changes: who, when, what, before/after and why.
 * Credential-like fields are never written.
 */
class AuditTrail
{
    private const REDACTED = ['password', 'password_confirm', 'current_password', 'hash_version', 'api_key', 'token', 'csrf_ospos_v4', 'honeypot'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function record(?int $employeeId, string $action, string $entityType, string $entityId, ?array $old = null, ?array $new = null, string $reason = '', string $ipAddress = ''): void
    {
        $this->db->table('audit_log')->insert([
            'logged_at'   => date('Y-m-d H:i:s'),
            'employee_id' => $employeeId,
            'action'      => mb_substr($action, 0, 50),
            'entity_type' => mb_substr($entityType, 0, 50),
            'entity_id'   => mb_substr($entityId, 0, 64),
            'old_values'  => $old === null ? null : json_encode(self::scrub($old), JSON_UNESCAPED_UNICODE),
            'new_values'  => $new === null ? null : json_encode(self::scrub($new), JSON_UNESCAPED_UNICODE),
            'reason'      => mb_substr($reason, 0, 255),
            'ip_address'  => mb_substr($ipAddress, 0, 45),
        ]);
    }

    public static function scrub(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED, true)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = self::scrub($value);
            }
        }

        return $values;
    }

    /**
     * @return list<array>
     */
    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        $builder = $this->db->table('audit_log')
            ->select('audit_log.*, people.first_name, people.last_name')
            ->join('people', 'people.person_id = audit_log.employee_id', 'left');

        if (!empty($filters['entity_type'])) {
            $builder->where('entity_type', $filters['entity_type']);
        }
        if (!empty($filters['employee_id'])) {
            $builder->where('audit_log.employee_id', (int) $filters['employee_id']);
        }
        if (!empty($filters['from'])) {
            $builder->where('logged_at >=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $builder->where('logged_at <', date('Y-m-d', strtotime($filters['to'] . ' +1 day')));
        }

        return $builder->orderBy('audit_id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
    }
}
