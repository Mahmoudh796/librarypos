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

    public function testAuthLanguagesOfferArabicAndEnglishEndonyms(): void
    {
        $this->assertSame(['ar-EG' => 'العربية', 'en' => 'English'], get_auth_languages());
    }

    public function testLanguageNameResolvesKnownCodesToTheirLanguage(): void
    {
        $this->assertSame('english', get_language_name('en'));
        $this->assertSame('arabic', get_language_name('ar-EG'));
        $this->assertSame('arabic', get_language_name('ar-LB'));
    }

    public function testLanguageNameReturnsNullForUnsupportedCode(): void
    {
        $this->assertNull(get_language_name('xx-NOTALOCALE'));
    }

    public function testPreferredLanguageCodeHonoursAWhitelistedCookie(): void
    {
        $this->withCookie('language', 'ar-EG', function (): void {
            $this->assertSame('ar-EG', get_preferred_language_code());
        });
    }

    public function testPreferredLanguageCodeRejectsACookieOutsideTheWhitelist(): void
    {
        $this->withCookie('language', 'xx-NOTALOCALE', function (): void {
            $this->assertNull(get_preferred_language_code());
        });
    }

    public function testPreferredLanguageCodeIsNullWhenNoCookieIsPresent(): void
    {
        $this->withCookie('language', null, function (): void {
            $this->assertNull(get_preferred_language_code());
        });
    }

    /**
     * Local currency formatting: EGP with the ج.م symbol, formatted through the
     * configured number_locale (en_US) so money keeps Western digits — the POS
     * inputs, barcode quantities and JS arithmetic are all Western-digit, and
     * Arabic-Indic digits in a total would break that round trip.
     */
    public function testToCurrencyFormatsEgyptianPoundWithWesternDigits(): void
    {
        $config           = new OSPOS();
        $config->settings = [
            'dateformat'          => 'Y-m-d',
            'number_locale'       => 'en_US',
            'currency_code'       => 'EGP',
            'currency_symbol'     => 'ج.م',
            'currency_decimals'   => 2,
            'thousands_separator' => 1,
        ];
        Factories::injectMock('config', OSPOS::class, $config);

        $formatted = to_currency('1234.5');

        // ICU separates the symbol from the number with a narrow/nbsp space, so
        // compare on normalised whitespace rather than on bytes.
        $normalized = preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $formatted);

        $this->assertSame('ج.م 1,234.50', $normalized);
        $this->assertMatchesRegularExpression('/[0-9]/u', $formatted, 'Western digits must survive the formatting path.');
        $this->assertDoesNotMatchRegularExpression(
            '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u',
            $formatted,
            'en_US must not render Arabic-Indic digits; ar_EG is kept off number_locale on purpose.'
        );
    }

    /**
     * Runs $assertions with the given request cookie visible to the cookie
     * helper, restoring the original request cookies afterwards.
     */
    private function withCookie(string $name, ?string $value, callable $assertions): void
    {
        $request  = service('request');
        $original = $request->getCookie() ?? [];

        $cookies = $original;

        if ($value === null) {
            unset($cookies[$name]);
        } else {
            $cookies[$name] = $value;
        }

        $request->setGlobal('cookie', $cookies);

        try {
            $assertions();
        } finally {
            $request->setGlobal('cookie', $original);
        }
    }
}
