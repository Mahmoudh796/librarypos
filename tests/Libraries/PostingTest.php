<?php

namespace Tests\Libraries;

use App\Libraries\Accounting\Posting;
use App\Models\Cashup;
use App\Models\Expense;
use App\Models\Receiving;
use App\Models\Sale;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

/**
 * Business loops through the real models, asserting the ledger consequences
 * and the accounting invariants after each one.
 */
class PostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->enableAccounting();
    }

    protected function tearDown(): void
    {
        $this->assertJournalBalances();
        $this->assertTreasuryReconciles();
        $this->restoreAccounting();

        parent::tearDown();
    }

    private function entryLines(int $entryId): array
    {
        $rows = $this->db->table('acc_journal_lines')
            ->select('code, SUM(debit) AS debit, SUM(credit) AS credit')
            ->join('acc_accounts', 'acc_accounts.account_id = acc_journal_lines.account_id')
            ->where('entry_id', $entryId)
            ->groupBy('code')
            ->get()->getResultArray();

        $lines = [];
        foreach ($rows as $row) {
            $lines[$row['code']] = round((float) $row['debit'] - (float) $row['credit'], 2);
        }
        ksort($lines);

        return $lines;
    }

    public function testCashSaleWithDiscountPostsRevenueDiscountCashAndCogs(): void
    {
        $itemId = $this->createStockedItem(6.00, 10.00, 50);

        // 3 x 10.00 with 10% off = 27.00 paid in cash; COGS 3 x 6.00.
        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 3, 'price' => 10, 'cost' => 6, 'discount' => 10, 'discount_type' => PERCENT]],
            [['type' => lang('Sales.cash'), 'amount' => 30, 'refund' => 3]]
        );

        $this->assertGreaterThan(0, $saleId);
        $entry = $this->liveEntryFor(Posting::SOURCE_SALE, $saleId);
        $this->assertNotNull($entry);
        $this->assertSame([
            '1110' => 27.00,
            '1300' => -18.00,
            '4100' => -30.00,
            '4150' => 3.00,
            '5100' => 18.00,
        ], $this->entryLines((int) $entry->entry_id));

        $movement = $this->db->table('treasury_movements')->where('entry_id', $entry->entry_id)->get()->getRow();
        $this->assertSame('in', $movement->direction);
        $this->assertEqualsWithDelta(27.0, (float) $movement->amount, 0.001);
        $this->assertSame('cash', $movement->payment_method);
    }

    public function testInvoiceDiscountIsPostedAsSalesDiscount(): void
    {
        $itemId = $this->createStockedItem(5.00, 50.00, 10);

        // 2 x 50 = 100 gross, 10.00 of invoice discount allocated to the line, 90 paid.
        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 2, 'price' => 50, 'cost' => 5, 'invoice_discount' => 10]],
            [['type' => lang('Sales.cash'), 'amount' => 90]]
        );

        $this->assertSame([
            '1110' => 90.00,
            '1300' => -10.00,
            '4100' => -100.00,
            '4150' => 10.00,
            '5100' => 10.00,
        ], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_SALE, $saleId)->entry_id));
    }

    public function testCreditSaleBooksCustomerReceivable(): void
    {
        $itemId = $this->createStockedItem(4.00, 9.00, 20);
        $customerId = $this->createCustomer();

        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 2, 'price' => 9, 'cost' => 4]],
            [['type' => lang('Sales.due'), 'amount' => 18]],
            null
        );
        // Sale::save_value drops unknown customers; attach one directly and repost to check party tagging.
        $this->db->table('sales')->where('sale_id', $saleId)->update(['customer_id' => $customerId]);
        (new Posting($this->db))->postSale($saleId);

        $this->assertEqualsWithDelta(18.0, $this->mappedBalance('accounts_receivable', 'customer', $customerId), 0.001);
    }

    public function testUnpaidInvoiceRemainderGoesToReceivable(): void
    {
        $itemId = $this->createStockedItem(2.00, 50.00, 5);

        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 1, 'price' => 50, 'cost' => 2]],
            [['type' => lang('Sales.cash'), 'amount' => 20]],
            null,
            1,
            SALE_TYPE_INVOICE
        );

        $lines = $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_SALE, $saleId)->entry_id);
        $this->assertEqualsWithDelta(20.0, $lines['1110'], 0.001);
        $this->assertEqualsWithDelta(30.0, $lines['1200'], 0.001);
    }

    public function testCardPaymentGoesToClearingNotTreasury(): void
    {
        $itemId = $this->createStockedItem(1.00, 5.00, 5);
        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 1, 'price' => 5, 'cost' => 1]],
            [['type' => lang('Sales.debit'), 'amount' => 5]]
        );

        $entryId = (int) $this->liveEntryFor(Posting::SOURCE_SALE, $saleId)->entry_id;
        $this->assertArrayHasKey('1130', $this->entryLines($entryId));
        $this->assertSame(0, $this->db->table('treasury_movements')->where('entry_id', $entryId)->countAllResults());
    }

    public function testReturnSaleReversesRevenueAndCogs(): void
    {
        $itemId = $this->createStockedItem(6.00, 10.00, 10);

        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => -1, 'price' => 10, 'cost' => 6]],
            [['type' => lang('Sales.cash'), 'amount' => -10]]
        );

        $this->assertSame([
            '1110' => -10.00,
            '1300' => 6.00,
            '4100' => 10.00,
            '5100' => -6.00,
        ], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_SALE, $saleId)->entry_id));
    }

    public function testCancellingSaleReversesItsEntry(): void
    {
        $itemId = $this->createStockedItem(3.00, 8.00, 10);
        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 1, 'price' => 8, 'cost' => 3]],
            [['type' => lang('Sales.cash'), 'amount' => 8]]
        );
        $original = $this->liveEntryFor(Posting::SOURCE_SALE, $saleId);

        $this->assertTrue(model(Sale::class)->delete($saleId, false, true, 1));

        $this->assertNull($this->liveEntryFor(Posting::SOURCE_SALE, $saleId));
        $this->assertNotNull($this->db->table('acc_journal_entries')->where('reverses_entry_id', $original->entry_id)->get()->getRow());
        $this->assertEqualsWithDelta(0.0, $this->sourceNet(Posting::SOURCE_SALE, $saleId, '4100'), 0.001);
    }

    public function testEditingSalePaymentRepostsInsteadOfMutating(): void
    {
        $itemId = $this->createStockedItem(3.00, 8.00, 10);
        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 1, 'price' => 8, 'cost' => 3]],
            [['type' => lang('Sales.cash'), 'amount' => 8]]
        );
        $original = $this->liveEntryFor(Posting::SOURCE_SALE, $saleId);
        $paymentId = (int) $this->db->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;

        model(Sale::class)->update($saleId, ['payments' => [[
            'payment_id' => $paymentId, 'payment_type' => lang('Sales.check'), 'payment_amount' => 8,
            'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => 1,
        ]]]);

        $current = $this->liveEntryFor(Posting::SOURCE_SALE, $saleId);
        $this->assertNotEquals($original->entry_id, $current->entry_id);
        $this->assertArrayHasKey('1140', $this->entryLines((int) $current->entry_id));
        $this->assertEqualsWithDelta(0.0, $this->sourceNet(Posting::SOURCE_SALE, $saleId, '1110'), 0.001);

        // Reposting an unchanged document is a no-op.
        (new Posting($this->db))->postSale($saleId);
        $this->assertSame($current->entry_id, $this->liveEntryFor(Posting::SOURCE_SALE, $saleId)->entry_id);
    }

    public function testHistoricalCostSurvivesLaterPurchases(): void
    {
        $itemId = $this->createStockedItem(5.00, 12.00, 10);
        $saleId = $this->completeSale(
            [['item_id' => $itemId, 'quantity' => 2, 'price' => 12, 'cost' => 5]],
            [['type' => lang('Sales.cash'), 'amount' => 24]]
        );

        $this->receive($itemId, 8, 9.00, lang('Sales.cash'));
        (new Posting($this->db))->postSale($saleId);

        $this->assertEqualsWithDelta(10.0, $this->sourceNet(Posting::SOURCE_SALE, $saleId, '5100'), 0.001, 'COGS stays at the cost captured on the sale line.');
    }

    public function testReceivingUpdatesMovingAverageAndBooksPayable(): void
    {
        // 10 on hand at 4.00; receive 10 more at 6.00 on credit -> average 5.00.
        $itemId = $this->createStockedItem(4.00, 10.00, 10);
        $supplierId = $this->createSupplier();

        $receivingId = $this->receive($itemId, 10, 6.00, lang('Sales.due'), $supplierId);

        $this->assertEqualsWithDelta(5.0, (float) $this->db->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price, 0.001);
        $history = $this->db->table('item_cost_history')->where('item_id', $itemId)->get()->getRow();
        $this->assertEqualsWithDelta(4.0, (float) $history->cost_before, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $history->cost_after, 0.0001);

        $this->assertSame(['1300' => 60.00, '2100' => -60.00], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_RECEIVING, $receivingId)->entry_id));
        $this->assertEqualsWithDelta(-60.0, $this->mappedBalance('accounts_payable', 'supplier', $supplierId), 0.001);
    }

    public function testAverageCostRoundingIsBookedSoInventoryEqualsStockValue(): void
    {
        // 118 at 3.00 + 10 at 4.00 -> 3.078125, stored as 3.08: stock is worth 0.24 more than paid.
        $itemId = $this->createStockedItem(3.00, 5.00, 118);
        $receivingId = $this->receive($itemId, 10, 4.00, lang('Sales.cash'));

        $this->assertEqualsWithDelta(3.08, (float) $this->db->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price, 0.0001);
        $lines = $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_RECEIVING, $receivingId)->entry_id);
        $this->assertEqualsWithDelta(40.24, $lines['1300'], 0.001, 'Inventory rises to 128 × 3.08 - 118 × 3.00.');
        $this->assertEqualsWithDelta(-0.24, $lines['5150'], 0.001);
        $this->assertEqualsWithDelta(-40.00, $lines['1110'], 0.001, 'The supplier is still paid exactly 40.');

        // Reposting the unchanged receiving keeps the same entry.
        $entryId = (int) $this->liveEntryFor(Posting::SOURCE_RECEIVING, $receivingId)->entry_id;
        (new Posting($this->db))->postReceiving($receivingId);
        $this->assertSame($entryId, (int) $this->liveEntryFor(Posting::SOURCE_RECEIVING, $receivingId)->entry_id);
    }

    public function testReceivingIntoEmptyStockTakesIncomingCost(): void
    {
        $itemId = $this->createStockedItem(4.00, 10.00, 0);

        $this->receive($itemId, 5, 7.50, lang('Sales.cash'));

        $this->assertEqualsWithDelta(7.5, (float) $this->db->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price, 0.001);
    }

    public function testPackPriceAndDiscountGiveNetUnitCost(): void
    {
        // 2 packs of 12 at 60.00 per pack, 10% off: 108.00 for 24 units = 4.50 a unit.
        $itemId = $this->createStockedItem(4.00, 10.00, 0);

        $this->receive($itemId, 2, 60.00, lang('Sales.cash'), null, 12, 10, PERCENT);

        $this->assertEqualsWithDelta(4.5, (float) $this->db->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price, 0.001);
        $this->assertSame(24.0, (float) $this->db->table('item_quantities')->where('item_id', $itemId)->get()->getRow()->quantity);
    }

    public function testPurchaseReturnLeavesAtAverageCostWithVariance(): void
    {
        $itemId = $this->createStockedItem(5.00, 10.00, 10);
        $supplierId = $this->createSupplier();

        // Return 2 units to the supplier at 6.00 although they are carried at 5.00.
        $receivingId = $this->receive($itemId, -2, 6.00, lang('Sales.due'), $supplierId);

        $this->assertSame(['1300' => -10.00, '2100' => 12.00, '5150' => -2.00], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_RECEIVING, $receivingId)->entry_id));
        $this->assertEqualsWithDelta(5.0, (float) $this->db->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price, 0.001, 'A return never changes the average.');
    }

    public function testDeletingReceivingReversesIt(): void
    {
        $itemId = $this->createStockedItem(5.00, 10.00, 0);
        $receivingId = $this->receive($itemId, 4, 5.00, lang('Sales.cash'));

        $this->assertTrue(model(Receiving::class)->delete_list([$receivingId], 1));

        $this->assertNull($this->liveEntryFor(Posting::SOURCE_RECEIVING, $receivingId));
        $this->assertEqualsWithDelta(0.0, $this->sourceNet(Posting::SOURCE_RECEIVING, $receivingId, '1300'), 0.001);
    }

    public function testCashExpensePostsToCategoryAccountAndTreasury(): void
    {
        $categoryId = $this->createExpenseCategory();
        $expense = [
            'date' => date('Y-m-d H:i:s'), 'amount' => 150, 'tax_amount' => 0, 'payment_type' => lang('Sales.cash'),
            'expense_category_id' => $categoryId, 'description' => 'Electricity', 'employee_id' => 1, 'deleted' => 0,
            'supplier_id' => null, 'supplier_tax_code' => '',
        ];

        $this->assertTrue(model(Expense::class)->save_value($expense));
        $expenseId = (int) $expense['expense_id'];

        $this->assertSame(['1110' => -150.00, '6100' => 150.00], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_EXPENSE, $expenseId)->entry_id));

        model(Expense::class)->delete_list([$expenseId]);
        $this->assertNull($this->liveEntryFor(Posting::SOURCE_EXPENSE, $expenseId));
    }

    public function testCorrectingAnOldDocumentStaysInItsOwnPeriod(): void
    {
        $date = date('Y-m-15 10:00:00', strtotime('first day of -2 months'));
        $expenseId = $this->saveExpense($date, 100);
        $original = $this->liveEntryFor(Posting::SOURCE_EXPENSE, $expenseId);

        $this->saveExpense($date, 120, $expenseId);

        $reversal = $this->db->table('acc_journal_entries')->where('reverses_entry_id', $original->entry_id)->get()->getRow();
        $current = $this->liveEntryFor(Posting::SOURCE_EXPENSE, $expenseId);
        $this->assertSame(substr($date, 0, 7), substr($reversal->entry_date, 0, 7), 'The reversal belongs to the original month.');
        $this->assertSame(substr($date, 0, 7), substr($current->entry_date, 0, 7));
    }

    public function testCorrectingADocumentInAClosedPeriodPostsBothHalvesNow(): void
    {
        $date = date('Y-m-15 10:00:00', strtotime('first day of -2 months'));
        $expenseId = $this->saveExpense($date, 100);
        $original = $this->liveEntryFor(Posting::SOURCE_EXPENSE, $expenseId);
        $period = ['period_year' => (int) substr($date, 0, 4), 'period_month' => (int) substr($date, 5, 2)];
        $existing = $this->db->table('acc_periods')->where($period)->get()->getRow();
        $existing === null ? $this->db->table('acc_periods')->insert($period + ['status' => 'closed']) : $this->db->table('acc_periods')->where($period)->update(['status' => 'closed']);

        try {
            $this->saveExpense($date, 120, $expenseId);

            $reversal = $this->db->table('acc_journal_entries')->where('reverses_entry_id', $original->entry_id)->get()->getRow();
            $current = $this->liveEntryFor(Posting::SOURCE_EXPENSE, $expenseId);
            $this->assertSame(date('Y-m'), substr($reversal->entry_date, 0, 7));
            $this->assertSame(date('Y-m'), substr($current->entry_date, 0, 7));
        } finally {
            $existing === null ? $this->db->table('acc_periods')->where($period)->delete() : $this->db->table('acc_periods')->where($period)->update(['status' => $existing->status]);
        }
    }

    public function testExpenseCategoryWithOwnAccountUsesIt(): void
    {
        $rent = $this->db->table('acc_accounts')->where('code', '6900')->get()->getRow();
        $categoryId = $this->createExpenseCategory((int) $rent->account_id);
        $expense = [
            'date' => date('Y-m-d H:i:s'), 'amount' => 40, 'tax_amount' => 0, 'payment_type' => lang('Sales.debit'),
            'expense_category_id' => $categoryId, 'description' => 'Bank fee', 'employee_id' => 1, 'deleted' => 0,
            'supplier_id' => null, 'supplier_tax_code' => '',
        ];

        model(Expense::class)->save_value($expense);

        $this->assertSame(['1120' => -40.00, '6900' => 40.00], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_EXPENSE, (int) $expense['expense_id'])->entry_id));
    }

    public function testCashupPostsShortageAgainstExpectedCash(): void
    {
        $open = date('Y-m-d H:i:s', time() - 3600);
        $close = date('Y-m-d H:i:s', time() + 60);
        $itemId = $this->createStockedItem(1.00, 100.00, 5);
        $this->completeSale([['item_id' => $itemId, 'quantity' => 1, 'price' => 100, 'cost' => 1]], [['type' => lang('Sales.cash'), 'amount' => 100]]);

        $cashup = model(Cashup::class);
        $expected = $cashup->expectedClosingCash($open, $close, 500, 0);

        $data = [
            'open_date' => $open, 'close_date' => $close, 'open_amount_cash' => 500, 'transfer_amount_cash' => 0,
            'closed_amount_cash' => $expected - 7.5, 'closed_amount_due' => 0, 'closed_amount_card' => 0, 'closed_amount_check' => 0,
            'closed_amount_total' => 0, 'note' => 0, 'description' => '', 'open_employee_id' => 1, 'close_employee_id' => 1, 'deleted' => 0,
        ];
        $this->assertTrue($cashup->save_value($data));
        $cashupId = (int) $data['cashup_id'];

        $row = $this->db->table('cash_up')->where('cashup_id', $cashupId)->get()->getRow();
        $this->assertEqualsWithDelta(-7.5, (float) $row->difference_cash, 0.001);
        $this->assertSame(['1110' => -7.50, '6300' => 7.50], $this->entryLines((int) $this->liveEntryFor(Posting::SOURCE_CASHUP, $cashupId)->entry_id));
    }

    public function testInventoryAdjustmentAndCostRevaluation(): void
    {
        $itemId = $this->createStockedItem(8.00, 10.00, 5);
        $posting = new Posting($this->db);

        $adjustment = $posting->postInventoryAdjustment(910000 + random_int(1, 9999), $itemId, -2, 1, 'damaged');
        $this->assertSame(['1300' => -16.00, '5200' => 16.00], $this->entryLines((int) $adjustment));

        $revaluation = $posting->postCostRevaluation(920000 + random_int(1, 9999), $itemId, 3 * 1.5, 1);
        $this->assertSame(['1300' => 4.50, '5150' => -4.50], $this->entryLines((int) $revaluation));
    }

    public function testStockAdjustmentKeepsTheValueOfItsDay(): void
    {
        $itemId = $this->createStockedItem(3.00, 5.00, 10);
        $transId = 940000 + random_int(1, 9999);
        $posting = new Posting($this->db);

        $entryId = $posting->postInventoryAdjustment($transId, $itemId, -2, 1, 'damaged');
        $this->db->table('items')->where('item_id', $itemId)->update(['cost_price' => 3.50]);

        // Posting the same stock row again (a backfill rerun) must not revalue it at today's cost.
        $this->assertSame($entryId, $posting->postInventoryAdjustment($transId, $itemId, -2, 1, 'damaged'));
        $this->assertSame(['1300' => -6.00, '5200' => 6.00], $this->entryLines((int) $entryId));
    }

    public function testInitialStockIsOpeningEquityNotAGain(): void
    {
        $itemId = $this->createStockedItem(8.00, 10.00, 0);

        $entryId = (new Posting($this->db))->postInventoryAdjustment(930000 + random_int(1, 9999), $itemId, 5, 1, 'initial', null, true);

        $this->assertSame(['1300' => 40.00, '3900' => -40.00], $this->entryLines((int) $entryId));
    }

    public function testPaymentLabelsFromAnyLocaleResolve(): void
    {
        $this->assertSame('cash', \App\Libraries\Accounting\PaymentMethod::resolve('نقدى'));
        $this->assertSame('cash', \App\Libraries\Accounting\PaymentMethod::resolve('Cash'));
        $this->assertSame('due', \App\Libraries\Accounting\PaymentMethod::resolve('مستحق'));
        $this->assertSame('giftcard', \App\Libraries\Accounting\PaymentMethod::resolve('بطاقة هدية:4100000048'));
        $this->assertSame('unknown', \App\Libraries\Accounting\PaymentMethod::resolve('Bitcoin'));
        $this->assertSame('cash', \App\Libraries\Accounting\PaymentMethod::resolve('نقدا'), 'Expenses saved with the Expenses.cash label are cash.');
    }

    private function sourceNet(string $sourceType, int $sourceId, string $code): float
    {
        $row = $this->db->table('acc_journal_lines')
            ->select('SUM(debit) - SUM(credit) AS net', false)
            ->join('acc_journal_entries', 'acc_journal_entries.entry_id = acc_journal_lines.entry_id')
            ->join('acc_accounts', 'acc_accounts.account_id = acc_journal_lines.account_id')
            ->where('acc_journal_entries.source_type', $sourceType)
            ->where('acc_journal_entries.source_id', $sourceId)
            ->where('acc_accounts.code', $code)
            ->get()->getRow();

        return round((float) ($row->net ?? 0), 2);
    }

    private function receive(int $itemId, float $quantity, float $price, string $paymentType, ?int $supplierId = null, float $packSize = 1, float $discount = 0, int $discountType = FIXED): int
    {
        $items = [1 => [
            'item_id' => $itemId, 'line' => 1, 'description' => '', 'serialnumber' => '', 'quantity' => $quantity,
            'receiving_quantity' => $packSize, 'discount' => $discount, 'discount_type' => $discountType, 'price' => $price, 'item_location' => 1,
        ]];

        $receivingId = model(Receiving::class)->save_value($items, $supplierId ?? NEW_ENTRY, 1, '', '', $paymentType);
        $this->assertGreaterThan(0, $receivingId, 'Receiving must be saved.');

        return $receivingId;
    }

    private function createCustomer(): int
    {
        $this->db->table('people')->insert(['first_name' => 'Customer', 'last_name' => uniqid(), 'email' => uniqid() . '@test.com', 'phone_number' => '']);
        $personId = (int) $this->db->insertID();
        $this->db->table('customers')->insert(['person_id' => $personId, 'taxable' => 0, 'tax_id' => '', 'deleted' => 0, 'discount' => 0, 'discount_type' => 0, 'date' => date('Y-m-d H:i:s'), 'employee_id' => 1, 'consent' => 1]);

        return $personId;
    }

    private function createSupplier(): int
    {
        $this->db->table('people')->insert(['first_name' => 'Supplier', 'last_name' => uniqid(), 'email' => uniqid() . '@test.com', 'phone_number' => '']);
        $personId = (int) $this->db->insertID();
        $this->db->table('suppliers')->insert(['person_id' => $personId, 'company_name' => 'Supplier ' . $personId, 'agency_name' => '', 'tax_id' => '', 'deleted' => 0, 'category' => 0]);

        return $personId;
    }

    private function saveExpense(string $date, float $amount, int $expenseId = NEW_ENTRY): int
    {
        $expense = [
            'date' => $date, 'amount' => $amount, 'tax_amount' => 0, 'payment_type' => lang('Sales.cash'),
            'expense_category_id' => $this->createExpenseCategory(), 'description' => 'Rent', 'employee_id' => 1, 'deleted' => 0,
            'supplier_id' => null, 'supplier_tax_code' => '',
        ];
        $this->assertTrue(model(Expense::class)->save_value($expense, $expenseId));

        return $expenseId === NEW_ENTRY ? (int) $expense['expense_id'] : $expenseId;
    }

    private function createExpenseCategory(?int $accountId = null): int
    {
        $this->db->table('expense_categories')->insert(['category_name' => 'Cat ' . uniqid(), 'category_description' => '', 'deleted' => 0, 'account_id' => $accountId]);

        return (int) $this->db->insertID();
    }
}
