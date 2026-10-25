<?php

namespace Tests\Language;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Focused regression suite for the Ru'ya employee-permission localization.
 *
 * The Employees permission tab resolves each sub-permission label through
 * lang("<Module>.<suffix>") and falls back to a humanized permission suffix
 * only when the lookup misses. These tests pin the language contracts that
 * keep that resolution Arabic-only in the Arabic UI, English in the English
 * UI, and non-empty in every shipped locale — without touching permission
 * ids, grants, or authorization semantics.
 */
class PermissionLabelLocalizationTest extends CIUnitTestCase
{
    /** module_id => permission_id suffix => lang file the lookup resolves to. */
    private const SUB_PERMISSIONS = [
        'Sales'      => ['change_price', 'delete', 'discount', 'return', 'stock'],
        'Items'      => ['stock'],
        'Receivings' => ['stock'],
        'Reports'    => ['categories', 'customers', 'discounts', 'employees', 'expenses_categories',
            'inventory', 'items', 'payments', 'receivings', 'sales', 'sales_taxes', 'suppliers', 'taxes'],
    ];

    private const ALL_LOCALES = [
        'ar-EG', 'ar-LB', 'az', 'bg', 'bs', 'ckb', 'cs', 'da', 'de-CH', 'de-DE', 'el', 'en', 'en-GB',
        'es-ES', 'es-MX', 'fa', 'fr', 'he', 'hr-HR', 'hu', 'hy', 'id', 'it', 'ka', 'km', 'lo', 'ml',
        'nb', 'nl-BE', 'nl-NL', 'pl', 'pt-BR', 'ro', 'ru', 'sv', 'sw-KE', 'sw-TZ', 'ta', 'th', 'tl',
        'tr', 'uk', 'ur', 'vi', 'zh-Hans', 'zh-Hant',
    ];

    private function localeArray(string $file, string $locale): array
    {
        return include APPPATH . 'Language/' . $locale . '/' . $file . '.php';
    }

    public function testEverySubPermissionKeyIsDefinedInEveryLocaleThatShipsTheFile(): void
    {
        $missing = [];

        foreach (self::ALL_LOCALES as $locale) {
            foreach (self::SUB_PERMISSIONS as $file => $suffixes) {
                $path = APPPATH . 'Language/' . $locale . '/' . $file . '.php';
                if (!is_file($path)) {
                    continue; // locales missing a whole file fall back to en via CI
                }
                $array = $this->localeArray($file, $locale);
                foreach ($suffixes as $suffix) {
                    if (!array_key_exists($suffix, $array)) {
                        $missing[] = "$locale/$file/$suffix";
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Missing sub-permission lang keys: ' . implode(', ', $missing));
    }

    public function testEnglishSignalsProperEnglishGrantLabels(): void
    {
        foreach (['en', 'en-GB'] as $locale) {
            $sales = $this->localeArray('Sales', $locale);
            $this->assertSame('Change Selling Price', $sales['change_price']);
            $this->assertSame('Allow Delete', $sales['delete']);
            $this->assertSame('Disc', $sales['discount']);
            $this->assertSame('Return', $sales['return']);
            $this->assertSame('Stock', $sales['stock']);

            $reports = $this->localeArray('Reports', $locale);
            $this->assertSame('Sales Taxes', $reports['sales_taxes']);
            $this->assertSame('Suppliers', $reports['suppliers']);
            $this->assertSame('Taxes', $reports['taxes']);
        }
    }

    public function testArabicGrantLabelsAreArabicNotEnglishFallbacks(): void
    {
        // No humanized permission suffix (ucwords of the internal key) may leak
        // into the Arabic UI, so every resolved label must carry an Arabic
        // codepoint and must not equal the humanized English fallback.
        foreach (['ar-EG', 'ar-LB'] as $locale) {
            foreach (self::SUB_PERMISSIONS as $file => $suffixes) {
                $array = $this->localeArray($file, $locale);
                foreach ($suffixes as $suffix) {
                    $value = (string) $array[$suffix];
                    $this->assertNotSame('', $value, "$locale/$file/$suffix is empty");
                    $this->assertMatchesRegularExpression('/\p{Arabic}/u', $value, "$locale/$file/$suffix is not Arabic");
                    $humanized = ucwords(str_replace('_', ' ', $suffix));
                    $this->assertNotSame($humanized, $value, "$locale/$file/$suffix still shows the untranslated suffix");
                }
            }
        }
    }

    public function testArabicGrantTerminologyMatchesTheRuYaDecisions(): void
    {
        $changePrice = "\u{062A}\u{063A}\u{064A}\u{064A}\u{0631} \u{0633}\u{0639}\u{0631} \u{0627}\u{0644}\u{0628}\u{064A}\u{0639}"; // تغيير سعر البيع
        $stock       = "\u{0627}\u{0644}\u{0645}\u{062E}\u{0632}\u{0648}\u{0646}";                                                     // المخزون

        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $sales = $this->localeArray('Sales', $locale);
            $this->assertSame($changePrice, $sales['change_price']);
            $this->assertSame($stock, $sales['stock']);
        }
    }

    public function testThirdPartyLocalesDoNotCarryTheEnglishGrantText(): void
    {
        // Third-party locales intentionally keep '' (or upstream translations);
        // none of them may end up with the untranslated humanized fallback.
        $english = $this->localeArray('Sales', 'en');

        foreach (self::ALL_LOCALES as $locale) {
            if (in_array($locale, ['en', 'en-GB', 'ar-EG', 'ar-LB'], true)) {
                continue;
            }
            $sales = $this->localeArray('Sales', $locale);
            $value = (string) $sales['change_price'];
            if ($value !== '') {
                $this->assertNotSame($english['change_price'], $value, "$locale carries the unchanged English grant text");
            }
        }
    }

    public function testToggleCostAndProfitIsArabicInArabicLocales(): void
    {
        $expected = "\u{0625}\u{0638}\u{0647}\u{0627}\u{0631}/\u{0625}\u{062E}\u{0641}\u{0627}\u{0621} \u{0627}\u{0644}\u{062A}\u{0643}\u{0644}\u{0641}\u{0629} \u{0648}\u{0627}\u{0644}\u{0631}\u{0628}\u{062D}"; // إظهار/إخفاء التكلفة والربح

        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $this->assertSame($expected, (string) $this->localeArray('Reports', $locale)['toggle_cost_and_profit']);
        }
        $this->assertSame('Toggle Cost & Profit', $this->localeArray('Reports', 'en')['toggle_cost_and_profit']);
    }

    public function testPermissionTabUsesSingleLangResolutionWithoutSelfComparingFallback(): void
    {
        // Regression guard for the old bug: the label was compared with a second
        // call to the identical lang expression, so a present translation was
        // always discarded in favour of the humanized suffix.
        $source = file_get_contents(APPPATH . 'Views/employees/form.php');

        $this->assertStringNotContainsString('lang(ucfirst($lang_key)) == $lang_line', $source);
        $this->assertStringContainsString('ucfirst($module->module_id) . \'.\' . $exploded_permission[1]', $source);
    }

    public function testReportToggleJsTargetsDataKeyRowsAndGuardsBootstrapTable(): void
    {
        // tabular/table system: the toggle must hide cost/profit columns guarded
        // by the bootstrapTable initialization probe (the killer of the dead
        // button), and the summary must follow the data-key rows.
        $visibility = file_get_contents(APPPATH . 'Views/partial/visibility_js.php');
        $this->assertStringContainsString('isTableInitialized', $visibility);
        $this->assertStringContainsString("summaryRowState(\$(this).data('key'))", $visibility);
        $this->assertStringContainsString("bootstrapTable('showColumn', field)", $visibility);
        $this->assertStringContainsString("bootstrapTable('hideColumn', field)", $visibility);

        // graphical system must no longer toggle rows positionally
        $js = file_get_contents(ROOTPATH . 'public/js/hide_cost_profit.js');
        $this->assertStringContainsString('data("key")', $js);
        $this->assertStringNotContainsString('rows.eq(', $js);
        $this->assertStringNotContainsString("JSON.parse(safeGetItem(\"summaryVisibility\")) || {\n        cost: false", $js);

        // Every report result renders its totals through the shared head, which
        // declares data-key on each total so the toggle can find cost/profit.
        $this->assertStringContainsString('data-key="<?= esc($key, \'attr\') ?>"', file_get_contents(APPPATH . 'Views/reports/partials/result_head.php'), 'result_head lost data-key');
        $this->assertStringContainsString("'.ruya-kpis .ruya-kpi[data-key]'", $visibility);
        $this->assertStringContainsString('".ruya-kpis .ruya-kpi[data-key]"', $js);
        foreach ([APPPATH . 'Views/reports/tabular.php', APPPATH . 'Views/reports/tabular_details.php', APPPATH . 'Views/reports/graphical.php'] as $view) {
            $this->assertStringContainsString("view('reports/partials/result_head'", file_get_contents($view), basename($view) . ' no longer renders the shared totals');
        }
    }
}
