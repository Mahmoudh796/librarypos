<?php

use CodeIgniter\Database\BaseBuilder;

/**
 * Applies the standard stock-location filter to a report query builder.
 *
 * Reports historically only filtered by location when a concrete location was
 * chosen and otherwise returned every location. That lets an employee who is
 * only granted Branch A read Branch B by requesting ?location=all (or by
 * tampering with the location segment), because the location dropdown is not a
 * security boundary.
 *
 * Contract:
 *  - a concrete location selected in the inputs is applied as-is; the
 *    controller is responsible for rejecting locations the employee lacks;
 *  - when 'all' is selected and `allowed_location_ids` is present (an array),
 *    the query is constrained to that subset;
 *  - an empty subset means "no accessible location" and must match no rows.
 *
 * @param BaseBuilder $builder
 * @param array $inputs Report inputs, optionally carrying allowed_location_ids
 * @param string $column Qualified location column, e.g. sales_items.item_location
 * @return void
 */
function apply_report_location_filter(BaseBuilder &$builder, array $inputs, string $column = 'item_location'): void
{
    if (($inputs['location_id'] ?? 'all') != 'all') {
        $builder->where($column, $inputs['location_id']);

        return;
    }

    if (array_key_exists('allowed_location_ids', $inputs) && is_array($inputs['allowed_location_ids'])) {
        $allowed = array_values(array_unique(array_map('intval', $inputs['allowed_location_ids'])));

        if ($allowed === []) {
            // Location id 0 is never a valid stock location, so this matches nothing.
            $builder->whereIn($column, [0]);
        } else {
            $builder->whereIn($column, $allowed);
        }
    }
}

/**
 * Raw-SQL counterpart to apply_report_location_filter() for the reports that
 * still build their query as a string. Only integer location ids reach the SQL,
 * so the returned fragment cannot be used for injection.
 *
 * Returns either '' (no constraint) or a leading " AND ..." clause.
 *
 * @param array $inputs Report inputs, optionally carrying allowed_location_ids
 * @param string $column Qualified location column, e.g. item_quantities.location_id
 * @return string
 */
function get_report_location_where(array $inputs, string $column = 'item_location'): string
{
    if (($inputs['location_id'] ?? 'all') != 'all') {
        return ' AND ' . $column . ' = ' . (int) $inputs['location_id'];
    }

    if (array_key_exists('allowed_location_ids', $inputs) && is_array($inputs['allowed_location_ids'])) {
        $allowed = array_values(array_unique(array_map('intval', $inputs['allowed_location_ids'])));

        // Location id 0 is never valid, so an empty subset matches no rows.
        return ' AND ' . $column . ' IN (' . ($allowed === [] ? '0' : implode(',', $allowed)) . ')';
    }

    return '';
}

/**
 * @param string $report_name
 * @param string $report_prefix
 * @param string $lang_key
 * @return array
 */
function get_report_link(string $report_name, string $report_prefix = '', string $lang_key = ''): array
{
    $path = 'reports/';
    if ($report_prefix !== '') {
        $path .= $report_prefix . '_';
    }

    /**
     * Sanitize the report name in case it has come from the permissions table.
     */
    $report_name = str_replace('reports_', '', $report_name);
    $path .= $report_name;

    if ($lang_key === '') {
        $lang_key = 'Reports.' . $report_name;
    }

    return [
        'path'  => site_url($path),
        'label' => lang($lang_key),
    ];
}

/**
 * @param string $permission_id
 * @param string[] $restrict_views
 *
 * @return bool
 */
function can_show_report(string $permission_id, array $restrict_views = []): bool
{
    if (!str_contains($permission_id, 'reports_')) {
        return false;
    }

    foreach ($restrict_views as $restrict_view) {
        if (str_contains($permission_id, $restrict_view)) {
            return false;
        }
    }

    return true;
}
