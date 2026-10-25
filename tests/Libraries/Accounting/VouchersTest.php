<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Vouchers;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;

class VouchersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    private Vouchers $vouchers;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->vouchers = new Vouchers($this->db);
    }

    protected function tearDown(): void
    {
        $this->assertJournalBalances();
        $this->assertTreasuryReconciles();

        parent::tearDown();
    }

    public function testCustomerReceiptSettlesReceivableIntoTreasury(): void
    {
        $customerId = 880000 + random_int(1, 9999);
        $cashBefore = $this->accountBalance($this->cashAccountId());

        $voucherId = $this->vouchers->create(['voucher_type' => 'customer_receipt', 'treasury_id' => 1, 'amount' => 125.5, 'party_id' => $customerId], 1);

        $voucher = $this->db->table('treasury_vouchers')->where('voucher_id', $voucherId)->get()->getRow();
        $this->assertStringStartsWith('RV-', $voucher->voucher_number);
        $this->assertEqualsWithDelta(-125.5, $this->mappedBalance('accounts_receivable', 'customer', $customerId), 0.001);
        $this->assertEqualsWithDelta($cashBefore + 125.5, $this->accountBalance($this->cashAccountId()), 0.001);

        $movement = $this->db->table('treasury_movements')->where('source_type', Vouchers::SOURCE)->where('source_id', $voucherId)->get()->getRow();
        $this->assertSame('customer', $movement->party_type);
    }

    public function testSupplierPaymentReducesPayable(): void
    {
        $supplierId = 770000 + random_int(1, 9999);

        $this->vouchers->create(['voucher_type' => 'supplier_payment', 'treasury_id' => 2, 'amount' => 300, 'party_id' => $supplierId], 1);

        $this->assertEqualsWithDelta(300.0, $this->mappedBalance('accounts_payable', 'supplier', $supplierId), 0.001);
    }

    public function testTransferMovesMoneyBetweenTreasuries(): void
    {
        $cash = $this->accountBalance($this->cashAccountId());
        $bankAccount = (int) $this->db->table('treasuries')->where('treasury_id', 2)->get()->getRow()->account_id;
        $bank = $this->accountBalance($bankAccount);

        $this->vouchers->create(['voucher_type' => 'transfer', 'treasury_id' => 1, 'target_treasury_id' => 2, 'amount' => 50], 1);

        $this->assertEqualsWithDelta($cash - 50, $this->accountBalance($this->cashAccountId()), 0.001);
        $this->assertEqualsWithDelta($bank + 50, $this->accountBalance($bankAccount), 0.001);
    }

    public function testTransferToSameTreasuryIsRefused(): void
    {
        $this->expectException(AccountingException::class);

        $this->vouchers->create(['voucher_type' => 'transfer', 'treasury_id' => 1, 'target_treasury_id' => 1, 'amount' => 5], 1);
    }

    public function testOtherPaymentRefusesTreasuryOrPartyAccounts(): void
    {
        $receivable = $this->db->table('acc_accounts')->where('code', '1200')->get()->getRow();

        $this->expectException(AccountingException::class);

        $this->vouchers->create(['voucher_type' => 'other_payment', 'treasury_id' => 1, 'amount' => 5, 'account_id' => (int) $receivable->account_id], 1);
    }

    public function testZeroAmountIsRefused(): void
    {
        $this->expectException(AccountingException::class);

        $this->vouchers->create(['voucher_type' => 'opening_balance', 'treasury_id' => 1, 'amount' => 0], 1);
    }

    public function testReversingVoucherRestoresBalancesAndCannotRepeat(): void
    {
        $cash = $this->accountBalance($this->cashAccountId());
        $income = $this->db->table('acc_accounts')->where('code', '4900')->get()->getRow();

        $voucherId = $this->vouchers->create(['voucher_type' => 'other_receipt', 'treasury_id' => 1, 'amount' => 80, 'account_id' => (int) $income->account_id], 1);
        $this->vouchers->reverse($voucherId, 1, 'entered twice');

        $this->assertEqualsWithDelta($cash, $this->accountBalance($this->cashAccountId()), 0.001);
        $this->assertSame('reversed', $this->db->table('treasury_vouchers')->where('voucher_id', $voucherId)->get()->getRow()->status);

        $this->expectException(AccountingException::class);
        $this->vouchers->reverse($voucherId, 1);
    }
}
