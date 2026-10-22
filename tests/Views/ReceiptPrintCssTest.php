<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Focused regression suite for the 80mm thermal-receipt print presentation.
 *
 * The receipt shell (app/Views/sales/receipt.php) is the only document that
 * may declare an @page size: it wraps both receipt templates
 * (receipt_default / receipt_short) but not the invoice/quote/work-order
 * pages, which keep their regular A4 print behaviour.
 */
class ReceiptPrintCssTest extends CIUnitTestCase
{
    public function testReceiptShellDefinesScoped80mmPrintCss(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/receipt.php');

        $this->assertStringContainsString('<style type="text/css" media="print">', $source);
        $this->assertStringContainsString('@page', $source);
        $this->assertStringContainsString('size: 80mm auto', $source);
        $this->assertStringContainsString('#receipt_wrapper', $source);
        $this->assertStringContainsString('overflow-wrap', $source);
    }

    public function testInvoicePageDoesNotInherit80mmPage(): void
    {
        $invoice = file_get_contents(APPPATH . 'Views/sales/invoice.php');

        $this->assertStringNotContainsString('80mm', $invoice, 'Invoice must keep its own (non-80mm) print layout');
    }

    public function testPrintChromeSuppressionStillShips(): void
    {
        $printCss = file_get_contents(ROOTPATH . 'public/css/ospos_print.css');

        $this->assertStringContainsString('.print_hide', $printCss, 'Buttons/nav must stay hidden on paper');
        $this->assertStringContainsString('@media print', $printCss);
    }
}
