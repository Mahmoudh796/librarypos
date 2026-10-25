# Ru'ya POS: full-system audit and completion report

Status: implemented on a local working tree and verified on test data. **Nothing is committed, pushed or deployed.**
Date: 2026-10-25. Base commit: `1684d187d` (master).
Local DB: `ospos` (test data only, confirmed by the owner). Accounting policy decisions confirmed by the owner:
moving weighted average cost, no VAT, calendar fiscal year.

Companion document: [ACCOUNTING_DESIGN.md](ACCOUNTING_DESIGN.md) (principles, invariants, posting rules).

---

## 1. Original system map

OSPOS 3.x (CodeIgniter 4.7.4, PHP 8.2, MariaDB, Bootstrap 3), with the local "Dahrouj" Arabic/RTL customisation.

| Area | Original state |
| --- | --- |
| POS (`Sales`, `Sale_lib`) | Cart in session, line discounts (% / fixed), payments stored as **translated text labels**, returns, suspended sales, quotes/invoices/work orders, receipts. No whole-invoice discount. |
| Purchasing (`Receivings`) | Receive / return, supplier, pack size, line discount. Optional average-cost update (off by default). |
| Inventory (`Items`, `Item_quantity`, `Inventory`) | Per-location quantities plus a movement table (`inventory`). Categories were free text on the item. |
| Cash-up (`Cashups`) | Open/close shift with manually typed amounts. No expected-cash calculation. |
| Expenses | Category, payment type, supplier, tax. No treasury or cost centre. |
| Reports | Summary/detailed/graphical OSPOS reports: sales, receivings, inventory, taxes, payments, discounts. |
| Accounting | **None.** There was no chart of accounts, journal, treasury ledger, AR/AP ledger, equity or fixed assets. |
| Auth | Module grants plus sub-permissions (`grants`, `permissions`), checked in the `Secure_Controller` constructor. |

## 2. Existing functionality retained

Everything was extended in place; nothing was rewritten. In particular:

- the session cart, line discounts, returns, suspended sales and all receipt formats;
- the payment-label storage format. Existing labels such as `نقدى` and `نقدا` were **not reworded**: the ledger resolves them through `PaymentMethod`, which knows every locale's label;
- all existing reports, with their totals now net of the invoice discount;
- the backup tooling, which was not touched.

Existing data stays readable by old code paths: all schema changes are additive columns and tables.

## 3. Broken functionality repaired

| Problem | Root cause | Fix |
| --- | --- | --- |
| Receiving screen: editing a line returned a 500 error | `FILTER_SANITIZE_NUMBER_FLOAT` stripped Arabic-Indic digits, then a string was passed to a float parameter, causing a TypeError | `Receivings::postEditItem` validates with `decimal_locale` and parses with `parse_decimals`/`parse_quantity`. Regression test added. |
| Number parsing in `ar-EG`: `10.5` was rejected and `1,000.50` was read as `1` | The ICU formatter with Arabic digits accepted partial parses | `parse_decimals` normalises digits, uses `@numbers=latn` and rejects partial parses. Tests added. |
| `-0.00` shown on totals | Rounding sign | `to_decimals` prints 0 for values below half a unit. |
| New product could not be saved | Two default tax rows named `0` collided on the primary key | Duplicate and blank tax rows are skipped. Migration `RuyaRepairDefaultTaxPlaceholders` cleans the placeholder data. |
| Category dropdown missed categories | Categories were free text with no catalogue | Approved-category catalogue (see section 8). |
| Table filters did not refresh; JS error on filter pages | A `const` was reassigned, and duplicate refresh handlers existed | `table_filter_persistence.php` fixed; per-page duplicate handlers removed. |
| Initial stock showed as profit | Stock entered on new items was valued as an inventory gain | Posted to opening-balance equity (3900). |
| Concurrent sales could fail ledger posting | Check-then-insert race on dimension values | Atomic upsert in `Ledger::dimensionValue` (`SaleTest` concurrency tests cover it). |
| Concurrent receivings of the same item could deadlock, and could average cost from stale stock | Each line insert takes a shared FK lock on the item row before the cost update needs an exclusive one; the cost reads used an older snapshot | `Costing::lockItems()` locks the item rows in id order as the first statement of the receiving transaction. `ReceivingTest` asserts that the second receipt averages against 15 units, not 10. |

## 4. Missing functionality implemented

- Whole-invoice discount (section 11).
- Moving-average costing with cost history (section 12).
- Double-entry accounting: chart of accounts, journal, general ledger, trial balance, periods, year close, reversals.
- Treasuries, treasury vouchers and a treasury movement ledger with balance before/after.
- Cashier-shift reconciliation (expected vs counted).
- Expenses linked to treasury, cost centre and reference.
- Fixed assets with straight-line depreciation.
- Customer and supplier statements and aging.
- Shareholders: capital, drawings, current accounts, profit allocation.
- Analytical dimensions and cost centres.
- Income statement and balance sheet.
- Inventory valuation report and cost-history report.
- Audit trail.
- Finance dashboard tiles.
- `php spark accounting:backfill` to post historical documents.

## 5. Navigation restructuring

`ruya_navigation()` in `app/Helpers/navigation_helper.php` groups modules by business function. Each entry is shown only if the employee holds the module grant and, where set, the extra permission:

- **المبيعات (Sales):** POS, sales history, customers, customer accounts, gift cards.
- **المشتريات (Purchases):** receiving, purchase history, suppliers, supplier accounts.
- **المخزون (Inventory):** items, categories, variants, kits, attributes, low stock, valuation.
- **الخزينة (Treasury):** movements, vouchers, shifts.
- **المصروفات والتكاليف (Costs):** expenses, expense categories, fixed assets, cost history.
- **الحسابات (Accounting):** reports, journal, GL, trial balance, P&L, balance sheet, shareholders, dimensions, chart of accounts, periods.
- **Administration:** team, communications, system, backups (config grant), audit log (accounting setup grant).

The header loads the employee's grants **once**. Initially there was one query per menu entry; see section 30.

## 6. Arabic terminology changes

Professional Egyptian commercial Arabic is used for `ar-EG` and `ar-LB` (about 255 keys revised, plus all new keys). Examples:

- Product → **المنتج / المنتجات** everywhere, following the convention already set by `ProductTerminologyTest`. For example: اسم المنتج, نوع المنتج, مجموعة منتجات, خصم المنتجات.
- Receivings → **المشتريات** / فاتورة مشتريات / حفظ الفاتورة.
- Cash-ups → **إقفال الورديات**.
- Stock location → **المخزن**.
- Non-stock item → **خدمة (بدون مخزون)**.
- Count → **تسوية المخزون**.
- Discount → **الخصم**. الحسم is no longer used.
- Opening stock → **بضاعة أول المدة**.

Payment-type labels were deliberately **not changed**, because historical rows store them as text. Other locales received empty strings for new keys, which fall back to English, per `AGENTS.md`.

## 7. RTL certification

- Machine values (codes, barcodes, amounts, dates, references) are wrapped in `dj-ltr`, `dir="ltr"` or `dir="auto"`. The `td.dj-ltr` display bug was fixed.
- Signed amounts are displayed explicitly; there are no parentheses, which flip in RTL. This now also applies to income-statement subtotals.
- Register summary columns are aligned with `text-align: end`.
- Screens reviewed in Chrome at 1366 px with Playwright screenshots: POS, receiving, items, categories, accounting, treasury, journal, P&L, balance sheet, shareholders, vouchers and fixed assets.
- `HeaderNavTest` covers RTL navigation rendering.
- **Not certified:** a full pass at phone width.

## 8. Product and category improvements

- Approved category catalogue (`app/Models/Category.php`, `items/categories`):
  - create, rename (merges and moves items), and delete (refused while in use);
  - Arabic-aware normalisation (أ/إ/آ→ا, ة→ه, ى→ي, tatweel and diacritics removed), so spelling variants snap to the approved name instead of fragmenting.
- New permission `items_categories`. Without it, users can only pick an approved category or keep an item's existing one.
- Item list filters: category, supplier, out of stock.
- Item form split into sections: item data / pricing and taxes / stock / details.
- Manual cost changes are recorded in cost history and revalue the ledger.
- Inventory adjustments and CSV imports are posted.

## 9. Purchasing improvements

- Root-cause fix of the receiving edit error (section 3).
- Moving-average cost always updates on receipt (`receiving_calculate_average_price=1`). The cost is net of line discount and pack size.
- Purchase returns leave at average cost; the price difference goes to 5150 (purchase price variance).
- Every receiving, edit and delete is posted, reversed or reposted.

## 10. POS improvements

- Register summary rows: gross, item discounts, invoice discount, net, tax, total, paid, change due.
- Invoice-discount panel with a reason field.
- The receipt, invoice and email templates show the invoice discount.
- Sales history rows expand to show product lines (code, name, qty, price, discount, net). This replaces showing "one arbitrary product".
- Audits record price changes, discount overrides, sale edits and sale deletes.

## 11. Whole-invoice discount

| Aspect | Implementation |
| --- | --- |
| Types | Percentage or fixed amount (`Sales::postInvoiceDiscount`) |
| Permissions | `sales_invoice_discount` to apply it; `sales_invoice_discount_override` to exceed the limit |
| Limit | `invoice_discount_max_percent`, default 10 and editable in General settings |
| Reason | Required |
| Audit | `invoice_discount` audit event with amount, limit and override flag |
| Allocation | Largest-remainder allocation across lines in `Sale_lib::get_cart()`, stored per line in `sales_items.invoice_discount`; the header is in `sales.invoice_discount_*` |
| Tax | Tax is computed after the discount; `Tax_lib` uses the discounted base |
| Returns | Returns carry each line's discount per unit, so a partial return refunds the net price. Verified: POS 40 refunded 18.47 |
| Reports | All sale-total SQL goes through `Sale::lineNetSql()` and `lineDiscountSql()` |
| Ledger | Posted to 4150 Sales discounts |

## 12. Costing method and rationale

The method is moving weighted average, as chosen by the owner. It fits a single-store retail operation with frequent small purchases, needs no lot tracking, and is what OSPOS's existing `cost_price` field already approximates.

- `Costing::receive()`: new average = (on-hand qty × current avg + received qty × net unit cost) ÷ new qty.
- When stock on hand is zero or negative, the incoming cost is used.
- Every change is written to `item_cost_history` (old/new cost, qty, source document, employee).

## 13. COGS implementation

- At sale completion, COGS = qty × the item's cost **at that moment**. The cost is saved on the sale line (`item_cost_price`), so later purchases never change historical profit (invariant I7, tested).
- Posting: Dr 5100 COGS / Cr 1300 Inventory, for stocked items only.
- Returns reverse the entry at the original line cost.

## 14. Treasury implementation

- `treasuries` are linked to GL accounts. Seeded: 1 = الخزنة الرئيسية → 1110, and 2 = البنك → 1120.
- Every journal line on a treasury account writes a `treasury_movements` row with balance before/after, under a `FOR UPDATE` lock. The balance is derived and never typed in.
- Vouchers (`treasury/voucher`): customer receipt, supplier payment, other receipt, other payment, transfer and opening balance. Reversal requires `treasury_reverse`.
- Card and cheque payments go to clearing accounts (1130/1140), not to the cash box.

## 15. Cashier shift reconciliation

- On open: the opening float is recorded.
- On close: the system computes **expected cash**, from treasury movements during the shift or from the legacy calculation before accounting was enabled.
  - The cashier types the counted amount; it is required when closing.
  - The difference (shortage/overage) is stored as `difference_cash` and shown live on the form and in the list.
- Posting: a shortage goes to 6300 Cash over/short. The movement is written to the treasury.

## 16. Expenses

- Each expense has an expense category; a category can have its own GL account, otherwise the default is 6100.
- A treasury is shown only for cash payments. Expenses also take a cost centre and a reference.
- Posting: Dr expense account / Cr treasury, AP (supplier) or bank clearing, depending on payment type.
- Edit and delete repost or reverse the entry inside the document's transaction.

## 17. Fixed assets

- Asset register (`fixed_assets`) records cost, salvage value, useful life in months, category and location.
- Payment comes from a treasury or on supplier credit.
- Capitalisation: Dr 1500 / Cr treasury or 2100.
- Depreciation is straight-line and monthly, **starting the month after purchase**. It runs as one entry per month (Dr 6200 / Cr 1590). Re-running a month is refused.
- Verified in the browser: asset saved and capitalised; depreciation for the purchase month was correctly refused.

## 18. Accounting architecture

`app/Libraries/Accounting/`:

| Class | Role |
| --- | --- |
| `Ledger` | The only writer of journal entries. Enforces debits = credits in cents, an open period, and postable, active accounts. Writes treasury movements. Corrections are mirrored reversals only. |
| `Posting` | Turns source documents (sales, receivings, expenses, cash-ups, inventory, cost changes) into entries. `sync()` is idempotent: if the document is unchanged it does nothing; if it changed, it reverses and reposts. |
| `Costing` | Moving average and cost history |
| `Vouchers` | Treasury vouchers |
| `Reports` | Trial balance, GL, party statements, aging, P&L, balance sheet, valuation, dimension summary |
| `Shareholding` | Partners, transactions, allocation |
| `FixedAssets` | Register and depreciation |
| `Periods` | Monthly periods and year close |
| `PaymentMethod` | Translated payment label → payment method → GL mapping |

Posting runs **inside the source document's DB transaction**: if posting fails, the sale or purchase rolls back. Nothing is saved without its accounting.

## 19. Chart of accounts

The chart has 4-digit codes, seeded by migration `RuyaAccountingFoundation`:

- 1xxx assets: 1110 cash, 1120 bank, 1130 card clearing, 1140 cheques, 1200 AR, 1300 inventory, 1500 fixed assets, 1590 accumulated depreciation.
- 2xxx liabilities: 2100 AP, 2200 tax payable, 2300 gift-card liability.
- 3xxx equity: 3100 capital, 3200 drawings, 3300 current accounts, 3800 retained earnings, 3900 opening-balance equity.
- 4xxx revenue: 4100 sales, 4150 sales discounts.
- 5xxx cost of sales: 5100 COGS, 5150 purchase price variance, 5200 inventory adjustments.
- 6xxx expenses: 6100 general, 6200 depreciation, 6300 cash over/short.

26 functional mappings (`acc_account_mappings`) decouple posting from codes; they are editable under Chart of accounts → Mappings. Accounts can be added and deactivated.

## 20. Journal integration

| Document | Entry |
| --- | --- |
| Sale | Per-category revenue, discounts, COGS, tax, and payments by method; any unpaid remainder goes to AR |
| Return | Mirror of the sale |
| Sale edit / cancel | Reverse and repost / reverse |
| Receiving / return | 1300 against treasury or AP; variance to 5150 |
| Expense | Expense account against treasury, AP or clearing |
| Cash-up close | Shortage or overage |
| Inventory adjustment | 1300 / 5200 |
| Initial stock, CSV import | 1300 / 3900 |
| Manual cost change | 1300 / 5150 revaluation |
| Vouchers, shareholder transactions, assets, depreciation | See sections 14, 17 and 23 |
| Manual journal | `journal/create` (`accounting_journal`). Reversible; posted entries are never edited. |

Historical documents are posted with `php spark accounting:backfill [--since=YYYY-MM-DD] [--dry-run]`. It is idempotent and safe to re-run.

## 21. Customer receivables

- Credit sales and unpaid remainders post to 1200 with `party_type=customer` and `party_id`.
- `accounting/statement?party_type=customer` gives the statement with running balance; `accounting/aging` gives the aging buckets.
- The customer list links to each statement (`accounting_view` grant).
- Customer receipts are recorded with a voucher.
- Local data: AR control 360.00 = sum of customer balances 360.00.

## 22. Supplier payables

- Credit receivings and credit expenses post to 2100 with a supplier party.
- The statement, aging and supplier-list links work the same way as for customers. Supplier payments are recorded with a voucher.
- The local test data has no credit purchases. This path is covered by `PostingTest` and `VouchersTest`.

## 23. Shareholders and equity

- Each shareholder has **ownership %** and **profit-share %** as separate fields.
- Transactions:
  - contribution / additional contribution: treasury ↑, capital 3100 ↑;
  - withdrawal: treasury ↓, drawings 3200.
- Yearly profit allocation (`shareholders/allocation`): closed-year profit is moved to each partner's current account by profit-share %, with a preview before posting.
- Each shareholder has a statement and an equity position.
- Verified in the browser: a 60,000 contribution increased cash and capital.

## 24. Analytical accounting

- Dimensions: cost centre, profit centre, cashier, sales channel, product category. They are tagged on journal lines (`acc_journal_line_dimensions`).
- Sales are tagged automatically with cashier, channel and category. Expenses and vouchers can be given a cost centre.
- `accounting/dimensions`: P&L by dimension value. The income statement can be filtered by cost centre.

## 25. Financial statements

- **Income statement:** revenue, minus discounts = net sales; minus cost of sales = gross profit; minus operating expenses and depreciation = operating profit; other income/expenses; net profit. Year-close entries are excluded.
- **Balance sheet:** assets = liabilities + equity, with current-year profit shown inside equity. If it doesn't balance, it shows a red BLOCKER message with the difference; a green confirmation shows when it balances. On local data it balances, and the difference in the GL is 0.00.
- **Trial balance:** opening, movement and closing balance per account, with an imbalance warning.

## 26. Report improvements

- All sales, payment, tax and management summary reports now use the net-of-invoice-discount line SQL.
- New reports: general ledger with running balance, party statements, aging, inventory valuation (quantity × average cost against the GL), cost history, dimension analysis and audit log.
- **Known gap:** `Summary_discounts` still reports line discounts only; the invoice discount appears in the P&L and in sales totals.

## 27. Daily-sales product/code improvement

The sales history (`sales/manage`) shows an expandable row per sale. It loads `sales/lines/{id}`, which requires the `reports_sales` grant, and lists every line with item number (code), name, quantity, unit price, discounts and net. Previously only one product was shown.

## 28. Security and permissions

- **New permissions:**
  - `items_categories`
  - `sales_invoice_discount`, `sales_invoice_discount_override`
  - `treasury`, `treasury_view`, `treasury_vouchers`, `treasury_reverse`
  - `accounting`, `accounting_view`, `accounting_journal`, `accounting_periods`, `accounting_setup`, `accounting_shareholders`, `accounting_assets`
- **Seeded grants:** the admin (person 1) and existing config/attributes holders. No other employee gains access.
- **Enforcement:** every POST checks its grant on the server (`denied()` returns 403 JSON). Page actions use `requireGrant()`. Controllers are gated in the constructor through `Secure_Controller`. The menu hides what a user cannot open, but **the server does not rely on it**.
- **Other checks:**
  - All new queries use the query builder or bindings.
  - Views use `esc()`.
  - CSRF stays on for every POST.
  - All menu pages and 27 non-menu accounting/treasury URLs were crawled, including unknown IDs; none returned a 500. One 500 (unknown shareholder) was found by the crawl and fixed; it now returns 404.

## 29. Audit trail

`app/Libraries/AuditTrail.php` writes to `audit_log`: who, when, IP, entity, action, and a before/after JSON. A fixed list of sensitive keys is **redacted** before writing: `password`, `password_confirm`, `current_password`, `hash_version`, `api_key`, `token`, the CSRF token and the honeypot field. Add to `AuditTrail::REDACTED` if new sensitive fields are ever audited.

Events recorded:

- category create / rename / delete;
- cost change and inventory adjustment;
- price change, discount override and invoice discount;
- sale edit and sale delete;
- expense save and delete;
- employee grant changes;
- voucher post and reverse;
- journal post and reverse;
- period status and year close.

The log can be searched at `accounting/audit` (`accounting_setup`).

## 30. Performance before/after

**Method.** The HEAD commit (`1684d187d`) and the working tree were served side by side with the same PHP built-in server and router, in production mode, against identical copies of the same test data. The baseline ran against a copy because HEAD refuses to log in once the newer migrations have been applied.

Each figure is the median of 5 requests after a warm-up. The query count is the delta of MariaDB's global `Questions` counter for one request. Hardware: the local Windows development machine.

| Endpoint | Baseline median s | Current median s | Baseline SQL | Current SQL |
| --- | --- | --- | --- | --- |
| Dashboard (`home`) | 0.342 | 0.344 | 53 | 54 |
| POS (`sales`) | 0.345 | 0.388 * | 30 | 31 |
| Barcode lookup | 0.291 | 0.284 | 17 | 17 |
| Product search (Arabic) | 0.300 | 0.288 | 17 | 17 |
| Product table page | 0.313 | 0.296 | 24 | 25 |
| Product table data | 0.288 | 0.274 | 23 | 23 |
| Customer search | 0.309 | 0.265 | 16 | 16 |
| Supplier search | 0.320 | 0.268 | 21 | 21 |
| Sales history data | 0.308 | 0.332 | 22 | 22 |
| Receiving screen | 0.288 | 0.342 * | 19 | 20 |
| Inventory summary report | 0.291 | 0.292 | 19 | 20 |
| Trial balance | n/a (new) | 0.282 | n/a | 29 |
| General ledger | n/a (new) | 0.301 | n/a | 33 |
| Treasury movements | n/a (new) | 0.287 | n/a | 26 |

\* Interleaved re-measurement (8 samples, alternating servers) put POS at 0.39/0.40 baseline vs 0.33/0.36 current, and receiving at 0.30/0.34 vs 0.29/0.29. The differences are noise.

- **Bottleneck found and removed:** the new grouped menu issued one grant query per entry, adding about 21 queries to *every* page (home 74, sales 51 queries). This is now a single grants query, so pages are within +1 query of the baseline.
- **Remaining bottleneck:** on this machine response time is dominated by framework bootstrap under `php -S` (~0.27 s even for trivial AJAX endpoints), not by SQL. Production numbers behind Apache/Nginx with OPcache will differ and were **not measured**.
- **Not measured:** the write-path cost of ledger posting during sale completion, and behaviour on a large dataset. The local data has 158 items, 40 sales and 224 journal entries.

## 31. Database and index changes

All changes are made by migrations. They are additive only; no column is dropped or retyped and no history is deleted.

| Migration | Content |
| --- | --- |
| `20261025000000_RuyaCategoryCatalog` | `items_categories` permission and grants; approves categories already in use |
| `20261025000100_RuyaRepairDefaultTaxPlaceholders` | Clears the `0` default-tax placeholders; deletes `items_taxes` rows named `0` at 0 % |
| `20261025010000_RuyaAccountingFoundation` | 17 tables (accounts, mappings, periods, journal entries/lines, dimensions, treasuries, vouchers, movements, cost history, shareholders, fixed assets, depreciation, audit log); new columns on `expense_categories`, `expenses` and `cash_up`; seeds; modules and permissions; settings |
| `20261025020000_RuyaInvoiceDiscount` | `sales_items.invoice_discount`, `sales.invoice_discount_type/value/reason`, permissions, setting |

- **Indexes** are only on the new tables, for their access paths: entries by `(source_type, source_id)` and by `entry_date`; lines by `entry_id`, `account_id` and `(party_type, party_id)`; movements by `(treasury_id, movement_id)` and `(treasury_id, movement_time)`; cost history by `(item_id, changed_at)`; unique codes and numbers. `EXPLAIN` confirmed that the live-entry lookup, last-movement lookup and trial-balance join use them. No existing table was re-indexed, because there was no evidence it was needed.
- **Rollback:** each migration has a `down()` that drops its tables, columns and permissions. Rolling back **destroys accounting history**, so take a backup first and do it only before go-live.

## 32. Tests added

92 new test methods:

- **New files (71 tests):**

  | File | Tests |
  | --- | --- |
  | `ReceivingsControllerTest` | 3 |
  | `CategoryTest` | 9 |
  | `LedgerTest` | 9 |
  | `PostingTest` | 20 |
  | `Sale_libTest` | 8 |
  | `VouchersTest` | 7 |
  | `ReportsTest` | 6 |
  | `ShareholdingTest` | 5 |
  | `FixedAssetsTest` | 4 |

- **Additions to existing files (21 tests):** `ItemsControllerTest`, `SalesControllerTest`, `SaleTest`, `CashupControllerTest`, `HeaderNavTest`, `LocaleHelperTest`.
- **Coverage:** unit, controller, model, permission (403 on missing grant), accounting invariants (balanced entries, reversal, idempotent sync, historical cost stability, balance sheet), costing, discount, return, treasury, shareholder, RTL/localisation and reports.
- **Browser flows** (Playwright, real Chrome, local server):

  | Flow | Covered |
  | --- | --- |
  | Purchasing | receive and complete |
  | A | categories |
  | B | treasury vouchers |
  | C | POS with invoice discount, tax after discount, change and receipt |
  | D | partial return |
  | E | shareholder contribution |
  | F | fixed asset |

  Each flow also checked the trial balance and balance sheet. Flows H (withdrawal/allocation) and I (shift close) are covered by automated tests rather than a browser run.

## 33. Full test results

The final full run was `php vendor/bin/phpunit` against local MariaDB (`ospos_test`), after all changes:

```
Tests: 764, Assertions: 3099, Skipped: 3, Failures: 0, Errors: 0   (12 min 55 s)
PHPUnit Warnings: 1  -> "No code coverage driver available"
```

The process exits with code 1 only because of that runner warning: `failOnWarning` is set, and this machine has no Xdebug or PCOV. Every test passed. CI with a coverage driver, or without coverage configured, will not show the warning. The 3 skips were present before this work.

Failures found by full runs during the work, and their fixes:

- **Run 2: two `SaleTest` concurrency failures.** Caused by the dimension-value race; fixed with an atomic upsert.
- **Run 3: five language-test failures and one `ReceivingTest` concurrency failure.**
  - Language: "product" must be **المنتج**, which is the repo's convention, and the tool had created empty `ka` files. The terminology was aligned and the 4 empty `ka` files removed, so `ka` falls back to English as before.
  - `ReceivingTest`: the receiving cost-update deadlock. Fixed with `Costing::lockItems()`, and the test was strengthened.
- **Run 4: all passing.**

## 34. Remaining limitations

- `Summary_discounts` does not include invoice discounts (see section 26).
- No phone-width RTL certification pass.
- No production-like performance or large-dataset measurement (section 30).
- Bank reconciliation (matching statement lines) is not implemented; the bank is a treasury with movements.
- Only one cash box is seeded. Multiple treasuries and branches are supported but not configured.
- Backfill reversals are dated on the day of the run. Re-running the backfill after documents were posted under old rules corrects them by reversal and repost, so the audit trail is kept, but the reversal dates are "today".
- The local test data has no opening treasury balance, so the main cash box shows a negative balance. In production the owner should enter opening balances through an opening-balance voucher before relying on treasury figures.
- `docs/` is git-ignored; this report and `ACCOUNTING_DESIGN.md` need `git add -f` when committed.
- PHP-CS-Fixer was not run on the changed files.

## 35. Decisions still requiring business-owner approval

1. Shareholder profit-share percentages, and whether they should follow ownership. They are modelled separately and default to whatever is typed in.
2. Useful lives and salvage values per asset category. Today these are entered per asset.
3. Credit-sale policy: per-customer credit limits are not enforced.
4. The period-closing routine: who closes months, and whether back-dated corrections into closed months are allowed (currently refused).
5. Opening balances at go-live: cash, bank, customer and supplier balances, and capital.
6. Whether a second cash box or bank account is needed now.
7. The invoice-discount limit. The default is 10 %; change it in Settings → General.

## 36. Exact files and migrations changed

**New files**

- Migrations: `app/Database/Migrations/20261025000000_RuyaCategoryCatalog.php`, `20261025000100_RuyaRepairDefaultTaxPlaceholders.php`, `20261025010000_RuyaAccountingFoundation.php`, `20261025020000_RuyaInvoiceDiscount.php`.
- Library: `app/Libraries/Accounting/` (AccountingException, Costing, FixedAssets, Ledger, PaymentMethod, Periods, Posting, Reports, Shareholding, Vouchers) and `app/Libraries/AuditTrail.php`.
- Command: `app/Commands/AccountingBackfill.php`.
- Controllers: `Accounting`, `Accounts`, `Fixed_assets`, `Journal`, `Ledger_Controller`, `Shareholders`, `Treasury`.
- Model: `app/Models/Category.php`.
- Views: `app/Views/accounting/*`, `app/Views/treasury/*`, `app/Views/home/finance.php`, `app/Views/items/categories.php`, `app/Views/sales/lines.php`.
- Language: `Accounting.php` and `Treasury.php` in all 46 locales.
- Tests: `tests/Controllers/ReceivingsControllerTest.php`, `tests/Models/CategoryTest.php`, `tests/Libraries/{LedgerTest,PostingTest,Sale_libTest}.php`, `tests/Libraries/Accounting/*`, `tests/Support/AccountingFixtureTrait.php`.
- Docs: `docs/ruya/ACCOUNTING_DESIGN.md`, `docs/ruya/FINAL_REPORT.md`.

**Modified files**

- Controllers: Cashups, Config, Employees, Expenses, Home, Items, Receivings, Sales, Secure_Controller.
- Helpers: locale_helper, navigation_helper, tabular_helper.
- Libraries: Sale_lib, Tax_lib.
- Models: Cashup, Expense, Inventory, Item, Receiving, Sale; Reports/Management_report, Summary_payments, Summary_report, Summary_taxes.
- Views:
  - `cashups/form`, `configs/general_config`, `employees/form`;
  - `expenses/form`, `expenses/manage`;
  - `home/home`, `items/form`, `items/manage`;
  - `partial/header`, `partial/table_filter_persistence`;
  - `sales/invoice`, `invoice_email`, `manage`, `receipt_default`, `receipt_email`, `receipt_short`, `register`.
- CSS: `public/css/dahrouj.css`.
- Language: existing files in all 46 locales (418 files modified, keys added in alphabetical order, alignment preserved).
- Tests: CashupControllerTest, ItemsControllerTest, SalesControllerTest, SaleTest, HeaderNavTest, LocaleHelperTest.

## 37. Recommended production deployment sequence

1. Review this diff. Then create a worktree/branch from `origin/master` and commit in logical commits:
   1. repairs;
   2. categories;
   3. invoice discount;
   4. accounting foundation;
   5. accounting UI;
   6. language;
   7. tests.
2. Run CI (full PHPUnit against MariaDB) and PHP-CS-Fixer scoped to the touched files. Build the assets with `npm run build`.
3. **Take a full database backup** and verify it restores on a staging copy.
4. Deploy to **staging** with a copy of production data. Log in, which runs the migrations automatically.
5. On staging:
   1. run `php spark accounting:backfill --dry-run`, then the real run;
   2. check the trial balance (difference 0), balance sheet (balanced), inventory GL = valuation report, and treasury GL = movements;
   3. review the chart of accounts, mappings and payment-label mapping (the dashboard warns about unmapped labels).
6. Owner sign-off on the decisions in section 35, and on opening balances entered through an opening-balance voucher.
7. Grant the new permissions to the right employees (Employees → permissions; finance and accounting groups).
8. Deploy to production in a quiet window: backup, deploy, log in to migrate, run the backfill, then repeat the step-5 checks.
9. Keep the pre-deployment backup. The rollback plan is to restore the backup. Do not run migration `down()` after go-live, because it destroys accounting history.

---

RU'YA FULL POS + INVENTORY + TREASURY + ACCOUNTING SYSTEM READY FOR FINAL QA
