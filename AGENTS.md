# Agent Instructions

Guidance for AI agents working on this Open Source Point of Sale (OSPOS) codebase: PHP 8.2+ on CodeIgniter 4.7.4, MySQL/MariaDB, Bootstrap 3 + Bootswatch. The tree is a CI3-era codebase that was mechanically ported to CI4, so filenames, constants, and method names are still largely legacy — match what is already there unless a rule below says otherwise.

## Environment

- `vendor/` and `node_modules/` are not committed. Install with `composer install && npm install`.
- This checkout has **no PHP/Composer on `PATH`** and no `.env`. `composer test` and `php-cs-fixer` therefore cannot be run locally here — verify by reading code and by CI, and say so instead of claiming tests passed.
- `public/resources/` is generated and gitignored. Without `npm run build` the app is not runnable ("system folder missing" in the README FAQ).
- Copy `.env.example` → `.env`. In production the app **throws** unless `app.allowedHostnames` (or `ALLOWED_HOSTNAMES`) is set; in testing/development it logs and falls back to `localhost` (`app/Config/App.php`).
- `encryption.key` and `throttle.key` are runtime secrets. They are auto-minted only when `.env` is writable; otherwise provision them with `php spark env:provision`. Docker/Compose passes `ENCRYPTION_KEY` / `THROTTLE_KEY` instead.

## Commands

| Task | Command |
| --- | --- |
| Full test suite | `composer test` (alias for `phpunit`) |
| One test file | `vendor/bin/phpunit tests/Models/ItemTest.php` |
| One test / group | `composer test -- --filter testSuggestionsColumnValidationRejectsSQLInjection` |
| Formatter | `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.no-header.php --path-mode=intersection <your files>` |
| Asset build | `npm run build` (== `gulp default`); needs Node >= 20 (build fails on 18) |

- Always scope the fixer to the files you touched. The `Coding Standards` workflow (`.github/workflows/main.yml`) is disabled upstream and the tree is not fixer-clean (e.g. every `app/Language/*` file is double-quoted while the ruleset enables `single_quote`), so a repo-wide run rewrites thousands of unrelated lines.
- `phpunit.xml.dist` sets `failOnRisky`, `failOnWarning`, and `beStrictAboutOutputDuringTests` — any stray `echo`/`var_dump` in a test fails the suite.

## Testing quirks

- DB-backed tests use `CIUnitTestCase` + `DatabaseTestTrait` with `protected $migrate = true; protected $migrateOnce = true; protected $refresh = false;`, which runs the real `app/Database/Migrations` against the `tests` DB group. That database must exist and its user needs `CREATE`/`DROP` on that schema (CI grants exactly that, scoped to `ospos_test`).
- Most DB classes drop/recreate the schema in `setUp()` via `App\Database\Seeds\TestDatabaseBootstrapSeeder::reset()`. It refuses to run unless `ENVIRONMENT === 'testing'` and the database name contains `test`. Because the shared `tests` connection caches `listTables()`, the seeder must call `Database::connect('tests')->resetDataCache()` — skip that and later classes fail with bogus "ospos_migrations doesn't exist" errors.
- `throttle.key` must be non-empty (in `.env`, or `THROTTLE_KEY`), otherwise `checkThrottleEncryption()` in `app/Helpers/security_helper.php` throws. CI writes it into `.env` rather than exporting an OS var because CodeIgniter's `env()` consults `$_ENV` first.
- CSRF is auto-removed in the testing environment by the `Config\Filters` constructor — you do not need to disable it yourself.
- `tests/Support/ConcurrentDbRaceTrait` uses raw `mysqli` async queries and spawns `tests/Support/RaceWorker.php` as a separate PHP process to get genuine row-lock contention. These need a real MySQL/MariaDB and are the slowest tests in the suite.

## Testing conventions

- **One test file per class under test.** `Item_kits.php` → `tests/Controllers/ItemKitsControllerTest.php`, `Sale_lib.php` → `tests/Libraries/Sale_libTest.php`. Add methods to the existing canonical file rather than creating a feature- or endpoint-scoped file next to it.
- Some older, feature-scoped files predate that rule (`ItemSearchTest.php`, `ItemQuantityTest.php`, `ItemBulkUpdateTest.php`, `ItemTaxesMultipleTest.php`, `Sale_libPaymentTest.php`, `CustomersCsvImportTest.php`). Leave them alone; do not copy the pattern or split existing files to match it.
- Reusable row builders live in `tests/Support/*FixtureTrait.php` — reuse one instead of hand-rolling inserts.

## Architecture notes

- `app/Config/Routes.php` only hand-routes login, `no_access`, and the `reports/*` report families. Everything else relies on CI4 improved auto-routing (`autoRoutesImproved = true`, `translateURIDashes = false`), so `items/index/5` → `Items::index(5)` works without a route. Method names stay snake_case (`getIndex`, `postSave`, `suggest_search`).
- Auth is in the constructor, not a filter: controllers extend `Secure_Controller`, whose `__construct(string $module_id, ...)` throws a `RedirectException` when the logged-in employee lacks the grant. It also publishes globals with `view('viewData', $this->global_view_data)`.
- `app/Config/Constants.php` defines bare CI3-era globals still used in code (`HAS_STOCK`, `NEW_ENTRY`, `PRICE_OPTION_*`). Use them as-is instead of inventing class constants.
- Schema migrations run automatically during login (`Login::index()` → `MY_Migration::latest()`); there is no manual migrate step in normal operation. New migrations go in `app/Database/Migrations/` as `YmdHis_description.php` and must be safe on both a fresh install and an existing one.
- Secrets live in `.env` guarded by a `.env.lock` mutex; the paths are centralized in `app/Config/SecurityEnv.php` (tests redirect them to a sandbox by mutating that shared config instance).

## Frontend

- Hand-written assets are in `public/js/` and `public/css/`. Page-specific scripts are loaded straight from the view (`<script src="<?= base_url('js/hide_cost_profit.js') ?>">`); only shared scripts (`imgpreview.full.jquery.js`, `manage_tables.js`, `nominatim.autocomplete.js`) are listed in the `gulpfile.js` `debug-js`/`prod-js` tasks.
- The build **rewrites tracked source files**: `gulp-inject` injects revved filenames into `app/Views/partial/header.php` and `app/Views/login.php`. After `npm run build`, expect those two files in `git status`.
- No JS linter or bundler config exists; use `const` for values that are never reassigned, `let` otherwise, never `var`.

## Localization

- New keys must be added to **all** `app/Language/<locale>/` variants (49 locales) — a missing key shows up as the raw key in that language.
- Insert keys in alphabetical order, and match the quoting and `=>` alignment already used in the file (currently double quotes, aligned to a fixed column). If your key is wider than the current column, realign the whole file rather than leaving one line ragged.
- Non-English locales use `''` for keys with no translation yet; CodeIgniter then falls back to `en`. Only `app/Language/en/` and `app/Language/en-GB/` contain English strings — never copy English text from a neighbouring key into another locale.
- When explicitly asked to translate a phrase, provide the real translation; never leave it empty.
- Keep placeholders (`{0}`, `{filePath}`) and literal filenames (`throttle.key`, `.env`) untranslated, moving them to wherever the target grammar needs them.
- Translations are managed through Weblate; commits beginning with `Translated using Weblate` are filtered out of `CHANGELOG.md` by `cliff.toml`.

## Code style

- PSR-12 via PHP-CS-Fixer using the CodeIgniter4 ruleset; write PHP 8.2+ code with type declarations.
- `camelCase` variables/methods, `PascalCase` classes, `UPPER_CASE` constants. When editing code that has non-compliant local variable names, rename them to `camelCase` in the same edit.
- Import classes/functions/constants with `use` at the top of the file rather than using fully-qualified names inline.
- No comments or docblocks that restate the code; only document non-obvious rationale, constraints, or behaviour.
- `app/Views/errors/html/` is excluded from the fixer.

## Git workflow

- Create a git worktree per issue from the latest `origin/master`, commit there, and push.
- Commits follow Conventional Commits with a scope and PR reference, e.g. `fix(items): guard sort column against injection (#4712)`.
- Never commit `.env`, `vendor/`, `node_modules/`, `public/resources/`, `build/`, or `dist/`.
- `.gitignore` also ignores `*.png`, `*.jpg`, `*.jpeg`, `*.webp`; a new image will be silently untracked — use `git add -f` and confirm it was added.

## Security

- Never commit secrets, credentials, or `.env` files; never reference security advisory IDs (CVE, GHSA, …) in code, comments, commit messages, docs, or URLs.
- Use parameterized queries and the framework's escaping (`esc()`, query builder bindings) — validate and sanitize all request input.