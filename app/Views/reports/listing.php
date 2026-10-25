<?php
/**
 * Reports hub, organised by the business question rather than by the report
 * engine (graphical / summary / detailed). Most links open the report for the
 * current month straight away; its period can then be changed in place.
 * Reports that need a choice first (a customer, an employee) open their form.
 *
 * Each link needs its own grant; the controllers enforce the same grants.
 *
 * @var array $permission_ids
 */

$granted = array_flip($permission_ids);
$from = date('Y-m-01');
$to = date('Y-m-d');
$sales = static fn (string $report): string => site_url("reports/$report/$from/$to/complete/all");

// [label, href, grant, chart href|null]
$domains = [
    'sales' => ['shopping-cart', [
        ['Reports.hub_sales_by_day', $sales('summary_sales'), 'reports_sales', $sales('graphical_summary_sales')],
        ['Reports.hub_sales_invoices', $sales('detailed_sales'), 'reports_sales', null],
        ['Reports.hub_payments', $sales('summary_payments'), 'reports_payments', $sales('graphical_summary_payments')],
        ['Reports.hub_sales_by_employee', $sales('summary_employees'), 'reports_employees', $sales('graphical_summary_employees')],
        ['Reports.hub_employee_sales', site_url('reports/specific_employees'), 'reports_employees', null],
        ['Reports.hub_discounts', site_url("reports/summary_discounts/$from/$to/complete/all/0"), 'reports_discounts', null],
        ['Reports.hub_taxes', $sales('summary_taxes'), 'reports_taxes', null],
    ]],
    'profit' => ['usd', [
        ['Accounting.income_statement', site_url('accounting/incomeStatement'), 'accounting_view', null],
        ['Reports.hub_profit_by_product', $sales('summary_items'), 'reports_items', null],
        ['Reports.hub_profit_by_category', $sales('summary_categories'), 'reports_categories', null],
    ]],
    'products' => ['tags', [
        ['Reports.hub_product_sales', $sales('summary_items'), 'reports_items', $sales('graphical_summary_items')],
        ['Reports.hub_category_sales', $sales('summary_categories'), 'reports_categories', $sales('graphical_summary_categories')],
    ]],
    'purchases' => ['download-alt', [
        ['Reports.hub_purchase_history', site_url("reports/detailed_receivings/$from/$to/all/all"), 'reports_receivings', null],
    ]],
    'inventory' => ['inbox', [
        ['Reports.hub_stock_summary', site_url('reports/inventory_summary'), 'reports_inventory', null],
        ['Reports.hub_low_stock', site_url('reports/inventory_low'), 'reports_inventory', null],
        ['Accounting.inventory_valuation', site_url('accounting/valuation'), 'accounting_view', null],
        ['Accounting.cost_history', site_url('accounting/costHistory'), 'accounting_view', null],
    ]],
    'customers' => ['user', [
        ['Reports.hub_customer_balances', site_url('accounting/statement?party_type=customer'), 'accounting_view', null],
        ['Accounting.receivables_aging', site_url('accounting/aging?party_type=customer'), 'accounting_view', null],
        ['Reports.hub_sales_by_customer', $sales('summary_customers'), 'reports_customers', $sales('graphical_summary_customers')],
        ['Reports.hub_customer_sales', site_url('reports/specific_customers'), 'reports_customers', null],
    ]],
    'suppliers' => ['briefcase', [
        ['Reports.hub_supplier_balances', site_url('accounting/statement?party_type=supplier'), 'accounting_view', null],
        ['Accounting.payables_aging', site_url('accounting/aging?party_type=supplier'), 'accounting_view', null],
        ['Reports.hub_sales_by_supplier', $sales('summary_suppliers'), 'reports_suppliers', $sales('graphical_summary_suppliers')],
    ]],
    'treasury' => ['piggy-bank', [
        ['Treasury.movements', site_url('treasury'), 'treasury_view', null],
        ['Treasury.vouchers', site_url('treasury/vouchers'), 'treasury_view', null],
        ['Treasury.shifts', site_url('cashups'), 'cashups', null],
    ]],
    'expenses' => ['credit-card', [
        ['Reports.hub_expenses_by_category', site_url("reports/summary_expenses_categories/$from/$to/complete"), 'reports_expenses_categories', site_url("reports/graphical_summary_expenses_categories/$from/$to/complete")],
        ['Reports.hub_expense_list', site_url('expenses'), 'expenses', null],
    ]],
    'accounting' => ['book', [
        ['Accounting.trial_balance', site_url('accounting/trialBalance'), 'accounting_view', null],
        ['Accounting.balance_sheet', site_url('accounting/balanceSheet'), 'accounting_view', null],
        ['Accounting.general_ledger', site_url('accounting/ledger'), 'accounting_view', null],
        ['Accounting.dimension_analysis', site_url('accounting/dimensions'), 'accounting_view', null],
    ]],
];
?>

<?= view('partial/header') ?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-stats" aria-hidden="true"></span>
        <?= lang('Module.reports') ?>
    </h1>
    <p class="dj-page-subtitle"><?= lang('Reports.hub_intro') ?></p>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<div class="ruya-hub">
    <?php foreach ($domains as $domain => [$icon, $links]): ?>
        <?php $visible = array_filter($links, static fn (array $link): bool => isset($granted[$link[2]])); ?>
        <?php if ($visible === []) {
            continue;
        } ?>
        <section class="ruya-hub-card" aria-labelledby="hub-<?= $domain ?>">
            <div class="ruya-hub-card__head">
                <span class="ruya-hub-card__icon"><span class="glyphicon glyphicon-<?= $icon ?>" aria-hidden="true"></span></span>
                <div>
                    <h2 class="ruya-hub-card__title" id="hub-<?= $domain ?>"><?= lang('Reports.hub_' . $domain) ?></h2>
                    <p class="ruya-hub-card__question"><?= lang('Reports.hub_' . $domain . '_question') ?></p>
                </div>
            </div>
            <ul class="ruya-hub-card__links">
                <?php foreach ($visible as [$label, $href, $grant, $chart]): ?>
                    <li>
                        <a href="<?= esc($href, 'attr') ?>">
                            <span><?= lang($label) ?></span>
                            <?php if ($chart !== null): ?>
                                <span class="ruya-view" title="<?= esc(lang('Reports.view_chart'), 'attr') ?>"><span class="glyphicon glyphicon-stats" aria-hidden="true"></span></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>

<?= view('partial/footer') ?>
