<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Focused regression suite for the Item variants page redesign
 * (app/Views/items/form_variants.php) and its localization keys
 * (Items.php across every locale).
 *
 * The variants builder is business-critical: these tests lock the POST field
 * contract, the JS hook ids, the deterministic item-parent navigation, and the
 * requirement that every operator-facing string on the page is localized
 * (no hard-coded English in the view, real Arabic in the Arabic locales).
 */
class ItemVariantsViewTest extends CIUnitTestCase
{
    private const VIEW_PATH = APPPATH . 'Views/items/form_variants.php';

    private const VARIANT_KEYS = [
        'create_variants', 'variant_colors', 'variant_sizes', 'variant_options_hint',
        'variant_preview', 'variant_include', 'variant_sku_prefix', 'variant_create_action',
        'variant_colors_placeholder', 'variant_no_options', 'variant_saved',
        'variant_duplicate_sku', 'variant_sku_in_use',
    ];

    private function viewSource(): string
    {
        return file_get_contents(self::VIEW_PATH);
    }

    private function itemsSource(string $locale): array
    {
        return include APPPATH . 'Language/' . $locale . '/Items.php';
    }

    public function testViewDeclaresItemParentBreadcrumb(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("view('partial/page_path'", $source);
        $this->assertStringContainsString("'module' => 'items'", $source);
        $this->assertStringContainsString("lang('Items.create_variants')", $source);
    }

    public function testBackActionIsDeterministicAndRelabelled(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('Common.back_to', $source);
        $this->assertStringContainsString('Module.items', $source);
        $this->assertStringContainsString('base_url($controller_name)', $source);
        // The ambiguous "Close" label must not return on a full page.
        $this->assertStringNotContainsString("lang('Common.close')", $source);
    }

    public function testNoHistoryOrHardcodedUrlsRegression(): void
    {
        $source = $this->viewSource();

        $this->assertStringNotContainsString('history.', $source);
        $this->assertStringNotContainsString('http://localhost', $source);
        $this->assertStringNotContainsString("javascript:", $source);
    }

    public function testEveryOperatorFacingStringIsLocalized(): void
    {
        $source = $this->viewSource();

        $langCalls = [
            'lang(\'Items.create_variants\')',
            'lang(\'Items.variant_options_hint\')',
            'lang(\'Items.variant_colors\')',
            'lang(\'Items.variant_sizes\')',
            'lang(\'Items.variant_sku_prefix\')',
            'lang(\'Items.variant_preview\')',
            'lang(\'Items.variant_include\')',
            'lang(\'Items.variant_create_action\')',
            'lang(\'Items.variant_colors_placeholder\')',
            'lang(\'Items.variant_sku_in_use\')',
            'lang(\'Items.name_required\')',
            'lang(\'Items.cost_price_number\')',
            'lang(\'Items.unit_price_number\')',
        ];
        foreach ($langCalls as $call) {
            $this->assertStringContainsString($call, $source, 'Missing lang call: ' . $call);
        }

        // Hard-coded English must not leak through the source.
        $this->assertStringNotContainsString('Create Variants', $source);
        $this->assertStringNotContainsString('One value per line', $source);
        $this->assertStringNotContainsString('Red, Orange, Blue', $source);
    }

    public function testPostFieldContractIsUnchanged(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("form_open('items/createVariants'", $source);
        foreach ([
            "'name'  => 'name'", "'category'", "'supplier_id'", "'cost_price'",
            "'unit_price'", "'tax_names[]'", "'tax_percents[]'", "'reorder_level'",
            "'description'", "'colors'", "'sizes'", "'sku_prefix'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, 'POST field missing: ' . $needle);
        }

        // Generated variant rows keep their indexed POST keys.
        foreach (['[name]', '[sku]', '[cost_price]', '[unit_price]', '[include]', '[color]', '[size]'] as $suffix) {
            $this->assertStringContainsString("'rows[' + index + ']" . $suffix, $source);
        }
    }

    public function testJsHookIdsArePreserved(): void
    {
        $source = $this->viewSource();

        foreach (["'id' => 'variants_form'", 'id="variant_preview"', 'id="variant_create"', 'id="variant_rows"', 'id="error_message_box"', 'id="variant_basic_info"'] as $id) {
            $this->assertStringContainsString($id, $source, 'Id hook missing: ' . $id);
        }
        foreach (['items/createVariants', 'items/variantPreview', 'suggestCategory'] as $urls) {
            $this->assertStringContainsString($urls, $source, 'endpoint missing: ' . $urls);
        }
    }

    public function testSectionedFormMarkupIsPresent(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('dj-form-panel', $source);
        $this->assertStringContainsString('dj-form-section', $source);
        $this->assertStringContainsString('dj-form-actions', $source);
        foreach (['section_item_data', 'section_pricing_taxes', 'section_stock', 'section_variant_options'] as $key) {
            $this->assertStringContainsString('Items.' . $key, $source);
        }

        // The CSS side ships the matching rules (base, wide cells, mobile).
        $css = file_get_contents(APPPATH . '../public/css/dahrouj.css');
        $this->assertStringContainsString('.dj-form-panel {', $css);
        $this->assertStringContainsString('.dj-form-section-title {', $css);
        $this->assertStringContainsString('.dj-form-cell-wide {', $css);
    }

    public function testEnglishLocaleKeepsEnglishValues(): void
    {
        $items = $this->itemsSource('en');

        $this->assertSame('Create Variants', $items['create_variants']);
        $this->assertSame('Colors', $items['variant_colors']);
        $this->assertSame('Sizes', $items['variant_sizes']);
        $this->assertSame('One value per line, or separate with commas.', $items['variant_options_hint']);
        $this->assertSame('Create variants', $items['variant_create_action']);
        $this->assertSame('Red, Orange, Blue', $items['variant_colors_placeholder']);
    }

    public function testArabicLocalesRenderRealArabicNotEnglishFallback(): void
    {
        $english = $this->itemsSource('en');
        // U+0645 (meem) and U+0623 (alef with hamza above), built from escapes
        // so this test file contains no raw multibyte literals.
        $meem = "\u{0645}";
        $alefHamza = "\u{0623}";

        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $items = $this->itemsSource($locale);
            foreach (self::VARIANT_KEYS as $key) {
                $value = $items[$key] ?? null;
                $this->assertIsString($value, "$locale Items.php is missing key $key");
                $this->assertNotSame('', $value, "$locale $key fell back to empty (English would leak)");
                $this->assertNotSame($english[$key], $value, "$locale $key still carries the English value");
            }

            // The title phrase must contain Arabic-specific codepoints.
            $this->assertTrue(mb_strpos($items['create_variants'], $meem) !== false, "$locale create_variants lacks Arabic meem");
            $this->assertTrue(mb_strpos($items['create_variants'], $alefHamza) !== false, "$locale create_variants lacks Arabic hamza");
        }
    }

    public function testEveryLocaleDefinesTheVariantKeys(): void
    {
        $missing = [];

        foreach (glob(APPPATH . 'Language/*', GLOB_ONLYDIR) as $dir) {
            $locale = basename($dir);
            $file = $dir . '/Items.php';
            if (!is_file($file)) {
                continue;
            }
            $items = include $file;
            foreach (self::VARIANT_KEYS as $key) {
                if (!array_key_exists($key, $items)) {
                    $missing[] = "$locale/$key";
                }
            }
        }

        $this->assertSame([], $missing, 'Missing variant keys: ' . implode(', ', $missing));
    }
}
