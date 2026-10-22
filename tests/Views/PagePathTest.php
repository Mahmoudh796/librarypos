<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Focused regression suite for the Ru'ya page-path (breadcrumb) component
 * (app/Views/partial/page_path.php) and its per-view integrations.
 *
 * Covers: granted-module linking, permission-safe plain-text fallback,
 * javascript:-free deterministic hrefs, RTL/LTR label rendering, escaping,
 * and the source-level contract that every nested page declares its parent.
 */
class PagePathTest extends CIUnitTestCase
{
    public function testModuleCrumbsLinkWhenGranted(): void
    {
        $output = view('partial/page_path', [
            'crumbs'         => [['module' => 'sales'], ['label' => 'History']],
            'allowed_module_ids' => ['sales'],
        ]);

        $this->assertStringContainsString('dj-page-path', $output);
        $this->assertStringContainsString('href="' . base_url('sales') . '"', $output);
        $this->assertStringContainsString('aria-current="page"', $output);
    }

    public function testModuleCrumbsWithoutGrantStayPlainText(): void
    {
        $output = view('partial/page_path', [
            'crumbs'         => [['module' => 'office'], ['label' => 'Backup']],
            'allowed_module_ids' => ['sales'],
        ]);

        $this->assertStringNotContainsString('<a ', $output);
        $this->assertStringContainsString('aria-current="page"', $output);
    }

    public function testIntermediateHrefRendersDeterministicLink(): void
    {
        $output = view('partial/page_path', [
            'crumbs' => [
                ['label' => 'Reports', 'href' => site_url('reports')],
                ['label' => 'Payments'],
            ],
        ]);

        $this->assertStringContainsString('href="' . site_url('reports') . '"', $output);
    }

    public function testJavascriptHrefIsSuppressed(): void
    {
        $output = view('partial/page_path', [
            'crumbs' => [
                ['label' => 'Parent', 'href' => 'javascript:history.back()'],
                ['label' => 'Child'],
            ],
        ]);

        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringNotContainsString('<a ', $output);
    }

    public function testSingleOrEmptyChainRendersNothing(): void
    {
        $empty   = view('partial/page_path', ['crumbs' => []]);
        $lonely  = view('partial/page_path', ['crumbs' => [['label' => 'Only']]]);
        $soloMod = view('partial/page_path', ['crumbs' => [['module' => 'sales']]]);

        $this->assertStringNotContainsString('dj-page-path', $empty);
        $this->assertStringNotContainsString('dj-page-path', $lonely);
        $this->assertStringNotContainsString('dj-page-path', $soloMod);
    }

    public function testRtlArabicLabelsRenderVerbatim(): void
    {
        $output = view('partial/page_path', [
            'crumbs' => [['label' => 'ط§ظ„ظ…ط¨ظٹط¹ط§طھ'], ['label' => 'ط³ط¬ظ„ ط§ظ„ظ…ط¨ظٹط¹ط§طھ']],
        ]);

        $this->assertStringContainsString('ط§ظ„ظ…ط¨ظٹط¹ط§طھ', $output);
        $this->assertStringContainsString('ط³ط¬ظ„ ط§ظ„ظ…ط¨ظٹط¹ط§طھ', $output);
    }

    public function testLtrEnglishLabelsRenderVerbatim(): void
    {
        $output = view('partial/page_path', [
            'crumbs' => [
                ['label' => 'Sales', 'href' => site_url('sales')],
                ['label' => 'Sales History'],
            ],
        ]);

        $this->assertStringContainsString('>Sales</a>', $output);
        $this->assertStringContainsString('Sales History</span>', $output);
    }

    public function testLabelsAreEscaped(): void
    {
        $output = view('partial/page_path', [
            'crumbs' => [['label' => 'Parent'], ['label' => '<b>x</b>']],
        ]);

        $this->assertStringNotContainsString('<b>x</b>', $output);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $output);
    }

    public function testEveryHrefIsSiteRelativeOrSiteAbsolute(): void
    {
        $output = view('partial/page_path', [
            'crumbs'         => [
                ['module' => 'sales'],
                ['label' => 'A', 'href' => site_url('reports/summary_sales')],
                ['label' => 'B', 'href' => base_url('items')],
                ['label' => 'Leaf'],
            ],
            'allowed_module_ids' => ['sales'],
        ]);

        $this->assertDoesNotMatchRegularExpression('/<a href="(?!' . preg_quote(base_url(), '/') . ')/', $output);
    }

    public function testReportHelperResolvesCurrentRouteShapes(): void
    {
        helper('report');
        $this->assertTrue(function_exists('get_report_path_info'));

        $info = get_report_path_info();
        $this->assertArrayHasKey('crumbs', $info);
        $this->assertArrayHasKey('input_path', $info);
        $this->assertSame(['module' => 'reports'], $info['crumbs'][0]);
    }

    public function testNestedViewsDeclareExplicitParentNavigation(): void
    {
        $expectations = [
            APPPATH . 'Views/sales/manage.php'         => ["partial/page_path", "'module' => 'sales'", "Sales.sales_history"],
            APPPATH . 'Views/configs/manage.php'       => ["partial/page_path", "'module' => 'office'"],
            APPPATH . 'Views/backups/index.php'        => ["partial/page_path", "'module' => 'office'"],
            APPPATH . 'Views/reports/date_input.php'              => ["partial/page_path", "get_report_path_info"],
            APPPATH . 'Views/reports/specific_input.php'          => ["partial/page_path", "get_report_path_info"],
            APPPATH . 'Views/reports/specific_customer_input.php' => ["partial/page_path", "get_report_path_info"],
            APPPATH . 'Views/reports/inventory_summary_input.php' => ["partial/page_path", "get_report_path_info"],
            APPPATH . 'Views/reports/tabular.php'         => ["partial/page_path", "edit_report_inputs", "get_report_path_info"],
            APPPATH . 'Views/reports/tabular_details.php' => ["partial/page_path", "edit_report_inputs", "get_report_path_info"],
            APPPATH . 'Views/reports/graphical.php'       => ["partial/page_path", "edit_report_inputs", "get_report_path_info"],
            APPPATH . 'Views/barcodes/barcode_sheet.php'  => ["Common.back_to", "Module.items", ".barcode-sheet-bar"],
        ];

        foreach ($expectations as $file => $needles) {
            $source = file_get_contents($file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, basename($file) . ' lacks: ' . $needle);
            }
        }
    }

    public function testBarcodeSheetNavigationIsPrintSuppressed(): void
    {
        $source = file_get_contents(APPPATH . 'Views/barcodes/barcode_sheet.php');

        $this->assertMatchesRegularExpression('/@media\s+print\s*\{\s*\.barcode-sheet-bar\s*\{\s*display:\s*none;?\s*\}\s*\}/', $source);
    }
}
