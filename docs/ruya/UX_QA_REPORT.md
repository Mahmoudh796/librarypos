# Ru'ya: UI/UX, reporting, dashboard and functional QA pass

Date: 2026-10-25 · Local test data only · **Not committed, not pushed, not deployed.**
This pass builds on the accounting work in [FINAL_REPORT.md](FINAL_REPORT.md) and [ACCOUNTING_DESIGN.md](ACCOUNTING_DESIGN.md).

Verification labels used below: **[B]** browser-verified (Playwright, real Chrome) · **[T]** automated test · **[C]** code inspected only.

---

## 1. UX problems found

- The dashboard gave every figure equal weight, repeated today's numbers in three places, and its cards linked nowhere.
- Reports were organised around the engine ("graphical / summary / detailed") rather than business questions. Totals sat *below* the table. Changing the period meant going back to an input form, and the run button said "حفظ" (save).
- The date picker always showed two calendars and 14 presets. When a page restored dates from the URL, the picker kept showing "today".
- On the POS, the amount to collect was not dominant, the action said "إضافة دفعة" (add payment), and the buttons were small with no protection against a double click.
- On the purchase screen, everything sat in one card, and purchase history was not reachable from it.
- In the treasury:
  - the running balance jumped around for back-dated postings;
  - descriptions were partly in English;
  - there was no opening or closing balance for the period.
- In accounting:
  - every page carried an 8-tab strip duplicating the menu, costing 8 extra queries;
  - the chart of accounts showed no visible hierarchy;
  - the manual journal asked for the internal *ID* of the customer or supplier;
  - the trial balance had no "balanced" confirmation.
- Cash-ups and expenses had a breadcrumb under "الأشخاص" (people) that rendered as the raw key `Module.people`.

## 2. Functional problems found (and fixed)

| # | Problem | Impact | Fix |
| --- | --- | --- | --- |
| 1 | Correcting a document reversed it **today** but reposted it at its original date | Monthly P&L distorted. October showed −22,659.50 with 15,734 of earlier months' expenses missing and 9,550 of August stock "gains" reversed into October | `Posting::sync()` now reverses on the old entry's date, or posts both halves in the current month if a period is closed. Local test data repaired (see §20). [T][B] |
| 2 | The CLI backfill posted descriptions in English | "Reversal of…", "Expense #…" in the treasury and ledger | The backfill uses the store language. 220 entries and 84 movements re-described in local test data. [B] |
| 3 | Product category dropdown invisible in the "new product" dialog | Category could not be chosen | The AJAX-loaded `.selectpicker` was never initialised; it is now, and the server requires a category when the approved list is on. [T][B] |
| 4 | Average cost stored at 2 decimals made inventory ≠ stock value | Inventory 1300 drifted from Σ qty × cost (0.24 on one test purchase) | The receiving books the rounding to 5150. [T][B] |
| 5 | A backfill rerun **revalued historical stock adjustments** at today's cost | Inventory overstated by 9.60 after one cost change | A posted stock adjustment is never reposted. [T] |
| 6 | Pasted or autofilled supplier invoice number and notes were not saved | Lost reference | Save on `input`/`change`, not only `keyup`. [B] |
| 7 | Saving a product took about 3 seconds | Slow catalogue entry | Four remote "is it a number?" requests per save, serialised by the session lock, replaced by a local check (same rules as the server). 3.0 s → 0.45 s. [B] |
| 8 | The currency symbol disappeared from positive amounts when thousands grouping was off | "3,164.50" without "ج.م" | `to_decimals` uses `GROUPING_USED`. [T] |
| 9 | Accounting periods could be closed for future or running months | Would block future sales and purchases | Server refuses; the UI shows "لم تنتهِ بعد" (not ended yet); the year close only applies to a finished year. [T][B] |
| 10 | Profit allocation allowed shares below 100% (the remainder silently stayed) | Contradicts the required rule | Allocation requires exactly 100% and explains why; nothing is rescaled. [T][B] |
| 11 | Purchase on credit possible without a supplier | Payable with no party | Refused with a clear message. [B] |
| 12 | Double-submit risks on the POS, purchases, shareholder transactions and fixed assets | Duplicate postings | Guarded in the browser. Repeat POST after completion is answered with "nothing to complete". The session lock serialises requests. [B] |
| 13 | Shareholder statement for an unknown id gave HTTP 500 | Error page | 404 (fixed in the previous pass, re-checked). [B] |
| 14 | Enter key on "complete sale" targeted a non-existent form | Keyboard completion broken | Triggers the same guarded click. [C] |

## 3. Translation and terminology problems

- "Product" is now **المنتج / المنتجات** everywhere. 153 lines (ar-EG + ar-LB) were converted from الصنف/الأصناف, including the products page title "الأصناف" → "المنتجات" and "صنف جديد" (new item) → "منتج جديد" (new product).
- **Spelling:** 90 corrections (ar-EG + ar-LB lines) ("لايوجد" → "لا يوجد", "خطاء" → "خطأ", "فى" → "في", "الغاء" → "إلغاء", "إختيار" → "اختيار"); "حسم" → "خصم" (discount).
- **Mistranslations:**
  - "requisition" "طلب الشراء" (purchase request) → **تحويل بين المخازن** (transfer between stock locations), which is what the feature does;
  - `check_balance` "تذكير بموعد الشيك" (cheque date reminder) → "متبقي الشيك" (cheque remainder);
  - report columns "كود الاستلام" (receipt code), "مورده بواسطة" (supplied by), "بيع" (sell; it was an invoice-count column) → "رقم الفاتورة" (invoice no.), "المورد" (supplier), "عدد الفواتير" (number of invoices).
- **Missing keys filled:** `Common.cancel` (in all 46 locales), seven POS validation messages, `Expenses.tax_amount_number`, Config `*_set` hints.
- **Treasury:** vouchers use the standard terms "سند قبض" (receipt voucher), "سند صرف" (payment voucher), "تحويل بين الخزنة والبنك" (transfer between cash box and bank).
- **POS:** "تسجيل الدفع" (record payment), "المبلغ المستلم" (amount received), "المتبقي للسداد" (remaining to pay), "الباقي للعميل" (change), "الإجمالي المستحق" (amount payable).
- **Dashboard:** "قيمة المخزون بالتكلفة" (stock value at cost) versus "قيمة المخزون بأسعار البيع الحالية" (at current selling prices), the latter marked as *not an accounting value*. Arabic number agreement is handled, e.g. "نفد مخزون 87 منتجًا" (87 products out of stock).
- Payment-method labels stored on historical rows were **not changed**.

## 4. Report and filter problems

- Report KPIs were not visible until after the table.
- There was no way to change the period from a result page.
- Export offered JSON/XML/SQL/TXT, plus PDF, which cannot render Arabic.
- Accounting and treasury filters used raw date inputs with no presets, no "clear filters" and no visible filter state.

## 5. Dashboard problems

- There were no priorities, no period control and no drill-down.
- Inventory value at selling price was presented next to cost as if equivalent.
- The negative monthly net profit was partly a defect (§2 #1).

**After the repair, October's net loss of −28,843.50 is correct for this test data:** net sales 3,164.50 − cost of goods sold (COGS) 1,804 − 30,204 of test operating expenses. The fixed asset is capitalised, not expensed; opening stock goes to equity; returns and discounts net correctly.

Supplier payables were 0 because every test purchase had been paid at once. A credit purchase now shows 40.00 owed to the supplier [B].

## 6. Changes implemented (summary)

- One shared design layer (`public/css/ruya.css`) and one shared behaviour script (`public/js/ruya.js`).
- A single period picker and filter bar used by every date-filtered screen.
- A rebuilt dashboard and reports hub, plus a shared report head.
- POS, receipt, purchase, treasury, journal, chart of accounts, trial balance and periods reworked.
- The business-rule fixes in §2 and §20.

## 7. Screens redesigned

| Area | Screens |
| --- | --- |
| Overview | Dashboard, reports hub, every report result (summary, detail, chart) |
| Sales | POS register, 80 mm receipt |
| Purchases | Purchase screen, purchase history (naming) |
| Treasury | Treasury movements, voucher account picker, cash-ups (breadcrumb and title) |
| Accounting | Manual journal, chart of accounts, trial balance, accounting periods, profit allocation |
| Catalogue and costs | Products (category picker), fixed assets (explanation), expense form (classification hint) |

## 8. Navigation changes

- **Sectioned dropdowns:**

  | Menu | Sections |
  | --- | --- |
  | Sales | Customers |
  | Purchases | Suppliers |
  | Products & inventory | Stock: low stock, valuation, product cost history (moved here from Costs) |
  | Accounting | overview / financial statements / books & entries / setup |

- "التكاليف والمصروفات" (costs & expenses) → "المصروفات والأصول" (expenses & assets); "استلام المشتريات" (receive purchases) → "فاتورة مشتريات جديدة" (new purchase invoice).
- The accounting tab strip was removed in favour of a breadcrumb (group › page › leaf), which also removed its eight grant queries.
- "سجل المشتريات" (purchase history) button added on the purchase screen.

## 9. Design-system changes

`ruya.css` is built only on the existing `--dj-*` tokens. Components:

- focus ring and busy-button state;
- period input and filter bar with "more filters" and removable chips;
- badges that pair an icon with text (never colour alone);
- KPI hierarchy: hero, alert, negative, trend;
- report head, reports hub cards, numbered steps, empty state;
- sticky ledger headers, tabular numerals;
- receipt layout and print rules.

## 10. Glassmorphism

**Used only on:**
- the sticky header;
- the header dropdowns;
- the period picker popover;
- modal surfaces.

Each uses `backdrop-filter` and is opaque where blur is unsupported.

**Intentionally not used on:**
- tables;
- forms and KPI figures;
- financial statements;
- receipts.

These stay on opaque white for legibility.

## 11. Arabic / RTL fixes

- Week starts on Saturday (ar-EG).
- The calendar no longer overlaps: long Arabic day names were stretching the cells.
- Machine values are left-to-right: dates, codes, quantities × price, percentages.
- The table uses logical CSS properties.
- No horizontal overflow at 768 px: a Bootstrap `.row` without a container was replaced.
- The breadcrumb is under the right business group.

## 12. Filter and date-picker redesign

- **Presets:** today, yesterday, last 7, last 30, this month, last month, this year, all time, and "فترة مخصصة" (custom period). The calendars open only for custom.
- **Field label:** shows the active period, e.g. "هذا الشهر · 01/10/2026 – 25/10/2026" ("this month · …"), including when restored from the URL.
- **Report pages:** the period reloads the same report and keeps every other filter.
- **Accounting and treasury:** the period applies immediately, with "عرض النتائج" (show results), "مسح الفلاتر" (clear filters) when filters are active, and removable chips such as "الخزنة: البنك ×" (treasury: bank).
- **Report input pages:** "حفظ" (save) → "عرض التقرير" (show report).

## 13. Dashboard redesign

- **Period switch:** today / last 7 days / this month, compared with yesterday / the previous 7 days / the same days of last month. When there is no prior data it says so rather than showing a misleading percentage.
- **Sections:**
  1. Sales and profit: net sales, gross profit with margin, net profit this month from the ledger, expenses.
  2. Cash and balances: each cash box and bank, customer receivables, supplier payables.
  3. Inventory: out-of-stock, low stock, value at cost, value at selling price (guidance only).
  4. Daily trend chart in brand colours, with tooltips and an empty state.
  5. Top products.
  6. Secondary details.
- **Alerts:** "يحتاج انتباهك" (needs attention) covers unmapped payment labels, a negative cash box, out-of-stock products, and store details still holding sample values.
- **Drill-down:** every figure links to its explanation. All 14 links verified [B].

## 14. Report redesign

- **Hub organised by question:** sales, profit, products & categories, purchases, inventory, customers, suppliers, cash & bank, expenses, accounting. Each card has a question line and links that open the current month directly. Links are shown per grant.
- **Shared result head:** title and period, summary / chart / detail switch (keeps the period), edit filters, print, totals as KPI cards above the table.
- **Export:** limited to CSV and Excel, both verified, with Arabic headers [B].

## 15. POS improvements

- Summary order: gross → product discounts → invoice discount → subtotal → **amount payable** (large) → paid → **remaining to pay / change** (dominant, coloured, labelled).
- Large full-width "record payment" and "complete sale" buttons with a busy state.
- Double click → one sale; refreshing the receipt → no second sale ("لا توجد فاتورة مفتوحة…", "no open invoice…"); reprint → no new sale or journal entry; suspended sale → not posted [B].

## 16. Receipt improvements

- 80 mm layout, black on white.
- Logo and store name, with address, phone and tax ID **only if configured**: the installer samples ("123 Nowhere street", "555-555-5555", "changeme@example.com", return policy "Test") are hidden on all receipt and invoice templates.
- Receipt type, number, date, cashier and customer.
- Two-line items, with the line discount on its own row.
- Gross, product discount, invoice discount (with %), taxes when enabled, bold payable total, payments, change.
- Return policy only if configured; barcode.
- Print removes the app header, URL and clock. Print preview and PDF are rendered at 80 mm [B].

## 17. Purchase improvements

- Numbered steps: 1 supplier → 2 products, quantities and purchase prices → 3 total and payment.
- Payment hint explains "مستحق" (due) as buying on credit.
- "رقم فاتورة المورد" (supplier's invoice no.) field.
- Large "حفظ الفاتورة واستلام البضاعة" (save the invoice and receive the goods) button, plus "سجل المشتريات" (purchase history).
- **Verified end-to-end [B]:**
  - 10 units on credit at 4.00 onto 118 at 3.00: stock 118 → 128, average 3.08;
  - exactly one balanced entry: Dr inventory 40.24 / Cr supplier 40.00 / Cr rounding variance 0.24;
  - supplier balance +40, cash unchanged;
  - the next sale carries 3.08 into COGS;
  - credit without a supplier is refused.

## 18. Treasury improvements

- Cash box and bank cards showing the current balance.
- Period position: opening + in − out = closing.
- Labelled filters (treasury, direction) with chips.
- Running balance in date order.
- Voucher accounts grouped by type, expenses first.
- **Verified [B]:** cash-out 1,500 to expenses, cash-in 200, cash → bank 1,000, bank → cash 300.
- My own misclassified test voucher (rent posted to card clearing) was **reversed**, not deleted.

## 19. Accounting UX improvements

- **Manual journal:**
  - searchable account picker grouped by type;
  - customer/supplier/partner chosen **by name**;
  - debit and credit exclusive per line;
  - status shows "متوازن" (balanced) or "الفرق X" (difference X);
  - post enabled only when balanced [B].
- **Chart of accounts:** type sections, bold groups, indented leaves, debit/credit note.
- **Trial balance:** visual blocks for opening / movement / closing, plus a "balanced" confirmation.
- **Periods:** month names, status badges with icons, no close button on months that haven't ended.
- **Allocation:** explains the 100% rule.
- **Fixed assets:** explains capitalisation and depreciation. Useful life stays an owner input.
- **Expense form:** steers goods and equipment away from expenses.

## 20. Business-rule fixes

**Rules:** see §2, #1, #4, #5, #9, #10 and #11; they are documented in `ACCOUNTING_DESIGN.md` → "Corrections and periods" and "Costing".

**Local test data repairs.** Each was made after a fresh local database backup:

- 35 misdated reversals moved to their originals' months.
- 220 entry and 84 movement descriptions put back in Arabic.
- RECV 30 reposted with its rounding line. A mistaken revaluation from my first repost attempt was undone by restoring the backup taken seconds earlier, before reposting correctly.

Amounts in documents were never edited.

## 21. Backend behaviour verified

On local data after all flows, every invariant holds:

| Invariant | Result |
| --- | --- |
| Unbalanced journal entries | 0 |
| Ledger total (debits − credits) | 0.00 |
| Cash GL vs treasury movements | 109,902.60 = 109,902.60 |
| Bank GL vs treasury movements | 8,860.00 = 8,860.00 |
| Inventory GL vs stock value | 14,439.08 = 14,439.08 |
| Receivables control vs per-customer | 360.00 = 360.00 |
| Payables control vs per-supplier | 40.00 = 40.00 |
| Stock quantities vs movement ledger | 0 mismatches |

This month's sales: dashboard = report subtotal = ledger net sales = 3,222.25.

## 22. Browser-tested workflows

| # | Workflow | Status |
| --- | --- | --- |
| 1–3 | Add/edit product, add/select category, search (barcode, partial Arabic, category, out of stock, empty) | [B] |
| 4–11 | Standard cash sale, invoice-discount sale (% and fixed), multi-product with line + invoice discount, payment, receipt, print preview (80 mm PDF), reprint, suspend | [B] |
| — | Full return with invoice discount; partial return verified last pass | [B] |
| 12–14 | Purchase, purchase at changed cost, purchase history | [B] |
| 15–17 | Treasury cash-in, cash-out, cash ↔ bank | [B] |
| 18 | Expense | [B] |
| 19 | Fixed asset | [B] last pass; [C] this pass (intro and guard only) |
| 20 | Cashier shift close: expected 505, counted 500 → shortage 5 recorded and posted | [B] |
| 21–22 | Manual journal, trial balance | [B] |
| 23–24 | Shareholder contribution, withdrawal | [B] |
| 25 | Profit allocation | [B] refused, with an explanation, because test shares total 150%; posting covered by [T] |
| 26 | Period restriction (direct POST refused, no button) | [B][T] |
| 27 | Dashboard drill-down (14 links) | [B] |
| 28–30 | Today's report, custom range, product/category reports | [B] |
| 31–33 | Purchase filter (returns), inventory summary, expenses by category = expenses table | [B] |
| 34–37 | Treasury filter and chips, accounting period filter, reset filters, empty period | [B] |
| 38 | Export CSV and Excel | [B] |
| — | Tablet width (768 px): dashboard, reports, treasury, trial balance, POS — no page overflow | [B] |

## 23. Automated tests added or changed

- **New test files:** `tests/Libraries/Accounting/PeriodsTest.php` (5), `tests/helpers/StoreHelperTest.php` (2).
- **New tests in existing files:**

  | File | Tests added |
  | --- | --- |
  | `PostingTest` | correction dating (2), average-cost rounding, historical stock adjustment |
  | `ShareholdingTest` | 100% rule |
  | `HomeTest` | period switch and drill-down, period ranges |
  | `ItemsControllerTest` | category required |
  | `LocaleHelperTest` | currency symbol without grouping |
  | `ReceivingTest` | strengthened in the previous pass |

- **Updated for intended UI changes:**

  | Test file | What changed |
  | --- | --- |
  | `HomeTest` | dashboard labels |
  | `PermissionLabelLocalizationTest`, `PagePathTest` | the shared report head |
  | `ArabicEncodingTest` | "المنتجات" title, hub class |
  | `ItemVariantsViewTest` | Arabic label kept with hamza |

## 24. Full test-suite result

Final run of `php vendor/bin/phpunit` against local MariaDB (`ospos_test`), after all changes:

| Result | Count |
| --- | --- |
| Tests | 780 |
| Assertions (checks) | 3,180 |
| Failures | 0 |
| Errors | 0 |
| Skipped | 3 (pre-existing) |
| Warnings | 1: "No code coverage driver available" |

The run took 16 min 2 s. PHPUnit exits with code 1 **only** because of that warning: `failOnWarning` is set and this machine has no Xdebug or PCOV.

Earlier runs in this pass:

- **Language and view tests:** they pinned old labels and markup that this pass intentionally changed. The tests were updated to the new behaviour.
- **Interrupted run:** one run was invalidated because I ran a test file in parallel against the same test database; it was discarded and rerun.

## 25. Performance

Measured on this machine, production mode, median of 5 after a warm-up. "Before" is the previous pass's measurement from the same script. Timings vary ±0.05 s between runs here (endpoints untouched by this pass moved by the same amount).

| Endpoint | SQL before → after | Median time before → after |
| --- | --- | --- |
| Accounting overview | 44 → 36 | 0.29 → 0.33 s (noise) |
| Trial balance | 29 → 20 | 0.28 → 0.32 s (noise) |
| General ledger | 33 → 24 | 0.30 → 0.35 s (noise) |
| Treasury | 26 → 29 (running balance) | 0.29 → 0.33 s |
| Purchase screen | 20 → 21 (history-link grant) | ≈ same |
| Dashboard | 54 → 52 | ≈ same |
| POS (`sales`) | 31 → 31 | 0.39 → 0.36–0.38 s (interleaved re-measure) |
| **Product save (dialog → saved)** | — | **3.0 s → 0.45 s** |

The menu's single grants query (previous pass) is preserved.

## 26. Remaining owner decisions

- **Numeral system:** amounts use Arabic-Indic digits (`number_locale` = ar-EG), while dates and codes are Latin. Recommendation: Latin digits throughout (Settings → Localization), for consistent receipts and reports.
- **Store details for receipts:** address, phone, e-mail and return policy still hold installer samples, which are hidden. The dashboard reminds users with the Settings grant.
- **Partners' profit shares:** test shares total 150%, so allocation is refused until they total 100%.
- **Asset useful lives**, **customer credit limits**, **real opening balances**, **a second cash box** and the **invoice-discount limit** (default 10%) are unchanged from the previous report.
- **Who may close or reopen accounting periods:** the `accounting_periods` grant.

## 27. Remaining risks and recommendations

- **Card settlements:** card payments accumulate in clearing (1130, 3,183.52) with no screen to settle them to the bank yet. Recommend a "card settlement" voucher.
- **Arabic search variants:** product search does not fold Arabic letter variants (أ/إ/ا, ة/ه); categories do.
- **Double posting without JavaScript:** voucher and asset posts are guarded in the browser and by the session lock, not by a server idempotency token.
- **Dead CSS:** `#page_title` styles in `dahrouj.css` are now unused on report pages.
- **Remote number checks:** Settings, tax and gift-card forms still use them (out of scope).
- **Asset bundle:** changes are in hand-written files only (`ruya.css`, `ruya.js`, views). No `npm run build` is needed, but `ruya.css`/`ruya.js` are loaded directly, like `dahrouj.css`.
- **Before deployment:** run PHP-CS-Fixer on the touched files and the test suite in CI. `docs/` is git-ignored, so use `git add -f` for these reports.
