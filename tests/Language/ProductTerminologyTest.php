<?php

namespace Tests\Language;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Focused regression suite for the Arabic product-terminology pass
 * ("اسم الصنف" → "اسم المنتج", barcode label "الباركود / ISBN") and the new
 * operator-helper keys (barcode/category help, backup status keys).
 *
 * All Arabic expectations are built from Unicode escapes so this file contains
 * no raw multibyte literals (byte-safety protocol). Database columns, POST
 * fields, routes and internal "item" terminology are NOT addressed by these
 * keys — only operator-facing Arabic UI labels.
 */
class ProductTerminologyTest extends CIUnitTestCase
{
    private const NAME_NEW      = "\u{0627}\u{0633}\u{0645} \u{0627}\u{0644}\u{0645}\u{0646}\u{062A}\u{062C}"; // اسم المنتج
    private const NAME_OLD      = "\u{0627}\u{0633}\u{0645} \u{0627}\u{0644}\u{0635}\u{0646}\u{0641}";           // اسم الصنف
    private const BARCODE_LABEL = "\u{0627}\u{0644}\u{0628}\u{0627}\u{0631}\u{0643}\u{0648}\u{062F} / ISBN";        // الباركود / ISBN
    private const BARCODE_ID    = "\u{0643}\u{0648}\u{062F}/" . self::NAME_NEW;                                 // كود/اسم المنتج
    private const PRODUCT       = "\u{0627}\u{0644}\u{0645}\u{0646}\u{062A}\u{062C}";                           // المنتج

    /** Product-referencing strings that must use the new terminology. */
    private const PRODUCT_REFERRING_KEYS = [
        'Items.php'      => ['cannot_find_item', 'details_count', 'is_serialized', 'low_sell_item',
                             'retrive_item_info', 'section_item_data', 'type'],
        'Sales.php'      => ['error_editing_item', 'item_insufficient_of_stock', 'item_out_of_stock',
                             'key_item_search', 'quantity_less_than_reorder_level'],
        'Receivings.php' => ['error_editing_item', 'error_requisition'],
        'Reports.php'    => ['item_count', 'low_sell_quantity', 'total_low_sell_quantity',
                             'requisition_item', 'requisition_related_item'],
    ];

    private function localeArray(string $file, string $locale): array
    {
        return include APPPATH . 'Language/' . $locale . '/' . $file . '.php';
    }

    public function testArabicProductNameFieldRenames(): void
    {
        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $items    = $this->localeArray('Items', $locale);
            $sales    = $this->localeArray('Sales', $locale);
            $shipping = $this->localeArray('Receivings', $locale);
            $reports  = $this->localeArray('Reports', $locale);

            $this->assertSame(self::NAME_NEW, $items['name']);
            $this->assertSame("{$items['name']} \u{0645}\u{0637}\u{0644}\u{0648}\u{0628}.", $items['name_required']);
            $this->assertSame(self::NAME_NEW, $sales['item_name']);
            $this->assertSame(self::NAME_NEW, $shipping['item_name']);
            $this->assertSame(self::NAME_NEW, $reports['item_name']);
            $this->assertSame(self::BARCODE_ID, $this->localeArray('Config', $locale)['barcode_id']);
        }
    }

    public function testArabicBarcodeLabelUsesShortClearWording(): void
    {
        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $items = $this->localeArray('Items', $locale);

            $this->assertSame(self::BARCODE_LABEL, $items['item_number']);
            $this->assertStringContainsString('ISBN', $items['item_number']);
        }
    }

    public function testOldProductPhraseRemovedFromTouchedFiles(): void
    {
        $sources = [
            'Items.php'      => ['name', 'name_required'],
            'Sales.php'      => ['item_name', 'start_typing_item_name'],
            'Receivings.php' => ['item_name'],
            'Reports.php'    => ['item_name'],
            'Config.php'     => ['barcode_id'],
        ];

        foreach (['ar-EG', 'ar-LB'] as $locale) {
            foreach ($sources as $file => $keys) {
                $localeArray = $this->localeArray(explode('.', $file)[0], $locale);
                foreach ($keys as $key) {
                    $this->assertStringNotContainsString(self::NAME_OLD, (string) $localeArray[$key], "$locale/$file/$key still contains the old phrase");
                }
            }
        }
    }

    public function testArabicRegisterPlaceholderMatchesNewTerminology(): void
    {
        $expected = "\u{0627}\u{0628}\u{062F}\u{0623} "
            . "\u{0628}\u{0643}\u{062A}\u{0627}\u{0628}\u{0629} "                       // بكتابة
            . "\u{0627}\u{0633}\u{0645} \u{0623}\u{0648} "                              // اسم أو
            . "\u{0645}\u{0633}\u{062D} \u{0628}\u{0627}\u{0631}\u{0643}\u{0648}\u{062F} " // مسح باركود
            . "\u{0627}\u{0644}\u{0645}\u{0646}\u{062A}\u{062C}...";                    // المنتج...

        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $this->assertSame($expected, $this->localeArray('Sales', $locale)['start_typing_item_name']);
        }
    }

    public function testSecondaryProductReferencesUseNewTerminology(): void
    {
        foreach (['ar-EG', 'ar-LB'] as $locale) {
            foreach (self::PRODUCT_REFERRING_KEYS as $file => $keys) {
                $localeArray = $this->localeArray(explode('.', $file)[0], $locale);
                foreach ($keys as $key) {
                    $this->assertStringContainsString(self::PRODUCT, (string) $localeArray[$key], "$locale/$file/$key lacks the product terminology");
                }
            }

            $items = $this->localeArray('Items', $locale);
            $this->assertSame("\u{0631}\u{0642}\u{0645} \u{0627}\u{0644}\u{0628}\u{0627}\u{0631}\u{0643}\u{0648}\u{062F}", $items['number_information']); // رقم الباركود
            $this->assertStringContainsString("\u{0627}\u{0644}\u{0628}\u{0627}\u{0631}\u{0643}\u{0648}\u{062F}", $items['item_number_invalid']);
        }
    }

    public function testNoOldPhraseOnArabicOperatorFiles(): void
    {
        foreach (['ar-EG', 'ar-LB'] as $locale) {
            foreach (['Items', 'Sales', 'Receivings', 'Reports', 'Config'] as $only) {
                $contents = file_get_contents(APPPATH . 'Language/' . $locale . '/' . $only . '.php');
                $this->assertStringNotContainsString(self::NAME_OLD, $contents, "$locale/$only still contains the old product-name phrase");
            }
        }
    }

    public function testEnglishKeepsEnglishTerminology(): void
    {
        $items = $this->localeArray('Items', 'en');

        $this->assertSame('Item Name', $items['name']);
        foreach (['en'] as $locale) {
            $this->assertSame('Barcode', $this->localeArray('Items', $locale)['item_number']);
        }
    }

    public function testHelperKeysDefinedInEveryLocale(): void
    {
        $missing = [];

        foreach (glob(APPPATH . 'Language/*', GLOB_ONLYDIR) as $dir) {
            $locale = basename($dir);
            $items  = is_file($dir . '/Items.php') ? include $dir . '/Items.php' : [];

            foreach (['barcode_help', 'category_help'] as $key) {
                if (!array_key_exists($key, $items)) {
                    $missing[] = "$locale/$key";
                }
            }
        }

        $this->assertSame([], $missing, 'Missing helper keys: ' . implode(', ', $missing));
    }

    public function testArabicLocaleHelpersAreRealArabic(): void
    {
        $meem  = "\u{0645}";
        $items = $this->localeArray('Items', 'en');

        foreach (['ar-EG', 'ar-LB'] as $locale) {
            $itemsArray = $this->localeArray('Items', $locale);
            foreach (['barcode_help', 'category_help'] as $key) {
                $this->assertTrue(mb_strpos($itemsArray[$key], $meem) !== false, "$locale $key is not Arabic");
                $this->assertNotSame($items[$key], $itemsArray[$key], "$locale $key still carries the English value");
            }
        }
    }

    public function testBackupStatusKeysDefinedEverywhere(): void
    {
        $missing = [];

        foreach (glob(APPPATH . 'Language/*', GLOB_ONLYDIR) as $dir) {
            $locale = basename($dir);
            $file   = $dir . '/Common.php';
            if (!is_file($file)) {
                continue;
            }
            $common = include $file;
            foreach (['backup_local_status', 'backup_offsite_status', 'backup_ready', 'backup_offsite_hint'] as $key) {
                if (!array_key_exists($key, $common)) {
                    $missing[] = "$locale/$key";
                }
            }
        }

        $this->assertSame([], $missing, 'Missing Common keys: ' . implode(', ', $missing));

        $english = include APPPATH . 'Language/en/Common.php';
        $this->assertSame('Local backup', $english['backup_local_status']);
        $this->assertSame('Ready', $english['backup_ready']);
        $this->assertSame('Off-device backup', $english['backup_offsite_status']);
    }
}
