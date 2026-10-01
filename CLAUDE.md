# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

REMEDI — a pharmacy point-of-sale + inventory system with SARIMA-based demand and sales
forecasting. Laravel 12 backend, Blade + Alpine frontend, MySQL, plus a Python forecasting layer
invoked as a subprocess from Artisan commands. Runs under XAMPP on Windows (docroot `public/`).

**`REMEDI.md` in the repo root is the deep reference** — ~2,570 lines of measured performance
numbers, UI decisions, and "don't undo this" notes accumulated over the project. This file is the
orientation layer; consult `REMEDI.md` before changing dashboard/reports queries, the forecasting
pipeline, POS checkout, the notification bell, or any layout detail, and update it when those change.

The two files share a section skeleton and therefore drift. **Where they disagree, prefer the one
carrying a measurement**, and re-derive rather than trusting either: the numbers here were true when
written, and several have already gone stale. Verify a claim against the code before building on it.

## Commands

```bash
php artisan migrate --seed
```
Seeds admin (`admin@remedi.com` / `password`) and staff (`staff@remedi.com` / `password`), then
categories → products → batches → inventory receipts → sales history. Catalog data comes from CSVs
on disk, not factories (see "Seeders read CSVs").

`phpunit.xml` has its sqlite in-memory lines commented out, so tests run against the `DB_*`
database in `.env` (`remedi_dbase`). Breeze tests use `RefreshDatabase` — **a bare
`php artisan test` wipes the seeded ~339k-row database.** Always override the connection on the
command line, which beats `.env` because Laravel's Dotenv does not clobber real environment
variables. **The primary shell here is PowerShell, which has no inline env-var prefix**, so the
bash form is a parse error there — and "fixing" that by dropping the prefix is exactly the command
that destroys the database:

```powershell
$env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php artisan test
```

Same thing through the Bash tool, and how to run one file or one test:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --filter=CheckoutTest
```

**The suite is green (367 passed, 1256 assertions — measured 2026-10-01) and is a usable regression gate.** It was 22 failed / 3 passed, for
two reasons that were both fixture bugs rather than application ones — see `UserFactory`: it
hardcoded a cost-10 bcrypt hash while `phpunit.xml` sets `BCRYPT_ROUNDS=4` (the `hashed` cast runs
`Hash::verifyConfiguration()` and rejected every user), and it set neither `role` nor `is_active`, so
`actingAs()` authenticated an in-memory user with no `is_active` and `EnsureUserIsActive` correctly
killed every authenticated request. The factory now hashes at runtime, sets both columns, and offers
`admin()` / `inactive()` states.

The tests that contradicted the app were rewritten to assert what it actually does, per the standing
rule below: `/` redirects to login, `/register` is the admin Add User form (a guest is redirected,
staff get 403, and an admin who creates a user **stays signed in as themselves**), and staff may
change their name but not their email. **Never change the app to satisfy a test; fix the test.**

Beyond Breeze there are now twenty-one suites covering the things REMEDI.md says must never regress:

- `Feature\DestructiveGuardsTest` — the refusals standing between an ordinary click and lost data,
  each one a thing that happened here or was one request away: a cashier with sales deleted, an admin
  deleting or demoting themselves out of the only role that can undo it (both `users.destroy` and the
  live-but-hidden `profile.destroy`), a `RULE_DRIVING_NAMES` category renamed, a category holding
  products deleted, a batch or product a sale refers to deleted, and a SKU rename that must re-point
  every table keyed on it.

- `Feature\Pos\CheckoutTest` — FEFO order, expired/returned/expires-today stock never being sold,
  exact payment on a price that floats badly (`1.05 * 3`), a centavo-short payment refused, a cart
  naming one product twice summed **before** the stock check, per-day transaction numbering, and a
  payment past the column's ceiling refused rather than crashing the till (with the ceiling itself
  still accepted, so the bound cannot be off by one).
- `Feature\Auth\DeactivationTest` — a deactivated cashier losing an open session on both a page
  request and the AJAX till (401, not a redirect).
- `Feature\Alerts\ActivityFeedTest` — the bell's System/Updates feed: a batch and a checkout each
  surviving a run of report views, every account event named rather than lumped under one label, the
  account kind reaching the toast seed while sign-ins do not, the action pill rendering, every href
  relative, and a staff toast stack carrying no audit-derived rows at all.
- `Feature\Pos\BackfillAttributionTest` — no backdated sale credited to an account created after it,
  eligibility narrowed to the sale's own timestamp rather than its day, and `--fix-attribution`
  re-pointing an impossible row while leaving a legitimate one and the row's `created_at` alone.

**Writing more session tests: Laravel's guard memoises the resolved user, and the test application
is not rebuilt between requests inside one test method.** Flip `is_active` in the database, issue
another request without `Auth::forgetUser()`, and the middleware reads a stale in-memory model and
returns 200 — which looks exactly like the protection being broken when it is not. That artifact
cost a false "security bug" during a QA pass; `DeactivationTest` carries the warning in full.

- `Feature\SalesListTest` — the sales list's date filter: reversed ranges reordered rather than
  returning nothing, unparseable and impossible dates refused rather than silently ignored.

- `Feature\Reports\InventoryReportTest` — the inventory report's expired stock: the `expired_batches`
  accessor resolving rather than returning null, the row badge actually rendering, the Expired-only
  filter narrowing the table, the KPI agreeing with the filtered rows, and the audit entry recording
  the filter.

- `Feature\Alerts\AlertToastTest` — the bottom-right alert toasts: the four kinds they cover, the
  missed-return kind they leave to the bell, cards naming a product rather than summarising a kind,
  every card carrying an onset date, the mount point surviving a quiet page, the watched-kind list in
  the seed, and the fresh-sign-in flag.

- `Feature\Inventory\BatchStateTest` — what a batch reports about itself and who may write it off:
  `markBatchReturned` as an ENDPOINT (a batch inside its window returned, one outside it and one
  already returned both refused, the refusal answering JSON for the confirm dialog rather than a
  redirect), plus the accessor memo not outliving the values it came from.

- `Feature\Inventory\ProductFormTest` — what the product and batch forms accept: unit as a closed
  list (a canonical unit accepted, free text and the wrong case refused, the one legacy `"20"` row
  still editable, the form rendering a `<select>`), and the numeric ceilings on `selling_price`,
  `cost_price`, `reorder_level` and batch `quantity` — plus that `cost_price` is genuinely optional
  (a product saves fine with none set, and the value round-trips when one is).

- `Feature\Auth\RegistrationEmailTest` — the Add User address: stored as typed, another domain
  accepted, capitals and stray spaces folded rather than refused (the `lowercase` rule REJECTS them),
  and case unable to slip a duplicate past the unique check.

- `Feature\Reports\RecentDemandTest` — the dashboard's High/Low Demand window: it counts terminal
  sales the imported record cannot see, ignores anything older than the window, and is retired by a
  checkout. All three fail against the anchoring this replaced.

- `Feature\RouteSurfaceTest` — walks the whole route collection and asserts every action METHOD
  exists. `Route::resource('products', ...)` registered `products.show` while `ProductController` has
  no `show()`, so `/products/{id}` answered **500** (BadMethodCallException) rather than 404. Nothing
  links there, which is why it survived; the route is now `->except(['show'])` and that URI answers
  405, since PUT/PATCH/DELETE still live on it.

- `Feature\Inventory\StockMovementTest` — the stock card ledger (`StockMovement`): `addBatch` logs a
  `stock_in` row, checkout logs a `sale` row linked back to the `Sale`, `markBatchReturned` logs a
  `return`, and `updateBatch` refuses a quantity change with no `reason` and, given one, logs an
  `adjustment` with the correct signed delta and `balance_after`. Also covers the `/products/{id}/
  stock-card` page itself and its type filter.

- `Feature\Reports\DashboardKpiTest` — ATV (Average Transaction Value) and ATC (Average Transaction
  Count) on the admin dashboard's KPI row, which replaced the Expired/Need-to-Return tiles (both stay
  visible elsewhere — the Inventory tab, the bell, the Medicine Returns card). Both are extracted as
  static, pure functions on `DashboardController` (`computeAtv`/`computeAtc`) and tested directly,
  never through the route: the admin dashboard body always calls `SalesHistory::monthlyRevenue()`/
  `seasonalTrends()`, both MySQL-only (`STRAIGHT_JOIN`) queries this suite's sqlite connection cannot
  run. `ReportController::atvAtc()` (the Sales Report's own ATV/ATC, POS-scoped, folded into
  `SalesReportPeriodTest`) is extracted the same way and for the same reason. Also covers
  `computeTodayProfit()` (Revenue Today, which replaced Last 7 Days): price minus cost times
  quantity, a line with no cost set excluded rather than scored at zero, zero lines reading as zero
  profit rather than null, and the sum rounding to centavos.

- `Feature\Pos\VoidTest` — `SaleController::void()`: refused with no reason or an unknown one,
  restocks the exact batch a line came from with a matching `StockMovement::TYPE_VOID` row, refused a
  second time on an already-voided sale (and does not double-restock), and a voided sale stays listed
  in Sales History while dropping out of "today"'s total. As of 2026-09-22 also covers the passcode
  half: staff refused with no passcode set at all, refused with the wrong one, allowed to void their
  OWN sale with the correct one, still refused (403) on a colleague's sale even with the correct code,
  and an admin needing none of it. Also covers `PosController::checkout()`'s payment method: defaults
  to `cash` with none sent, records the one chosen, refuses an unrecognised one AND the removed `card`
  value specifically.

- `Feature\Auth\PasswordResetRequestTest` — the in-app "Forgot your password?" flow: a request flags
  the account and logs it, a second click doesn't duplicate the audit row, an unknown or archived
  email is refused, an admin can reset the password (with or without a pending request) and it sets
  `must_change_password`, staff cannot (403), and the resulting lock is a HARD block -- a plain page
  load redirects to `/profile`, an AJAX/JSON request gets 423, and only changing the password (which
  clears the flag) lifts it.

- `Feature\SettingsTest` — store-wide settings (`SettingsController`, `Setting` model), currently just
  the POS void passcode `Feature\Pos\VoidTest` covers spending: staff can't reach `/safeguard` at all
  (403), an admin can set and later replace it, the 6-digit and confirmation rules are enforced, and
  replacing one invalidates the old code immediately.

- `Feature\SmsServiceTest` — `App\Services\SmsService`, on `Http::fake()` throughout so no test ever
  reaches semaphore.co (which would spend real credits and text a real handset). The cases that earn
  their keep are the ones where nothing was sent but a naive integration would say otherwise: a
  `Failed` status inside a 200, an HTTP error, a missing API key (which must not even call out), and
  a gateway that throws. Plus number normalisation, the sender name being withheld unless
  configured, and `delivers()` following the driver rather than being set by hand.

- `Feature\Auth\AdminOtpPasswordResetTest` — the ADMIN half of "Forgot your password?", the shortest
  path in the app between an email address and an admin account, so it is mostly REFUSALS: a staff
  request still notifies an admin and mints no code, an admin with a number on file gets one, an
  admin with NO number falls back to the staff path rather than dead-ending, and then wrong / expired
  / guessed-too-many-times codes are each refused (with the right code dying alongside the burned
  one), the password form can't be reached without verifying, the code form can't be reached with
  nothing pending, sending is rate limited, and the resend button issues a fresh code, kills the old one, needs a pending request, and shares that one limit. Note the test reads the code out of
  `SmsService::fake()` — it is stored hashed, so there is no other way to get it, which is the point.

Forecasting is the one area still uncovered — neither pipeline, neither service, neither page.

```bash
vendor/bin/pint
```

**Re-deriving a measurement.** Nearly every number in this file and in REMEDI.md is a count over the
seeded catalogue, and most of them are computed accessors (`is_running_out`, `sellable_stock`,
`expired_batches`) rather than columns, so SQL alone cannot answer. Bootstrap the app in a one-liner
rather than reaching for `tinker --execute`, which gave contradictory results across identical runs
while the HTTP path stayed stable (see "A price edit rewrites historical revenue"). This answers in
~0.4s against the full 2,638-product catalogue:

```bash
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $ps=App\Models\Product::with("batches")->get(); echo $ps->filter(fn($p)=>$p->is_running_out)->count();'
```

**Date-stamp whatever you write down, and REPLACE the old number rather than adding a second one.**
This file carried five different figures for "the low-stock count" at once, three of them from before
the rule they described existed, and no reader could tell which was current.

**Running the app.** `.claude/launch.json` defines two preview configurations, and only one of them
is real: **`remedi`** (`php artisan serve --port=8000 --no-reload`) serves the app, while **`dev`**
(`npm run dev`, port 5173) starts a Vite server nothing consumes — no view references `@vite`, so it
compiles to an asset the app never loads. Start `remedi`, never `dev`. Under XAMPP the docroot is
`public/`, so the app is also reachable through Apache without `artisan serve` at all.

**`--no-reload` is required on this Windows box, not cosmetic.** Bare `php artisan serve` spawns its
child process through Symfony `Process`, and Laravel's hot-reload feature strips every `$_ENV` key
not on its `passthroughVariables` allowlist (setting it to `false`, i.e. unset) before handing the
child its environment — `APP_KEY` is not on that list. The intent is that the child re-reads `.env`
itself on every request so edits apply without a restart; here it does not reliably do that, so the
very first request throws `MissingAppKeyException` and every page — including `/login` — renders the
generic 500 page (measured 2026-09-22, reproduced with `curl` directly against both `php artisan
serve` and a manually spawned `php -S 127.0.0.1:PORT server.php`: the latter, and `artisan serve
--no-reload`, both serve `/login` correctly; bare `artisan serve` does not). `--no-reload` skips the
stripping branch entirely and passes `$_ENV` through unchanged, which is what fixes it. If a preview
ever shows the branded 500 page on first load, check `storage/logs/laravel.log` for
`MissingAppKeyException` before suspecting application code — restarting with `--no-reload` is the
fix, not `php artisan key:generate`.

**`artisan serve` is single-threaded — one request at a time — and `/dashboard` occupies it for
5–12s.** Nothing else is served during that window, static files included; see the loader notes
under "Frontend" for what that broke. Prefer Apache when timing anything, and never judge a "slow
asset" from a `serve` session. Note also that `localhost` and `127.0.0.1` are different origins with
separate caches and cookies — several bugs in this file trace back to warming a cache from one and
reading it from the other.

Forecast regeneration — on Windows pass `--python=python` (the signature defaults to `python3`):

```bash
php artisan forecast:generate --source=mysql --python=python
```

```bash
php artisan sales-forecast:generate --source=mysql --python=python
```

```bash
php artisan forecast:evaluate-split --python=python
```
**The 80/20 train/test split (added 2026-09-28, asked for by the user for the defence).** `resources/python/evaluate_train_test_split.py` splits each product's monthly series IN TIME ORDER — first 80% train, last 20% test, never shuffled — trains `generate_forecasts.forecast_product()` (the live model, same loader) on the training months and scores every test month against two naive baselines. EVALUATION ONLY: it writes `storage/app/forecasts/train_test_split_80_20.csv` and changes nothing in the database; the live forecasts still train on every month and the Forecasting page's accuracy is still the 3-month holdout. `--ratio=` changes the split. Re-measured 2026-09-29 on the seasonal-first model with enforced seasonal fits (RED record, most products train 2022-01..2025-08 / test 2025-09..2026-07, an 11-month horizon): MAE 16.36 / RMSE 19.78 / MAPE 75.4% / sMAPE 59.6% / WAPE 42.2%, vs mean-of-last-3 15.17 / 18.32 / 74.1% / 52.5% / 39.2% and repeat-last 18.48 / 21.93 / 80.6% — it beats repeat-last but NOT mean-of-last-3 over this long horizon; grades Normal 18 / Acceptable 116 / Not acceptable 196 (non-seasonal only read 13.78 / 16.91 / 66.7%).

**Both commands default to `--workers=1` (sequential) as of 2026-09-13** -- previously `0` (auto, all
cores but one), which reads `os.cpu_count()` in the Python script and in a container returns the
HOST's core count, not the container's actual memory allocation. That mismatch is what OOM-killed the
container the one time this ran unattended (`forecast:generate`'s nightly cron, hence
`Kernel::schedule()` pinning `--workers=1` explicitly), and `sales-forecast:generate` had no scheduled
run to learn the same lesson from -- it defaulted straight to the unsafe value and this file documented
running it that way. Pass `--workers=0` explicitly for full parallel fitting on a machine you know has
the RAM for it (safe locally under XAMPP; not on the ~1GB Railway container). **The subprocess ceiling
is 3 hours (was 30 minutes) as of 2026-09-29**: seasonal orders competing per product made a run ~84
CPU-minutes on 2,617 products, so the sequential nightly run on Railway (~1,300 products, ~40 min) would
have timed out every night and silently kept yesterday's forecasts. Python deps:
`pip install -r resources/python/requirements.txt`.

```bash
php artisan logo:mark
```
Regenerates `public/logo-mark.webp`, the 160px derivative of `public/logo.png` that the dashboard
loader inlines as a data URI. **Run it whenever `logo.png` changes** — nothing else reads the
derivative, so a stale one shows the old artwork silently.

```bash
php artisan pos:backfill --dry-run
```
**Fills up to YESTERDAY, never today** — today is the day someone is standing at the till, and
"Total sales today" is the one figure on screen that has to be theirs. The first run filled through
today and put PHP 9,541.69 of generated sales under that heading, over the PHP 400.78 the shop had
actually taken; those 46 rows were deleted and their 147 units put back on the shelf. Fills the days
between the imported record and yesterday with ordinary POS sales — FEFO deduction against
real batches, per-day transaction numbers, line items pointing at the batch they came from. The two
records meet at 2026-08-16 and the till had only 73 sales in the eighteen days after it, so every
report spanning the handoff showed a cliff that was about RECORDING, not trade: the dashboard's August
bar read PHP 206k against July's PHP 377k. Run without `--dry-run` to write; **re-running tops each
day up to the target rather than doubling it**, and `--from` / `--to` / `--per-day` / `--seed` are all
adjustable. It deducts real stock (2,555 units on the first run, which pushed low stock 632 → 673) and
deliberately writes NO audit entries — the trail records what people did, and nobody did this.

**A backdated sale may only be credited to an account that already existed.** Cashiers were drawn at
random from every active user, resolved ONCE before the day loop, so accounts created 2026-09-02 were
credited with sales going back to 2026-08-16 — **611 of 874 rows**. It shows up on the Sales history
list, `/sales/{id}` and every reprinted receipt, which all print `$sale->user->name`: the cashier
column named someone hired a fortnight after the transaction. Attribution is now narrowed per SALE
against `users.created_at` (not per day — an account created at 20:52 was not taking money at 09:00),
and the command fails up front if no account predates the window rather than dropping those days one
basket at a time.

```bash
php artisan pos:backfill --fix-attribution --dry-run
```
Repairs rows already written that way. Its predicate — `sales.created_at < users.created_at` — can
only match generated rows, since a real checkout is attributed to whoever is signed in and nobody can
sign into an account that does not exist, so it needs no way to tell demo data from real. Only
`user_id` moves (`update()`, never `save()`, or the backdated `created_at` is dragged to now); totals,
stock and transaction numbers were always right. **Repairing this install moved 476 sales to Admin and
135 to Staff** and left every other account with only what it could have rung up — because the honest
answer is that before those staff accounts existed, only the admin could have been at the till. Do
NOT "fix" it from the other end by backdating `users.created_at`: the audit trail records those
accounts being created on 2026-09-02, and the two would then contradict each other. Covered by
`Feature\Pos\BackfillAttributionTest`, which asserts both halves and fails against the old command.

```bash
php artisan products:reorder-levels --apply
```
Rewrites every product's `reorder_level` from **its own** average monthly demand — dry run without
`--apply`, `--months=` to change the cover (default 0.5), `--floor=` the minimum (default 5). The
seeded levels were unrelated to sales velocity and inconsistent between units: BOTTLE/PACK/TUBE sat
at ~2 weeks of cover while PCS/BOX sat at ~1 month, and **HERACLENE 1MG TAB X100 sold 869 boxes a
month against a reorder level of 5** — roughly four hours of stock before it would have alerted. A
flat number per UNIT cannot fix that, because the same unit holds both that product and one selling
once a month. **Re-applied 2026-09-02**, after the sales record was rebuilt at a small pharmacy's volumes: 944
products changed and the levels collapsed with the demand behind them — HERACLENE 435 → 5, because it
now sells 1.9 boxes a month rather than 869. `reorder_level` itself hasn't been touched since that
run (re-verified 2026-09-09: no product created or reorder-levels run since), so this is still the
live state — levels run **5 (the floor) to 85**, with only **182 products above the floor**: on this
shop's volumes the MEDIAN product's own average monthly demand rounds to **zero** — over half the
catalogue never sells enough to clear even half a month of cover — so the floor is doing the work for
most of the catalogue. The 182 that clear it are the real movers (the busiest, LENOXA 500MG X100 TAB,
sells ~168/month). Low-stock counts read **666** on `Product::is_running_out` — the rule the bell,
the toasts, the Inventory tab, the dashboard panel and the inventory report all share — against
**755** on the till's sellable rule (`is_low_stock`, which only the POS grid now reads). Both
re-measured 2026-09-09 — lower than the 734/818 read on 2026-09-02, since POS trade through
2026-09-03 sold stock down — and verified to agree across all five surfaces. Previous levels are in
`storage/app/backups/reorder_levels_before.csv` if the basis ever needs revisiting. Half a month
stands in for supplier lead time, which the schema does not record — give it a real one and
`--months` is the single number to change. It bulk-updates, so `Product::saved` never fires and the
command clears `AlertService` itself.

Other commands — note the classifier comes in two halves, and each defaults to a dry run:
`php artisan products:classify-csv database/data/inventory_seeder.csv --write` re-derives the
Category column **in the seed CSV** (dry run without `--write`), while
`php artisan products:classify --apply` re-files rows **already in the database**
(`--category=` to narrow, `--samples=` to widen the preview; dry run without `--apply`). Also
`php artisan import:receiving-reports <csv|xlsx>...` (bootstrap products/batches from supplier
exports).

**Never run `php artisan route:cache`.** Caching the route collection drops GET from `/`, which then
answers 405 and locks everyone out at the login redirect. `config:cache` / `view:cache` are safe.

**Undo those with `config:clear` / `view:clear`, not `optimize:clear`.** `CACHE_DRIVER=file`, so the
application cache is the same store the load-bearing aggregates live in, and `optimize:clear` runs
`cache:clear` among its six tasks — which empties the `sales_history` aggregates (3.9s each to
rebuild, 53.6s if the index hint is ever lost), the `AlertService` payload, `sidebar_categories`, and
the `sales_cache_version` / `pos_cache_version` stamps. Nothing is corrupted by that and the next
dashboard hit rebuilds it, but the first person to load a page pays for all of it. Reach for
`cache:clear` deliberately — when you have changed a cached *sort* or query shape, per "Top selling"
below — not as a reflex after editing config.

`npm run dev` / `npm run build` exist but are inert — see "Frontend".

## Architecture

### One definition of each rule, and where it lives
Most of the bugs recorded below are the same bug: a rule written down twice, then changed in one
place. Each of these is the single definition — read it before writing a second one, and if you add
a surface that needs the same answer, call it rather than re-deriving it.

| The rule | Lives in |
|---|---|
| What an open alert is (all five kinds, both caches, every reader) | `App\Services\AlertService` |
| "Reorder this" — low stock on every list | `Product::$is_running_out` |
| "The till may sell this" | `ProductBatch::scopeSellable()` / `$is_sellable` |
| Where a 30-day expiring alert links | `ProductBatch::expiringSoonUrl()` |
| Whether a batch may go back to the supplier | `ProductBatch::$is_returnable` |
| When a forecast becomes actionable | `App\Support\ForecastHorizon` |
| Normal / Acceptable / Not acceptable | `App\Support\ForecastGrade` |
| Which sales a user may see | `Sale::isVisibleTo()` |
| Transaction numbers, counted within the day | `Sale::nextTransactionNo()` |
| Escaping a search term before it reaches `LIKE` | `Controller::likeTerm()` (9 sites) |
| Answering both a confirm-dialog fetch and a plain post | `Controller::actionOk()` / `actionFailed()` |
| A validated, ordered, clamped report range | `ReportController::clampRange()` |
| The last day any report may count | `SalesHistory::reportableThrough()` |
| Merging live POS into the history series | `SalesHistory::mergePos()` |
| Tables keyed on `products.sku` with no foreign key | `ProductController::SKU_KEYED_TABLES` |
| What counts as medicine; which categories cannot be renamed | `Category::MEDICINE`, `Category::RULE_DRIVING_NAMES` |
| The unit list both product forms render | `Product::UNITS` / `Product::unitOptions()` |
| Every state-changing write's record | `AuditTrail::log()` |
| The actions the audit filter may offer | `AuditTrail::ACTIONS` / `canonicalAction()` |
| A new batch number: product letters + received date | `ProductBatch::nextBatchNumber()` |
| Which audit rows are an account CHANGE, and pop as a toast | `AlertService::ACCOUNT_KIND` |
| Columns the user list may be sorted by | `UserController::SORTABLE` |
| Upper bounds for money and counts | `Controller::MAX_MONEY` / `MAX_COUNT` |
| Chart gradients; the navigation skeleton | `partials/_chart-gradient`, `partials/_page-skeleton` |
| Store-wide admin-set config (the POS void passcode so far) | `App\Models\Setting` |
| Sending a text message; whether one really gets delivered | `App\Services\SmsService::send()` / `delivers()` |
| An SMS reset code's life: mint, check, burn | `User::issuePasswordOtp()` / `checkPasswordOtp()` / `clearPasswordOtp()` |
| Who may void a sale, and whether a passcode is needed | `Sale::isVisibleTo()` + `Setting::checkVoidPasscode()` in `SaleController::void()` |

### Bug sweep, 2026-09-26 — what was fixed and the rule each one leaves behind

- **Voided sales fed the forecasts.** Both Python scripts' `sale_items` branch now has
  `AND sales.payment_voided = 0`; regenerate both pipelines after touching that SQL. Same family as
  `posMonthlyRevenue()` (fixed the day before): **any raw read of `sales`/`sale_items` needs the
  voided filter by hand.**
- **The forecast CHARTS read `sales_history` alone while the models train on history + the till**,
  so once the import was cut back to 2026-07-31 every actual line stopped at July and the forecast
  opened in September with August missing. `SalesHistory::posMonthly()` (the till per month,
  non-voided) and `SalesHistory::lastCompleteMonth()` (last complete month across BOTH records) are
  now the one definition both forecast services read. `SalesForecastService::cacheKey()` carries the
  POS stamp — forget THAT, never the bare `CACHE_KEY`.
- **The Sales Forecast chart on `/forecast/{sku}` had a sparse x-axis** (months with sales only), the
  fault the Demand chart was fixed for long ago. Now continuous with zeros and trimmed to
  `DemandForecastService::CHART_HISTORY_MONTHS`, so both charts on the page share one axis.
- **`/admin/backup` streams the `users` table** (password + reset-code hashes, personal emails) and
  was one click from any open admin screen. It now sits in the `password.confirm` group beside
  Safeguard; its link carries `data-password-gate` (`App\Support\PasswordGate::state()`, the one
  definition of "recently confirmed" for views) so the same pop-up opens in place.
- **The staff void passcode could be guessed without limit** — 6 digits, nothing slow. `void()` now
  locks an account out after `SaleController::PASSCODE_MAX_ATTEMPTS` (5) wrong codes for 15 minutes;
  a correct code clears it. `PUT /password` (change password, checks `current_password`) is now
  `throttle:6,1` like confirm-password.
- **An array where a string was expected answered 500 on ten routes** — `?search[]=x` on Inventory,
  POS, Products and Forecast, `?q[]=x` on every `/suggest/*`, `?sku[]=x` on the POS barcode lookup,
  `?filter[]=x` on Inventory, `?category_id[]=x` on the Inventory report, and `email[]=x` on
  Forgot Password (guest-facing), Add User and Edit User. Read request input through
  `Controller::searchParam()` / `normalisedEmail()` rather than casting it, and **never `(string)` a
  request value** — on an array that is "Array to string conversion". `Feature\SearchInputTest` sends
  an array for every filter parameter on every list page; add a page to it when you add one.
- **Second pass (a fuzz of every route × admin/staff/guest × arrays, garbage, huge, negative and
  impossible dates) found four more 500s, now fixed:** the profile email (`lowercase` runs even after
  `string` fails — rules that call a string function need `bail`), `current_password` on an array
  (profile delete, change password, confirm password — `password_verify()` throws; `bail` + `string`
  first), `/inventory?page=<20 digits>` (the manual paginator's offset overflowed to a float; the page
  is now clamped to one that exists), and the dead Breeze reset page echoing `?email[]=`. After the
  fixes the fuzz answered **zero** 500s, and the MySQL-only pages (dashboard, forecast, reports, all
  27 report exports) were swept the same way against real data with zero 500s. `pos:backfill` now
  excludes voided sales when counting what a day already has.

### Laravel 10-style skeleton on Laravel 12
`bootstrap/app.php` binds `App\Http\Kernel` / `App\Console\Kernel`; middleware aliases live in
`app/Http/Kernel.php` and the scheduler in `app/Console/Kernel.php::schedule()` (`forecast:generate`
nightly at 02:00 — `sales-forecast:generate` is manual). Register new middleware and scheduled
commands there — do not migrate piecemeal to the Laravel 11+ fluent style.

### Deploying: one image carrying both runtimes
`Dockerfile` + `docker/entrypoint.sh` + `railway.json`, documented at length in
`docs/DEPLOY-RAILWAY.md`. The image is FrankenPHP (`dunglas/frankenphp:1-php8.3`) serving `public/`
directly — **`php artisan serve` is not an option in a container for the same reason it is a poor
local benchmark**: single-threaded, with `/dashboard` occupying it for 5–12s. It also installs
**python3 and a `/opt/forecast-venv` virtualenv on PATH**, because a PHP-only image deploys cleanly
and then fails the 02:00 `forecast:generate` every night, silently, while the forecast pages keep
serving whatever they last imported.

Four things the entrypoint encodes, all of them rules from this file:

- **It refuses to start if `APP_ENV=production` and `APP_DEBUG=true`.** That combination turns every
  unhandled error into a page carrying a stack trace, the failing SQL, and (via Ignition's environment
  tab) other env vars — DB credentials included — served to whoever's browser hit it. `APP_DEBUG=false`
  being the documented value below is not a guard on its own; a variable left unset or mistyped in the
  Railway dashboard would ship that page with nothing catching it. This is the enforcement.
- **`config:cache` + `view:cache` only, never `php artisan optimize`** — that runs `route:cache`,
  which drops GET from `/` and locks everyone out at the login redirect.
- **`migrate --force` only; seeding is a separate manual step.** The seeders read a ~15 MB CSV and
  insert ~122k history rows, and a second run collides on `(product_sku, sale_date)`.
- It waits for the database (12 × 5s) before migrating, since Railway starts app and MySQL together.

**The Vercel function's region must sit next to the DATABASE, and `vercel.json` pins it (`fra1`).**
Sessions and the cache both live in the database there (`SESSION_DRIVER` / `CACHE_DRIVER=database`),
so a page is ~25–30 round trips, and the function defaulted to `iad1` (Washington DC) while the
Railway MySQL is in **Europe**. Measured 2026-09-19 with the same code and data: logged-in pages took
3–4s and the dashboard body 6.6s on `iad1`, **4.5–8s on `sfo1`** (worse — it moved the function
further from the DB), and **0.7–1.5s / 2.0s on `fra1`**, level with Railway. Evidence for "Europe":
Manila → the DB proxy is a 230 ms round trip (`SELECT 1`, timed straight against the public proxy),
and each step west→east of the function cost ~80 ms per query. Railway does not expose a service's
region through its CLI, so if the database ever moves, re-derive it the same way (time `SELECT 1`
from two places, then try a Vercel region and time real pages) rather than guessing — the first guess
here was US West and it was wrong.

**Measured on the live sites, 2026-09-28.** Most of a click's time from the Philippines is the trip to
Europe (~0.3–0.4 s); the server's own share was 60–160 ms for `/login`. Railway's MySQL answers even
`select 1` in ~8 ms (private network, same `ams` region as the app), so every query costs that much there.
**The LIVE catalogue is not the local one**: 2,638 active products / 2,543 batches against 330 / 270
locally (the RED-catalogue archive of 2026-09-27 was never applied live), so per-batch PHP work is ~8×
heavier live — `ProductBatch::days_to_expiry` / `return_days` (Carbon `diffInDays()` per batch, unmemoized)
were the dashboard's hottest code and now use calendar-day numbers, memoized. **Railway blocks outbound
SMTP** (587 and 465 both time out from the container): "Forgot password" hung 60 s and answered 500, and
reset codes only ever go out from Vercel. `config/mail.php` now times SMTP out at `MAIL_TIMEOUT` (10 s) so
the controller's catch can answer; sending from Railway needs an HTTP mail API or Railway's Pro plan.
Railway's HTTP logs (`railway logs --http --json`) carry `upstreamRqDuration` per request — the real server
time for every page anyone opened.

**Second pass, 2026-09-30, on the synced 2,620-product catalogue** (local timings, same data as live; the
dashboard output was diffed byte-for-byte before/after for admin and staff, and the Forecasting page's
only change is SKUs rendered as strings): **dashboard body 664 → 379 ms, Forecasting 171 → 100 ms.**
(1) The dashboard loads products ONCE and shares them with their batches (`with('product.category')`
hydrated all ~2,600 a second time and made the per-product memo per-batch). (2) `Product::total_stock` /
`sellable_stock` (loaded path) and `non_pharma_return_window_days` are memoized, and **Product now clears
its memo in `setRelation()` / `unsetRelation()` / `setRelations()`** — the dashboard attaches batch
slices after load. (3) `ProductBatch::getAttributeValue()` parses `expiry_date` / `received_date` once per
instance — **safe only while nothing mutates the returned Carbon in place** (every caller uses `copy()`
or a comparison; keep it that way). (4) The non-pharma return window counts whole days (`days_to_expiry`,
the `is_returnable` rule) instead of a fractional `diffInDays(now())` per batch — the same answer, since
expiries are midnights. (5) Both "Top 5" forecast charts rank in SQL and fetch only the winners' rows.
Covered by two `BatchStateTest` cases. What is left is mostly hydrating ~5,200 models (~70 ms) and the
0.3–0.4 s trip to Europe per request.

Migration `2026_09_02_000001_create_sessions_and_cache_tables` exists for this deploy and no-ops
locally. **A container filesystem is rebuilt on every deploy and is not shared between instances**,
so `CACHE_DRIVER=file` there means every deploy signs everyone out and two instances disagree about
`AlertService`'s payload and the `sales_cache_version` / `pos_cache_version` stamps — this app leans
on the cache for CORRECTNESS, not just speed. Locally both drivers stay `file`, which is right for
one XAMPP process on one disk.

### Roles and routing
Everything is in `routes/web.php` behind `['auth', 'active']`. Two roles on `users` (`admin`,
`staff`), enforced by the `role` alias → `app/Http/Middleware/EnsureUserHasRole.php`, which does
**role only**.

**Deactivation is a separate middleware and must stay paired with `auth` on every authenticated
group** — `active` → `EnsureUserIsActive`, which ends the session of an `is_active = false` user
(401 JSON for AJAX, redirect otherwise). It used to live inside `EnsureUserHasRole`, which is
attached to the `role:admin` routes alone, so it never ran for the staff-facing half of the app: a
deactivated cashier with a live session could still ring up sales through `POST /pos/checkout`.
`LoginRequest` blocks a fresh sign-in, but that does nothing about a session already open. Never put
a route in the `auth` group without `active`.

Both paths take their wording from `trans('auth.deactivated')` in `lang/en/auth.php`. That key was
missing, so the login page rendered the literal string `auth.deactivated` to the one person who
couldn't interpret it. App lang files **merge** with the framework's, so that file defines only this
key — don't copy `failed`/`password`/`throttle` in beside it.

**Nothing that used to have a Delete button is deleted any more — it is ARCHIVED.** Products,
batches, categories and user accounts carry a nullable `archived_at` (migration
`2026_09_19_000001`), and the four models use `SoftDeletes` with `DELETED_AT = 'archived_at'`, so the
old `DELETE` verbs and route names (`users.destroy`, `products.destroy`, `batches.destroy`,
`categories.destroy`) are unchanged but now stamp the column instead of removing the row. Each has a
`PATCH …/restore` twin (`->withTrashed()` on the route, because route-model binding hides archived
rows) and an "Archived" list: `?archived=1` on Products and Users, a section under the Categories
table, and an "Archived batches" block on the product edit page. Audit rows are `Archived` /
`Restored` (`AuditTrail::ACTIONS`; `Deleted` stays on the list because rows written before this still
carry it). **Why it matters: the whole family of "cannot be deleted because history refers to it"
refusals is gone, because archiving cannot orphan anything.** The old guards existed because a real
delete cascaded (`sales.user_id`, `products.category_id`) or hit RESTRICT (`sale_items`), and the
message always had to send the admin somewhere else. Now: an account with sales can be archived (it
loses sign-in, keeps its sales — `Sale::user()` reads it `withTrashed()`), a sold product can be
archived (`SaleItem::product()` / `batch()` are `withTrashed()`), and a product with 2,555 rows of
`sales_history` can be archived without a peso leaving any report. The refusals that REMAIN are the
ones about hiding the wrong thing: you cannot archive yourself, a category still holding active
products, or restore a product into an archived category (restore the category first).
Deactivate stays as the reversible in-place way to suspend an account that should remain on the list.

**Where the soft-delete scope does and does not apply — this is the part to get right when adding a
query.** Eloquent queries (`Product::`, `ProductBatch::`, `Category::`, `User::`, relations,
`whereHas`) exclude archived rows automatically, which is what takes an archived product off the
till, the inventory, the alerts and the forecast lists without a filter per surface. **Raw query-builder
queries (`DB::table(...)`, `join('products', ...)`) do not see the scope, and that is deliberate for
HISTORY**: every revenue aggregate joins `sales_history` to `products` on `sku` by raw join, so an
archived product keeps pricing its own past. Anything raw that describes the CURRENT catalogue must
filter by hand — `ReportController`'s slow-moving query does (`whereNull('products.archived_at')` and
the same on the batch join), and both forecast Python scripts exclude archived SKUs from their
training SQL so a discontinued product stops being forecast and stops feeding the store-wide totals.
Archiving a product **cascades to its batches with the same timestamp**, and restore hands back only
the batches carrying that timestamp (one archived on its own earlier stays archived). The SKU stays
reserved by the archived row — `unique:products,sku` still sees it — which is what stops a new
product inheriting a dead one's history and forecast. Validation that names a row must ask for an
ACTIVE one (`Rule::exists(...)->whereNull('archived_at')` on category and checkout product ids).
**A migration must not query these models through Eloquent** — the scope filters on a column that
does not exist yet on a fresh database; the two old data migrations that did now use
`withoutGlobalScopes()`. `DedupeOpeningStockBatches` uses `forceDelete()` on purpose: those are
duplicate rows an import wrote by mistake, not records anyone wants kept.

**Role scoping belongs on every route that reads the data, not just the pages.** `Sale::isVisibleTo()`
is the one rule — staff see only their own transactions, admins see all — and **three** routes expose
a sale: `sales.show`, `pos.receipt` and `suggest.sales`. Only the first ever checked. `pos.receipt`
had no authorisation at all, so a staff account could read any cashier's full receipt by walking the
sequential ids while `/sales/{id}` returned 403 for the same record. `SuggestController::sales()`
likewise returned 8 transactions where the list shows 2, and could enumerate a colleague by name —
there, put the scope *outside* the search closure or the `OR` escapes it. The `/suggest/*` routes are
not fetched by any view, which is precisely why that gap survived: still registered, still reachable
with a session. Call `isVisibleTo()` from any new route that renders a sale.

**Two routes archive a user — `users.destroy` and `profile.destroy` — and both must enforce the same
two rules:** never the last *active* admin (the admin pages are `role:admin`, so that lockout is
unrecoverable through the UI; for `users.destroy` the actor can never be the target, which is what
guarantees a survivor) and log `Archived` to the audit trail on success. `profile.destroy` is the
stock Breeze route; its card is hidden on the profile page but the route is live. It logs out BEFORE
archiving, inside the transaction, so `SessionGuard::logout()`'s `cycleRememberToken()` save cannot
undo the stamp. Any new account-removal path needs both.

**"Forgot your password?" is an in-app request-an-admin flow, not email** -- this app has no
working mail delivery (`.env` points `MAIL_MAILER=smtp` at a local mailpit catcher, nothing that
exists outside a dev machine), so Breeze's own token-by-email reset
(`PasswordResetLinkController` / `NewPasswordController`) can never deliver anything here. The two
routes it used to own (`password.request` / `password.email`, still `GET`/`POST /forgot-password`)
are repointed at `PasswordResetRequestController` instead; `reset-password/{token}` is left wired
to the original `NewPasswordController` as harmless dead code, since nothing links to it any more.

A signed-out account (any role -- the login page's "Forgot your password?" link doesn't
distinguish) posts its email; `PasswordResetRequestController::store()` validates it against an
ACTIVE, non-archived user (`Rule::exists('users','email')->whereNull('archived_at')`, the same
"validate against a row that can still be acted on" rule checkout and category validation already
follow) and stamps `users.password_reset_requested_at`, idempotently -- a second click while one is
already pending touches nothing and writes no second audit row, so the page always answers with one
generic message regardless. `AuditTrail::log('Requested', "Password reset requested: {name}")`
- 'Requested' and 'Reset' are new entries in `AuditTrail::ACTIONS` - is what actually notifies an
admin: `AlertService`'s `$isAccount` detection (see "Notifications" below) now also matches a
`str_starts_with($row->details, 'Password reset')` details string, so the request rides the EXISTING
account-change bell/toast pipeline (`ACCOUNT_KIND`, admin-only, linking at `/users`) rather than a
new notification channel built just for this.

**As of 2026-09-23 that is the STAFF half; `store()` forks on ROLE, because the two roles have
genuinely different problems.** A cashier always has an admin above them to ask. An admin does not,
so "an admin has been notified" is circular, and on a one-admin shop it is a lockout. An admin with
a `phone` on file instead gets a **6-digit code texted to their own number**, verifies it, and sets
their own password -- possession of the registered handset standing in for the authority a staff
request borrows. The fork is `isAdmin() && phone !== ''`, not `isAdmin()` alone: **an admin with no
number saved falls back to the staff path** rather than dead-ending, since another admin can still
reset them, and the message says which of the two things was missing rather than telling the admin
an admin has been notified. That still leaves a genuine gap on a single-admin install with no number
saved, and the honest fix is to save a number.

**Superseded 2026-09-25, at the user's request: the fork is now on the PERSONAL EMAIL, not the role,
and the code is EMAILED, not texted.** Any account — staff included — with `users.personal_email`
saved gets a 6-digit code at that address (`PasswordResetCodeMail`) and sets its own password; an
account with none (either role) flags itself for an admin as above. `canEmailCode()` in
`PasswordResetRequestController` is the one test, shared by send and resend. The SMS wording in the
paragraphs around this is history — the phone/SMS driver (`SmsService`) is still present but no
longer used by this flow. Live mail goes through Gmail SMTP (`MAIL_*` on Vercel and Railway; the
App Password is set by the account owner in each dashboard, never through a chat). Covered by
`Feature\Auth\AdminOtpPasswordResetTest` (staff with an address get a code and finish the reset
themselves; staff without one still ask an admin).

**The reset-code email has a plain-text part (2026-09-28).** It landed in Gmail's spam folder although the sender is right (live `MAIL_FROM_ADDRESS` = the authenticating Gmail account, so Gmail signs it): it was HTML-only, a bare `<div>` with no document, and said "don't reply" — all things filters score. `PasswordResetCodeMail` now sends `emails.password-reset-code` (a complete HTML document) AND `emails.password-reset-code-text`; the text part uses `{!! !!}` because it is not HTML. It still landed in spam, so (same day) the wordmark is one plain word — `RE<span>ME</span>DI` split a word across tags, a filter-evasion pattern filters score — and the envelope sets From name `REMEDI` and a Reply-To explicitly rather than trusting `MAIL_FROM_NAME` (whose fallback is "Example"); both hosts' `MAIL_FROM_NAME` read `REMEDI` when checked. A brand-new sender can still be filtered at first — marking one "Not spam" trains the recipient's Gmail; a domain of your own with SPF/DKIM is the durable fix. Covered by `Feature\Auth\ResetCodeMailTest`.

Three steps, four guest routes (`password.otp` / `password.otp.verify` / `password.otp.reset` /
`password.otp.update`), and **each re-checks the session itself rather than trusting the step
before**. **`password.otp.resend` is a fifth**: a real resend on the code screen, not a link back to
the email form (which is what it was, and which cost a retyped address). It re-reads the account
rather than trusting the rendered page, so one that lost its number, its admin role or its active
flag mid-flow cannot be texted by a button that was drawn before any of that changed, and it
**shares the one rate limiter** so the button cannot buy sends the email form would have refused.
Issuing a code replaces the outstanding one, so the resend says the previous code has stopped
working -- otherwise somebody who resent while the first text was still in flight keeps trying the
code that arrived first and cannot see why it is refused. Its 30-second button cooldown is in
`sessionStorage`, not a timer, because the submit it guards causes a redirect that would kill a
timer; it is a courtesy against double-clicks spending two credits, never the real limit. The verified identity rides in the SESSION as an EMAIL -- never a user id in the URL,
which would let anyone skip to "set a new password" for any account they can name, and never a user
object, so an account archived or deactivated mid-flow stops resolving and the flow simply ends. The
session is regenerated on successful verification (so an id captured beforehand can't be replayed as
a verified one) and again after the reset.

**Four things hold this up and none is optional.** The code is stored HASHED
(`User::issuePasswordOtp()`, `Hash::make`, same as the POS void passcode) and minted with
`random_int`, not `rand`; issuing a new one replaces any outstanding code and zeroes the attempt
counter, so two live codes never double the guessing surface. It EXPIRES (`User::OTP_TTL_MINUTES`,
10) and is BURNED after `OTP_MAX_ATTEMPTS` (5) wrong guesses -- six digits is a million combinations
and nothing else here is slow enough to be a deterrent. Sending is RATE LIMITED per account (5 per
10 minutes), because every text costs money once a real gateway is wired in and an unlimited send
endpoint is both a bill and a way to flood somebody's phone. And wrong / expired / burned all answer
with **one** message, since which of the three it was is information a guesser can use. The code
itself is never written to the audit trail -- that trail is readable by every admin, which would
turn it into a way to take over a colleague's account; only "Password reset code sent: {name}" is.

**`App\Services\SmsService` is the one place a text leaves the app**, and it has two drivers
(`config/sms.php`, `SMS_DRIVER`). `log` writes the message to `storage/logs/laravel.log` instead of
sending it, so the whole flow is exercisable with no account and no credits -- it is the right
setting for a dev machine, and note it writes the code IN FULL, which is both the only way to read
one without a phone and a reason that log file can hand somebody an admin reset. `semaphore`
(semaphore.co, the usual Philippine gateway) sends real texts and costs credits per message.
**`SmsService::delivers()` is what the OTP screen reads** to decide whether to tell the person the
code is in the log rather than claiming a text reached a handset that will never ring -- that notice
removes itself when the driver moves, so it can never disagree with reality.

Four things about the Semaphore driver, each of which is a way an integration looks fine and sends
nothing. **A 200 does not mean delivered**: Semaphore answers with an ARRAY of per-message objects,
and a message it refuses (bad number, no credits) arrives inside a successful response carrying
`status: Failed` -- so the statuses are read, and `Failed`/`Refunded` come back false. **The sender
name is blank by default and must stay that way until it is registered**: Semaphore REJECTS an
unapproved sender name, so setting `SEMAPHORE_SENDER_NAME=REMEDI` before registering it fails every
message; blank lets Semaphore use its own. **Numbers are normalised** (`+63 945 455 3998`,
`0945-455-3998` and `9454553998` are one handset, and a profile field collects all three). And
**nothing about this driver logs the message body**, unlike `log` -- that body carries the reset
code, and a configured gateway means a real shop floor where the log is not a private dev file.
A missing key, an HTTP error and a gateway that throws all return false rather than raising, because
the caller turns false into "we could not text you, use another way" and a login screen must not 500
because a gateway is having a bad day.

**`SmsService::fake()` is the test seam and tests genuinely need it** -- the code is hashed at rest,
so there is no other way to read one back. It is STATIC, and `Tests\TestCase::setUp()` calls
`stopFaking()` before every test for that reason: PHPUnit runs a suite in one process, so one class
calling `fake()` otherwise leaves every later test faking too. That is not hypothetical -- it turned
eight `Feature\SmsServiceTest` cases green-alone and red-in-suite, because `send()` short-circuited
into the capture branch and never reached the gateway the test was checking. Same family as the
guard memoisation `DeactivationTest` documents: state that outlives the test that set it.

**The admin side is `UserController::resetPassword()` (`PATCH /users/{user}/reset-password`,
`role:admin`), not gated on a pending request existing** -- an admin can act on a phone call or
someone standing at the counter, whether or not they used the online form; the per-row "Reset
requested" badge and the `/users` page's own "Password Resets" KPI (counted before any filter, same
rule the other three follow) just say who's actually waiting. It resets the password to
`User::DEFAULT_RESET_PASSWORD` ('staff123', the one place the value is written down), sets
`must_change_password`, and clears the pending flag.

**A known, shared default password is only safe because it can't stay valid, and the block is HARD
-- every request shape, not just page loads.** `EnsureUserSetsNewPassword` (paired with `auth`/
`active` on the same top-level `['auth', 'active', 'must_change_password']` group in
`routes/web.php`, the same way `active` itself rides along) sends an account with
`must_change_password` set to `/profile` instead of wherever it was headed -- same rigor as
`EnsureUserIsActive`, AJAX/JSON included (423 Locked, not let through), because "can't enter the
system unless you change it" means the bell's poll and an in-progress checkout too, not only a full
page navigation. `/profile*` is the one exemption -- where the password form lives, and where the
Change Password card's own AJAX submit needs to keep reaching `/password` (a separate route in
routes/auth.php's OWN `['auth','active']` group, so it was never gated by this in the first place).

**That exemption is for SAFE (GET/HEAD) requests ONLY, as of 2026-09-23** (`isMethodSafe()`). It
covered the whole path at first, which let a locked account WRITE to itself: `PATCH /profile` went
straight through, so somebody holding the shared `staff123` could edit that account's name and phone
without ever setting a new password. **The phone is what makes that more than untidy** -- it is
where an admin's SMS reset code is sent, so pointing it at another handset turns a temporary
password into a permanent way in, which is the exact escalation `must_change_password` exists to
prevent. Changing the password is unaffected (different route, different group). The Personal
Information form is `<fieldset disabled>` while the flag is set so the UI agrees with the endpoint --
but the ENDPOINT is the guard, per this file's standing rule that a gated button is not a gated
endpoint. Covered by three tests in `Feature\Auth\PasswordResetRequestTest`: a locked account can't
rename itself, can't move its phone, can't delete itself, and editing works again the moment the
flag clears.
**Editing your own profile is audited** (`ProfileController::update`, 2026-09-24) — it was the one
account mutation that wrote nothing. The entry is `Updated own user account: {name} (phone, name…)`:
field NAMES only, never values, because the trail is readable by every admin and `personal_email` is
where an admin's reset code goes. Dirty fields are captured BEFORE `save()` (afterwards the model is
clean), and a save that changed nothing writes nothing. The wording contains "user account" so
`AlertService`'s `$isAccount` predicate files it with the other account changes.
The flag is cleared in exactly one place, `PasswordController::update()`, so the account holder's
current password IS the temporary one and they type it as `current_password` like anyone else
changing their password. `/profile`
carries an amber banner (`session('status') === 'must-change-password'`) that also auto-opens the
Change Password dialog. **The AJAX success handler REMOVES that banner** (it is the one element
carrying `data-open-password-change`): the request that just succeeded is the request that cleared
the flag, and nothing re-renders the banner away because the change goes over AJAX and the page
never reloads -- so leaving it up states something that stopped being true a moment earlier. The
auto-open check reads the same attribute, but on load, long before any success, so removing it
afterwards costs nothing -- same trigger condition the pre-existing "a validation error means open
straight onto it" case uses, and note the `setTimeout(openModal, 0)` on that path: this content
script runs where `@yield('content')` sits, AHEAD of the layout's own trailing scripts that build
`window.REMEDI`, so calling `REMEDI.lockScroll()` synchronously here throws before those scripts have
run. Deferring one tick waits for the current synchronous parse-and-execute pass to finish.

Shared: dashboard, POS, inventory, sales list, suggest,
`/alerts` and `/notifications`. `role:admin`: products/batches/categories CRUD, reports, both
forecast pages, user management, audit trail. `/register` is the admin "Add User" form, not public
signup. `DashboardController` branches on role between `admin.dashboard` and `staff.dashboard`.

### Inventory: products → batches
Stock is never a column on `products`; it lives on `product_batches` (quantity, unit_cost,
expiry_date, received_date, dr_no). `Product::$total_stock` sums them, `is_low_stock` compares to
`reorder_level`. Accessors check `relationLoaded('batches')` and read from memory —
`DashboardController` and `InventoryController` load batches once and attach slices with
`setRelation` to avoid N+1. Preserve that pattern in new accessors.

**The accessor memo (`$derivedMemo` on `Product` and `ProductBatch`) is cleared whenever the
attributes change.** It caches `is_expired` / `is_sellable` / `return_status` / `is_medicine` for the
length of a request, which is right while an instance is read-only — that memo is what took ~10k
Carbon operations off the dashboard. It was wrong the moment an instance was written to: an expired
batch whose `expiry_date` was moved into the future kept reporting `is_expired = true`, and so
`is_sellable = false`, on the same instance after `refresh()`. `setRawAttributes()` (hydration and
`refresh()`) and `setAttribute()` (`fill`/`update`/assignment) now reset it. Nothing in the app reads
an accessor after a write, which is the only reason it had not surfaced — keep it that way if you
add a memoized accessor.

**Both of those loads are `quantity > 0 OR returned_at IS NOT NULL`** — a returned batch has been
shipped back so its quantity is 0, and loading only `quantity > 0` makes completed returns disappear
from the "Successfully Returned" figures and the Returned filter. In `DashboardController` the
`$onShelf` subset (`quantity > 0`) is what feeds every shelf-oriented derivation — expiring, expired,
`$batchesByProduct`, category breakdown, expiry overview — while the return tallies read the full
set. Keep that split, or zero-quantity returned stock starts counting as expired stock to pull.

**Two expiry scales, and one of them is regulated.** Medicine gets a 90–120 day supplier *return*
window (`ProductBatch::return_status` → Need/Fail/Successfully Returned); everything else reads
`Product::getNonPharmaReturnWindowDaysAttribute()` — a flat `NON_PHARMA_RETURN_WINDOW_DAYS` (10) for
most categories, but `EXTENDED_RETURN_WINDOW_DAYS` (30) for Baby Care and Vitamins & Supplements
(`EXTENDED_RETURN_WINDOW_CATEGORIES`), both restricted/dated stock a supplier will take back further
out than snacks or household goods. `Product::getNeedsReturnAttribute()`, `ProductBatch::$return_days`,
`ProductBatch::$is_returnable`, `AlertService::returnWindowOpenedAt()` and `DashboardController`'s
non-pharma tallies all read this one accessor rather than the constant directly, so a category can't
disagree with itself across the bell, the toasts and both dashboards. This whole scale is separate
from expiry *severity*
(`EXPIRY_CRITICAL_DAYS` 7 / `SOON` 30 / `WATCH` 90) — by the time a batch is "expiring soon" it has
already left the returnable window. `is_returnable` is the only accessor that applies the category
check `return_status` asks callers to make; gate buttons on it, not on `is_medicine` + status.

**`is_medicine` is derived from the category NAME**, so that name is business logic wearing the
costume of a display label: `category->name === Category::MEDICINE`, minus a name/brand blocklist,
with a dosage regex overriding the blocklist. Use `Category::MEDICINE` — never the literal — and note
that `CategoryController::update` refuses to rename a category in `Category::RULE_DRIVING_NAMES`,
because renaming it silently reclassified 1,393 products and zeroed an entire alert kind. REMEDI.md
"What counts as medicine" has the measurements and the durable fix.

**`ProductBatch::scopeSellable()` / `is_sellable` is the one definition of stock the till may sell**:
`quantity > 0`, not returned, not expired. `Product::$sellable_stock` sums it. Only `quantity > 0` was
ever checked, so the register would dispense expired stock — and FEFO's `expiry_date ASC` meant it
reached for the *most* expired batch first (79 batches / 3,434 units / ₱41,351.99 here, expired
antihistamine syrup at the head of the queue). `total_stock` deliberately still means "physically on
the shelf" **for inventory valuation only**; checkout and `is_low_stock` both use `sellable_stock`.
The scope and the accessor are twins — change one, change the other.

**`is_low_stock` compares `sellable_stock`, not `total_stock`.** A reorder level answers "when do I
buy more?", which depends on what can be dispensed — not on unsellable boxes awaiting disposal.
Reading `total_stock` hid 37 products with nothing sellable, reported as adequately stocked; the
worst was a prescription antibiotic showing 301 units, one batch, expired three months earlier,
reorder level 30. Switching the accessor moved the count 645 → 682 at the time; on the reorder levels
applied 2026-09-01 it read 818 on 2026-09-02 and is **755** re-measured 2026-09-09 (POS trade sold
stock down in between). That is the TILL's number — for a purchasing
list read `is_running_out` below, which is what every list in the app actually counts.

**A control that navigates without being an `<a>` must raise the loading state itself.** The skeleton
and header pill come from a `document` click handler that only matches `<a>`, so the Inventory nav
toggle — a `<button>` — navigated with no loading state at all while its submenu was mid-animation,
which reads as a glitch. `window.REMEDI.showNavigating` is exposed for exactly this, and is looked up
at click time rather than bind time because the nav-group script runs before the navigation script
defines it.

**Clicking the Inventory tab goes to Inventory.** It used to be expand-only — a `<button>` that
unfolded the category submenu — so reaching the unfiltered list from anywhere else took two clicks
and the tab itself appeared to do nothing. It now navigates to All Categories, *except* when you are
already on the unfiltered list, where there is nowhere to go and it stays the pure expand/collapse it
was. That exception is what keeps the remembered open/closed state meaningful; without it the
category list could never be collapsed.

**Search placeholders don't mention barcodes** (inventory, POS, products), regardless of the scanner
card's own visibility. Barcode SEARCH works everywhere either way — `likeTerm()` still matches the
column — this was only ever about the prompt, not the capability.

**The barcode scanner card is UNHIDDEN as of 2026-09-22, at the user's request, in BOTH modules**
(`pos/index.blade.php` and `inventory/index.blade.php`) — it was built hidden-not-deleted specifically
so bringing it back would cost one attribute, and that is exactly what happened: the `hidden` attribute
is gone and nothing else needed to change. `barcode-input`, `barcode-status`, the keydown listener and
a hardware reader all already worked whether the card was shown or not, and `focusEntryField()`'s
`offsetParent !== null` check — which picks the scanner when it is visible, the product search box
when it is not, because a hidden input cannot take focus — now resolves to the scanner automatically
on both pages, no code change required. If it is ever hidden again, the markup staying in the DOM
(rather than being deleted) is what keeps that one-attribute reversibility.

Inventory carries the same card and the same mechanism: its own `focusEntryField()` now picks the
scanner too, and the click-anywhere refocus handler (which bailed out entirely while the scanner's
`offsetParent` was `null`) is active again — clicking anywhere that isn't an input/select/button/link
refocuses the scanner, the same behavior the page had before it was ever hidden. `autofocus` stays
OFF on both scanner inputs regardless: it was dropped when hidden (a hidden input cannot take focus,
so it was a promise the page could not keep) and was never restored, since autofocusing one of
several entry points on a busy page on every load would be a surprise of its own — `focusEntryField()`
already arms it after every sale/restock/click, which is the behavior that actually matters.

**A scan means a different thing in each module, and as of 2026-09-23 both say so plainly.** In POS
it rings the item up — `addToCart()`, straight into the sale being built, unchanged. In INVENTORY an
admin's scan now navigates to that product's Manage Product page (`products.edit`), replacing the
inline **Quick Restock** card that used to open in place; the card, its form and its
`openQuickRestock`/`closeQuickRestock` functions are DELETED, not hidden, because the scan handler
was the only thing that ever called them. That also collapses two copies of "add a batch" into the
one already on the edit page (the Add New Batch card), which posts to the same
`ProductController::addBatch` the removed card did — so nothing about restocking was lost, it just
moved to the page that was already the home for it. A STAFF scan still fills the search box and
filters the list in place: `products.edit` is `role:admin`, so sending a staff scan there would
trade a useful lookup for a 403. `isAdmin` in the page's JS is what forks the two, and the branch
returns early rather than falling through to the search path.

**The CAMERA is a scanner too** (`partials/_barcode-camera.blade.php`, inside both scanner cards):
hold a barcode up to the machine's camera and it rings up (POS) or opens Manage Product (Inventory),
exactly as a gun scan would. History, 2026-09-23: a "Camera" button opening a modal (rejected: a gun
costs nothing, so two clicks per item is a worse feature), an inline preview (rejected then), then a
hidden-preview scanner. **2026-09-30, three fixes at the user's request, after it turned out it had
never read a printed barcode:**

- **The decoder is `BarcodeDetector`, not html5-qrcode.** The browser's own where it exists and reads
  EAN-13 (Chrome on Android/macOS; Chrome on Windows has none), else the `barcode-detector` ponyfill
  (jsdelivr, pinned `@3.2.2`) — the same API over **ZXing C++ in WebAssembly** (~1 MB wasm, fetched
  on first use from the zxing-wasm version the ponyfill pins). html5-qrcode's ZXing-js failed twice
  over: it decoded at its container's CSS size (a hidden 280×200 reader shrank every frame until the
  bars merged), and even at 1280×720 it read NONE of five synthetic camera pictures (straight, 10°
  tilt, sideways, a bit blurry, further away) where ZXing C++ read all five, the tilted+blurred one in
  0.2 s. `detect(video)` reads the stream's own frames, so the visible preview IS the source — no
  off-screen reader any more.
- **A 176×99 aiming preview** (`#camPreview`, red aim line, green `.is-hit` flash on a read,
  mirrored unless the track reports `facingMode: 'environment'`), shown only while the camera runs.
- **A failure is ONE QUIET amber line, never a pop-up** (`#camStatus`): camera blocked, no camera,
  camera in use, an address without camera access (needs https or localhost; `http://<LAN-ip>` has no
  camera API), or the scanner not loading. "Try again" retries, and a permission flipped to Allow
  starts the camera without a reload (`navigator.permissions` change, where supported).

A USB/bluetooth gun never needed code — it types into `barcode-input` and sends Enter. The camera
hands its result to **`window.handleScannedCode(code)`, the one definition of what a scan MEANS**; the
partial owns no lookup logic, so the two cannot drift. Rules in the partial: one `detect()` in flight
at a time (the next is scheduled after it settles); **a code is ONE scan for as long as it stays in
view** — every sighting pushes `REPEAT_GAP_MS` (1.5 s) on, and it scans again only after leaving view
that long (a fixed 2.5 s window from the first read added an item twice when held for 3 s; verified
held 5 s = 1 add, away and back = 2); the stream asks for 1080p and continuous focus where offered;
formats are the retail 1D set plus QR; and the camera is released on `visibilitychange` / `pagehide`
and re-acquired on return. `$cameraScannerEnabled` turns the whole thing off — markup, CDN script and
`getUserMedia` all absent. Verified in the browser pane (which blocks the real camera: the blocked
line shows) with a synthetic `captureStream()` camera: preview, green flash, "Added: 3D MASK
DISPOSABLE X10".

**Every scan answers with a sound (2026-10-01, at the user's request): `REMEDI.scanBeep(found)`** in
`public/assets/remedi.js`, called from each page's `handleScannedCode()` once the lookup answers — so a
camera read and a gun scan sound the same. Found: one short high beep (1976 Hz triangle, 0.11 s);
not found: two low falling tones (330 → 247 Hz). It shares the toast chime's ONE `AudioContext` and
its autoplay handling (a blocked beep is silent, never an error), with its own per-workstation mute,
`localStorage['remedi.scanSound'] = 'off'` / `REMEDI.scanSound(false)`, so silencing the alert
pop-ups does not silence the till. An admin's Inventory scan waits 150 ms before opening Manage
Product, or the navigation cuts the beep off. Verified by recording the oscillators each scan
started: POS found 1976 Hz, unknown 330+247 Hz; Inventory the same, the found beep sounding before
the page left.

**Every POS surface must report the same number checkout will honour** — `pos/_grid.blade.php`
(badge, `is-out` class, `data-true-stock`, and the `addToCart` ceiling) and
`PosController::lookupBySku`'s `stock` field. They showed `total_stock` while checkout enforced
`sellable_stock`, so an all-expired product offered "Stock: 10", let the cashier add 10 to the cart,
and failed only at checkout with "Available: 0". Inventory and the reports keep `total_stock` — those
units are physically present and need pulling; the till just may not sell them.

**Compare money in integer centavos.** `selling_price` arrives from MySQL as a string and `"1.05" * 3`
is `3.1500000000000004`, so `(float) $paid < $totalAmount` refused exact payment on 1,613
price × quantity combinations — "Amount due: 3.15, Received: 3.15". Checkout now compares
`(int) round($x * 100)`, rounds each line subtotal as it is computed (so the total always equals the
sum of stored subtotals), and rounds `change_due`. The `decimal(10,2)` columns meant nothing bad was
ever *stored*; the bug only ever refused good money.

**Bound every numeric input by the COLUMN behind it, not just by its type.** Money here is
`decimal(10,2)` and counts are signed ints, and MySQL runs with `STRICT_TRANS_TABLES` — so a value
past either limit is not clamped, it raises `SQLSTATE[22003]` and the request dies as a **500 with
SQL in the body**. `amount_paid` was `numeric|min:0` with no ceiling, so a mistyped payment over
99,999,999.99 took checkout down mid-sale instead of answering "that is too large" (verified against
MySQL: *Out of range value for column 'amount_paid'*). The same shape was on `selling_price`,
`reorder_level` and batch `quantity`. `Controller::MAX_MONEY` / `MAX_COUNT` are the COLUMN limits —
not a business rule — so widening a column means widening them. **The till also has to be able to
SHOW the refusal**: a Laravel validation 422 is `{message, errors}` with no `error` key, and the POS
handler read only `error`, so every validation failure reached the cashier as "Checkout failed.
Please try again." It now falls back to `data.message`.

**Checkout's stock guard aggregates per product, and the FEFO walk must fully fulfil the line.** The
check ran per cart line, so a cart naming the same product twice (5 + 5 against 6 in stock) passed
both lines, billed for 10, deducted 6, and the loop silently ran out of batches — ₱1,000 charged for
₱600 of goods, on a receipt reading "6 × ₱100.00" above "Total ₱1,000.00". Requested quantities are
now summed per `product_id` before any availability check, and `$remainingQty > 0` after the FEFO
walk aborts the transaction. Keep both: the aggregate covers duplicate lines, the post-walk assert
covers stock moving mid-checkout (a second register), which no pre-check can.

**POS checkout deducts FEFO** (`PosController::checkout` walks batches `expiry_date ASC`), so one
cart line can produce several `sale_items`; all inside a `DB::transaction`. Checkout answers in two
shapes: JSON (`{success, transaction_no, receipt_html, receipt_url}` / `422`) for the in-page receipt
modal, and a redirect to `pos.receipt` for the no-JS fallback and reprint permalink — keep both.
Receipt markup/CSS live in `pos/_receipt.blade.php` + `pos/_receipt-styles.blade.php`, shared by the
modal and the standalone page.

**Anything that lists a sale's lines must group by product, not iterate `sale_items`.** FEFO records
one row per batch drawn from, so 8 units taken 5+3 from two batches printed the product twice at the
same unit price — correct totals, but a customer reads it as being charged twice. `_receipt.blade.php`
and `sales/show.blade.php` both group on `product_id|price` (price too, so rows charged at different
rates can never merge). The `sale_items` rows are untouched: batch traceability stays in the data,
it just isn't shown. Add a Batch column if that detail is ever wanted on the internal view. Payment
must cover the total; the old supervisor-passcode bypass that could mark a sale voided at the point of
sale was removed deliberately — `checkout()` always writes `payment_voided => false`.

**A real void exists again as of 2026-09-22 — `SaleController::void()` — not a checkout-time bypass.**
Voiding a completed sale is a full reversal: every line's batch gets its `quantity` back
(`ProductBatch::increment`), one `StockMovement::TYPE_VOID` row per line so the stock card shows
exactly where it came from, and the sale is flagged `payment_voided` / `voided_at` / `voided_by` /
`void_reason` (`Sale::VOID_REASONS` — Cashier Error / Customer Cancelled / Duplicate Transaction /
Other). Idempotent: a lock-and-recheck inside the transaction refuses a second void on the same sale
rather than restocking it twice, the same race `markBatchReturned` already guards against. The reason
is picked IN the confirm dialog, not before it — `data-confirm-reasons` (a JSON `{value: label}` map)
swaps the shared confirm modal's single generic Confirm button for one button per reason (see "Every
state-changing action answers in two shapes" below), the same mechanism `User::ARCHIVE_REASONS` uses
on the Archive button in User Management.

**"Other" asks for the reason in words (2026-09-28, at the user's request).** `data-confirm-note-for="other"`
makes that one reason button open a "Reason" text box (`#confirmModalNote`) instead of submitting, and
the Confirm button ("Void transaction", `data-confirm-note-label`) takes over, disabled until something
is typed (and until the passcode is complete, for staff). The text rides in the form's hidden `note`
field; `void()` requires it for `Sale::VOID_REASON_NEEDS_NOTE` and keeps it only then, in `sales.void_note`
(migration `2026_09_28_000002`), and `Sale::voidReasonLabel()` ("Other: printer jammed") is what the
voided banner, the list tooltip and the audit entry print. Covered by four cases in `VoidTest`.
**And every void asks Yes / No first** (same day, at the user's request): `data-confirm-ask="1"` makes a
reason click SELECT the reason (highlighted) and show "Void this transaction for “Cashier Error”?"
(`#confirmModalAsk`, `data-confirm-ask-text` with `:reason`), with Cancel relabelled **No** and Confirm
shown as **Yes, void** (`data-confirm-yes-label`) — only Yes submits. Opt-in per form: User
Management's Archive reasons still submit on one click.

**As of the same day, void is SHARED, not `role:admin` — staff may void a sale THEY rang up, given a
manager passcode.** `role:admin` was "the closest thing this app has to restricted to managers"; a
6-digit passcode an admin sets is the real thing. `void()` checks two gates, in order:

1. `Sale::isVisibleTo()` — an admin may void anything; staff only a sale they rang up themselves.
   Checked INSIDE the endpoint, not just on the button that links to it (`sales/show.blade.php` only
   ever renders "Void Transaction" on a sale the viewer can already see, but per this file's own
   standing lesson — `pos.receipt`, `suggest.sales` — **a gated button is not a gated endpoint**).
2. The passcode (`Setting::checkVoidPasscode()`) — required for staff, skipped for an admin, who
   does not need to prove themselves to themselves.

`Setting` is a new plain key/value table (`settings`: `key` unique, `value`) for store-wide config an
admin sets through the UI rather than `.env`/`config()` (which would need a redeploy) — the void
passcode (`Setting::VOID_PASSCODE_KEY`) is its first and so far only user, stored HASHED
(`Hash::make`/`Hash::check`, same as any other credential) via `SettingsController` at `/safeguard`
(`role:admin`, its own sidebar entry).

**The page is called SAFEGUARD, not Settings, as of 2026-09-24, and opening it asks for the LOGIN
password first.** Both routes (`safeguard.edit`, `safeguard.void-passcode.update`) sit behind Laravel's
`password.confirm` middleware, so a signed-in admin screen left open at the counter is not enough to
change the code that authorises voids; confirmation lasts `auth.password_timeout` (3 hours) per session.
`/settings` is a `Route::redirect` for old bookmarks. **The prompt is a POP-UP, never a separate
sign-in screen** (asked for explicitly): `#passwordGateModal` in `layouts/app.blade.php`. The sidebar
link carries `data-password-gate="required|confirmed"` from the session's confirmation stamp; a
CAPTURE-phase click handler opens the dialog for `required` — capture, because the navigation-skeleton
handler bails on `defaultPrevented` and would otherwise paint a skeleton behind a dialog that has not
navigated. The dialog posts over fetch to `password.confirm`, which answers `{success:true}` for JSON
(`ConfirmablePasswordController`) and a normal 422 validation error for a wrong password. Reaching a
gated URL directly lands on `auth/confirm-password.blade.php`, which is now just the app shell — the
layout auto-opens the same dialog there (`data-auto-open`, keyed on `routeIs('password.confirm')`) and
Cancel leaves for the dashboard. The confirm POST is `throttle:6,1`: it checks a password from inside
a signed-in session, so unmetered it is a guesser for whoever finds a screen left open. The dialog
only saves a round trip — the middleware is the guard, per "a gated button is not a gated endpoint". A void with no passcode set at all, or the
wrong one, is refused with a message naming which (`actionFailed`, so it reads the same both-422-shapes
way every other confirm-dialog refusal does).

**The confirm dialog's `data-confirm-passcode="1"` is the UI half — a THIRD opt-in on top of
`data-confirm-reasons`, not a replacement for it.** It reveals a "Manager passcode" field ABOVE the
reason buttons and disables all of them (or the plain Confirm button, if there are no reasons) until
exactly 6 digits are entered (`syncPasscodeGate()`), the same "picking an answer both confirms and
supplies the field" instinct the reason picker already has. **The value is copied into the form's own
`[name="passcode"]` field only when `passcodeRequired` is true** — the first version of this copied it
unconditionally on every confirm-dialog submission app-wide, which silently blanked the Settings
page's OWN "New passcode" field (also named `passcode`, for an unrelated purpose) every time it was
submitted, since that dialog's gate input is always empty. Any confirm dialog that ever reuses the
field name `passcode` for something else needs to know this. The passcode `<input>` is
`type="password"` (masked, not plain text — a credential someone else's screen can be watching), with
`inputmode="numeric"` for a numeric keypad on mobile despite not being `type="number"`.

Covered by `Feature\SettingsTest` (setting/replacing the passcode, the 6-digit and confirmation rules,
staff locked out of `/safeguard` itself) and `Feature\Pos\VoidTest` (no passcode set at all, the wrong
one, the correct one restocking exactly like an admin's void, staff blocked from someone else's sale
even with the right code, an admin needing none of it).

**A voided sale stays fully visible — its own receipt, Sales History — while dropping out of every
revenue aggregate that reads `payment_voided`.** Filtered explicitly (not a global Eloquent scope,
which would also hide it from `sales.show`/`pos.receipt` — the wrong call, since an admin needs to
SEE what they voided) in: `DashboardController`'s today/yesterday/7-day/hourly queries,
`SaleController::index()`'s `$scopedToday` KPI cards (the list itself still shows the row, with a
"Voided" badge), `ReportController`'s `$sales`/`$salesByStaff`/`$scopedItems` queries, and
`SalesHistory::posTotalsBetween()`/`posUnitsBetween()`/`scopedTrendBetween()`'s POS halves (raw
`DB::table` joins, so each needs `where('sales.payment_voided', false)` by hand — Eloquent's scope
does not reach a raw join). **If you add another aggregate that reads `sales`/`sale_items`, it needs
the same filter or a void silently keeps counting.** A void also calls `SalesHistory::
bumpCacheVersion()` and `AlertService::forget()`, the same two invalidations checkout itself makes —
restocking can pull a product back out of low stock, and the POS-dependent cached aggregates need to
stop serving pre-void numbers.

**`payment_method` (default `'cash'`) records how the sale was paid — `Sale::PAYMENT_METHODS`
(Cash / GCash / Other QR / E-wallet — no card/debit/credit option, removed 2026-09-22 at the user's
request, this till only ever took cash and QR-based e-wallets) is the one list**, rendered as a row
of buttons in the checkout modal (not a `<select>`: options read faster as something to tap) and
validated against the same constant server-side. A sale recorded before the removal
(`payment_method='card'`) still displays correctly everywhere — the receipt and `sales/show.blade.php`
both fall back to `ucfirst($sale->payment_method)` for a value not in the current list, so old data
needed no migration. The default matters: every sale before this existed, and every no-JS checkout
post today, really is cash, so a missing field must resolve to the true historical value, not an
arbitrary one.

**The QR is the shop's real GCash / InstaPay QR when one is saved (2026-10-01, at the user's request).** A QR holding plain text — "REMEDI", or a phone number (both tried that day) — is refused by every e-wallet as INVALID: payment apps only pay EMV **QR Ph** codes (tag-length-value fields starting `000201`, ending in a CRC-16/CCITT check, tag 63). Safeguard's "GCash QR for the Till" box takes a screenshot of GCash → My QR, decodes it IN THE BROWSER with the camera scanner's reader (barcode-detector ponyfill) and posts only the decoded text; `SettingsController::updateGcashQr()` refuses anything that is not a well-formed QR Ph payload (`Setting::isQrPhPayload()`, CRC included) and stores it as `Setting::GCASH_QR_KEY` — in the database, never the code (the repository is public; the payload carries the account). Blank removes it. The audit entry never carries the payload. The POS draws it for GCash and Other QR at 208px / correction level M (a ~170-character code is too dense at 128px), with "Scan with GCash or any bank app, then enter ₱X" — it is a STATIC code with no amount — and "REMEDI" beneath; with none saved it falls back to a QR of the word "REMEDI", which no app can pay. Verified with the user's own QR (decoded in the browser, never printed): the server accepted it and the till's QR decoded back to the identical payload for both methods. Covered by two `SettingsTest` cases using a made-up payload.
**GCash / Other QR show an actual QR code to scan, and lock the payment field to the exact total.**
No real payment gateway sits behind this till, so the code (`qrcodejs`, loaded from cdnjs on this one
page) encodes a plain reference string — store, method, the order total, this checkout ATTEMPT's
idempotency key (which is what changes the code between two carts of the same total) — not a live
payment link; it exists so the payment METHOD reads as an actual QR, not a button merely labelled
"QR". Both are exact-payment methods: the customer's e-wallet app charges them the precise total, so
there is no change to work out and nothing for the cashier to type or mistype. Selecting either makes
`amount-paid-input` `readOnly` and fills it with `cartTotal.toFixed(2)`; switching back to Cash clears
it and hands editing back, since that number was never actually typed by anyone. **The change
calculation compares in integer centavos**
(`Math.round(amountPaid*100) - Math.round(cartTotal*100)`), not a bare float subtraction — the field
now holds a PROGRAMMATICALLY-set value every single time a QR method is chosen, so any float remainder
(131.75 vs 131.75000000000003) would show "Insufficient amount" on a payment that is, to the peso,
exact, on every QR checkout rather than the rare case a typed cash amount happened to hit it. Same
reasoning `PosController::checkout()` already compares money in centavos for.

`transaction_no` is `unique()`; mint it only via `Sale::nextTransactionNo()`, which counts within the
day under `lockForUpdate()`. Never derive it from `Sale::count()` — a global count behind a per-day
prefix regresses whenever a sale is deleted and collides when two registers check out at once, both
of which end in an unhandled 500 at the till. `PosController::withTransactionNoRetry()` covers the
first-sale-of-day race the lock can't. REMEDI.md "Transaction numbers" has the reproduction.

Checkout deducts with `decrement()`, which **bypasses model events on purpose** — so it clears the
alert cache itself (see "Notifications").

**`updateBatch` is the back door into the sellable rules — guard it.** Everything above keys off
`expiry_date`, so editing that field undoes it: an expired batch pushed to a future date became
sellable and the till dispensed it (verified in two requests). `expiry_date` is now `required` (a
null expiry is never "expired", i.e. permanently sellable), gets `after:received_date` **only when
the value actually changes** (73 seeded batches violate it and must stay editable so they can be
zeroed), and the audit entry names the before/after — flagging `— BATCH WAS EXPIRED` when an expiry
is moved on already-expired stock. Correcting a receipt typo is legitimate; doing it invisibly is not.

**`markBatchReturned` writes off stock, so it must check eligibility itself.** It sets `quantity` to
0. Both Return buttons were already gated on `is_returnable` -- the inventory row picks the earliest
returnable batch, the edit page wraps the form in an `is_returnable` check -- but the endpoint was
not, so a POST zeroed ANY batch. Measured 2026-09-02: 2,611 batches hold stock and only **80** can
actually go back to a supplier; the other 2,531 carry 491,379 units worth about PHP 7.69M (valued at
`selling_price`, since `unit_cost` is unpopulated on every seeded batch). Re-measured 2026-09-09:
**2,541** hold stock, **81** returnable, 2,460 not — 491,318 units, still ~PHP 7.69M — every one of
which could be written off by a request the UI would never issue, with no undo. It now refuses a batch
outside its return window, and one already returned (which also stops a re-post rewriting the
original return date). Same lesson as `pos.receipt` and `suggest.sales`: **a gated button is not a
gated endpoint.** Covered by `Feature\Inventory\BatchStateTest`.

**A delivery cannot be dated in the future.** `received_date` validates
`before_or_equal:today` and the picker caps itself at `max="{{ now()->toDateString() }}"` on both
forms that post to `addBatch` (product edit, inventory quick restock); expiry carries the matching
`min` of tomorrow, since `after:today` is what the endpoint applies and a batch expiring today is
already expired. A future received date is not cosmetic: expiry validates `after:received_date`, and
`edit()` derives the next batch's suggested shelf life from the gap between the two, so one date from
next year poisons both. **The quick-restock card's JS also built that date with `toISOString()`** --
the UTC trap the audit-trail presets already had -- so between midnight and 08:00 Manila time it
stamped deliveries with YESTERDAY; it now builds from local parts. Covered by
`Feature\Inventory\ProductFormTest`.

`ProductController::addBatch` is the single write path for new batches — the product edit form and
the Inventory quick-restock card both post to it. `quantity` is what's left after FEFO; `qty_received`
is what the batch arrived with, and `addBatch` must set it (nothing reads it yet, but it can't be
recovered later). Expiry validates `after:today` **and** `after:received_date` — an inverted pair
poisons the shelf-life suggestion `edit()` derives for the next batch. REMEDI.md "Inventory model"
has the detail.

**A batch may carry its own cost, DR number and supplier — all three optional, all three filled in
only through `addBatch`/`updateBatch`.** `unit_cost` and `dr_no` existed as real columns
(`2026_08_02_023329`) with no form writing them; `supplier` (migration `2026_09_22_114535`) never
existed at all. Optional rather than required: a delivery note isn't always in hand when stock is
keyed in, and the alternative — blocking the form on data nobody has yet — would stop a legitimate
stock-in over paperwork. Nothing downstream requires them either; profit reporting and traceability
simply have nothing to show for a batch that skipped them, same as before this existed.

**Every stock movement is logged to `stock_movements` (the "stock card"), through one write path:
`StockMovement::record()`.** Four callers, four types — `addBatch` (`stock_in`), the checkout FEFO
walk (`sale`, linked to the `Sale` via `sale_id`), `markBatchReturned` (`return`), and `updateBatch`
when the posted quantity differs from the stored one (`adjustment`). `balance_after` is read off the
batch's OWN quantity column immediately after it is written, never computed independently, so the
ledger cannot drift from what the batch itself reports. A manual quantity edit through `updateBatch`
now **requires a `reason`** — enforced only when quantity is actually changing, via
`Rule::requiredIf()`, so a bare expiry correction or filling in cost/DR/supplier needs none. Per
product at `/products/{id}/stock-card` (`ProductController::stockCard`), newest first, filterable by
type and date range — this is what answers "500 units sold but never recorded in POS": read the
ledger for the period in question rather than piecing it together from batches, sale_items and the
audit trail separately. **The ledger is forward-only** — it started recording 2026-09-22, so a product
with stock and no rows on its card simply predates the feature; nothing was retrofitted.

**The batch number is the product's letters plus the RECEIVED DATE, and
`ProductBatch::nextBatchNumber()` is the one definition.** `AAA-YYYYMMDD-NN` — three letters off the
product name (`batchNameCode()`), the day it arrived, and a counter within that product on that day:
`HERACLENE 1MG TAB X100` received 2026-09-03 becomes `HER-20260903-01`. **The code takes LETTERS
only**, not the first three characters, because names here open with digits and punctuation often
enough to matter (`3M TAPE` → `MTA`, `G. CROSS ETHYL 70%` → `GCR`); it pads with `X` below three
letters, though no product in the current catalogue needs it. The `-NN` is not decoration — without it
a second delivery of the same product the same day is the identical string, and the two rows cannot be
told apart. The date is the received date, not today, because a delivery entered late still belongs to
the day it arrived, and a number stamped with the day someone got round to typing it would contradict
the `received_date` beside it.

**The number is ASSIGNED, not entered.** Both forms render the field `readonly`, and `addBatch`
**discards whatever is posted** and re-derives — a gated control is not a gated endpoint, the same
lesson `markBatchReturned` and `pos.receipt` carry. Deriving server-side is also what closes the race
the readonly field opens: two people adding a batch for the same product on the same day are both
SHOWN `-01`, and the second must still be told `-02`. **The form's auto-fill is a PREVIEW of the rule,
not a second copy** — it reads the same prefix and padding plus `ProductBatch::takenSequences()`, and
with JavaScript off the box simply stays empty and the server still gets it right. `readonly` rather
than `disabled`: a disabled field posts nothing and leaves the tab order, hiding the form's one
derived value from a keyboard user.

`takenSequences()` skips anything not in the generated format, so the 2,641 seeded `OPENING-*` rows
and the importer's `HIST-*` / DR numbers can never be parsed as a sequence — and note the importer and
seeders write `ProductBatch` directly rather than through `addBatch`, so they are unaffected by any of
this. The counter is scoped per product on purpose — `batch_number` has no unique constraint and
nothing joins on it, and the letters already separate two different products received the same day, so
both start at `01`. Covered by `Feature\Inventory\ProductFormTest`.

### Syncing the LIVE database to local (2026-09-30)
At the user's request, after a verified backup (`C:\xampp\htdocs\remedi.2\backups\live_before_sync_20260930_022253.sql`,
22 tables, restored into `remedi_restore_test` and every row count matched live). Two committed steps,
rehearsed on that restored copy first: **`php artisan catalogue:sync --file=database/data/catalogue_sync_2026-09-30.json
--write`** (dry run without `--write`; matches by SKU and batch number, never id; archives 18 products with
their batches plus the 4 duplicate OPENING batches, sets 324 selling prices and 2,634 costs; skips rows
already right, so a rerun is a no-op) and **`sales-history:import`** with the 2022–2026 daily CSV. It
deliberately leaves live's own rows alone — users, sales, audit trail, stock movements, stock quantities
(the till moved them), and live's two archived QA test products. Forecasts are then regenerated on the
container. `sales-history:import` now calls `SalesHistory::forgetCaches()`: it only bumped the POS stamp,
so the history-only aggregates would have described the replaced record for up to 24 h.

### The data swap of 2026-09-29, second file — the CURRENT record
**`transaction_items_2022_2026.csv` replaced the 2023 file the same evening** (same columns): 565,272 lines /
192,164 orders / **2,617 products, 2022-06-01 .. 2026-08-16**; identical to the 2023 file from 2025 on, a
longer and different ramp before it (~1,350 units a month in Jun 2022). Cut at 2026-08-15 →
**`database/data/sales_history_daily_transaction_items_2022-2026.csv`** (421,390 daily rows, 1,535,678
units), imported; the 2023 file's rows are in `sales_history_archive_20260929_232434`. **Costs: identical
to the database for all 2,617** (nothing changed); prices untouched. Two products it sells were archived
and are restored (SYMBICORT 160/4.5MCG TURBOHALER, LACTUM 1-3 2KG, 17 units); three it does NOT sell stay
ACTIVE because they hold stock (BEAR BRAND JR. 2.4KG, LACTUM 1-3 2.3KG, BEARBRAND JR. 2KG) and simply have
no forecast — **2,620 active**. Backup: `storage/app/backups/before_transaction_items_2022_import_20260929_232401.sql`.
**Model switched the same night to SEASONAL COMPETES per product (the user's choice)** — `forecast_product()`
/ `forecast_series()` choose from `SEASONAL_CANDIDATES + NONSEASONAL_CANDIDATES` on each product's rolling
windows, then the 50% level guard; seasonal fits stay enforced. Regenerated: 2,617 products, **873 visibly
moving**; 3-month holdout **MAE 8.46 / RMSE 9.87 / MAPE 56.8% (2,600) / sMAPE 45.1%, grades Normal 441 /
Acceptable 1,234 / Not acceptable 941** — level with mean-of-last-3 (8.36 / 56.3%), ahead of repeat-last
(10.16 / 66.9%); 80/20 split 10.05 / 12.20 / 65.0% / 48.2% / 36.8% — behind mean-of-last-3 (8.17 / 61.1%)
on MAE, ahead of it on MAPE, and just behind repeat-last on MAE (9.80) but well ahead on MAPE (70.6%).
The seasonal-first figures below are the run this replaced.
Forecasts regenerated (seasonal-first + guard): 2,617 products, 15,702 rows each pipeline, **2,335 visibly
moving**. 3-month holdout (2,616 scored): **MAE 9.11 / RMSE 10.58 / MAPE 63.6% (2,600) / sMAPE 47.2%, grades
Normal 380 / Acceptable 1,123 / Not acceptable 1,113 — behind mean-of-last-3 (8.36 / 56.3%)**, ahead of
repeat-last (10.16 / 66.9%). 80/20 split (most products train 2022-06..2025-09, test 2025-10..2026-07):
11.48 / 13.71 / 76.3% / 51.2% / 42.1% against mean-of-last-3 8.17 / 9.98 / 61.1% and repeat-last 9.80 /
11.72 / 70.6% — behind both. On the 2023 file the three options measured non-seasonal 8.20 / 55.6%
(473 moving), seasonal-competes 8.47 / 57.7% (857), seasonal-first 9.43 / 66.2% (2,252) against a
mean-of-3 of 8.42 / 57.4% — not re-measured on this file.

### The data swap of 2026-09-29, first file — superseded by the section above
The user supplied **`transaction_items_2023_2026.csv`** (line level: Order#, Date / Time, Product ID,
Name, SKU / Barcode, Category, Unit, Quantity, Unit Price, Unit Cost, Line Total, Line Cost, Gross Profit):
**610,797 lines / 208,982 orders / 2,618 products, 2023-01-01 .. 2026-09-29**, arithmetic exact, one fixed
price and one fixed cost per product. It is a shop RAMPING UP — ~116 orders/2k units a month in Jan 2023,
a steady **~10,000 orders / ~70,000 units a month from May 2025**. At the user's decisions: (1) **cut at
2026-08-15**, the day before the till's first checkout, same rule as every import (the last 6 weeks of the
file are left out); aggregated to **`database/data/sales_history_daily_transaction_items_2023-2026.csv`**
(421,560 daily rows, 1,538,201 units) and imported with `sales-history:import --write` — the RED record is
in `sales_history_archive_20260929_215821`; (2) **the catalogue follows the file**: the 2,288 archived
products it sells were restored with the 2,294 batches archived alongside them (402,122 units) — **2,618
active**, the 20 it does not sell stay archived; (3) **`cost_price` from the file's Unit Cost** — 120
filled in, 47 changed, 2,451 already equal (it is the receiving file's cost), every active product now has
one, average margin 20.6%, 2 cost more than they sell for (real in the file). **Selling prices were NOT
touched**: the 330 RED products keep their RED prices, the file matches the rest on 2,294 of 2,618. Backup
of products / product_batches / categories / sales_history beforehand:
`storage/app/backups/before_transaction_items_import_20260929_215741.sql`. Reorder levels were not
recalculated. **Local database only** — the live one still has the older catalogue and record.
**Forecasts regenerated on it (seasonal-first + guard, enforced seasonal fits), 2026-09-29:** 2,617 of 2,618
products forecast (LACTUM 1-3 2.3KG has too little history), 15,702 rows each pipeline, Aug 2026 – Jan
2027, **2,252 visibly moving**. 3-month holdout (May–Jul 2026, 2,616 scored): **MAE 9.43 / RMSE 10.85 /
MAPE 66.2% (2,596) / sMAPE 47.4%, grades Normal 342 / Acceptable 1,128 / Not acceptable 1,146** — **BEHIND
mean-of-last-3 (8.42 / 57.4%)**, ahead of repeat-last (10.18 / 69.7%). 80/20 split (most products train
2023-01..2025-10, test 2025-11..2026-07): 10.59 / 12.68 / 74.0% / 49.7% / 38.1% against mean-of-last-3
8.16 / 9.90 / 60.0% and repeat-last 10.04 / 11.90 / 69.0% — behind BOTH. Likely cause: the record grows
~35× through 2023–2025 and then plateaus, and a seasonal difference carries last year's growth past the
plateau. The first forecast run on 2,618 products takes ~12 min (demand) + ~6 min (sales) on all cores.

### The data swap of 2026-09-27 — superseded by the section above
**SUPERSEDED THE SAME DAY — the current record is the RED Pharmacy file.** The user replaced the first
zip with `Monthly Files 2022 - Aug 16 2026 (RED Pharmacy).zip`: 120,545 line rows, 71,088 orders, **330
products**, a steady ~300–500 units/day with no level step, prices that rise over time. Now in
**`database/data/sales_history_daily_red_pharmacy_2022-2026.csv`** (98,688 daily rows, 636,756 units,
2022-01-02 .. 2026-08-15); the first zip's rows are in `sales_history_archive_20260927_121001`. At the
user's decision the **catalogue follows the file**: the 981 further products not in it were archived
(2,308 archived in total, **330 active**; backup `storage/app/backups/products_batches_before_red_
catalogue_20260927_121042.sql`), and the 330 **selling prices were set to each product's latest Unit
Price in the file** (324 changed, 232 up / 92 down — revenue figures, past months included, are now at
those prices). Costs still come from the receiving file (average margin 22.5%, 13 of the 330 have no
cost). **Re-measured on this record** (May–Jul 2026 holdout): non-seasonal MAE 15.33 / MAPE 59.5%,
seasonal-included 15.93 / 62.6%, seasonal-with-guard 15.62 / 60.3%, baselines "mean of last 3" 16.61 /
62.9% and "repeat last" 18.13 / 72.9% — so the model stays non-seasonal and now **beats both baselines
on MAE, RMSE, sMAPE and MAPE**. **Switched 2026-09-29 to SEASONAL FIRST with a level guard, at the user's request** (a non-seasonal
forecast is a flat line, and they want every product's seasonal shape): `forecast_product()` /
`forecast_series()` fit from `SEASONAL_CANDIDATES` only, and fall back to `NONSEASONAL_CANDIDATES` only
when no seasonal order fits or the forecast averages >50% (`SEASONAL_LEVEL_GUARD`) from the last 3 months.
**Seasonal orders are now fitted with stationarity/invertibility ENFORCED, and a degenerate fit
(`sigma2` ≤ 0 or a non-finite band) is a failed fit** (`_sarimax_rows` / `sarimax_rows`). Left free, the
seasonal MA term DIVERGED — TELMIGEN 40MG fitted `ma.S.L12 = -3.5e13`, `sigma2 = 0`, a point forecast that
replayed last year and an "80% band" of ±590 million units, clamped on screen to 0..the ceiling — and
`_plausible()` only checks point values, so it was accepted. Every seasonal measurement taken before this
fix included those broken fits (seasonal-first read 18.06 / 69.4% with them). Non-seasonal orders keep the
free fit they were always measured with. Regenerated with the fix: **330 scored, MAE 16.16 / RMSE 18.71 /
MAPE 67.3% (300) / sMAPE 52.4%, grades Normal 57 / Acceptable 145 / Not acceptable 128; 274 of 330
forecasts visibly move** (≥5% spread); TELMIGEN 471–541 with a ~270–740 band. On that holdout it beats
mean-of-last-3 on MAE (16.61) but not on MAPE (62.9%). Same holdout, same fix: seasonal competing on
accuracy (guarded) 15.33 / 61.9%, moving 116; non-seasonal only 15.33 / 59.5%, moving 57. To go back, make
`forecast_product()` choose from `SEASONAL_CANDIDATES + NONSEASONAL_CANDIDATES` again (both scripts) and
regenerate both pipelines. Current: **330 scored, MAE 15.33 / RMSE 17.70 / sMAPE 47.2% / MAPE
59.5% (300), grades Normal 60 / Acceptable 161 / Not acceptable 109**. The file still runs ~3× the
till's volume, so `forecast.include_pos` stays off. The bullets below describe the FIRST zip and are
kept for the measurements; where they give counts, these replace them.

These supersede the figures in "Two sales tables" and the forecasting accuracy notes, which describe
the records this replaced.

- **`sales_history` is now the 56 "Sales by Product" monthly files** (Jan 2022 – Aug 16 2026, a zip
  the user supplied), aggregated to one row per product per day in
  **`database/data/sales_history_daily_2022-2026.csv`** (332,574 rows, 1,537,234 units,
  2022-01-01 .. 2026-08-15, 1,311 products). Cut at Aug 15 because the terminal's first checkout is
  Aug 16 and the two records must not both count one day. Every barcode in the files matched
  `products.sku` exactly. Imported with `php artisan sales-history:import --file=<that csv> --write`,
  which now reads that DAILY format (product_sku / sale_date / quantity_sold, SKU-first) as well as
  the old line export, and **archives into a NEW dated table every run** (`sales_history_archive_
  <Ymd_His>`) — the first version TRUNCATED `sales_history_archive` and a second run would have
  destroyed the record the first one saved. Current archives: `sales_history_archive` (the generated
  four-year record, 113,960 rows) and `sales_history_archive_20260927_105817` (the 2026-09-23 export,
  108,542 rows).
- **`products.cost_price` comes from the supplier's receiving file**, `database/data/
  master_inventory_list.csv` (MASTER INVENTORY LIST), via `php artisan products:import-costs
  --write` (dry run without). Matched on NAME then description — Excel turned every ItemCode into
  scientific notation, so the codes identify nothing. 2,514 of 2,638 set, average margin 20.5%; two
  names listed twice with different costs are skipped, blank costs stay NULL ("not known", never 0),
  and two products cost more than they sell for (Castor Oil Apollo, Fix Clay Doh) — real in the file.
  **Selling prices were NOT changed**: the monthly files' Unit Price equals the database's
  `selling_price` on all 1,311 products, and the receiving file's own Price column is the supplier's
  list price, not this shop's.
- **The four hand-added second "opening" batches were archived** (`OPENING-4801812140999`,
  `-4805756332151`, `-4808896601234`, `-4803150893344`; 2,178 units off the shelf). They were not exact
  duplicates, which is why `batches:dedupe-opening-stock` never found them.
- **The imported record runs ~10× the terminal's volume** (~1,600 units/day against ~150), so
  **`config('forecast.include_pos')` / `FORECAST_INCLUDE_POS` is OFF**: both pipelines train on the
  imported record alone, through July 2026 (its last complete month), and both forecast CHARTS read
  the same switch (`SalesHistory::lastCompleteMonth()` ignores the till while it is off) so each still
  draws the series its model saw. With it on, August read as a 42% crash that was only a change of
  source. Consequence, accepted: the horizon opens in August, so Aug–Sep forecast rows are in the
  past; the KPIs count from `ForecastHorizon::firstActionableMonth()` regardless. Reorder levels were
  deliberately NOT recalculated (they still reflect the old volume).
- **The model was tested against naive baselines, and fixed (2026-09-27).** On the May–Jul 2026
  holdout the metrics recompute EXACTLY (40 random products refit independently, actuals read straight
  from SQL), but seasonal SARIMA LOST to "mean of the last 3 months" (MAE 13.68 vs 6.57): the record
  changes level at its file boundaries (~2× May–Dec 2025), and the seasonal term projected last year's
  level forward. Three fixes: (1) **`_plausible()` now judges the RAW forecast before `_clamp_rows()`** —
  clamping first made it impossible to fail, which is how ALKALINSE (~450/month) got [0, 9360, 0];
  (2) the order is chosen over **`SELECTION_FOLDS` (3) rolling windows, MAE-first**, not one window
  MAPE-first; (3) **`SARIMA_CANDIDATES` is non-seasonal only** — (1,1,1) and (0,1,1), i.e. ARIMA, the
  s=0 case of SARIMA — chosen by the user from a measured comparison (seasonal kept: 12.07 / 68.7%;
  seasonal with a level guard: 7.83 / 61.6%; non-seasonal: **6.92 / 60.2%**; baseline 6.57 / 60.8%).
  Current: **MAE 6.92 / RMSE 7.99 / MAPE 60.2% (963) / sMAPE 72.7%, grades Normal 468 / Acceptable 269 /
  Not acceptable 570** over 1,307 scored. Re-measure against both baselines after any model change.
- **The 1,327 products that never appear in the sales files were ARCHIVED** (with their 1,331 batches,
  145,755 units), at the user's request — 1,311 products are active, exactly the file's. One audit row
  records it; backup of both tables beforehand in `storage/app/backups/products_batches_before_archive_
  20260927_114058.sql`. 27 of them had till sales, which are untouched (archiving never removes
  history). Low stock fell 662 → 287. Restore any from Products → Archived.

### Two sales tables — pick the right one
- `sales` / `sale_items` — live POS checkouts only.
- `sales_history` — the imported record. **REPLACED 2026-09-23 from a real transaction export**
  (`database/data/sales_transactions_2022-2026.csv`, 114,891 line rows / 79,491 transactions) via
  `php artisan sales-history:import --file=… --write`. Now **108,542 rows, 2022-07-01 .. 2026-07-31,
  189,514 units**, against the generated record it replaced (113,960 rows, 2022-09-01 .. 2026-08-15).
  **The old rows are still in the database**, copied to `sales_history_archive` by that command
  before anything was deleted and count-verified first; restore with
  `INSERT INTO sales_history SELECT * FROM sales_history_archive`.

  Two consequences of the swap, both real and neither a bug. **There is now a 16-day hole**: history
  ends 2026-07-31 and the terminal's first checkout is 2026-08-16, where the generated record used to
  run to 2026-08-15 and meet it exactly, so any report spanning early August shows nothing for
  Aug 1–15. And the export is **far more intermittent** — 189,514 units spread over 2,603 products
  and 49 months, averaging 1.75 units per product-day (max 13), where the generator deliberately
  concentrated volume on 450 active lines. That is what moved the accuracy figures below, in both
  directions at once.

  It stops before the terminal's first checkout on purpose: this is what the books said before REMEDI
  was installed, and `sales` is the record since. The POS never touches it. Stores units only, so
  revenue is always `quantity_sold * products.selling_price` — the export's prices, discounts,
  customers and payment types are deliberately NOT imported, since nothing downstream reads them and
  widening the table would mean re-deriving every revenue aggregate. `SalesHistory` sets `$table`
  explicitly (Eloquent would resolve `sales_histories`, which does not exist).

Anything plotting a trend over time must read `sales_history`, or it charts an almost-empty table.
Today's takings and transaction counts correctly read `sales`.

**A window anchored to "the newest row in `sales_history`" is now anchored to the handoff.** That is
what `recentDemand()` did — correctly, while the imported record ran up to the present. Once it was
cut back to the day before the terminal went live, the anchor froze there and the dashboard's
High/Low Demand cards described the thirty days ending at the handoff, blind to 918 terminal sales
across eighteen days. Nothing failed and nothing errored; the cards were full of plausible products
that were quietly three weeks out of date. It anchors on `reportableThrough()` (today) now and spans
both records. **If you add a window, anchor it on today and clamp it — never on the last row of one
table.**

**Derived caches inherit their source's invalidation.** `quarterlyRevenue()` is computed from
`monthlyRevenue()`, and the moment that started folding in POS the quarterly ring needed the POS stamp
in its key too — without it a checkout refreshed the monthly chart and left the ring beside it serving
the previous quarter's figures until the TTL expired, two panels on one dashboard disagreeing about
the same three months. `seasonalTrends()` derives from the same source but is not cached itself, so it
was already fine. Ask what a cached value READS, not what it is named after.

**And anything reporting on a RECENT period must read both.** Because the imported record stops at
the handoff, a history-only aggregate returns nothing for the current month — the Analytics report
printed "No sales data." while the till had 73 sales in the window. `topProductsBetween()` and
`unitsSoldBetween()` fold in `posUnitsBetween()` (joined through `products.sku`, the only place
`sale_items.product_id` and `sales_history.product_sku` meet), and both keys are now
`dependsOnPos: true` so a checkout retires them. If you add another aggregate that a user can point
at this month, it needs the same treatment.

**The two tables are disjoint, so `SalesHistory::mergePos()` merges POS across the whole requested
range — do not reintroduce a `MAX(sale_date)` boundary between them.** One existed, and because the
seeded history runs into the future while reports clamp to today, it put the cutoff a week ahead of
every `$end`: `$includePos` became a no-op and every live checkout was silently absent from the Sales
and Analytics reports. Clamping the boundary doesn't help (history covers today too); the per-key
merge already sums a day present in both sources.

Aggregates over `sales_history` are
cached and carry two version stamps (`sales_cache_version` for reseed/import, `pos_cache_version`
bumped by checkout) so a sale doesn't retire the expensive history-only keys. Those aggregates use
`STRAIGHT_JOIN` and lean on the `sale_date`-leading index — both are load-bearing (53.6s → 3.9s and
45.6s → 92ms measured); read REMEDI.md "Two sales tables" before touching either.

**The seeded history runs into the future** — it fills its final month to that month's last day
regardless of the calendar — so every aggregate must stop at `SalesHistory::reportableThrough()`
(today), or it counts sales that have not happened. `dateBounds()` clamps the default range. The
fixed-key aggregates expire at `min(TTL, end of day)` via `cacheUntil()`, so a finished day isn't
held out of the chart until tomorrow. Clamp, never delete: those days come back into range as the
calendar reaches them.

**Any code that queries `sales_history` directly must clamp it itself** — going through
`SalesHistory`'s own aggregates gets it for free, but `SalesForecastService` and
`DemandForecastService` build raw queries and for a long time did not. That put un-happened days into
the "actual" series on both forecast pages: August 2026 read ₱1,699,579.63 against the dashboard's
₱1,282,779.84, a ₱416,799.79 disagreement about the same month. Both are clamped now; if you add a
`DB::table('sales_history')` query anywhere, add `where('sale_date', '<=', reportableThrough())`.

**Every controller that takes a date range must validate it AND order it — there are three, and the
sales list was the one that had neither.** `SaleController::index` fed `start_date` / `end_date`
straight into `whereDate()`, and all three failures were silent because the page still rendered a
normal table: `?start_date=banana` was dropped by MySQL and returned the **whole unfiltered list**,
`?end_date=2026-13-45` matched nothing, and a reversed pair — reachable straight from the UI by
picking the two dates the wrong way round — rendered "No transactions found." for a period that had
15 sales in it. It now validates (`nullable|date`) and reorders via `orderedRange()`, parsing through
Carbon first because `nullable|date` accepts formats the `<input type="date">` fields cannot display
and that sort alphabetically rather than chronologically.

**When you reorder a range, the page has to say so.** The view echoes the *queried* range
(`$startDate` / `$endDate`, not `request()`), and the AJAX response carries a `range` key so the two
date inputs and the URL correct themselves — otherwise the fields keep showing the reversed pair
while the table below them lists a different period. Same rule `clampRange()` follows: the range
queried, displayed and logged must be one range. Covered by `Feature\SalesListTest`.

**"Top selling" means revenue, and the ORDER BY has to say the same thing the page does.**
`SalesHistory::computeTopProductsBetween()` sorted by `total_qty` while the analytics chart plotted
`total_revenue` and the KPI beside it was captioned "best seller by revenue" — so the bars came out
visibly unsorted (₱910,893.84 above ₱983,800.88 above ₱24,761.36) and the Top Product KPI named the
wrong product: HERACLENE at ₱910,893.84 when SYMBICORT had earned ₱7,743,182.94. The sort must stay
in SQL — re-sorting the result is not enough, because `LIMIT` would still pick the top N by units and
a high-price/low-volume product would never reach the list. **These aggregates are cached**, so clear
the cache after changing a sort or the old ordering survives its TTL.

**A value label drawn INSIDE its bar must take its colour from that bar.** `valueLabelPlugin` (one
copy per dashboard body, and they must stay in step) writes each bar's number just past its end, and
falls back to writing it inside the bar when there is no room. That fallback was hardcoded to white —
correct on the saturated gradient bars, invisible on the pale slate (`#e2e8f0`) the "Reorder level"
dataset uses. On *Lowest Stock vs. Reorder Level* the longest bar is the one most worth reading, and
its number was drawn in white on near-white: rendered, present in the canvas, and impossible to see.
`barIsLight()` now picks slate or white by relative luminance, treating anything that is not a plain
colour string (a `CanvasGradient` from `_chart-gradient`) as dark, which every gradient here is. The
fallback also fires far less often now: it measures against the CANVAS edge (`chart.width`) rather
than `chartArea.right`, because `layout.padding.right` reserves canvas beyond the plot edge precisely
so these labels have somewhere to go, and measuring against the plot threw that room away.

**Every chart is gradient-filled by one plugin, not 32 edited definitions.**
`partials/_chart-gradient.blade.php` registers a Chart.js plugin that rewrites each dataset's colours
on `afterLayout` — the first point at which `chartArea` exists, which a canvas gradient needs for its
pixel coordinates. **It is included immediately after each page's Chart.js `<script src>`, in all
eight of them**, because Chart.js is loaded per page rather than in the layout; registering it in the
layout would run before `Chart` exists. The dashboard bodies are AJAX-injected and their scripts are
re-created one at a time in order, so the same placement works there.

Three treatments, by dataset type: bars go full-strength at the top and lift toward the baseline;
doughnut arcs LIGHTEN toward the top rather than fading, because a transparent lower half reads as a
rendering fault against the card; an unfilled line has no area to shade, so the gradient goes on the
STROKE and `pointBackgroundColor` is pinned to the solid colour, or markers vanish into the faded
end. `fill: '-1'` — the forecast confidence band, filled between two datasets — is deliberately left
alone, since fading it vertically would misrepresent the interval.

**The original colours are stashed on the dataset (`__bg` / `__border`).** `afterLayout` re-runs on
every resize, and building the next gradient out of the previous one compounds into mud. Anything the
parser does not recognise (a hand-built `CanvasGradient`, a scriptable function) is returned
untouched, so the plugin can never overwrite a deliberate choice it does not understand.

**Charts that plot sales carry a trend line over the bars** — `monthlySalesChart` (dashboard),
`dailySalesChart` (sales report) and `seasonalTrendsChart` (analytics) are mixed bar+line, with the
bar dataset at `order: 1` so the line draws on top. Stock, demand and forecast bar charts are
deliberately left alone: a trend line over a ranking means nothing. When adding one, the line dataset
goes INSIDE the `datasets` array — appending after the closing `}],` is a syntax error that blanks
every chart in the file.

**The printed transaction list is capped at `POS_PRINT_CAP` (100); the FOOTER is not.** Once the
terminal had a month of trade behind it, "every transaction in the period" was ~890 rows and a 0.83 MB
page that grows with every day the shop opens. The section title says "the first 100 of 808" and the
grand total is `$posTotal` — the true figure for all of them — because a total that quietly counted
only the printed hundred is the same class of bug as a KPI that disagrees with its own list. August:
**0.83 MB → 0.39 MB**, footer verified equal to `posTotal`. The screen never renders this table at
all; it shows the day/month breakdown instead, so this is purely about paper.

**The Sales Report's filters are LIVE as of 2026-09-28 (the user's request) — no Generate button, no Month picker — and this supersedes the three paragraphs below about the month picker, Print regenerating first, and the month `<select>` label.** Every control applies itself: a period button, either date (debounced 350ms), the category, the product (only once it names a real product) and the cashier. `ReportController::sales()` answers an AJAX request with `{html, state}` — `html` is `reports/_sales-body` alone (KPIs, both charts, the breakdown, the print copy), `state` is the RESOLVED range plus filters and export URLs, which the filter bar re-syncs from (a period becomes dates, a reversed pair is reordered, and the address bar records the resolved URL). The partial carries its chart data as a `<script type="application/json">` block and the page's one `renderSalesCharts()` draws from it after every swap, since `innerHTML` never runs a script. Clicks are caught on `#salesReport`, below the layout's document-level skeleton handler, so a refresh paints no navigation skeleton. Print waits for a refresh in flight. `?month=` is still honoured server-side for old bookmarks. Hourly Sales & Transaction Volume now sits ABOVE "Where the total comes from". Measured 2026-09-28: a filter change downloads **18–91 KB instead of 650–760 KB**; the full page went 764 → 472 KB (the product `<datalist>` lists only products that have sold — ~2,300 fewer options — and the body's tables are class-based `.sr-*` instead of inline-styled); the hourly breakdown groups sales once instead of filtering them 24 times (an all-time refresh 242 → 66 ms). The Inventory report's two selects also apply on change now (a plain submit; `<noscript>` keeps an Apply button). The report page is MySQL-only, so none of this is covered by the sqlite suite — verify it in the browser.

**Daily / Weekly / Monthly / Yearly GROUP the Sales Report — they no longer pick a date range (2026-09-28, the user's choice from options).** They were quick RANGES counted from today, and on a Monday "this week" and "today" were the same empty day, so two buttons drew the identical report and the live filters looked broken. Now `?group=day|week|month|year` buckets the chosen dates: one bar and one table row per day, week (Monday start), month or year; the totals do not move, the shape does. `SalesHistory::groupedTrendBetween()` builds a DAILY series (history cached under `rangeKey('daily')`, the till fresh) and `bucketDaily()` rolls it up — the category/product view goes through the same function (`scopedTrendBetween(..., $group)`), and the till's per-bucket column keys through the same `bucketKey()`, so no date can land in two buckets. With nothing chosen the group is `autoGroup()` (day ≤ 92 days, else month), and an old `?period=weekly` link reads as a grouping. `active_days` counts days with a sale in EITHER record — Average / Day moved ₱7,922 → ₱7,824 because the till's own days now count. The breakdown table lists the latest 366 rows (the graph and the footer cover all) — Daily over four years is 1,695 rows and was 1.5 MB per click, now 395 KB. `periodRange()` and its test remain but nothing calls them. The fetches are same-origin relative, `cache: 'no-store'`, and both answers carry `Vary: Accept, X-Requested-With`. Covered by `Feature\Reports\SalesGroupingTest`.

**Nothing is shown as selected unless the person picked it, and the graph's axis is the whole range (2026-09-28, both at the user's request).** The automatic grouping (`autoGroup()`) used to light up "Monthly" and write `group=month` into the address, which read as a filter nobody set; `$chosenGroup` (null unless `group`/`period` was sent) now drives the button and the hidden field, and clicking the lit button again unselects it. The graph draws `SalesHistory::bucketAxis()` — every bucket from start to end, zero where nothing sold — because a cashier who only sold in Aug–Sep collapsed a 57-bar Monthly graph to 2 bars that looked like the graph had gone; it now keeps the Jan 2022 – Sep 2026 axis with their sales at the end. The breakdown TABLE still lists only buckets with a sale.

**Print must print what you asked for.** The month picker submits on change; the two date inputs do
not, and wait for Generate — right, since setting a start date should not fire a report before the end
date is chosen. The cost is that the form and the report can describe different periods: edit the
dates, click Print instead of Generate, and the header, the KPIs and the whole daily breakdown carry
the PREVIOUS range while the date boxes above them show the new one. Nothing is wrong with the report;
it is an accurate report of a period you are no longer looking at, which is worse than an obviously
broken one, because the printout looks finished. The Print button now snapshots `month` /
`start_date` / `end_date` at load and, if they have moved, regenerates first and prints on arrival
(`?print=1`, stripped from the URL by `replaceState` so a refresh does not reprint). Unchanged
controls still print immediately. Verified separately that screen and print agree in every generated
state — same period heading, same KPIs, same daily rows — so this gap is the only way the two can
disagree.

**The Sales Report PRINTS the merged breakdown, not just the POS transactions.** The print copy's
only table used to be `$sales` -- live POS checkouts -- and those exist for the handful of days this
terminal has rung anything up. So printing any other month put KPIs in the millions above "No
transactions found in this date range.", which reads as a broken report rather than as "those sales
were imported". `#print-area` now leads with the same `$dailyBreakdown` table the screen shows
(day/month, units, imported, this terminal, total, with a footer that equals the KPI), and the POS
section renders only `@if($sales->isNotEmpty())` -- a period whose sales are all imported simply has
no terminal section, instead of an empty table. Verified: Mar 2026 prints 31 day rows totalling
PHP 1,554,763.66 where it previously printed nothing; the current month prints both sections.

**SUPERSEDED 2026-09-28 — a cashier now narrows the WHOLE report** (the user found the split, and the paragraph of banner text it needed, confusing). With `cashier_id` set, the trend is that cashier's TILL sales alone (`SalesHistory::posDailyForCashier()` through `bucketDaily()`, category/product applied too), and `$allSales` / `$allScopedItems` narrow with it, so Total Sales, the graph, the breakdown (imported column ₱0.00), Average / Day, ATV/ATC and the lists all describe the same sales; the banner says "Showing sales rung up by X only. Till sales only; the imported record has no cashier." Verified: Admin, all dates = ₱128,341.91 over 21 trading days, footer ₱0.00 + ₱128,341.91. The paragraph below describes the old behaviour.

**The Sales Report's Cashier filter is a THIRD dimension, orthogonal to Category/Product and
deliberately kept apart from `$isScoped`.** `sales_history` has no cashier column at all — it predates
this terminal — so a cashier can only ever narrow the figures that are already POS-only:
`$sales`/`$scopedItems`, `$totalTransactions`, ATV/ATC, the hourly chart, and the printed transaction /
line-item tables. It must NOT narrow `Total Sales`, `$historyTotal`, `$posTotal` or the "Where the
total comes from" bucket table (`$dailyBreakdown`/`$posByBucket`) — those describe the WHOLE till (and
the imported record), and `historyTotal = totalSales - posTotal` would read too high, not too low, if
`$posTotal` were quietly narrowed to one cashier while `$totalSales` stayed store-wide. Both branches of
`buildSalesReportData()` therefore fetch an unfiltered collection first (`$allSales`/`$allScopedItems`)
for `$posTotal`/`$posByBucket`, then derive the (possibly cashier-filtered) `$sales`/`$scopedItems` from
it for everything else — including a THIRD total, `$listedPosTotal`, which is what the printed
transaction/line-item table's own footer must equal (the list is cashier-filtered; `$posTotal` no
longer is). `$scopeCashier` (`User::withTrashed()`, an archived/deactivated cashier still rang up real
sales) folds into `$scopeLabel` alongside Category/Product (`implode(' — ', ...)`), so the PDF/print
title and the Excel sheet name pick it up for free. The filtered-by banner explains the split in
words — "Total Sales above still covers every cashier... since sales_history has no cashier to filter
by" — the same "say what the KPI is made of" instinct behind the History/POS caption below.

**The Sales Report's headline figure is two records added together, and it has to say so.**
`Total Sales` comes from `trendBetween()`, which merges `sales_history` with live POS checkouts —
but the table beneath it lists POS transactions only, because a history row has no transaction
number or cashier to show. So the KPI could never equal the visible rows: Aug 21–25 read
₱283,265.21 above 16 transactions worth ₱31,195.98. Both numbers were right; neither said what it
was counting. The KPI now carries the split (`$historyTotal` / `$posTotal`) underneath it. If you add
another figure sourced from the merged series, label it the same way.

**A `<select>` with nothing selected displays its FIRST option, and that option was a claim.** The
Sales Report's month control offers "All time (Sep 2022 – Sep 2026)" at `value=""`, so generating by
DATE — which leaves `$month` null — left the picker reading "All time" above a report showing a
single day. The figures were right; the control described a filter that was not in force. A "Custom
range · Aug 20, 2026" option is now rendered, selected, when a custom range is driving the report. It
carries the same empty value and is NOT disabled, so submitting untouched still lets the dates drive
and choosing All time still clears them.

**The Sales Report has Daily / Weekly / Monthly / Yearly quick ranges, as a "Period" segmented control
FIRST in the filter bar** (`.period-toggle`, sized level with `.report-select`, full width on phones).
They are plain links carrying only `?period=`, resolved on the server by
`ReportController::periodRange()` (the one definition) as "the current one, up to today" — Weekly is
Monday to today, Monthly the 1st to today, Yearly January 1 to today — anchored on
`SalesHistory::reportableThrough()`, never on the last row of a table. Dates or a month set by the
person beat a `period` carried beside them, the month picker reads "Weekly · Sep 14 – Sep 19" rather
than claiming "All time" (the `<select>` trap above), and an unknown period is a validation error, not
ignored. The report page cannot be rendered by the sqlite suite (its aggregates are MySQL), so
`SalesReportPeriodTest` pins `periodRange()` and the validation; check the page itself against MySQL.

**ATV/ATC, Hourly Sales, Sales by Category and Sales by Staff are all POS-only — `sales_history` has
no transaction, no time-of-day and no cashier to read any of them from.** It's an imported record of
units sold per day; there was nobody signed into anything before this terminal existed, and a row
carries a date, never a time. `ReportController::atvAtc()` (static, pure — see the DashboardKpiTest
entry above) divides the Sales Report's `posTotal`/`totalTransactions` for ATV and
`totalTransactions` by the whole period length (not just days that had a sale) for ATC. The dashboard
carries its own pair, `DashboardController::computeAtv()`/`computeAtc()` — ATV off TODAY's sales,
ATC off the TRAILING 7 DAYS' transaction count divided by 7 — deliberately not the same function,
since the two describe different windows for a different purpose (a KPI tile vs. a report's selected
range). **The admin dashboard's KPI row replaced Expired and Need-to-Return with these two** (2026-09-
22); both stock counters are still shown elsewhere on the same page (the Alerts panel, which reads
`$expiredCount`/`$needReturnCount` independently — do not delete those from `_dashboard-body`'s `@php`
block just because the tiles that used to read them are gone). Hourly Sales/Transaction Volume buckets
`sales.created_at` by hour of day (0–23) over the report's own range; Sales by Category and Sales by
Staff live on the Analytics report — category merges `sales_history` with the till exactly the way
`topProductsBetween()` does (`SalesHistory::salesByCategoryBetween()`), staff is a flat POS-only
group-by since there is no cashier to attribute an imported row to.

**The month picker and the date inputs are mutually exclusive — keep them that way.** The filter form
submits every field it owns, so a month left selected rode along with a later date edit and won on
the server unconditionally: setting the start date to the 21st snapped it back to the 1st and the
date pickers looked broken. Fixed on both sides — the form clears the dates when a month is chosen
and clears the month when a date is edited, and `sales()` drops `$month` when `start_date`/`end_date`
are present (which also covers a hand-edited URL carrying both).

**Date pickers must not offer days that have not happened.** The seeded history runs to the end of
the current month, so `max="{{ $dataEnd }}"` on the report inputs offered future days; they now cap
at `min($dataEnd, today)`. The sales list's two inputs cap at today for the same reason — a sale
cannot have been rung up tomorrow.

**The audit trail's date presets must build dates from LOCAL parts, never `toISOString()`.** That
method converts to UTC first, and the app runs in `Asia/Manila` (UTC+8) — so between midnight and
08:00 local the "Today" button filled both date inputs with **yesterday**, and the audit trail
quietly showed the wrong day. Verified across the clock: wrong at 00:30/03:30/07:30, right from
08:30. The server renders `max="{{ now()->toDateString() }}"` on those inputs from its own local
date, so local is also the only thing that agrees with the rest of the page.

**`Product::$is_running_out` is the one definition of "reorder this", and it is NOT `is_low_stock`.**
`is_low_stock` compares SELLABLE stock — the right question for the till, where expired units cannot
be dispensed. It is the wrong question for a low-stock LIST, where every row is an instruction to
buy more: a product with 301 units that happen to be expired is a clearing problem, not a purchasing
one, and the Expired filter beside it already says so. `is_running_out` adds the second test —
`total_stock <= reorder_level` **and** not holding stock it cannot sell — which on this catalogue
moves **89** products out of low stock (re-measured 2026-09-09: 755 → 666; read 818 → 734 on
2026-09-02, before POS trade sold stock down further), every one of them with zero
sellable units and none with good stock left.

**The Inventory tab, the bell, the toasts, the dashboard panel and the report all read it**, because
the bell's low-stock alert LINKS at the Inventory low-stock filter: a different definition on either
side is the "badge promises 28, list delivers 85" bug again. Verified they agree at 666 against the
sellable rule's 755 (both re-measured 2026-09-09). **The POS grid deliberately still uses `is_low_stock`** — at the register "can I
sell this?" really is the whole question. `total_stock <= 0` still counts as low everywhere: nothing
on the shelf is genuinely out and does need reordering.

**Asserting a product is absent from a list needs a row id, not its name.** Every authenticated page
also renders the bell, which lists that product by name — so `assertDontSee('All Expired')` matches
the bell's copy and proves nothing. `id="product-row-{id}"` is only ever emitted by a rendered row.

**The inventory report reads `is_running_out` too — it is where that rule was worked out, not an
exception to it.** Both tests above were written for this report first, then lifted into the accessor
once the Inventory tab, the bell and the dashboard panel turned out to need the same answer;
`ReportController::inventory()` calls the shared accessor like every other surface, and only the POS
grid still reads `is_low_stock`. Anything you read elsewhere about this rule being "scoped to the
report on purpose" predates that move.

What the report adds on top of the shared rule is presentation, and all of it has to agree with the
rule: `total_stock <= 0` still counts as low (29 products have nothing at all — genuinely out, and
they do need reordering), the filter and the Low Stock KPI call the same closure so the figure
describes the rows on screen, and the row's status badge follows the same order (Out of Stock →
Expired Stock → Low Stock → OK) so it cannot label a row "Low Stock" that the filter just excluded.
For the record from when the second test landed: it removed 41 products that were below their reorder
level with nothing but expired units left, all 41 with zero sellable stock and none with good stock
alongside — clearing those is the job rather than reordering, and every expired-carrying product (84
as of 2026-09-02) is still listed by the Expired filter.

**The inventory report renders the whole catalogue TWICE (screen + print), so per-cell inline
styles are paid for ~5,300 times.** That is how the page reached **7.6 MB**, 3.35 MB of it
`style="..."` attributes and 216 KB of it `onmouseover`/`onmouseout` handlers on every row. Both
tables are now styled from the view's own `<style>` block (`.inv-rep`, `.inv-print`), hover is a
`:hover` rule painting the CELLS, and the status badge resolves once per row into a class + icon +
label: **3.2 MB, a 58% cut**, with identical rendered text under every filter state. Keep new markup
here class-based, and keep `data-search` on each screen row — the filter box walks it and it carries
the SKU, which the table does not show. REMEDI.md "Reports: screen copy vs print copy" has the
measurements.

**Low Stock and Expired Stock have their accents swapped** from where they started: low stock carries
the red (`#ef4444` KPI, `#fee2e2`/`#991b1b` badge), expired the amber (`#f59e0b` KPI,
`#FCEBEB`/`#791F1F` badge). The report's search box no longer offers "status" either — it filters
product and category text only, which is all it ever matched.

**Zero gets its own badge on the report**, graphite `#334155`, checked BEFORE the low-stock branch —
at zero a product is also "low", and "Low Stock" on an empty shelf understates it. Same colour the
bell and toasts use, so out-of-stock reads the same everywhere.

**The inventory report's low-stock view is ordered by stock, worst first** — matching the Inventory
page's own low-stock tab. It listed alphabetically, so the product actually about to run out could
sit on page three. Sorted on `sellable_stock` (300 expired units is not "well stocked"), tie-broken
on `total_stock`, which is the column the table shows.

**"Slow moving" on the Analytics report is a RATE, and the list is capped.** The threshold was a flat
5 units applied to whatever window was chosen, which only reads correctly over the full four years:
over ONE MONTH it asks "did this sell 5 units in 30 days?", and on a small pharmacy's volumes that is
most of the catalogue — 2,400 of 2,638 products flagged, and a **3.25 MB** print page of them.
`SLOW_MOVING_PER_30_DAYS` now scales with the window so the rule means the same thing in any period,
and `SLOW_MOVING_LIST_CAP` (100) bounds what is rendered while `$slowMovingCount` still reports the
true total, which both views print as "the slowest 100 of N". The page is **0.45 MB**.

**The inventory report filters on category plus ONE status.** `expired` was missing for years while
the page still carried an "Expired Stock" KPI, so it reported a non-zero count with no way to see
which products. Filtered in PHP like `low_stock`, because expiry state is a computed accessor over
the loaded batches rather than a column. All three KPIs are taken AFTER filtering, so they describe
the rows on screen — keep it that way, and add any new filter to the audit-log "(filtered)" marker
and the Clear-filters condition as well.

**Status is a single `<select name="status">`, not two checkboxes — and that is a correctness fix, not
a cosmetic one.** The two were independent, so both could be ticked, and that asks for the
INTERSECTION of two sets this report deliberately keeps disjoint: a product below its reorder level
holding nothing but expired units is excluded from low stock on purpose (`Product::is_running_out`),
because clearing it is the job rather than reordering it. Ticking both therefore printed an empty
table under two non-zero KPIs. `ReportController::inventory` resolves `status` to `$lowStockOnly` /
`$expiredOnly`, and **still honours the old `low_stock=1` / `expired=1` parameters** so bookmarks keep
working, with `status` winning where both appear and `low_stock` winning a legacy URL that asks for
both. Covered by `Feature\Reports\InventoryReportTest`.

**`Product::$expired_batches` exists because the report referenced it before anything defined it.**
`reports/inventory.blade.php` had `@if($p->expiredBatches && ...)` in both the screen table and the
print copy; `Product` has `batches` and nothing else, and **Eloquent resolves an unknown relation
name to `null` rather than erroring**, so the guard was permanently false and the per-row "N expired
batches" badge never rendered on either — while the KPI above it counted correctly. If a Blade guard
on a model property never fires, check the model actually defines it.

**Blade will not compile a directive that sits immediately after another one's `@endif`.** Its
statement regex needs a non-word character before the `@`, and `f` is a word character — so
`@endif@if($x)` leaves the second `@if` as literal text while its `@endif` compiles anyway, giving an
unbalanced `endif`. **Neither `php -l` nor `php artisan view:cache` catches it**: `-l` does not read
Blade directives, and `view:cache` writes the compiled PHP without ever executing it, so it reports
success. Only an actual request fails. Separate the directives with whitespace, or build the string
in an `@php` block — which is what the report's two print headings now do.

**Normalise user-supplied ranges with `ReportController::clampRange()`, not `clampEnd()` alone.**
Clamping only the end inverts a future window — `2030-01-01 → 2030-12-31` became start 2030-01-01 /
end today, which the page printed as its heading and the audit trail recorded as fact, with ₱0.00
beneath it. `clampRange()` clamps both ends then orders them, so the range queried, displayed and
logged are always identical. Range inputs are validated (`nullable|date`, `date_format:Y-m`) —
without that, `?start_date=banana` reached `Carbon::parse()` as a 500.

### `product_sku` is a foreign key that isn't
**Four tables key on `products.sku` as a plain string with no FK** — `sales_history`,
`demand_forecasts`, `sales_forecasts` and `inventory_receipts`. Nothing in the database stops a
rename or a delete from orphaning them, so every product mutation has to do that work by hand.

`inventory_receipts` is the one that is easy to miss: `product_sku` / `qty` / `received_at`, seeded
by `InventoryReceiptSeeder`, and a **fourth stock table unrelated to `product_batches`** — it is
purchase history, not shelf stock, and nothing about `total_stock` reads it. Its only consumer is
`DashboardController`, as a *fallback* ranking: when both forecast-derived product lists come back
empty it sets `demandFromHistory` and ranks by summed receipt quantity instead. That path degrades
into an empty panel rather than an error, which is why an empty table here goes unnoticed.

**A SKU rename must re-point the history.** `sales_history`, `demand_forecasts` and `sales_forecasts`
key on `product_sku` as a plain string with **no foreign key**, so editing a SKU silently orphaned
everything joined to it — ₱909,358.82 of lifetime revenue vanished from every report on one product,
and its 6 forecast rows became unjoinable. `ProductController::update` re-points **all four** tables in
the same transaction, audits the move (the entry names the per-table counts), and clears the revenue
caches (the `Product::saved` hook only watches `selling_price`). The list lives in
`ProductController::SKU_KEYED_TABLES` — add to that constant, never to the loop. (`destroy()` no
longer touches these tables: it archives.) Verified over HTTP on YAKULT 5S: 218 receipt rows and 1 history row moved with the
rename, no orphans left behind.

**`destroy()` archives, so it neither refuses on history nor cleans up after itself.** It used to
refuse on `sale_items` (a real RESTRICT), refuse on `sales_history` (no FK — the guard covered 29
products while **2,555** carried history, and deleting one silently removed ₱7.74M of revenue on the
worst case) and hand-delete rows from the other four SKU-keyed tables. None of that applies to a
row that stays: the history keeps pointing at a product that still exists, and stays in every
revenue report. Only a `forceDelete()` would need that work again — nothing in the app calls one
except the dedupe command above.

**A price edit rewrites historical revenue.** `sales_history` stores units only, so every revenue
figure is `quantity_sold * products.selling_price` — changing a price changes every past month.
`AppServiceProvider` clears the `SalesHistory` + `SalesForecastService` caches on `Product::saved`
when `wasChanged('selling_price')`, and on `Product::forceDeleted` (history joins on `sku`, no FK) —
not on an archive, which changes no revenue. Keep
the `wasChanged` gate: `forgetCaches()` retires ~3.5s aggregates, so ungated it would make every
routine product edit pay for a rebuild. **Verify these hooks over HTTP.** `tinker --execute` gave
contradictory results across identical runs while the HTTP path was stable and correct, so a tinker
result is not evidence either way.

### Forecasting pipeline
Two independent pipelines, each PHP → Python subprocess → CSV → upsert into MySQL:

| Concern | Command | Script | Table | Service |
|---|---|---|---|---|
| Demand ("how much to buy") | `forecast:generate` | `resources/python/generate_forecasts.py` | `demand_forecasts` | `DemandForecastService` |
| Sales units + revenue | `sales-forecast:generate` | `resources/python/generate_sales_forecast.py` | `sales_forecasts` | `SalesForecastService` |

**The pipelines stayed independent, but the two PAGES merged into one, `/forecast`, on 2026-09-22.**
They used to be separate nav entries built from the same sales data and, on the per-product page, the
same forecast for one SKU shown two different places — `SalesForecastController`'s own docblock used
to argue for keeping them apart ("to avoid two pages showing the same per-product forecast"), which
stopped being the right call the moment the ask became "show both for the product I clicked, together".
`/sales-forecast` still resolves — `SalesForecastController::index()` is now a one-line
`redirect()->route('forecast.index')`, so an old bookmark or a link saved somewhere does not 404 — but
it renders nothing of its own any more; `resources/views/sales_forecast/` is deleted. `forecast.index`
now opens with the store-wide units/revenue trend that used to be the whole of `/sales-forecast`
(`SalesForecastService::overallMonthlyTrend()`, unchanged), then two "top 5" charts side by side —
`DemandForecastService::topDemandSeries()` (forecast **units**, unchanged) and the new
`SalesForecastService::topSalesForecastSeries()` (forecast **revenue** over the same actionable
horizon, same "sum over the horizon, not a spiky single month" ranking). They deliberately answer
different questions — what to reorder vs. what earns — so they name different products, not the same
ranking twice. Below that is the same searchable per-product table as before, still linking to
`forecast.show`.

`forecast.show` (`/forecast/{product}`) now renders BOTH forecasts for that one SKU: the existing
Demand Forecast chart (units, from `demand_forecasts`) first, then a new "Sales Forecast" card (KPIs +
a revenue trend chart with confidence band, from `sales_forecasts`) below it, then Seasonal Pattern and
the forecast-detail table as before. `DemandForecastController::show()` still 404s only on an empty
DEMAND forecast — a product can have one without a sales forecast (the two scripts search their own
SARIMA candidates independently), so the Sales Forecast card guards on `$hasSalesForecast` and prints
"No sales forecast available for this product yet." rather than assuming the second half exists.
`SalesForecastService::forProduct($sku)` is new (`DemandForecastService::forProduct()` had no revenue
twin) — actual revenue is `quantity_sold * products.selling_price` at the CURRENT price, same
"a price edit rewrites historical revenue" convention as every other revenue figure in the app, scoped
to one SKU so no `STRAIGHT_JOIN` is needed the way the store-wide aggregate does.

**The dashed forecast is stitched to the solid actual at `$joinMonth`, and that month must be
STRICTLY BEFORE the first forecast month.** All three forecast series (value, lower, upper) carry the
ACTUAL value there, so the dashed line starts exactly where the solid one is and the confidence band
pinches to zero width before opening out. The old code tested `$forecastByMonth->has($m)` first and
fell back to `$m === $lastActualMonth` — which worked only while the horizon began strictly after the
last actual. Once it opened on the current month those two coincided, the join branch became
unreachable, and the two lines rendered as separate strokes: on GLUMET XR the actual ended at 90 while
the dashed line began at 252.6 in the same column, and the band opened at full width (169.82–335.38)
instead of from a point. Test `$joinMonth` BEFORE the forecast lookup, never after.

**The forecast half of the detail chart is shaded** by the `forecastRegion` Chart.js plugin in
`forecast/show.blade.php`. The dashed line already says "predicted", but a dash pattern is easy to
miss at a glance and invisible in print. It draws in `beforeDatasetsDraw` so the tint sits BEHIND the
lines and the confidence band instead of washing them out, is clipped to `chartArea` so it cannot
bleed over the axes, and opens half a step left of the first forecast-only point so the boundary
falls between the last actual and the first prediction rather than through a marker. The boundary is
the first index where the ACTUAL series runs out — not where forecast rows begin — because the
current month legitimately carries both.

**`App\Support\ForecastHorizon` is the one definition of when a forecast becomes actionable.** The
boundary used to exist three times over: `scopeNextMonthOnly()` on both forecast models (dead code,
never called) plus two hand-rolled copies of the same Carbon expression in the service and the detail
view. The scopes could not be reused by either live caller — they filter a QUERY, while both call
sites filter an already-loaded collection — so they were dead weight that merely looked authoritative.
Both scopes are gone, along with `scopeSixMonthTotals()` (also dead in both models). The service
passes `firstActionableMonth` through to the view rather than letting it recompute, so the list and
the detail page cannot disagree about which month counts as next. Note `addMonthNoOverflow()`, not
`addMonth()`: on the 31st the latter rolls a short month over and skips one entirely.

**Forecasts are emitted in WHOLE UNITS.** Demand is integral — nobody dispenses 0.4 of a box — so a
forecast written to two decimals was scoring an error for its own formatting: 2.47 against an actual
of 2 read as a 23% miss. 12,387 of 15,474 rows carried decimals, 2,339 of them a fraction between 0
and 1. `_clamp_rows()` rounds the value and both CI bounds, then re-orders the bounds so rounding
cannot invert the band. Measured effect: sMAPE 86.8% → 74.1%.

**As of 2026-09-09, both pipelines fit ONLY SARIMA models, at the user's explicit request, replacing
the holdout-scored cross-model cascade described below in git history.** `generate_forecasts.py` and
`generate_sales_forecast.py` no longer contain `_pick_by_holdout()`, `_selection_error()`,
`_candidates()`, Holt-Winters, plain ARIMA-as-a-separate-method, the seasonal-naive/Holt-Winters/
SARIMA median ensemble, Croston SBA, or the moving-average floor. **This alone was a measured
regression** (MAE 2.28→2.42, sMAPE 30.1%→33.3%, see "Re-measured on the rebuilt sales record" below)
because a single order forced onto every product fits some of them badly — most of all
SARIMA(0,1,1)(0,1,1,12), the "airline" order, which needs a full seasonal cycle to estimate its
seasonal MA term and roughly half this catalogue does not have one.

**So the order itself is now chosen per product, from `SARIMA_CANDIDATES` — four orders, still all
SARIMA, never a different model family.** Two are seasonal (`(0,1,1)(0,1,1,12)` and
`(1,1,1)(0,1,1,12)`), two are the same equation with `P=D=Q=0` and `s` dropped — i.e. plain
`ARIMA(p,d,q)` — for a series too short or irregular to support a 12-month seasonal term at all.
`_pick_sarima_order()` fits every candidate on a 3-month holdout and scores with **MAPE first, sMAPE
as fallback** — the opposite order from the retired cascade's `(MAE, sMAPE)` criterion, and safe here
specifically because selection never leaves the SARIMA family, so there is no risk of picking a model
tuned to a metric nobody reports. The winning order is refit on the full series; if that refit fails
(rare), every candidate is tried directly, richest first. A product where nothing in
`SARIMA_CANDIDATES` produces a usable fit gets no forecast — there is still no fallback outside the
family. `demand_forecasts`/`sales_forecasts.method` reads `sarima` regardless of which order won, so
the "Model" column on both forecast pages stays one row; the order chosen per product is not
persisted anywhere, only its output. Re-run both `forecast:generate` and `sales-forecast:generate`
after touching either script, and re-derive the accuracy numbers below rather than trusting them.

The retired cascade, for context (no longer live — kept here only so a future re-introduction has the
prior measurements to build on): `croston_sba` existed because Croston is the textbook estimator for
intermittent demand, splitting the series into how MUCH is bought and how OFTEN and forecasting the
rate — genuinely applicable, since **36% of product-months had no sale**. It moved the headline almost
nowhere (MAE 5.16 → 5.15, sMAPE 74.1% → 74.4%) because `moving_average` was already doing the same
job for a zero-heavy series, but won 190 of 2,576 products on merit. Selection itself existed because
the old cascade took the richest model a series could support and kept it if merely plausible, which
put the SARIMA ensemble on 2,346 of 2,576 products including short series it was the wrong tool for.
`_pick_by_holdout()` fit every candidate on a truncated series and scored each with `(MAE, sMAPE)` —
an order settled by measurement (MAE-first: 8.12/9.63/122.6%/101.5%; sMAPE-first: 8.16/9.65/123.1%/
98.1% — MAE-first won three of four).

**MAPE is bounded by how noisy the demand is, not by the model.** It falls hard with volume —
currently **17.3%** for products selling 100+ units a month against **65.8%** for those selling 5–20 —
because being one unit out on a month that sold two is a 50% error however good the fit.

The seed data was smoothed on 2026-08-25 to a realistic dispersion (see REMEDI.md "The demand series
were smoothed"), which took the headline from 122.5% to **59.4%**. That was a fix to over-dispersed
generated data — CV 1.04 where real retail sits at 0.2–0.4 — **not** a way of making the number look
better, and the distinction matters: these metrics now describe performance on realistic demand, which
is a different claim from performance on the original series. Never edit sales history to move a
metric; the number is only worth having while it is earned.
**`App\Support\ForecastGrade` is the one definition of Normal / Acceptable / Not acceptable.** Bands
are the conventional MAPE reading (≤20 / ≤50 / >50) applied to whichever measure is DEFINED — sMAPE
where MAPE is not, with its own higher bands (≤40 / ≤90), because sMAPE is bounded at 200% and sits
structurally higher on intermittent demand; reading it against MAPE's thresholds would condemn every
sporadic product for having sold nothing. A product with no score is **Not rated**, never a pass.
The badge, the list column and the index distribution all call this one grader, so a product cannot
read Normal on one screen and Acceptable on another.

**As of 2026-09-09 both forecast scripts fit only SARIMA models, with the ORDER chosen per product —
see "Forecasting pipeline" below — and the figures here trace three points in that day's history.**
MAE: 8.43 (original catalogue) → 2.28 (sales record rebuilt to 450 active lines, still on the
cross-model cascade) → 2.42 (cascade replaced with ONE forced order,
`SARIMA(0,1,1)(0,1,1,12)`) → **2.31** (that one order replaced with a per-product search over
`SARIMA_CANDIDATES`, still SARIMA-only). RMSE followed the same path: 10.06 → 2.70 → 2.89 → **2.75**.
sMAPE: 30.1% → 33.3% → **31.8%**. The order search recovered most, not all, of what forcing one order
had cost — expected, since it still never leaves the SARIMA family the cascade used to range across.
The grades then read **Normal 721 / Acceptable 233 / Not acceptable 293** over **1,247** scored
products, and MAPE **87.1%** across the **501** where it is defined.

**All of the above describes the GENERATED record, and is superseded as of 2026-09-23** — those runs
are kept because they isolate what each MODEL change was worth, which the numbers below cannot. On
the real transaction export that replaced it (see "Two sales tables"), the same SARIMA-only pipeline
scores **2,599 products: MAE 1.89 / RMSE 2.34, MAPE 72.8% across the 1,819 where it is defined,
sMAPE 102.4%**, grading **Normal 581 / Acceptable 644 / Not acceptable 1,374**.

**Read that as two true things at once rather than a regression.** MAE and RMSE IMPROVED (2.31 → 1.89,
2.75 → 2.34) and MAPE both improved and more than tripled its coverage (501 → 1,819 products). sMAPE
and the grade split got much worse. Both follow from the same property: the export spreads 189,514
units over 2,603 products at 1.75 units per product-day, where the generator concentrated volume on
450 active lines. Smaller absolute numbers are easier to be close to and harder to be close to in
PERCENTAGE terms — being one unit out on a month that sold two is a 50% error however good the fit,
which is exactly why `ForecastGrade` falls back to sMAPE and why sMAPE sits structurally higher on
intermittent demand. **Do not "fix" this by reshaping the data**: the standing rule that a metric is
only worth having while it is earned applies with more force to a real record than a generated one.

**What moved the FIRST jump (8.43 → 2.28) was the size of the SHELF, not the model.** A first pass
spread the shop's units across all 2,638 catalogue lines, leaving the median product at 0.73/month
and 1,410 products grading "Not acceptable". The generator now stocks **450 active lines**
(`ACTIVE_SKUS`) on a flatter curve (`ZIPF_EXP = 0.75`), which is what a small pharmacy actually keeps;
the units, revenue and transaction count are unchanged. See REMEDI.md "The sales record was rebuilt"
for that comparison. The SECOND and THIRD jumps are both model changes described above and in
"Forecasting pipeline" — dropping the cross-model cascade, then recovering part of the loss by
searching SARIMA orders instead of forcing one.

**Forecast accuracy is measured, not asserted.** `forecast:generate` refits each product's OWN
SARIMA model (whichever order won that product's holdout) on its series minus the last
`HOLDOUT_MONTHS` (3) and scores the result against the months it was not allowed to see, writing one
row per product to `forecast_accuracy` (`--metrics` CSV → `importMetrics()`). Scoring the model
actually in use is the point; a number from some other model would describe a forecast nobody is
looking at. Currently **2,599 products scored: MAE 1.89 / RMSE 2.34** averaged across products
(measured 2026-09-23, on the real transaction export -- the 1,247 / 2.31 / 2.75 figures it replaced
were the generated record, 2026-09-09).

**MAPE is nullable and must stay nullable.** It divides by the actual, so a holdout where the product
sold nothing has no defined percentage error — and that is the common case here, not an edge case:
**780 of 2,599** products have no non-zero month to measure against (it was 746 of 1,247 on the
generated record -- proportionally far better on the real export, which is why MAPE coverage tripled). Null means "not measurable",
never 0.0, and the views render it as "—" with sMAPE beside it rather than as a perfect score. MAPE
also runs high on intermittent demand by construction (one unit out on a month that sold two is 50%),
which is why MAE and sMAPE are shown next to it. Averages are taken ACROSS PRODUCTS, not pooled over
every residual, so a few high-volume products cannot set the headline.

`forecast_accuracy` keys on `product_sku` with no FK like the rest, so it is in
`ProductController::SKU_KEYED_TABLES` — a rename re-points five tables now, verified over HTTP.

**"Next month" on the forecast pages means the next month, not this one.** The horizon OPENS on the
current month — training stops at the last complete month, so the first forecast row is a nowcast of
the month in progress. Both the list column and the detail card took that row, so "Next month
forecast" quoted a month already 80% elapsed, and "Forecast total (6-month)" counted it as if it
were still ahead. Both now start at `now()->addMonthNoOverflow()->startOfMonth()`; the label's month
count is derived, so it reads "(5-month)" when only five are still actionable. **The chart
deliberately keeps the current-month row** — the model's nowcast read against the partial actual is
worth seeing; it is only the KPIs that must not count it.

**The forecast search is the ninth `likeTerm()` site.** `DemandForecastService::allProductsSummary()`
interpolated the term straight into `LIKE "%{$search}%"`, so a bare `%` returned the whole catalogue
(51 rows against the 9 products that actually contain one). The service carries its own private copy
because `Controller::likeTerm()` is `protected` — keep the two in step.

**The detail chart shows `CHART_HISTORY_MONTHS` (12) of actuals, not the whole series.** Four years
squeezed the recent months — the ones that inform a reorder — into a few pixels. The Seasonal Pattern
panel below still reads the FULL history, so nothing is lost by trimming the chart.

**Both Python scripts read `sales_history` UNION the terminal's own sales.** They read the imported
record alone, which was fine while it ran to the present — and wrong the moment it was cut back to the
day before the till went live. `monthly_series()` drops an incomplete trailing month, so training
ended in JULY while the shop had been trading into September, and the horizon opened on **2026-08**,
a month already past: the forecasting-the-past fault this file warns about, reintroduced through the
DATA rather than the code. Both queries now `UNION ALL` `sale_items` joined through `products.sku` —
the only place `sale_items.product_id` and `sales_history.product_sku` meet — and
`monthly_series()` aggregates by (product, month) anyway, so the two sources sum rather than collide.
After regenerating: horizon **2026-09 .. 2027-02, no month in the past**. **If you ever narrow the
window `sales_history` covers, re-run both pipelines and check
`MIN(forecast_date) >= the current month`.**

**Every product must forecast the SAME forward window.** The horizon is generated from
`series.index[-1]`, and each product's series used to end at its own last month with a sale — so a
product that stopped selling a year ago got a "forecast" covering months that had already happened.
Measured before the fix: **320 of 2,517 products had a horizon entirely in the past** and there were
**39 different horizon start months**. ACICLOVIR 800MG last sold 2025-10 and was forecast for
2025-11..2026-04, drawn on its own chart as a dashed line and confidence band sitting in the *middle*
of the history with nothing ahead of today — and its "Next month forecast" card was quoting November
2025. Both scripts now pad each series to the shared last month (`series_end`) with zeros before
fitting. After: 0 products forecasting the past, 1 distinct horizon start, and 62 more products
modelled at all because padding gave them enough months to fit.

**The detail chart's actual series is one continuous run of COMPLETE months, ending at a month
shared by every product.** `monthlySeriesTo()` builds it from the product's first sale to
`lastCompleteDataMonth()`, filling absent months with 0. Three separate faults came out of not doing
that, and all three looked like chart bugs:

- **Sparse keys**: the `GROUP BY month` returns only months with rows, and the axis was built from
  those keys — so 2025-09 sat beside 2026-06 and the axis was not a time axis at all.
- **Filling only the interior**: months between a product's last sale and the start of the forecast
  vanished from the axis. 15 of 40 products sampled jumped straight from their last sale to the first
  forecast month, one from 2024-06 to 2026-08 in a single step.
- **The incomplete trailing month**: history stops on the 15th, so the final point was half a month.
  Of the 1,043 products selling in both July and August, **631 (60%)** showed a fall of more than 30%
  that was purely the calendar — GLUMET XR appeared to drop from 235 units to 90.

All three are now impossible by construction: every chart has the same axis length and the same join
month. The Python `monthly_series()` applies the identical rules before fitting, so the chart draws
the series the model was actually trained on — keep the two in step. The KPI is labelled **"Last
complete month"** because that is what it now is, and the chart subtitle says the month in progress
is excluded rather than leaving the omission silent.
**Unit axes count whole units (2026-10-01, at the user's request).** On a product selling about 1 a quarter the detail chart's axis read 0, 0.1 … 1.0 — Chart.js picks fine steps when the maximum is 1 — which read as "less than 1 unit". The unit axes on both forecast pages carry `ticks: { precision: 0 }`, and the peso axes print amounts under ₱1,000 as pesos (₱20, not ₱0.02k). Such a product's FORECAST is genuinely 0: it averages ~0.3 a month and forecasts are whole units.
**The store-wide forecast is whole units per month too (same day).** `sales_forecasts.forecast_units` is stored to two decimals per product, so the summed month read 73,783.83: the chart rounded it per month while the "Forecast total, units (3-month)" card summed the raw figures and rounded once — 73,784 + 75,107 + 75,440 = 224,331 on the chart against 224,330 on the card. `SalesForecastService::overallMonthlyTrend()` now rounds the three unit series per month, so every reader adds the same numbers; `CACHE_KEY` became `sales_forecast_overall_trend_v2` so a payload cached in the old shape is not served for its 6 hours after a deploy.
**A zero forecast is usually correct, not a bug.** Once the zeros are visible the reason is plain:
the 14 products forecasting all-zero sell in single digits across scattered months and most sold
nothing at all in the last three (the busiest managed 144 units over 17 months). The demo history is
not a busy shop — check the product's actual line before treating a zero as a modelling failure.

**`monthly_series()` drops an incomplete trailing month, in BOTH scripts.** These models are fitted
on calendar months, so a month holding only part of its days is not a weak month — it is a partial
one, and feeding it in as a real observation makes every method in the cascade read a collapse and
forecast forward from it. After the seeded history was trimmed to 2026-08-15 the tail read Jun 42,649
units / Jul 46,477 / **Aug 22,239** — nothing happened in August except the calendar. The guard is
judged on the GLOBAL last date, not per product: an incomplete tail is a property of when the data
stops, and doing it per product would drop a real final month for everything that did not sell on the
last day. **This matters for any month-to-date run, trimmed history or not** — regenerating on the 3rd
would otherwise train on three days. You can see it working in the output: forecasting now starts at
the first month AFTER the last complete one.

Both shell out via Symfony `Process` (30-minute timeout), write a CSV to `storage/app/forecasts/`,
upsert on `(product_sku, forecast_date)`, then **delete rows the run did not refresh**
(`generated_at < now`) — without that, a moved forecast window leaves stale rows that shadow the
fresh ones. Forecasts key on `product_sku`, so every join to `products` goes through `products.sku`.
The Python scripts read DB credentials themselves rather than through Laravel config, so DB changes
made only in Laravel config break them silently. `db_credentials()` (in both scripts) takes the
`--env-path` FILE first and falls back per key to the process ENVIRONMENT — the file wins where it
exists, so a local XAMPP run is unchanged, while a container has no `.env` at all and would otherwise
have no credentials. `--env-path` is therefore no longer required for `--source=mysql`; a run missing
`DB_HOST`/`DB_DATABASE` in both places exits saying which. Model selection is by history length plus a
plausibility check: ≥36 months → median ensemble of airline SARIMA / seasonal Holt-Winters /
seasonal naive, falling back to plain SARIMA; ≥24 → ARIMA(1,1,1); ≥12 → damped exponential
smoothing; ≥3 → moving average; below that, no forecast rows.

### Notifications: one service, one cache
`App\Services\AlertService` is the single definition of "an open alert" — five inventory kinds (low
stock, expiring, expired, returns due, returns missed). The topbar badge, its dropdown, the polled
`GET /alerts` feed and the `/notifications` page all read it, so the badge can never disagree with
the list it opens. `AppServiceProvider`'s `layouts.app` view composer pushes `topbarAlertCount` /
`topbarAlertItems` / `topbarAlerts` / `topbarActivity` into every authenticated page. The dashboards
deliberately build their own Alerts panels from batches they already loaded — keep the two lists in
step (same kinds, same thresholds, same links).

**An alert's link must show the set the alert counted.** Two expiring horizons are deliberate —
`EXPIRY_SOON_DAYS` (30) for the dashboards/bell, `is_expiring_soon` (90) for the Inventory tab — but
the bell linked straight at the tab, so "28 batches expire within 30 days" opened 85 products across
9 pages. `InventoryController` honours a `days` parameter (clamped to those two values only), and every link
that quotes the 30-day figure goes through **`ProductBatch::expiringSoonUrl()`** — the bell plus nine
across the two dashboards and `_dashboard-actions`. Use that helper rather than writing
`['filter' => 'expiring']` by hand. If you add an alert whose threshold differs from its destination
filter, thread the threshold through the link the same way.

**Invalidate a cache from every direction its data depends on.** `sidebar_categories` is
`Category::withCount('products')` but only *category* writes cleared it, so creating/deleting/moving a
product left the sidebar counts stale for up to its 6-hour TTL. `AppServiceProvider` now clears it on
`Product::saved`/`deleted` too — hooked on the model, not in the controller, so imports, seeders and
tinker are covered as well. Ask what the cached payload actually reads, not what it is named after.

**Never cache an absolute URL.** `AlertService`'s payload is cached and shared by every user, so
`route()`'s default absolute output froze whichever host warmed it — warm from `127.0.0.1`, read from
`localhost`, and every notification links to the wrong origin, so the session cookie isn't sent and
the user appears logged out. Use `route($name, $params, false)` for anything that goes into a cache.
Live-rendered links (the Inventory filter tabs) stay absolute.

**Keep the dashboard Alerts panel numerically identical to the bell.** The dashboards build their own panels from batches they already loaded, so every count is duplicated logic. "Return window open" showed the medicine-only tally (26) where the bell and the Inventory filter said 78 — use `ProductBatch::is_returnable` for anything labelled generically, and keep `$returnStats` for the per-category Medicine Returns card. Also: inside an `@php` block the body is raw PHP, so a Blade comment there is a parse error, not a comment.

**Derive expiry state from the accessors, never from raw date comparisons.** `is_expired` counts the
expiry date itself as expired, so "expiring soon" must be `! is_expired && <= horizon` — the shape
`is_expiring_soon` and the Inventory filters already use. `AlertService` and `DashboardController`
each hand-rolled `between($today, $cutoff)` instead, which double-listed a batch expiring today as
both kinds and left the dashboard reporting 77 expired against the bell's 79. Both now gate on
`is_expired`. (An `expired` + `need_to_return` overlap is fine and intended — expired non-pharma
stock is still returnable.) REMEDI.md "Two status scales" has the reproduction.

Two caches, both `TTL_SECONDS` (30s, matching the bell's poll interval — polling faster only
re-serves the same payload):

- `topbar_alerts:<perKind>` — `payload()`, capped per kind by `PER_KIND` (bell) or `PAGE_PER_KIND`
  (`/notifications`).
- `topbar_activity` — `activity()`, built from the audit trail. **Admin-only**, and kept out of
  `payload()` on purpose: that key is shared by every signed-in user, so role-dependent rows in it
  would serve a staff account whatever an admin cached first. `AlertController` and the view composer
  both gate it on `isAdmin()`.

**Anything that moves stock must invalidate this.** `AppServiceProvider` hangs `saved`/`deleted`
hooks on `Product` and `ProductBatch` that call `AlertService::forget()` (which clears every per-kind
key plus `topbar_activity`); `PosController::checkout` calls it explicitly because `decrement()`
fires no model events; `AuditTrail::log()` forgets `topbar_activity` on every write. The client-side
`window.remediRefreshAlerts()` hook is not a substitute — re-fetching a cache nobody cleared returns
the same rows.

**The alert toasts are a fourth reader of the same payload.** `layouts/app.blade.php` renders a
bottom-right card from `$topbarAlertItems` — the same rows the bell lists — filtered to three kinds:
`low_stock`, `expiring`, `need_to_return`. It costs no query and cannot disagree with the badge,
which is the same rule the dashboard Alerts panel follows. The seed is a
`<script type="application/json">` block (hex-escaped: these are product names), not data
attributes.

**Up to five cards stand together**, staggered 140ms apart so the stack reads as building rather
than appearing. Each names an individual product ("JUST SOLD OUT — Out of stock · reorder at 5") and
carries its onset, rather than summarising a kind. Every card owns its own 5s countdown started when
it actually appears — a shared timer would give the last of five only 5s minus its stagger, and a
live pop landing beside a 4s-old card would inherit its remaining second. `MAX_VISIBLE` (5) caps the
stack, dropping the oldest; past five it walks up the page and covers the thing it is reporting on.

**The chime fires once per BATCH**, not per card. Five chimes 140ms apart is an alarm. (This was
briefly per-card while the stack was a one-at-a-time queue, where cards were five seconds apart —
if the presentation ever changes again, the chime rule has to be re-derived from the spacing.)

**Two ways in, one queue.** The GREETING plays the alerts that were open when the page rendered,
once per browser session. LIVE pops are queued from the bell's poll. Both build the same card.

**The bell is live by POLLING, not broadcasting — decided 2026-09-27.** A note asked for Laravel
Broadcasting/Echo; it was rejected on purpose: Vercel's serverless functions cannot hold a websocket,
so it would need Pusher (an account and keys) or a second always-on Railway service, for a gain of a
few seconds at a pharmacy counter. What made it LOOK like "only after a refresh" was that **the POS
checkout never told the bell** — every confirm-dialog action called `window.remediRefreshAlerts()`,
the till did not, so a sale that emptied a shelf waited out the poll. Now: (1) checkout calls it;
(2) `remediRefreshAlerts()` also posts to a `BroadcastChannel('remedi-alerts')`, so every other tab
of the browser refreshes at once (the listener calls `refresh()` directly, never the wrapper, so a
message cannot echo — verified: one broadcast, exactly one poll); a HIDDEN tab ignores it, since
`visibilitychange` already refreshes on return; (3) `POLL_MS` is 15s, HALF `TTL_SECONDS` (30s),
because every stock change clears the cache (`forget()`) — the TTL only bounds time-driven changes,
while the poll bounds how long a change on ANOTHER machine takes to arrive, and every other poll hits
the cache the first one warmed. Do not raise `TTL_SECONDS` to match, or lower it: the rebuild hydrates
every batch. `Feature\Alerts\LiveAlertTest` pins the server half (the next poll after a checkout
already carries the new low-stock row; `/alerts` is `no-store`).

**The live watch rides the bell's existing poll; it must never open its own.**
`window.dispatchEvent(new CustomEvent('remedi:alerts', {detail: data}))` fires from the bell's fetch
handler and the toast module listens. A second loop would double the request rate against a 30s
`TTL_SECONDS` and could still disagree with the badge by an interval.

**A card is raised for a NEW ITEM ID, never for a kind whose total moved.** A summary card ("Low
stock alert — 12 products") cannot say which product, cannot carry an onset, and cannot link at
anything but a filter, so it is the badge restated rather than a notification.

**`AlertService` selects each kind's item slice by RECENCY, not severity — this is what makes the
pops real-time, and it overrides an earlier deliberate decision.** Selection used to take the three
deepest below their reorder line, the three soonest to expire. Severity is STABLE, so the same three
worst products won the slice every poll: a product that dropped below its line today never entered
the list, and because a card is only raised for an item id not seen before, **its alert never fired
at all**. Nothing was broken — the news was never selected. Recency is also right for the
calendar-driven kinds, where it looks backwards at first glance: the batch that entered the 30-day
window this morning is news, while the one expiring on Friday entered it a month ago and has been
reported every day since. The per-kind totals in `alerts` are untouched, the "View all N" links still
reach everything, and **the Inventory page still ranks by severity** — that is where urgency belongs.
The `$recent*` slices in `payload()` are the only thing that changed.

**The low-stock card says "Out of stock" at zero** rather than "0 PCS left", and carries its own
colour: `cls` becomes `is-out` (graphite `#334155`) instead of `is-low` (amber). It stays inside the
`low_stock` KIND — the counts, the tabs and the Inventory filter all treat it as one, and splitting
the kind would double-count the totals. Only the colour forks. **Deliberately off the amber-to-red
warning ramp entirely.** Rose was tried first and read as a variant of the `#dc2626` red that expired
stock owns, which is the one thing it must not look like: expired is a shelf to CLEAR, empty is a
shelf to REFILL. A neutral dark says "there is nothing here" instead of competing for a rung on the
severity ladder — an absence, not a louder warning. Kept high-contrast (slate-700 on slate-200) so it
reads as deliberate rather than as a disabled row. **The legend is defined in five places and they must agree** — `.topbar-bell-row.is-out`
(border + icon, two rules), `.remedi-toast.is-out`, `.notif-row.is-out:hover::before` and
`.dot.is-out`. The notifications TAB dot stays amber, because the tab is the whole `low_stock` kind.

**READS ARE NOT NEWS — `activity()` excludes `Viewed`.** `Viewed` is written on every report open,
including the same report twice while a filter is adjusted, and it reached **877 of 1,426 audit rows
(62%)**. `activity()` takes the newest few with no notion of importance, so all six bell slots read
"New report generated" and nothing that CHANGED anything was ever selected — a batch addition and a
live sale sat in the same window unseen. Nothing downstream was broken, which is exactly why it
looked like the notification system ignored batches. Filtered in SQL, so the window is the newest N
CHANGES rather than the survivors of one full of page views. The audit trail still records every view;
that is its job, and it is a different surface.

**`AlertService::ACCOUNT_KIND` marks an account CHANGE, and it is what the toasts watch.** Added,
updated, deleted, activated, deactivated — rare, deliberate, usually someone else's doing, which is
the shape of thing a pop-up is for. **Sign-ins deliberately keep the generic `activity` kind**: a card
every time anybody logs in makes the stack useless by lunchtime. Nothing filters the bell on `kind` —
its tabs read `group` — so the split costs nothing there. Each event is also NAMED (New user added /
User account updated / User account deleted / Account activated / Account deactivated); they were all
"Account updated" before, including deletions.

**`$isAccount` must match how the row was actually WRITTEN.** It looked for the string `'user
account'`, and `UserController` writes status changes as `"Account deactivated: Emman"` — so the one
thing that routinely happens to an account was filed under Updates as a generic "Record updated".
Same shape as the audit filter offering `Create` while every row says `Created`: a string that never
matched the data it was written for. If you add an audit message, check it against this predicate.

**Merging two newest-first lists APPENDS; it does not interleave.** The toast seed merges
`$topbarAlertItems` with `$topbarActivity`, and account rows landed at the END however recent they
were — the greeting takes the first `MAX_VISIBLE`, so the card was in the seed and still never
appeared. Sort the merged collection on `sort_at`, the onset stamp both sides carry. The bell's own
feed already did this; the toast seed did not.

**Live pops read `items` AND `activity`.** `activity` is its own key on the polled payload, not part
of `items`, so watching only `items` meant an account change could be seeded into the greeting yet
never pop live.

**Account rows link at `/users`, not the audit trail, and carry an action pill.** The trail is the
record of what happened; `/users` is where you act on it, and a notification that someone was
deactivated is only useful if it lands you there. **The pill is a `<span>`, not a `<button>`** — it
sits inside the row's own `<a>`, and interactive content may not nest inside an anchor; browsers
recover by splitting the anchor, which breaks the row. The row already carries the href. It is
rendered in **three places that must agree** — the Blade row (`.bell-action`), the bell's JS
`render()`, and the toast card (`.remedi-toast__action`) — and the JS one is the easy miss: get it wrong and the pill is there on load and gone on the
first poll, the same failure `data-when` had.

**All of this is ADMIN-ONLY BY CONSTRUCTION, not by a second gate.** These rows come from
`activity()`, which the view composer and `AlertController` already resolve to `[]` for staff, and the
toast seed merges them in a per-request render — never into `payload()`'s cache, which every
signed-in user shares. Keep it that way; a role-dependent row in that key serves a staff account
whatever an admin cached first.

**Four stock kinds: `low_stock`, `expiring`, `expired`, `need_to_return`.** `fail_to_return` is left to the
bell — a missed return window is a standing regret, not something to interrupt anyone about.

**The greeting is most-recent-first, except expired.** `AlertService` already sorts the payload
newest-first, so the queue takes from the front. Expired stock is the one exception: those units are
on the shelf now and have to come off, so they lead the queue and keep being raised on every open
**until someone has actually clicked one** — read state, via `window.remediAlertReads`, the same
per-device store the bell paints from. Everything else is a standing condition the bell will still
be holding tomorrow, so only the newest earn an interruption.

**The container renders even when nothing is wrong.** It is the mount point pops are appended to.
The seed carries `kinds` (the watched list, so the live watch knows what to care about) and `items`.

**"Freshly opened" is a browser-session fact, so the greeting's gate is `sessionStorage`, not a
session flash.** The app does full page loads on every navigation, so a purely server-side flag
either fires once at login (missing someone who reopened a closed tab) or on every page view. The
key is per user id and a fresh sign-in clears it — `data-fresh-login` carries
`session('remedi.just_signed_in')`. **This gates the greeting only**; live pops are never suppressed,
because they are news rather than a summary. Seen item ids are seeded from the server payload
whether or not the greeting plays, so a page opened later in the same session does not replay what
the first one already showed.

**On `/dashboard` the greeting waits for `remedi:dashboard-ready`.** The shell's loader owns the
screen for 5–12s, so firing at page load would spend the first card behind the loading card.
`dashboard/index.blade.php` dispatches that event after `runScripts()` resolves; the listener has a
15s fallback so a dashboard that fails to load cannot swallow the alerts too. `#dashboardRoot` exists
only in the shell, so `?full=1` skips the wait.

**The chime is synthesised, not fetched.** Two sine notes (A5 880Hz, then D6 1174.7Hz 120ms later)
built with WebAudio — for the same reason the dashboard loader inlines its logo as a data URI:
`artisan serve` is single-threaded, so an `.mp3` requested while something slow is in flight arrives
after the toast it was meant to accompany. Gains are ramped rather than switched, since a gain
jumping from 0 clicks louder than the note.

**One AudioContext per tab, reused.** Browsers cap how many a document may create, and a register
left open all day can pop dozens of alerts; a fresh context per chime eventually throws and takes
the sound out for the rest of the shift. Reuse also means that once it is unlocked it stays
unlocked — **which is why live pops are reliably audible where the greeting may not be**. Autoplay
policy suspends a context created before the user has interacted with the document, so on a cold
load the greeting's chime can be silent. Do not treat that on a fresh profile as a bug.

**A blocked chime must never surface anything.** All three failure modes (constructor throws, resume
rejects, no WebAudio at all) were verified to leave the toasts fully working with zero console
errors and zero unhandled rejections.

**Muting is per workstation, not per user**: `localStorage['remedi.toastSound'] = 'off'`, flipped by
`REMEDI.toastSound(false)`. Deliberately not in the per-user alert-read store — the thing that wants
silence is the machine on the shop floor with customers beside it, not the account signed into it.
Muted still shows the cards; it only skips the audio.

**Every feed row carries a time, and `sort_at` is what makes that honest.** `AlertService` has always
stamped each row with the moment its alert BEGAN — it was only ever used to order the feed.
`whenLabel()` formats it into a `when` field on the item, so the bell, the notifications page and
the toasts cannot drift into three date formats. Inventory alerts are prefixed **"Since"**, which is
the whole reason they can now show a time at all: an inventory alert is a standing condition, so
"2 minutes ago" would claim it happened then, whereas "Since Aug 14" says exactly what the value
means. Audit rows get no prefix — they ARE events — and keep the relative "x ago" the panel already
re-stamped every minute, now rendered as `x ago · <date>`. Two formatting rules, both about not
inventing precision: a clock is shown only when the onset is not midnight (several are derived from
a DATE — an expiry, a window opening N days before one), and the year only when it is not the
current one.

**`data-when` is the absolute label; `data-at` marks a row as a real event; `data-since` carries a
standing alert's onset.** Blade and the bell's JS `render()` must emit all three the same way, or
timestamps vanish on the first poll after page load.

**Every row's time moves, standing alerts included.** An audit row's "x ago" was always live; an
inventory alert rendered `Since Aug 14` and then froze until the next full page load — which on a
register left open all day is the whole shift. Each now carries how long the condition has been open
beside its onset (`Since Sep 2, 1:35 PM · 6 minutes`), computed client-side from `data-since` on a
15s tick. That does not reverse the rule above: the row still never claims to have HAPPENED at a
moment. The duration is FLOORED (110 seconds is one minute, not two), dropped past 30 days where the
date says it better, and dropped for an onset in the future. **The logic exists twice** — the bell's
in `layouts/app.blade.php`, the page's in `notifications/index.blade.php`, because the bell script
only stamps inside `#bellList` — and the two must produce the identical string. REMEDI.md "Times and
dates on the feed" has the detail.

**This is not a revival of the removed `#ajaxFlash` banner**, and it is deliberately not
`REMEDI.showMessage()`. That card is modal and waits to be acknowledged, which is right for the
outcome of an action you just took and wrong for a standing condition nobody asked about. Covered by
`Feature\Alerts\AlertToastTest`, which asserts against the `#remediToastSeed` JSON only: every
authenticated page also renders the bell, which lists all five kinds, so an unscoped `assertSee`
would pass on the bell's copy and prove nothing. **Its helper is `toastSeed()`, not `seed()` —
`TestCase::seed()` already exists and shadowing it turns every call into a
`BindingResolutionException` with the whole page as the "class name".**

**Staff see one bell tab, not two.** System and Updates read from the audit trail, which is
admin-only, so a staff bell contains nothing but alerts — which made "All" and "Alerts" two buttons
producing the identical list. Staff now get the Alerts tab alone; admins keep all four. `activeTab`
is initialised from whichever tab carries `is-active` in the markup rather than hardcoded to `all`,
or staff would have a selected-looking tab that did not match the filter actually in force.

Read/unread is per user, per device, in `localStorage`, owned by `window.remediAlertReads` in
`layouts/app.blade.php` — the bell and the `/notifications` page both paint from it and repaint on
the `remedi:alerts-read` event it fires, rather than touching each other's DOM. The badge counts
unread, not total; item ids key off the batch or product and encode the stock level, never list
position. **Reordering never runs on a pointer path** — the rows are links, and one that moves
between `mousedown` and `mouseup` takes its click with it.

**Both surfaces are one flat chronological feed — never cluster them.** Rows are ordered unread
first, then newest first; nothing is grouped by kind or by `data-group`, and there are no section
headings on either surface. Recency comes from `sort_at`, an onset timestamp `payload()` stamps on
every row (when the batch expired / entered its return window, when stock last moved, when the audit
event happened). `sort_at` orders the list **and is now also rendered**, as the `when` label —
prefixed "Since" for inventory alerts, because a standing condition has an onset rather than an
"x ago"; only audit rows, which are real events, get the relative stamp. Selection is still capped per kind
in `AlertService`; that cap is what stops 666 low-stock rows from swamping the feed, so keep it if
you touch the sort. Three places sort identically and must stay in step: the Blade render, the JS
`render()` after each poll, and `orderRows()` / `sinkRead()`. REMEDI.md "One feed, newest first" and
"The bell is polled" cover this, the tab filter, and the traps in it.

### Frontend
Blade + Alpine, and **no view references `@vite`**. Chart.js, Tabler icons and Google Fonts come from
CDNs. The Tailwind/PostCSS/Vite toolchain is inherited Breeze scaffolding and is currently inert — a
Tailwind class you add will not apply.

**The shared styles and the shared page script are STATIC FILES as of 2026-09-28: `public/assets/remedi.css`
and `public/assets/remedi.js` — edit those, not the layout.** They were an inline `<style>` (131 KB) and
the layout's trailing `<script>` (111 KB), re-sent with every page: a page was 315 KB (75 KB compressed)
and is now ~65 KB, the rest fetched once. The layout links both through `App\Support\StaticAsset::url()`,
which appends a hash of the file's CONTENTS (`?v=`) — never mtime, which a Vercel build does not keep —
so they are cached for a year (`server.php` on Vercel, `CADDY_SERVER_EXTRA_DIRECTIVES` in the Dockerfile
on Railway) and still change the moment you edit them. The script stays synchronous and at the END of
the body, so it runs in the order it always did (page content scripts first — hence the
`setTimeout(openModal, 0)` note below). **It cannot contain Blade**: the three server values it needs
(`userId`, `alertsUrl`, `loginUrl`) ride in `window.REMEDI_BOOT`, set inline just above it — add a key
there rather than an `@json` in the file. Wherever this file says a rule "lives in
`layouts/app.blade.php`", the CSS or JS half of it is now in those two files. Tabler icons is pinned to
`@2.47.0` (what `@latest` actually served, byte-identical; `@latest` is cached a week, a version a year) —
3.x renames icons.

`layouts/app.blade.php` is the shared shell and owns more than styling: the navigation-skeleton
handler (`.is-loading` / `.is-navigating`, title swap, bfcache restore, and click guards for
modified/middle clicks, `target=_blank`, `#`, cross-origin), the notification bell and its poll loop,
the confirm dialog, `#logoutModal`, the date-placeholder script, and the password reveal toggle.
**REMEDI.md "Frontend" and the sections after it explain why each of these exists** — every rule
below is stated there with the measurement and the reproduction behind it.

**Search terms go through `Controller::likeTerm()`, never straight into a `LIKE` pattern.** `%` and
`_` are LIKE's own wildcards, so an unescaped term is executed rather than searched for — a bare `%`
matched the entire catalogue. Not theoretical: 9 products carry `%` in their name and 3 carry `_`.
All nine search sites use the helper (`DemandForecastService` keeps a private copy, since the
controller's is `protected` — keep the two in step).

**AJAX partial pattern:** list controllers (POS, inventory, products, sales, forecast) check
`$request->wantsJson() || $request->ajax()` and return
`['html' => view('x._rows', ...)->render(), 'pagination' => (string) $paginator->links()]`. Edit the
`_rows.blade.php` / `_grid.blade.php` partial — it is shared by the full page render and the AJAX
refresh, not duplicated inside `index.blade.php`.

Because these partials return only the table, **any KPI card outside it must not depend on the user's
filters** — the AJAX path never refreshes it. Where a list is both role-scoped and filterable, clone
the query *between* the two: `SaleController::index` takes `$scopedToday` after the role scope and
before search/date filters. Cloning after the filters made cards labelled "Total sales today" read
₱0.00 whenever the list was narrowed to a past range.

**The dashboard is the one page whose whole body is AJAX.** `/dashboard` is the slowest route in the
app — ~5s warm, 10–12s cold — so `DashboardController::index` answers a plain GET with
`dashboard/index.blade.php`, a shell that runs **no queries at all**, and the page fetches its own
body. The `$wantsBody` test therefore sits above every query in the method: move a query above it and
the shell stops being instant, which is the only thing it is for. Three shapes, one Blade partial
behind all of them so they cannot drift:

| Request | Answer |
|---|---|
| plain GET | `dashboard.index` — shell + `dashboard/_loading` |
| `ajax()` / `wantsJson()` | `{html}` from `admin._dashboard-body` or `staff._dashboard-body` |
| `?full=1` | the whole page rendered synchronously, body included |

`?full=1` is the no-JS path (`<noscript>` redirects to it) and the loader's failure panel offers it —
keep it working, it is the floor under the enhancement.

**The admin KPI row's second tile is "Revenue Today" -- PROFIT, not a second revenue figure** (it
replaced "Last 7 Days" 2026-09-22, at the user's request: Sales Today beside it already shows raw
revenue, so a week-wide revenue total was the same question asked twice rather than a second one
answered). It sums, per POS line rung up today, `(price charged − product's cost_price) × quantity`
— `DashboardController::computeTodayProfit()`, static and pure like `computeAtv()`/`computeAtc()`
beside it, for the identical reason: `index()`'s AJAX-body branch cannot be hit under the sqlite test
suite. **`products.cost_price` is a brand-new, optional column** (migration
`2026_09_22_173452`) nothing in the seeded catalogue has ever populated, so a line whose product has
none set contributes **nothing** to this figure rather than being scored at zero cost (which would
overstate profit as pure margin) or silently dropped from Sales Today (which still counts every unit
regardless). The tile's own sub-line names how many lines that was, so an under-counted figure reads
as "waiting on cost data" rather than as a wrong number. Voided sales are excluded, same as every
other "today" figure on this page. `$last7Transactions` (the trailing-7-day COUNT, not revenue) is
kept — Avg. Transaction Count still reads it — only the revenue half of that old query is gone.

**`cost_price` is edited on the Product form, labelled "Default Cost Price" — never bare "Cost
Price".** `ProductBatch::unit_cost` already owns that exact label on the Add New Batch card sitting
on the same edit page (what a specific delivery actually cost, purchase-history/traceability, nothing
downstream computes profit from it), so the product-level default needed a name that couldn't be
mistaken for it. Optional for the same reason `unit_cost` is: a cost isn't always in hand when a
product is first keyed in, and blocking the form on data nobody has yet would stop a legitimate new
product over paperwork. Bounded by `Controller::MAX_MONEY`, same as `selling_price`. Covered by
`Feature\Inventory\ProductFormTest` (the ceiling, and that a product saves fine with none set) and
`Feature\Reports\DashboardKpiTest` (the profit arithmetic itself, including the "no cost set"
exclusion).

Two traps in the injector, both in
`dashboard/index.blade.php`: **`innerHTML` never executes `<script>`**, so the body's Chart.js block
has to be re-created node by node or all nine charts come up blank; and those scripts must run **one
at a time in order**, because the inline block calls `new Chart(...)` at parse time and would throw
if the CDN `<script src>` were still in flight. Neither dashboard uses `DOMContentLoaded` — that
event is long past by injection time, so a listener added there would never fire. Keep the `<script>`
blocks at the bottom of the body partials.

**A loading screen must not depend on a request to the server it is waiting for.** `artisan serve` is
single-threaded, so both the loader's logo and the sidebar's are inlined as data URIs
(`logo-mark.webp` and `logo-nav.webp`, both from `php artisan logo:mark`) — **regenerate both
whenever `logo.png` changes**, nothing else reads them and a stale one shows the old artwork
silently. The same reasoning is why `.page-hero` is drawn in CSS rather than being an image. The
branded loader card is for arrivals only (`is-first-load`, from the `remedi.just_signed_in` flash); a
revisit gets the skeleton alone, so **`.dash-loader` must not carry `display` in its own rule block**
or it defeats that gate. REMEDI.md "Theme: one palette" has the loader in full.

**`partials/_page-skeleton.blade.php` is the one definition of the navigation skeleton**, included
by the layout (`.content-body.is-navigating`) and by `dashboard/_loading.blade.php` as the backdrop.
It paints only after `SKELETON_DELAY_MS` (180ms), so a fast page paints none at all, and it has two
silhouettes chosen from the destination link (`data-shape="list"` for the table pages, the dashboard
shape otherwise) — one generic shape was what made every tab look like it glitched on click. Keep it
mirroring the dashboard's real proportions. **The login page has no skeleton, deliberately**; its
submit button disables itself and relabels instead, after the browser has serialised the form.

**Every page raises a "Loading <destination>…" pill in the header while a navigation is in flight**,
built by `showNavigating()` from the sidebar item's own text. **Two pills, two owners:** the dashboard
builds its own for a different wait, the navigation one carries `id="navLoadingPill"`, and
`clearNavigating()` removes only that — give any new pill its own id rather than clearing by class.
A control that navigates without being an `<a>` must call `window.REMEDI.showNavigating` itself.

**The two sidebar scripts run inline right after `</aside>`, NOT with the rest at the end of the
body — the placement is the feature.** Both change the sidebar's geometry (submenu height, remembered
scroll offset), and at the end of the body they ran late enough for the browser to have painted the
menu already, so it visibly snapped into position on every load. Both are self-contained (DOM plus
web storage, no `REMEDI` helpers), which is what makes running them that early safe. Put anything
else that sizes or scrolls the sidebar there too.

**Every empty date field carries a real `mm/dd/yyyy` placeholder, and it takes a script to do it.**
`<input type="date">` ignores `placeholder` and draws its own hint from the BROWSER's locale, which
no markup here can override. The layout holds an empty date input as a text input and flips it to
`type="date"` on `focusin`, calling `showPicker()` on the same gesture; it flips back on `focusout`
if still empty. **A field holding a VALUE is never touched**, which is why Add New Batch no longer
prefills either date — an expiry accepted by accident because it was already in the box is the one
mistake on that form that reaches the shelf. A MutationObserver catches fields arriving over AJAX.

**Design vocabulary, all defined once in the layout and all detailed in REMEDI.md:** `.form-card` /
`.form-grid` / `.form-field` / `.form-chip` / `.form-actions` / `.btn-lg` are shared by the four form
pages (`.field-row` is the separate one-wide-strip shape; don't merge them). Form chips are NEUTRAL —
the `chip-*` tints are gone, because **colour is kept only where it means something**: the reports,
the inventory status badges, and the alert legend. Buttons are solid mid-tones, and **a `.btn-*`
variant must be declared AFTER `.btn`** or it silently loses its border. `.section-head` is a filled
band and only belongs at the TOP of a card, since it cancels the card's padding with negative
margins. `.btn-lg` is for page-level actions only. Back buttons sit beside the page title in
`.page-head`, never on a row of their own. Row actions carry an icon from the same vocabulary the
confirm dialogs use. **Don't hide the substance of a page behind a disclosure** — long tables scroll
inside `.table-scroll`.

**Add Category is a dialog**, and two things make it work rather than merely open: it validates into
its own error bag (`validateWithBag('addCategory', ...)`, because the rename forms below use `name`
too), and it reopens itself when that bag is non-empty. **`Category::ICONS`** maps a category to its
glyph, falling back to `ti-category` for the user-created names it cannot know.

**`Product::UNITS` is the one list of units**, and both product forms render `Product::unitOptions()`
rather than a text box — unit was free text, which is how the catalogue acquired a product whose unit
is the string `"20"`. The update path passes `unitOptions($product->unit)` so that legacy row stays
editable.

**Add User's email field carries a locked `@remedi.com` suffix**, front-end only —
`auth/register.blade.php`'s inline script refuses to let Backspace/Delete/paste/cut reach it and
clamps the caret so clicking or selecting can't land inside it either, so an admin can only edit the
local part in front of it. `type="text"`, not `email`: `setSelectionRange()` throws on `type="email"`
(that type has no selection API at all), which the lock cannot work without. This is NOT a backend
restriction — `RegisteredUserController::store` still accepts any domain (see
`RegistrationEmailTest::test_another_domain_works`), so a no-JS submission or a test posting directly
can send whatever address it likes; only the UI keeps typed input inside the company domain.
`old('email')` still wins on a validation redisplay. `RegisteredUserController::store` trims and
lower-cases before validating, because the `lowercase` rule REJECTS a capitalised address rather than
folding it — and folding before the
`unique` check is also what stops case slipping a duplicate past it.

**Money is formatted to 2 decimals; only counts are formatted bare.** `number_format($x)` with no
precision rounds to whole units — right for units, products and batches, wrong for pesos. If you add
a peso figure anywhere, pass the `, 2`.

**Every view that extends `layouts.app` must set `@section('title')`.** The layout falls back to
`@yield('title', 'Dashboard')`, so a view without one silently renders "Dashboard" in the topbar
heading and the browser tab while you are looking at something else.

**The products list shows the SKU only** — the `barcode` column still exists and every search still
matches on it, it is simply not printed, and both barcode SCANNER cards are hidden (see the inventory
note above).

**Each Inventory filter is ordered by the thing it is about**, in PHP, because every sort key is a
computed accessor: `low_stock` by `sellable_stock` ascending, `expiring` by the earliest still-
sellable batch, `expired` by the longest-expired batch. Sort on `sellable_stock`, never
`total_stock` — a product with 300 expired units is not better stocked than one with 2 good ones.

**User Management is search + Filter + four KPIs + a sortable table.** Three rules behind it, none
cosmetic:

- **The KPI cards are counted BEFORE any filter.** They describe the account list as a whole, so
  narrowing the table must not quietly rewrite "Total Users" — the same rule `SaleController::index`
  follows for its "today" cards, where cloning the query after the filters made them read ₱0.00 for
  any past range.
- **The search is GROUPED in a closure.** `where(name)->orWhere(email)` was harmless while search was
  the only filter, but with role and status beside it the `OR` escapes the group and an email match
  returns rows the filter excluded — the leak `SuggestController::sales()` had.
- **`UserController::SORTABLE` is a whitelist, not the request value.** `orderBy()` interpolates its
  column name straight into the SQL. It falls back to `name` and carries a stable tie-break on `id`,
  so rows do not swap places between pages of one sorted list.

**An action column only aligns if the widest label is pinned.** "Activate" is 14px narrower than
"Deactivate", and that difference does not stay in its own column — it drags every button after it
left on those rows, stepping the last column down the table. `.action-toggle` is sized to its widest
label in `em`, not px, so a font-size change cannot silently break it again.

**Row actions are SOFT — tinted fill, coloured border, coloured text — in every table that has them.**
That is not a reversal of "buttons are solid mid-tones": that rule is about the button you press to
COMMIT something, where the fill is what says "this is the action". A row is the other case, carrying
two or three per line at ten lines a page, and thirty saturated fills is a block of colour competing
with the status badges beside it. The colour still means the same thing, moved into the border and
label. Defined once in `layouts/app.blade.php` under `.remedi-table .actions-cell`, so users,
products and inventory read as one pattern.

**User Management's row actions are ICONS that stretch on hover (2026-09-28, at the user's request).** Edit keeps its label; Reset password, Activate/Deactivate and Archive rest as equal icon squares (`.action-icon`) and slide their `.act-label` out on `:hover` / `:focus-visible` (max-width transition — `auto` cannot animate). **Third version, same day:** a second version opened each button OVER its neighbours (absolutely positioned in a fixed slot) so nothing moved; the user asked why it no longer pushed right, so it is back in the row's flow — the hovered button grows from its own left edge and the icons after it slide RIGHT, continuously, because what animates is the label's max-width. Width eases out (cubic-bezier .22,1,.36,1) with the label fading in just behind; `prefers-reduced-motion` drops the animation. The column must not widen while that happens, so `.users-actions { min-width: 320px }` reserves the row at its WIDEST (Edit + three icons + "Reset password" open) — verified: the actions cell stays 352px wide with each of the three open in turn. `aria-label` names each button. `applyToggle()` in the layout swaps the `.act-label` text and the icon rather than setting `textContent` on the button, which used to wipe the icon on every activate/deactivate. The old `.action-toggle` min-width pin is gone (icons are one width).

**No Reset password button, "Reset requested" badge or "Password Resets" card in User Management (removed 2026-09-28, at the user's request).** An admin sets a new password from Edit user, which also clears a pending request; `UserController::resetPassword()` stays routed (and tested) but unlinked. **Every password change now notifies the admins** as an account event: own change logs `Password changed: {name}` (`PasswordController`, was "Changed own account password", which matched nothing and filed as a generic "Record updated"), an admin setting one logs `Password changed by an admin: {name}` — both match `AlertService`'s `str_starts_with($details, 'Password changed')` and pop live with "Manage users" (measured: 27 s on the 15 s poll). A reset by email code already did (`Password reset …`). Covered by `Feature\Alerts\PasswordChangeNotificationTest`.

**Stock reports: staff notify an admin, an admin approves (2026-09-28).** `stock_reports` (`App\Models\StockReport`, `StockReportController`). A staff Inventory row with a real problem — `StockReport::applies()`, the ONE check behind both the button and the endpoint (`is_running_out` for low stock, an unreturned expired batch holding units for expired) — gets a "Notify: Low stock" / "Notify: Expired stock" js-confirm button, then a "notified" marker while a report waits. One pending report per product+type (a second click answers success and writes nothing). The admin is told through the audit trail: details beginning `Stock report` become `AlertService::STOCK_REPORT_KIND` rows in the bell (Alerts tab, "Review" pill, linking at `/stock-reports`) and a toast (added to `$toastKinds`), and the sidebar's "Stock reports" link carries a pending count (`StockReport::pendingCount()`, cached 60s, forgotten on every write). `/stock-reports` is shared — staff see only their own — while approve/reject are `role:admin`, locked-and-rechecked so a report is decided exactly once, and audited as `Approved` / `Rejected` (new `AuditTrail::ACTIONS`). **Approval moves no stock**: it records that the admin authorised the fix, which happens through Add New Batch / Mark Returned / a batch adjustment with its own stock-card row. Needs migration `2026_09_28_000001` on deploy. Covered by `Feature\Inventory\StockReportTest`. **Stock reports carry no note (2026-10-01, at the user's request).** For one day staff could add an optional note in the Notify dialog (`data-confirm-note="optional"`, a mode of the shared confirm dialog) and add/edit it from their Stock Reports list; the Note columns, the button, its `PATCH …/note` route and the dialog's optional-note mode were all removed again the same day. `stock_reports.note` stays as a column (nothing writes it now; the few notes already saved are kept), and `AlertService` still files `Updated` "Stock report note: …" audit rows so the ones written that day read correctly.

**The staff member who sent a stock report is told the answer (2026-09-28, at the user's request).** `AlertService::stockReportDecisionsFor($user)` — their own reports approved/rejected in the last 14 days, per user and uncached — and `activityFor($user)`, the ONE fork the view composer, `/alerts` and `/notifications` all call (audit feed for an admin, these rows for staff). Rows are `group: alerts` (the only tab a staff bell has) and `STOCK_REPORT_KIND`, so the toast pops them live: measured 16.9 s after the admin approved, "Your stock report was approved — Low stock — … · by …" with a View pill. A colleague's feed stays empty. Covered by `StockReportTest::test_the_reporter_is_notified_of_the_decision`. The Inventory report also has an **OK** KPI card (and print card) reading the same `$okCount` as the filter and the chart.

**A FIRED account cannot be restored (2026-09-28, at the user's request).** `User::FINAL_ARCHIVE_REASONS` (`['fired']`) and `User::isRestorable()` are the one definition: `UserController::restore()` refuses it (`actionFailed`, 422 for the dialog) and the Archived list shows a locked "Cannot be restored" badge instead of Restore. Resigned stays restorable. The archive dialog and the Archived banner both say so. Covered by `Feature\ArchiveTest::test_a_fired_account_cannot_be_restored`.

**The Inventory report's filters are LIVE too (2026-09-28), and Status gained OK.** Same pattern as the Sales Report: `ReportController::inventory()` answers AJAX with `{html, state}`, `html` being `reports/_inventory-body` (KPIs, both doughnuts, the table, the print copy); `renderInventoryCharts()` draws from the body's JSON after every swap and `initInventorySearch()` re-wires the table's search box, keeping what was typed. A filter change moves 9–274 KB instead of ~720 KB. **OK = nothing to act on** — in stock, not `is_running_out`, no expired stock on the shelf (`$isOk` in `buildInventoryReportData()`, statuses in `INVENTORY_STATUSES`); a healthy product that also holds an expired batch is NOT OK, it is on the Expired list. The Stock Health chart's OK slice reads the same `$okCount` — it used to be "total minus low stock", which on the Expired filter showed the same 109 products as both OK and Expired; now OK + low + expired adds up to the product count (218 + 104 + 8 = 330, measured). Clear is `.btn-clear` (soft rose, `ti-filter-off`) on both reports, declared after `.btn-secondary` in the layout.

**`.actions-cell` is a SHARED primitive — overriding it from a page needs matching specificity.** The
layout's selector is `.remedi-table .actions-cell`, two classes; a bare `.actions-cell { gap }` in a
page is one, loses, and changes nothing at all. **Nothing errors when a rule loses on specificity —
the page simply ignores you**, which is a long way to look for a gap that will not move. Four views
share this primitive, so change the layout only when you mean all four.

**The audit trail uses the shared pager**, centred from 768px up (`.audit-pager`), rather than the
private one it used to draw. **Row numbers use `$paginator->firstItem() + $loop->index`** so page 2
starts at 11; adding a column means bumping the empty-state `colspan` in the same partial.

`InventoryController` pushes category and text search down to SQL but filters status (low stock /
expiring / expired / returned — all computed accessors, not columns) in PHP, then paginates manually
with `LengthAwarePaginator`.

**Inventory monitoring filters (2026-09-30, at the user's request): an Out of Stock tab and an expiry
window.** `?filter=out_of_stock` is `total_stock <= 0` — a subset of Low Stock, which still counts zero
as low; its chip and the row badge are the graphite `#334155` of an empty shelf (`.badge-out`, so an
empty product now reads "Out of Stock" instead of "Low Stock" on every tab). `?expiry_from=` /
`?expiry_to=` (strict `Y-m-d`, a reversed pair swapped, garbage a 422) narrow ANY tab to products with
units on the shelf whose batch expires inside the window, sorted by the earliest such expiry; on
**Expired** that is "what expired then", on **Expiring Soon** the window REPLACES the 90/30-day horizon,
and the SQL superset follows the window rather than the fixed 120 days. The page's Month picker only
fills both dates (first/last day); editing a date clears the month; everything applies live through the
same `runInventorySearch()` the tabs use, and the tab links carry the window. Also fixed: picking a search
suggestion called `runProductSearch()`, which does not exist on this page. Covered by
`Feature\Inventory\InventoryMonitoringFilterTest`; `SearchInputTest` sends both new params as arrays.

**Print Barcodes (2026-09-30, at the user's request) — `/barcodes`, `BarcodeController`, `role:admin`.**
Sidebar "Print barcodes" under Products, and a "Print Barcode" button on the product edit page
(`?product=ID` preselects it). Pick products (the `/suggest/products` search; a scanner gun's SKU +
Enter adds the exact match) or "Add all" for a category (`/barcodes/products?category_id=`, active
products only, capped at `MAX_PRODUCTS` 300, skipping ones already on the sheet), set copies (1–100),
Small/Medium/Large (5/3/2 across A4), optional price, Print. **The bars are drawn in the browser
(JsBarcode, cdnjs) as CODE 128 of the SKU exactly as stored — never EAN-13**, because many SKUs here
carry no valid EAN check digit and an EAN encoder would print a different number, and never a Word
barcode font: the user's font-made Code 39 labels had no `*` start/stop characters (65 bars for 13
digits where a real one has 75) and nothing could read them. Printing copies the sheet into a direct
`<body>` child (`#bcPrintRoot`) and print CSS hides every other child, so no layout chrome reaches
the paper. The sheet persists per viewer in `localStorage['remedi.barcodeSheet']`; nothing is saved
server-side. Verified: labels rasterised at 300 dpi decode with the camera scanner's own reader at
all three sizes. Covered by `Feature\Inventory\PrintBarcodesTest`.

### Every state-changing action answers in two shapes
There is no `window.confirm()` in the app. Destructive and state-changing actions are real POST forms
tagged `class="js-confirm"`; the handler in `layouts/app.blade.php` intercepts `submit`, opens
`#confirmModal`, then posts over `fetch`. Copy and behaviour come from data attributes —
`data-confirm-title` / `-body` / `-label`, `data-confirm-icon`, `data-confirm-tone="neutral"` for
reversible actions, and `data-on-success` (`remove-row` / `toggle` / `reload` / `none`). Adding one
costs attributes, not another copy of the markup.

**`data-confirm-reasons` (a JSON `{value: label}` map) replaces the single generic Confirm button
with one button per reason** — User Management's Archive (`User::ARCHIVE_REASONS`: Resigned/Fired)
and the sales detail page's Void (`Sale::VOID_REASONS`) both use it. The form still needs a plain
`<input type="hidden" name="reason">` for whichever button gets clicked to fill in before the same
`fetch` submission every other confirm uses. Picking a reason both answers "are you sure?" and
supplies the field the server requires in one click, rather than a `<select>` filled in beforehand and
a second click to confirm it — and it is why `submitConfirmed()` in `layouts/app.blade.php` takes
`busyBtn` as a parameter: the button that gets disabled and (for the plain Confirm case) relabelled
"Working…" is whichever one was actually clicked. **`#confirmModalConfirm[hidden]` has its own CSS
rule** — `.btn` sets `display: inline-flex` as an AUTHOR rule, which beats the `hidden` attribute's
UA-stylesheet `display: none` regardless of selector specificity, so hiding it via the JS `hidden`
property alone left it fully visible and clickable behind the reason buttons.

**The confirm dialog has to read BOTH 422 shapes.** A controller refusal answers
`{success:false, error}`; a Laravel VALIDATION failure answers `{message, errors:{field:[...]}}` with
no `error` key at all. The handler read only `error`, so every validation message in the app — on
every `js-confirm` form — surfaced as the one sentence that says nothing: *"That action could not be
completed."* Found on Add User, where an address with a capital letter fails the `lowercase` rule and
the dialog gave no reason. `failureMessage()` in `layouts/app.blade.php` now falls back to the field
messages (joined with a space; the dialog body is textContent) and then to `message`. Same bug the
POS checkout had, same fix — if you add a third answer shape, teach that one function about it.

Controllers answer both callers through `Controller::actionOk()` / `actionFailed()` — JSON
`{success, message}` (or `422 {success:false, error}`) for the dialog's fetch, the original redirect
or `withErrors()` for a plain post. Use them for new mutations rather than hand-rolling the branch;
`ProfileController` and `PasswordController` branch inline only because their pages render their own
status banner. With JavaScript off every one of these forms still works, unconfirmed — keep it that
way.

### Audit trail
`AuditTrail::log($action, $details)` is a static helper that resolves the current user itself
(falling back to "System"). Call it from controllers for any state-changing operation — sales,
user/product/category mutations, logins. Each write also feeds the bell's System/Updates tabs, which
is why it forgets `topbar_activity`.

**`AuditTrail::ACTIONS` is the one list of actions, and the filter dropdown renders from it.** The
dropdown carried a hand-typed `['Login','Logout','Viewed','Create','Update','Delete']` while every
row is written in the PAST tense, and `applyFilters()` matches the column exactly — so three of the
six options could never match anything: `?action=Create` returned **0 of 109** rows, `Update` 0 of 35,
`Delete` 0 of 12. Nothing errored, the page just rendered its normal empty state, which reads as *"the
app does not record this"* — and that is how creating, updating, deactivating and deleting user
accounts all appeared to go unlogged when all four were in the table the whole time. Login/Logout/
Viewed matched by luck, being already the tense `log()` is called with.
`AuditTrail::canonicalAction()` maps the superseded singulars so an old link still finds its rows
rather than asserting that nothing ever happened — the worst thing an audit trail can say. Covered by
`Feature\AuditTrailFilterTest`, which asserts both the four account events and the vocabulary.

**Call it after the mutation succeeds, not before.** `ProductController::destroy`/`destroyBatch`
logged first, so a delete the database then refused still wrote "Deleted product: X" while the
product remained. Related: anything referenced by `sale_items` (`product_id`, `product_batch_id` —
both `ON DELETE RESTRICT`) must be checked with `saleItems()->exists()` and refused with
`actionFailed()`; letting the constraint fire returns a 500 with raw SQL in the body.

The row's column is **`details`** (there is no `description`), and identity is denormalised onto
`username` / `role` because `user_id` is `onDelete('set null')` — read those columns, not
`$log->user->name`, or deleted accounts come back as "System". **The CSV export escapes formula triggers** (`csvCell()`): `fputcsv` quotes for CSV, but a quoted
cell beginning `= + - @` is still live in Excel, and this export carries usernames and product names,
which are user input. Escape on the way out, never on write — the stored row must stay a faithful
record. `AuditTrailController::applyFilters()`
is shared by the table and the CSV export so the two cannot drift; it **validates the filters** (an
unvalidated bad date resolves to `NULL` in MySQL and fails silently — `date_from` ignored the filter,
`date_to` matched nothing and exported an empty CSV that still looked valid), the export streams with
`lazy()`, and its button carries the current filters. All four of those were bugs. REMEDI.md "Audit trail"
has the detail, plus a known unfixed CSV formula-injection exposure.

### Migrations carry data decisions, not just schema
Several migrations exist to *edit the seeded catalogue*, and they encode business rulings that a
schema-only reading of `database/migrations/` will miss —
`2026_08_17_000002_merge_beverages_into_water_and_beverages` (folds a duplicate supplier category
that showed up as two sidebar links), `2026_08_19_000001_reclassify_products_into_correct_categories`
(the master's Category column behaves like a substring match over the product name, so it had filed
deodorants under Medicine and left salbutamol inhalers in General Merchandise — and since
`is_medicine` reads the category name, that misfiling *was* the alerting bug), and
`2026_08_17_000004_ensure_no_zero_stock_products` (the export carries `Stock = 0` for anything out
of stock that day; seeding it verbatim left 86 products the POS could not ring up). Each is written
to be re-runnable and to no-op on a fresh `migrate --seed`, because the seeders normalise the same
aliases via `Category::normalizeName()`. Read the docblock before altering one: reverting it
restores a bug, not a schema.

### Seeders read CSVs, not factories
`DatabaseSeeder` creates the two users inline, then hands off to CSV importers that read by path and
**skip silently** (printing `File not found: ...`) if a file is missing, leaving that table empty
while the seed still "succeeds". `database/data/inventory_seeder.csv` feeds categories, products and
opening batches; `database/data/Sales_Records_4Year.csv` (~15 MB, 147k lines) feeds `sales_history`
and is itself generated — **`database/data/generate_sales_history.py` is the generator**, last run
2026-09-02 to re-aim the record at a small non-urban pharmacy (~55 sales and PHP 9,000 a day) rather
than the urban chain branch it described before. Read its docstring before regenerating: the 12-month
seasonal cycle per product is deliberate, and without it seasonal SARIMA has nothing to fit.
`Transaction_Records_Seed.csv` in the repo root feeds inventory receipts.
`transaction_history_seed.csv` is absent from this checkout, so the purchase-history pass always
skips — that is the current state, not a bug to chase.
