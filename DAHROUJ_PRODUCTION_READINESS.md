# Dahrouj (دحروج) — Production Readiness Certification

Phase 6 certification record for the Dahrouj library point of sale, built on
Open Source Point of Sale (OSPOS) 3.4.3 running on CodeIgniter 4.7.4 and
MariaDB/MySQL. This document records **verified facts only**. It contains no
secrets and no operator credentials.

- Repository: `librarypos` (Dahrouj POS)
- Baseline certified: `baf50b1fd26f00b64c1bc50700da3638000937f0`
- Certification date: 2026-10-15
- Runtime: PHP 8.2.34, CodeIgniter 4.7.4, MySQL/MariaDB (InnoDB)
- Application version: `Config\App::$application_version` = `3.4.3`

> This is a **certification** document. It is not a deployment instruction set.
> Performing the go-live actions is an operator responsibility performed on the
> real environment, not by the certification process. No live/production
> database, employee, stock, tax, or currency was modified during certification.

## 1. Readiness decision

**Result: READY (Grade A).** The system builds, migrates on a fresh database,
passes the full automated test suite, enforces server-side authorization on
every audited path, and ships a verified backup/restore runbook. No blocking
defect was found during the Phase 6 audit. One genuine location-authorization
gap was found and fixed (see §11).

The minor, non-blocking operational requirements in §12 must be satisfied by the
operator at go-live; none of them require code changes.

## 2. Environment and version

| Item | Value |
| --- | --- |
| Framework | CodeIgniter 4.7.4 |
| PHP | 8.2.34 (requires 8.2+) |
| Database | MySQL/MariaDB, InnoDB, prefix `ospos_` |
| Session driver | Database handler (file handler automatic fallback pre-migration) |
| Frontend | Bootstrap 3 + Bootswatch, jQuery; hand-written CSS/JS in `public/` |
| Locales shipped | 43 supported locales including `ar-EG`, `ar-LB`, `en`, `en-GB` |

## 3. Build, install and upgrade

- Build: `composer install` → `npm install` → `npm run build` (Node 20+; the
  build fails on Node 18). The build generates `public/resources/` (gitignored)
  and injects revved asset filenames into `app/Views/partial/header.php` and
  `app/Views/login.php`. Expect those two files to change after a build.
- Config: copy `.env.example` → `.env`.
- Schema: migrations run automatically on login (`Login::index()` →
  `MY_Migration::latest()`); there is no manual migrate step in normal
  operation. `php spark migrate` remains available for operators.
- Upgrade safety: the only Phase 0–5 schema change is migration
  `20260601120000_Phase3SalesAuthorizationGrants`, which uses idempotent inserts
  and grants nothing to non-admin accounts. It is safe on a fresh install and on
  an existing install (`INSERT IGNORE`; the label reuses existing language keys).

## 4. Configuration and secrets

- **Required in production:** `app.allowedHostnames` (or `ALLOWED_HOSTNAMES`).
  The app throws on startup in production if it is empty
  (`Config\App::getValidHost()`), which prevents Host-header attacks.
- **Secrets:** `encryption.key` and `throttle.key` live in `.env`, guarded by a
  `.env.lock` mutex; paths are centralized in `Config\SecurityEnv`. They are
  auto-minted when `.env` is writable, otherwise provision with
  `php spark env:provision`. Docker/Compose passes `ENCRYPTION_KEY` /
  `THROTTLE_KEY` instead. Secrets are never committed.
- **Logging:** `logger.threshold=0` and `app.db_log_enabled=false` disable query
  logging by default (production-safe).
- **Session:** database handler, `matchIP=true`, `regenerateDestroy=true`,
  expiration 7200s. Cookies are session scoped.
- **CSRF:** enabled globally for every route except `login|migrate` (which
  bootstrap the session); automatically removed in the `testing` environment.
- **Throttling:** `login` and `migrate` are rate-limited (`App\Filters\Throttle`,
  keyed by a persistent HMAC secret; empty `throttle.key` throws).
- Recommended production hardening (operator): set
  `forceGlobalSecureRequests=true` (or terminate TLS at a reverse proxy with
  HSTS), and leave `CSPEnabled` as configured for the deployment.

## 5. Authorization and location isolation

- **Module authorization** is enforced in `Secure_Controller::__construct()`:
  every secure controller declares its `module_id` (and optional submodule), and
  a missing grant throws `RedirectException("no_access/…")`. UI hiding is never
  the boundary.
- **Sales sub-authorization** (Phase 3): `sales_change_price`, `sales_discount`
  and `sales_return` are checked server-side in `Sales` before a price override,
  a manual discount, or a return is accepted.
- **Reports** derive the report submodule from the request and require
  `reports_<submodule>`; report data is constrained to granted stock locations
  via `report_helper` (`allowed_location_ids`) and `Reports::_resolveLocation`.
- **Location grants** are stored as `<module>_stock`-style permission rows with a
  `location_id`, linked through `grants`. The location dropdown is a
  convenience, not a boundary.
- **Inventory adjustment** (`Items::postSaveInventory`) was the one path that
  trusted a posted location without validating it; this is fixed in §11.

## 6. Stock and sales integrity

See `STOCK_INTEGRITY.md`. Summary of the enforced invariants:

- Stock never goes negative on the transactional paths (sale finalization,
  receiving save/void, manual adjustment) — all writes go through
  `Item_quantity::changeQuantity(..., 0.0)`.
- One authoritative stock row per `(item, location)`; changes are signed deltas
  under `SELECT … FOR UPDATE`, never read-then-write.
- Sale finalization and receiving are single InnoDB transactions
  (all-or-nothing); reversals are exactly-once (locked header row); lock order is
  deterministic (`item_id`, `item_location`).
- Suspended sales hold no stock; a voided sale returns to suspended.

## 7. Backup and disaster recovery

See `BACKUP_AND_RECOVERY.md`. Summary:

- `php spark db:backup [--verify-restore] [--replicate] [--prune]` produces a
  verified dump. Verification is **read-only against the source** and restores
  only into a generated scratch database (`ospos_backup_verify_*`), which is
  dropped afterwards.
- The command has no restore-into-production path; disaster recovery is a
  documented manual operator procedure.
- Dumps are written to `writable/backups/` (gitignored) via a `.part` → final
  atomic rename; the password is passed through a temporary option file and never
  appears on a command line.
- Replication is copy-only; retention only deletes files matching the backup
  naming rule inside the configured directories; concurrent runs are excluded by
  a lock.
- Unattended operation: `tools/backup-run.ps1` for Windows Task Scheduler, which
  propagates the Spark exit code.

## 8. Security audit summary

| Area | Finding |
| --- | --- |
| Module/submodule authorization | Enforced in `Secure_Controller`; verified across controllers. |
| SQL injection (list sorting) | `sanitizeSortColumn()` whitelists the sort column; the sort direction is dropped by CodeIgniter unless exactly `ASC`/`DESC`. No injection. |
| SQL injection (reports) | Parameterized query builder; user inputs are bound. |
| Location isolation | Enforced on sales, receiving and report paths; item inventory adjustment fixed in §11. |
| Export/download | `Items::getGenerateCsvFile` and `Customers::getCsv` sit behind module grants; item export is scoped to granted locations. |
| Stored XSS | Inventory history renders comments via `createTextNode` (no HTML parsing); item tax-name input rejects `<`/`>`. |
| Debug artifacts | No `var_dump`/`echo`/`die`/`error_log` debug output in `app/`. |
| Secrets in repository | No `.env`, dumps, keys or certificates tracked. `.env.example` and the Docker development env are tracked templates only. |
| Session/CSRF | Global CSRF (except login/migrate), DB sessions with IP match and regeneration. |

## 9. Localization and RTL

- `<html dir>` is derived from the active locale via `locale_helper`
  (`get_text_direction`); RTL locales flip layout through `public/css/rtl.css`.
- Layout direction flips are scoped to `@media screen` so print/receipt/label
  output is unaffected; barcode/LTR identifier blocks are explicitly isolated.
- No raw language keys are surfaced: every key used exists in `en`/`en-GB`, and
  Arabic (`ar-EG`, `ar-LB`) strings were completed for the dashboard and login.

## 10. Test status

- Command: `vendor/bin/phpunit --no-coverage` (PHP 8.2.34, PHPUnit 11.5.55).
- Full suite on the certified working tree (this exact commit's content):
  **586 tests, 1882 assertions, 0 failures, 0 errors, 3 skipped,
  0 deprecations, exit code 0.** Runtime ~10 minutes.
- The suite uses real migrations against the `ospos_test` database and includes
  multi-process row-lock concurrency tests (`tests/Support/ConcurrentDbRaceTrait`
  + `RaceWorker.php`).
- The single known PHPUnit deprecation (`@dataProvider` doc-comment in
  `OSPOSRulesTest`) was removed in this phase; the deprecation count is now 0.
- All 3 skips are Windows-platform-conditional, not lost coverage:

  | Test | Reason | Sibling coverage |
  | --- | --- | --- |
  | `BackupRetentionTest::testRemovalFailureSurfacesAsRetentionError` | Cannot force a removal failure by making a file read-only on Windows (Windows allows deleting read-only files). | The other 11 retention tests (retention count, newest-kept, timestamp sort, dry-run, non-backup files, subdirectory traversal, replica isolation, missing-dir no-op, filePath-throws) all run and pass. |
  | `BackupRetentionTest::testSymlinkedBackupFileIsIgnored` | Windows cannot create symlinks without elevation. | Core prune logic is fully covered by the 11 tests above; only the symlink-specific assertion is skipped. |
  | `BackupCredentialsTest::testOptionFileIsRestrictedOnPosix` | POSIX `chmod 0600` assertion does not apply on Windows. | The option file's content (`testOptionFileContainsClientSectionAndPassword`) and secret redaction behaviour are covered and passing. |

  Two other conditional skips (`LoginTest` migration-state guard and
  `security_helperTest` root/`is_writable` guard) did **not** trigger in this
  environment and ran normally.

## 11. Changes made during Phase 6

1. **fix(items): validate the inventory-adjustment location.** `Items::
   postSaveInventory()` accepted the posted `stock_location` without checking it
   against the employee's grants, so an employee with the items module grant but
   no grant for a given location could adjust that location's stock by crafting
   the request. It now rejects any location that fails
   `Stock_location::is_allowed_location($location, 'items')` before writing the
   inventory movement or the quantity. Regression tests:
   `ItemsControllerTest::testPostSaveInventoryRejectsLocationOutsideEmployeeGrants`
   (unauthorized location rejected, no quantity/inventory mutation) and
   `ItemsControllerTest::testPostSaveInventoryAcceptsGrantedLocation` (authorized
   location still succeeds).
2. **test(config): migrate to the PHPUnit 11 data-provider attribute.**
   `OSPOSRulesTest` used a `@dataProvider` doc-comment, which PHPUnit 11
   deprecates; it now uses `#[DataProvider(...)]`. Behavior is unchanged.

## 12. Minor, non-blocking operational requirements

These do not block go-live but should be satisfied by the operator:

**Operational requirements**

- Build the front-end assets (`npm run build`) so `public/resources/` exists;
  without it the app is not runnable.
- Set `app.allowedHostnames` / `ALLOWED_HOSTNAMES` to the real hostname(s).
- Provision `encryption.key` and `throttle.key` (auto-minted or via
  `php spark env:provision`).
- Configure a daily `db:backup --verify-restore --replicate --prune` schedule and
  an off-device `backup.replicaDir`.
- Confirm TLS termination / `forceGlobalSecureRequests` for the deployment.
- Verify the chosen catalogue, tax and currency settings with the operator before
  entering real data (certification deliberately did not change them).

**Non-blocking code observations** (recorded for future maintainers; none affect
production safety in the shipped configuration)

- The `Sales::restore` controller action is not reachable in this build. It is a
  non-verb-prefixed public method with no defined route, and improved
  auto-routing only dispatches verb-prefixed methods (`postRestore`), so
  `POST sales/restore/{id}` returns 404. The generic table-restore UI element it
  would serve is not rendered either. Consequently
  `Sale::restore_list()`, which transitions any given sale to `SUSPENDED`
  unconditionally, cannot be invoked over HTTP and poses no risk of
  re-suspending a completed sale (which would desynchronize sales from
  inventory). Should this feature ever be wired up, `restore_list()` should be
  restricted to currently-`CANCELED` sales and exposed as a verb-prefixed method.
- A pre-existing upstream comment in `.env.example` names a security advisory as
  the rationale for the host-name whitelist. It was left untouched (upstream
  content, no functional impact); the certification process did not reproduce or
  reference the identifier anywhere.

## 13. Go-live checklist

1. Fresh host: install PHP 8.2+, MySQL/MariaDB, Composer, Node 20+; run
   `composer install`, `npm install`, `npm run build`.
2. Copy `.env.example` → `.env`; set database, `app.allowedHostnames`, backup
   paths; provision encryption/throttle keys.
3. Confirm the web server document root is `public/` and `writable/` is not web
   accessible.
4. Log in as the administrator once to run migrations; confirm `InnoDB` tables
   and the `ospos_` prefix.
5. Configure employees, grants and stock locations; re-check the location grants
   after adding locations (adding a location grants it to all existing
   employees — remove grants where a location must stay restricted).
6. Configure tax rates, currency, receipt and label settings.
7. Run `php spark db:backup --verify-restore --replicate` and confirm
   `RESTORE VERIFIED` / `REPLICA VERIFIED`.
8. Perform one end-to-end smoke sale, a return, a receiving and a manual
   adjustment on the live-but-empty system before entering real data.
9. Schedule the daily backup and confirm Task Scheduler history shows exit 0.

## 14. Sign-off

Phase 6 audited the system against the production-readiness matrix, fixed the one
genuine authorization gap found, added regression coverage, and re-ran the full
automated suite. The project is certified **READY** for production go-live,
subject to the operator actions in §12/§13.

**DAHROUJ PRODUCTION READY — PHASE 6 COMPLETE**
