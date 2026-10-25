<?php
/**
 * Where-am-I trail for the accounting area: "group › page [› leaf]". Moving
 * between accounting screens is the header menu's job, so this no longer
 * repeats it as a row of tabs (which also cost one grant query per tab).
 *
 * @var string      $active
 * @var string|null $leaf   label of a nested screen (an entry, a statement…)
 */

$accounting = ['Common.nav_accounting', 'accounting'];
$inventory = ['Common.nav_inventory', 'items'];
$sections = [
    'income_statement' => [$accounting, 'Accounting.income_statement', 'accounting/incomeStatement'],
    'balance_sheet'    => [$accounting, 'Accounting.balance_sheet', 'accounting/balanceSheet'],
    'trial_balance'    => [$accounting, 'Accounting.trial_balance', 'accounting/trialBalance'],
    'dimensions'       => [$accounting, 'Accounting.dimension_analysis', 'accounting/dimensions'],
    'audit'            => [['Common.admin', null], 'Accounting.audit_trail', 'accounting/audit'],
    'journal'          => [$accounting, 'Accounting.journal', 'journal'],
    'ledger'           => [$accounting, 'Accounting.general_ledger', 'accounting/ledger'],
    'statements'       => [$accounting, 'Accounting.statements', 'accounting/statement'],
    'shareholders'     => [$accounting, 'Accounting.shareholders', 'shareholders'],
    'accounts'         => [$accounting, 'Accounting.chart_of_accounts', 'accounts'],
    'periods'          => [$accounting, 'Accounting.periods', 'accounts/periods'],
    'assets'           => [['Common.nav_costs', null], 'Accounting.fixed_assets', 'fixed_assets'],
    'valuation'        => [$inventory, 'Accounting.inventory_valuation', 'accounting/valuation'],
    'cost_history'     => [$inventory, 'Accounting.cost_history', 'accounting/costHistory'],
];

if (!isset($sections[$active])) {
    return;
}

[[$groupLabel, $groupHref], $pageLabel, $pageHref] = $sections[$active];
$crumbs = [['label' => lang($groupLabel)] + ($groupHref !== null ? ['href' => $groupHref] : [])];

if (!empty($leaf)) {
    $crumbs[] = ['label' => lang($pageLabel), 'href' => $pageHref];
    $crumbs[] = ['label' => (string) $leaf];
} else {
    $crumbs[] = ['label' => lang($pageLabel)];
}
?>
<?= view('partial/page_path', ['crumbs' => $crumbs]) ?>
