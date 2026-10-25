# Ru'ya accounting, treasury and costing — design

Status: implemented in this branch. Owner policies confirmed 2026-10-25:
moving weighted average cost, no VAT today (tax stays configurable), calendar
fiscal year with monthly periods.

## Principles

1. **One ledger.** Every financial consequence is a posted journal entry in
   `acc_journal_entries` / `acc_journal_lines`. Treasury, customer, supplier and
   shareholder balances are views over that ledger plus the treasury movement
   sub-ledger, never stored editable numbers.
2. **Operational documents stay where they are.** Sales, receivings, expenses and
   cash-ups keep their OSPOS tables. The posting engine (`App\Libraries\Accounting\Posting`)
   reads a document and writes its entry inside the same database transaction.
3. **Posted entries are immutable.** A change to a posted document reverses its
   entry (a new entry with mirrored lines, `reverses_entry_id` set) and posts a
   fresh one. Nothing is updated or deleted in place.
4. **Idempotent posting.** `(source_type, source_id)` identifies the live entry
   of a document; re-posting an unchanged document is a no-op.
5. **Configurable, not hard-coded.** Accounts used by the engine are resolved
   through `acc_account_mappings` (key → account). Expense categories map to
   their own expense account.

## Invariants (enforced in code and covered by tests)

| # | Invariant | Where |
|---|-----------|-------|
| I1 | Every posted entry: Σ debit = Σ credit, ≥ 2 lines, no negative amounts | `JournalService::post()` refuses otherwise |
| I2 | Treasury balance = Σ IN − Σ OUT of its movements = GL balance of its account | movement `balance_after` written under a row lock; reconciliation report |
| I3 | Customer balance = Σ AR lines for that customer | statement is computed from lines |
| I4 | Supplier balance = Σ AP lines for that supplier | statement is computed from lines |
| I5 | Item quantity = Σ inventory movements | existing `inventory` table (unchanged) |
| I6 | GL inventory = Σ quantity × moving average cost | revaluation/variance entries on every cost change; valuation report shows the difference |
| I7 | Historical profit never changes | sale lines store `item_cost_price`; COGS posts from it |
| I8 | Posted entries are never mutated | reversal + repost only |
| I9 | Returns reference the original sale when made from a receipt | invoice-discount allocation travels with the line |
| I10 | Balance sheet balances (Assets = Liabilities + Equity incl. current profit) | balance sheet report flags any difference as a blocker |

Posting into a **closed period** is refused.

## Chart of accounts (seeded, editable)

```
1000 الأصول                     Assets
 1100 النقدية والخزائن          Cash & treasuries
  1110 الخزنة الرئيسية          Main cash box          (treasury)
  1120 البنك                    Bank                   (treasury)
  1130 مدفوعات البطاقات تحت التحصيل  Card clearing
  1140 شيكات تحت التحصيل        Cheques receivable
 1200 العملاء (المدينون)        Accounts receivable    (party: customer)
 1300 المخزون                   Inventory
 1500 الأصول الثابتة            Fixed assets (cost)
 1590 مجمع الإهلاك              Accumulated depreciation (contra)
2000 الخصوم                     Liabilities
 2100 الموردون (الدائنون)       Accounts payable       (party: supplier)
 2200 ضرائب مستحقة              Tax payable
 2300 أرصدة كروت الهدايا        Gift card liability
 2400 نقاط مكافآت العملاء       Reward points liability
3000 حقوق الملكية               Equity
 3100 رأس المال                 Capital (one sub-account per shareholder)
 3200 المسحوبات الشخصية         Drawings (one sub-account per shareholder)
 3300 جاري الشركاء              Partner current accounts (per shareholder)
 3400 الأرباح المحتجزة          Retained earnings
 3900 أرصدة افتتاحية            Opening balance equity
4000 الإيرادات                  Revenue
 4100 المبيعات                  Sales
 4150 خصومات المبيعات           Sales discounts (contra revenue)
 4900 إيرادات أخرى              Other income
5000 تكلفة المبيعات             Cost of sales
 5100 تكلفة البضاعة المباعة     COGS
 5150 فروق تكلفة المخزون        Inventory cost variance
 5200 عجز وزيادة المخزون         Inventory adjustments
6000 المصروفات                  Operating expenses
 6100 مصروفات عامة              General expenses (default expense category account)
 6200 الإهلاك                   Depreciation expense
 6300 عجز وزيادة الخزنة          Cash over / short
 6900 مصروفات أخرى              Other expenses
```

## Posting rules

Amounts below are per document; negative operational amounts (returns) flip the
side of the line.

| Document | Debit | Credit |
|----------|-------|--------|
| Sale (completed) | per payment: cash → treasury account, card → 1130, cheque → 1140, due → 1200 (customer), gift card → 2300, rewards → 2400; line discounts + invoice discount → 4150 | 4100 gross line amount (split by product category), 2200 tax |
| Sale COGS (stock items) | 5100 Σ qty × `item_cost_price` | 1300 |
| Sale cancelled / edited | reversal of the live entry (+ repost when edited) | |
| Return sale | same rules, negative quantities flip every line | |
| Receiving | 1300 net line cost | cash → treasury, due → 2100 (supplier), card/cheque → 1120 |
| Purchase return | 2100 / treasury at return value | 1300 at moving average cost; difference → 5150 |
| Expense | category account (default 6100) | cash → treasury, due → 2100 (supplier), others → 1120 |
| Cash-up close | 6300 shortage / treasury overage | treasury shortage / 6300 overage |
| Initial stock (new item quantity, CSV import, an item's first stock row in the backfill) | 1300 at moving average cost | 3900 opening balance equity |
| Inventory adjustment (later counts, corrections, write-offs) | 1300 / 5200 at moving average cost | 5200 / 1300 |
| Manual cost change | 1300 / 5150 revaluation of quantity on hand | 5150 / 1300 |
| Customer receipt | treasury | 1200 (customer) |
| Supplier payment | 2100 (supplier) | treasury |
| Shareholder contribution | treasury | 3100-x |
| Shareholder withdrawal | 3200-x | treasury |
| Profit allocation | 3400 | 3300-x (share × profit) |
| Asset purchase | 1500 | treasury / 2100 |
| Depreciation (monthly, straight line) | 6200 | 1590 |
| Treasury transfer | destination treasury | source treasury |
| Opening balance | account | 3900 |

Payment types are stored by OSPOS as translated text. `PaymentMethod::resolve()`
maps any locale's label (`Sales.cash`, `Sales.due`, and the older `Expenses.cash`
"نقدا", …) back to a method code; unknown labels post to 1130 (sales) or the
bank (purchases/expenses) and are listed on the accounting overview so they can
be corrected. Because the label *is* the stored value, the payment-method
language keys (`Sales.cash` "نقدى", `Sales.due`, …) must never be reworded.

## Invoice (whole-sale) discount

* Entered on the register as a percentage or an amount, with a mandatory
  reason; `sales_invoice_discount` is needed, and above
  `invoice_discount_max_percent` (default 10%) also
  `sales_invoice_discount_override`. Every change goes to the audit trail.
* Allocated to the lines in proportion to their net after line discounts
  (largest-remainder rounding, so the cents always add up) and stored per line
  in `sales_items.invoice_discount`; the header (type, value, reason) is on
  `sales`.
* Taxes are computed on the line net after its share of the invoice discount.
* A return made from the receipt carries the original per-unit share, so a
  partial return refunds exactly what was paid. A return cannot receive a new
  invoice discount.
* Reports use `Sale::lineNetSql()` so every total is net of the allocation;
  the ledger posts it to 4150 Sales discounts with the line discounts.

## Costing (moving weighted average)

On a receipt of `q` units at net unit cost `c` (line price ÷ pack size, after
line discount) with `Q` units on hand at average `C`:

* `Q > 0`: new average `(Q·C + q·c) / (Q + q)`
* `Q ≤ 0`: new average `c` (stock was empty; nothing to blend)
* purchase return (`q < 0`): average unchanged; units leave at `C`
* sales and sales returns: average unchanged; COGS uses the cost stored on the line

Every change is written to `item_cost_history` with before/after quantity and
cost, source document and employee. Negative stock is already refused by
`Item_quantity::changeQuantity()` (floor 0).

The average is stored on the product at two decimals. The rounding revalues the
stock on hand (`(Q+q)·round(C') − (Q·C + q·c)`); the receiving entry books it
to 5150 so that **inventory (1300) always equals Σ quantity × stored cost**.
It is computed from the cost-history row, so reposting a receiving is stable.

A stock adjustment row never changes once written. It is valued at the cost of
its day and is never reposted, so a later cost change cannot rewrite history.

## Corrections and periods

* Correcting a posted document (edit, re-sync, backfill rerun) reverses the old
  entry **on the old entry's date** and reposts on the document's date, so each
  month shows the corrected document. If either month is closed, both halves go
  into the current month.
* A month can be closed only after its last day; the year-end close only for a
  finished year. Reopening needs the `accounting_periods` grant.
* Profit allocation requires the active partners' profit shares to total
  exactly 100%; shares are never rescaled automatically.

## Analytical accounting

`acc_dimensions` (cost center, profit center, cashier, sales channel, product
category, …) and `acc_dimension_values`; any journal line can carry values in
`acc_journal_line_dimensions`. The engine tags automatically:

* sale revenue/COGS lines: cashier = employee, sales channel = POS/Invoice,
  product category = item category, profit center = default store center
* expense lines: the cost center chosen on the expense (default store center)

The dimensional ledger report groups or filters any account by any dimension.

## Decisions still open for the owner

* Shareholder profit-sharing percentages (modelled separately from ownership %).
* Useful lives / salvage values per asset category (entered per asset today).
* Initial stock is posted to opening balance equity (3900), so it is not reported as profit.
  An owner who instead buys stock through a new item's quantity field should use a receiving.
* Credit-sale limits per customer.
* Second treasury / bank accounts (supported; one cash box is seeded).
