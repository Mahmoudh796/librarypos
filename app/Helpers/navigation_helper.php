<?php

/**
 * Navigation helpers for the Ru'ya interface.
 *
 * A breadcrumb ancestor is a promise: "this crumb leads to a real page". That
 * promise only holds when the destination is a canonical route of this
 * application. A crumb built from browser history (`javascript:history.back()`),
 * from the previous referrer, or from a hard-coded absolute URL breaks it: the
 * link works on one deployment and dies on the next, and it can leave the
 * application entirely.
 *
 * The helper below is the single gate every breadcrumb destination passes
 * through, so the rule lives in one place instead of in each view.
 */

if (!function_exists('ruya_navigation')) {
    /**
     * The main menu, grouped by business workflow rather than by OSPOS module.
     *
     * An entry is shown only when the employee holds its module grant and, when
     * set, its extra permission. Groups left without entries are dropped. The
     * controllers enforce the same grants; this only decides what is offered.
     *
     * @param string[] $allowedModuleIds module ids the employee may open
     * @param callable(string): bool $hasGrant
     * @return array<string, array{label: string, icon: string, items: list<array{href: string, label: string}>}>
     */
    function ruya_navigation(array $allowedModuleIds, callable $hasGrant): array
    {
        // A [null, label] entry is a section heading; it is kept only when at least
        // one entry of its section is visible to the employee.
        $groups = [
            'sales' => ['Common.nav_sales', 'shopping-cart', [
                ['sales', 'Common.nav_pos', 'sales', null],
                ['sales/manage', 'Common.nav_sales_history', 'sales', 'reports_sales'],
                [null, 'Common.nav_section_customers'],
                ['customers', 'Module.customers', 'customers', null],
                ['accounting/statement?party_type=customer', 'Common.nav_customer_accounts', 'accounting', 'accounting_view'],
                ['giftcards', 'Module.giftcards', 'giftcards', null],
            ]],
            'purchases' => ['Common.nav_purchases', 'download-alt', [
                ['receivings', 'Common.nav_receivings', 'receivings', null],
                ['reports/detailed_receivings', 'Common.nav_purchase_history', 'reports', 'reports_receivings'],
                [null, 'Common.nav_section_suppliers'],
                ['suppliers', 'Module.suppliers', 'suppliers', null],
                ['accounting/statement?party_type=supplier', 'Common.nav_supplier_accounts', 'accounting', 'accounting_view'],
            ]],
            'inventory' => ['Common.nav_inventory', 'tags', [
                ['items', 'Module.items', 'items', null],
                ['items/categories', 'Items.categories', 'items', null],
                ['item_kits', 'Module.item_kits', 'item_kits', null],
                ['attributes', 'Module.attributes', 'attributes', null],
                ['items/variants', 'Items.create_variants', 'items', null],
                [null, 'Common.nav_section_stock'],
                ['reports/inventory_low', 'Common.nav_low_stock', 'reports', 'reports_inventory'],
                ['accounting/valuation', 'Accounting.inventory_valuation', 'accounting', 'accounting_view'],
                ['accounting/costHistory', 'Accounting.cost_history', 'accounting', 'accounting_view'],
            ]],
            'treasury' => ['Common.nav_treasury', 'briefcase', [
                ['treasury', 'Treasury.movements', 'treasury', 'treasury_view'],
                ['treasury/vouchers', 'Treasury.vouchers', 'treasury', 'treasury_view'],
                ['cashups', 'Treasury.shifts', 'cashups', null],
            ]],
            'costs' => ['Common.nav_costs', 'usd', [
                ['expenses', 'Module.expenses', 'expenses', null],
                ['expenses_categories', 'Module.expenses_categories', 'expenses_categories', null],
                ['fixed_assets', 'Accounting.fixed_assets', 'accounting', 'accounting_assets'],
            ]],
            'accounting' => ['Common.nav_accounting', 'book', [
                ['accounting', 'Accounting.overview', 'accounting', 'accounting_view'],
                [null, 'Common.nav_section_statements'],
                ['accounting/incomeStatement', 'Accounting.income_statement', 'accounting', 'accounting_view'],
                ['accounting/balanceSheet', 'Accounting.balance_sheet', 'accounting', 'accounting_view'],
                ['accounting/trialBalance', 'Accounting.trial_balance', 'accounting', 'accounting_view'],
                [null, 'Common.nav_section_books'],
                ['journal', 'Accounting.journal', 'accounting', 'accounting_view'],
                ['accounting/ledger', 'Accounting.general_ledger', 'accounting', 'accounting_view'],
                ['accounting/dimensions', 'Accounting.dimension_analysis', 'accounting', 'accounting_view'],
                ['shareholders', 'Accounting.shareholders', 'accounting', 'accounting_shareholders'],
                [null, 'Common.nav_section_setup'],
                ['accounts', 'Accounting.chart_of_accounts', 'accounting', 'accounting_view'],
                ['accounts/periods', 'Accounting.periods', 'accounting', 'accounting_periods'],
            ]],
        ];

        $allowed = array_flip($allowedModuleIds);
        $result = [];

        foreach ($groups as $key => [$label, $icon, $entries]) {
            $items = [];
            $pendingHeading = null;
            foreach ($entries as $entry) {
                if ($entry[0] === null) {
                    $pendingHeading = $entry[1];
                    continue;
                }

                [$href, $itemLabel, $module, $grant] = $entry;
                if (isset($allowed[$module]) && ($grant === null || $hasGrant($grant))) {
                    if ($pendingHeading !== null && $items !== []) {
                        $items[] = ['heading' => $pendingHeading];
                    }
                    $pendingHeading = null;
                    $items[] = ['href' => $href, 'label' => $itemLabel];
                }
            }

            if ($items !== []) {
                $result[$key] = ['label' => $label, 'icon' => $icon, 'items' => $items];
            }
        }

        return $result;
    }
}

if (!function_exists('ruya_nav_is_active')) {
    /**
     * Whether a menu href points at the page being shown: the controller must
     * match, and a two-segment href must also match the method.
     */
    function ruya_nav_is_active(string $href, string $segment1, string $segment2): bool
    {
        $parts = explode('/', strtok($href, '?'));

        if ($parts[0] !== $segment1) {
            return false;
        }

        return !isset($parts[1]) ? $segment2 === '' || $segment2 === 'index' : strcasecmp($parts[1], $segment2) === 0;
    }
}

if (!function_exists('dj_canonical_route')) {
    /**
     * Normalises a breadcrumb destination to a canonical in-application URL.
     *
     * Accepts a relative route (`reports/summary`), an application-root path
     * (`/reports/summary`), or an absolute URL that already points at this
     * application (which is rewritten to its route rather than emitted as an
     * absolute URL). Anything else - a foreign host, a protocol-relative URL,
     * a `javascript:`/`data:` scheme, a Windows-style path, or an empty value -
     * is rejected so the caller renders plain text instead of a dead link.
     *
     * @param string|null $href Candidate destination.
     * @return string|null Canonical URL, or null when the destination is unsafe.
     */
    function dj_canonical_route(?string $href): ?string
    {
        $href = trim((string) $href);

        if ($href === '') {
            return null;
        }

        // A backslash is never part of an application route and is a common
        // way to smuggle an absolute path past a naive check.
        if (str_contains($href, '\\')) {
            return null;
        }

        if (str_starts_with($href, '//') || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href) === 1) {
            // Scheme-qualified or protocol-relative: only accept it when it is
            // this very application, and then emit the route instead.
            $base = rtrim(base_url(), '/');

            if ($base !== '' && str_starts_with($href, $base . '/')) {
                return base_url(ltrim(substr($href, strlen($base)), '/'));
            }

            return null;
        }

        if (str_starts_with($href, '/')) {
            return $href;
        }

        return base_url($href);
    }
}
