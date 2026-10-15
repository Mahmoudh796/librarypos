<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

class LocaleHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../app/Helpers/locale_helper.php';

        $config           = new OSPOS();
        $config->settings = ['dateformat' => 'Y-m-d'];
        Factories::injectMock('config', OSPOS::class, $config);
    }

    public function testValidDateReturnsTrue(): void
    {
        $this->assertTrue(isValidDate('2024-06-10'));
    }

    public function testInvalidDateFormatReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('10/06/2024'));
    }

    public function testImpossibleDateReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2024-13-01'));
    }

    public function testPhpDateOverflowReturnsFalse(): void
    {
        // PHP silently overflows Feb 30 → Mar 1; the format()===candidate check catches this
        $this->assertFalse(isValidDate('2024-02-30'));
    }

    public function testEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(isValidDate(''));
    }

    public function testLeapDayValidReturnsTrue(): void
    {
        $this->assertTrue(isValidDate('2024-02-29'));
    }

    public function testLeapDayInvalidYearReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2023-02-29'));
    }

    public function testPartialDateReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2024-06'));
    }

    public function testArabicLanguageCodesAreRtl(): void
    {
        $this->assertTrue(is_rtl_language('ar-EG'));
        $this->assertTrue(is_rtl_language('ar-LB'));
        $this->assertTrue(is_rtl_language('ar'));
    }

    public function testArabicRegionalVariantIsRtlViaPrimarySubtag(): void
    {
        // A regional variant that has no explicit entry still inherits the script direction.
        $this->assertTrue(is_rtl_language('ar-SA'));
    }

    public function testUnderscoreSeparatedLanguageCodeIsNormalized(): void
    {
        $this->assertTrue(is_rtl_language('ar_EG'));
    }

    public function testWhitespaceAroundLanguageCodeIsTrimmed(): void
    {
        $this->assertTrue(is_rtl_language('  ar-EG  '));
    }

    public function testPersianHebrewUrduAndKurdishAreRtl(): void
    {
        $this->assertTrue(is_rtl_language('fa'));
        $this->assertTrue(is_rtl_language('he'));
        $this->assertTrue(is_rtl_language('ur'));
        $this->assertTrue(is_rtl_language('ckb'));
    }

    public function testLatinLanguagesAreNotRtl(): void
    {
        $this->assertFalse(is_rtl_language('en'));
        $this->assertFalse(is_rtl_language('en-GB'));
        $this->assertFalse(is_rtl_language('tr'));
        $this->assertFalse(is_rtl_language('az'));
        $this->assertFalse(is_rtl_language('fr'));
    }

    public function testEmptyLanguageCodeIsNotRtl(): void
    {
        $this->assertFalse(is_rtl_language(''));
    }

    public function testGetTextDirectionReturnsRtlForArabic(): void
    {
        $this->assertSame('rtl', get_text_direction('ar-EG'));
    }

    public function testGetTextDirectionReturnsLtrForLatinLanguages(): void
    {
        $this->assertSame('ltr', get_text_direction('en-GB'));
    }

    public function testGetRtlLanguageCodesIncludesArabicPrimarySubtag(): void
    {
        $this->assertContains('ar', get_rtl_language_codes());
    }
}
