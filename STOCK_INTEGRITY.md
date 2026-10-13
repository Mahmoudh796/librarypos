# Stock & Sales Integrity Guarantees

Business-integrity hardening for the point-of-sale stock paths (Phase 1). It
covers the sale register, receiving, returns, voids, item kits and manual
adjustments. There are no UI/theme, schema or reporting changes.

## Invariants

1. **Inventory never goes negative on the transactional paths.** Sale
   finalization, receiving save/void and manual adjustments all write stock
   through `Item_quantity::changeQuantity(..., 0.0)`, which refuses any change
   that would take a location below zero.
2. **One authoritative stock row per `(item, location)`.** Every transactional
   write goes through `Item_quantity`; there is a single source of truth.
3. **All-or-nothing finalization.** A sale is committed as one InnoDB
   transaction (header, lines, payments, stock, inventory movement, taxes,
   rewards, table). Any failure rolls the whole sale back. A receiving
   behaves the same way.
4. **Exactly-once reversals.** Voiding a sale (or a receiving) takes a
   `SELECT ... FOR UPDATE` lock on the header row first, so two concurrent
   voids cannot both restore stock.
5. **No lost updates.** Quantities change by signed delta inside the
   transaction, never by a read-then-write of an absolute value.
6. **Deterministic lock order.** Lines are locked in `(item_id,
   item_location)` order in both sales and receiving, so two baskets that
   share items acquire locks in the same sequence. InnoDB deadlock detection
   remains the backstop for the remaining odd cases.
7. **Recoverable failures.** A rejected sale or receiving leaves the cart
   intact and shows a plain message; no SQL detail is surfaced.

## Intentional behaviour changes

- **Selling an item that has no stock row is refused** instead of creating a
  negative row. The transaction returns `INSUFFICIENT_STOCK` and the register
  keeps the cart so the cashier can correct it.
- **A receiving return / requisition line, a receiving void, and a manual
  adjustment are refused** when they would drive a location below zero.
  Correct the stock explicitly (an adjustment or the item form) instead.
- Voiding a receiving whose goods were already sold on is therefore refused;
  it rolls back and the receiving remains.

## Bounds

- Protection is enforced at the database level for the paths above and relies
  on a transactional (InnoDB) schema, which this installation uses.
- The item maintenance form and the CSV importer can still set absolute
  quantities. They are deliberate administrative overrides and are out of
  scope here.
- "Suspending" a sale holds no stock; stock is only consumed when the sale
  completes. Restoring a voided sale returns it to suspended.

## Verifying

```sh
vendor/bin/phpunit tests/Models/SaleTest.php tests/Models/ReceivingTest.php tests/Models/ItemQuantityTest.php
```

The suite includes real two-process concurrency tests (last unit, kit
components, opposite line order, per-location isolation, concurrent
receivings and concurrent voids).
