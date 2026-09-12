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

**The suite is green (147 passed, 432 assertions — measured 2026-09-03) and is a usable regression gate.** It was 22 failed / 3 passed, for
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

Beyond Breeze there are now fourteen suites covering the things REMEDI.md says must never regress:

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
  `reorder_level` and batch `quantity`.

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
is real: **`remedi`** (`php artisan serve --port=8000`) serves the app, while **`dev`**
(`npm run dev`, port 5173) starts a Vite server nothing consumes — no view references `@vite`, so it
compiles to an asset the app never loads. Start `remedi`, never `dev`. Under XAMPP the docroot is
`public/`, so the app is also reachable through Apache without `artisan serve` at all.

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

`--workers=1` forces sequential fitting when debugging a model change (default `0` = all cores but
one). Python deps: `pip install -r resources/python/requirements.txt`.

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

Three things the entrypoint encodes, all of them rules from this file:

- **`config:cache` + `view:cache` only, never `php artisan optimize`** — that runs `route:cache`,
  which drops GET from `/` and locks everyone out at the login redirect.
- **`migrate --force` only; seeding is a separate manual step.** The seeders read a ~15 MB CSV and
  insert ~122k history rows, and a second run collides on `(product_sku, sale_date)`.
- It waits for the database (12 × 5s) before migrating, since Railway starts app and MySQL together.

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

**A cashier with sales cannot be deleted.** `sales.user_id` was `ON DELETE CASCADE` (and
`sale_items.sale_id` cascades too), so deleting an account silently destroyed every transaction they
had rung up — while reporting success and leaving the deducted stock deducted. Migration
`2026_08_24_000001` makes it RESTRICT; `UserController::destroy` checks `sales()->count()` and points
the admin at Deactivate. Retire accounts with `is_active`, never by deleting.

**Role scoping belongs on every route that reads the data, not just the pages.** `Sale::isVisibleTo()`
is the one rule — staff see only their own transactions, admins see all — and **three** routes expose
a sale: `sales.show`, `pos.receipt` and `suggest.sales`. Only the first ever checked. `pos.receipt`
had no authorisation at all, so a staff account could read any cashier's full receipt by walking the
sequential ids while `/sales/{id}` returned 403 for the same record. `SuggestController::sales()`
likewise returned 8 transactions where the list shows 2, and could enumerate a colleague by name —
there, put the scope *outside* the search closure or the `OR` escapes it. The `/suggest/*` routes are
not fetched by any view, which is precisely why that gap survived: still registered, still reachable
with a session. Call `isVisibleTo()` from any new route that renders a sale.

**Two routes delete a user — `users.destroy` and `profile.destroy` — and both must enforce the same
three rules:** no account with sales, never the last *active* admin (the admin pages are
`role:admin`, so that lockout is unrecoverable through the UI), and log to the audit trail on
success. `profile.destroy` is the stock Breeze route; its card is hidden on the profile page but the
route is live, and it had none of these. Any new deletion path needs all three.

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

**Search placeholders no longer mention barcodes** (inventory, POS, products). Barcode SEARCH still
works everywhere — `likeTerm()` still matches the column — it is only the prompt that stopped
advertising it, alongside the hidden scanner cards.

**The barcode scanner card is hidden, not deleted — in BOTH modules** (`pos/index.blade.php` and
`inventory/index.blade.php`). The markup stays in the
DOM so `barcode-input`, `barcode-status` and the keydown listener all still resolve and a hardware
reader keeps working. `focusEntryField()` decides where the caret belongs — the scanner when it is
visible, the product search box when it is not — because a hidden input cannot take focus and the
three `barcodeInput.focus()` calls would otherwise silently leave the caret nowhere after every sale,
receipt close and click. Remove the `hidden` attribute to bring it back; nothing else needs changing.

Inventory carries the same card and the same trap, and now the same fix: its own `focusEntryField()`
picks the search box while the scanner is hidden, and the click-anywhere refocus handler bails out
entirely rather than dragging focus to the search box on every click. `autofocus` was dropped from
the hidden input — a hidden input cannot take focus, so it was a promise the page could not keep.
**Note that Quick Restock lives inside that card** and is only ever revealed by a successful scan, so
hiding the scanner takes it with it; restocking is still on the product edit page, which posts to the
same `ProductController::addBatch`.

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
it just isn't shown. Add a Batch column if that detail is ever wanted on the internal view. Payment must cover the total; the old supervisor-passcode bypass was
removed deliberately. `sales.payment_voided` is read-only history (checkout always writes `false`).

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

### Two sales tables — pick the right one
- `sales` / `sale_items` — live POS checkouts only.
- `sales_history` — the imported/synthetic record (**113,960 rows, 2022-09-01 .. 2026-08-15**,
  re-counted 2026-09-09; the row count read 121,949 right after the 2026-09-02 regeneration and has
  since dropped, though nothing in this repo's history should touch the table between regenerations —
  see REMEDI.md "The sales record was rebuilt"). It stops the day before the
  terminal's first checkout (2026-08-16) on purpose: this is what the books said before REMEDI was
  installed, and `sales` is the record since. Written **only** by
  `SalesHistorySeeder`; the POS never touches it. Stores units only, so revenue is always
  `quantity_sold * products.selling_price`. `SalesHistory` sets `$table` explicitly (Eloquent would
  resolve `sales_histories`, which does not exist).

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
`ProductController::SKU_KEYED_TABLES` — add to that constant, never to the loop, and `destroy()`
picks it up too. Verified over HTTP on YAKULT 5S: 218 receipt rows and 1 history row moved with the
rename, no orphans left behind.

`destroy()` deliberately does **not** refuse on `inventory_receipts` the way it does on
`sales_history`: receipts are purchase history carrying no revenue, and guarding on them would block
essentially every deletion. It deletes them alongside the product instead, in a transaction, and
says how many in the audit entry.

**`destroy()` refuses on `sales_history` as well as `sale_items`.** `sale_items` has a real RESTRICT
so it was already safe; `sales_history.product_sku` has no FK, and that covered **2,555** products the
old guard missed against 29 it caught. Deleting one silently removed its revenue from every report —
₱7.74M on the worst case.

**A price edit rewrites historical revenue.** `sales_history` stores units only, so every revenue
figure is `quantity_sold * products.selling_price` — changing a price changes every past month.
`AppServiceProvider` clears the `SalesHistory` + `SalesForecastService` caches on `Product::saved`
when `wasChanged('selling_price')`, and on `Product::deleted` (history joins on `sku`, no FK). Keep
the `wasChanged` gate: `forgetCaches()` retires ~3.5s aggregates, so ungated it would make every
routine product edit pay for a rebuild. **Verify these hooks over HTTP.** `tinker --execute` gave
contradictory results across identical runs while the HTTP path was stable and correct, so a tinker
result is not evidence either way.

### Forecasting pipeline
Two independent pipelines, each PHP → Python subprocess → CSV → upsert into MySQL:

| Concern | Command | Script | Table | Service / page |
|---|---|---|---|---|
| Demand ("how much to buy") | `forecast:generate` | `resources/python/generate_forecasts.py` | `demand_forecasts` | `DemandForecastService`, `/forecast` |
| Sales units + revenue | `sales-forecast:generate` | `resources/python/generate_sales_forecast.py` | `sales_forecasts` | `SalesForecastService`, `/sales-forecast` |

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
The grades now read **Normal 721 / Acceptable 233 / Not acceptable 293** over **1,247** scored
products — back to the cascade's exact coverage (1,247), and closer to its grade split (752/198/297)
than the single-order run's (690/223/315) was. MAPE is **87.1%** across the **501** products where it
is defined, which is the honest figure for intermittent demand — being one unit out on a month that
sold two is a 50% error however good the fit, which is why `ForecastGrade` falls back to sMAPE.

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
looking at. Currently 1,247 products scored: **MAE 2.31 / RMSE 2.75** averaged across products
(measured 2026-09-09, immediately after the SARIMA-order-search run).

**MAPE is nullable and must stay nullable.** It divides by the actual, so a holdout where the product
sold nothing has no defined percentage error — and that is the common case here, not an edge case:
**746 of 1,247** products have no non-zero month to measure against. Null means "not measurable",
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
Blade + Alpine, and **no view references `@vite`**. `layouts/app.blade.php` carries a large inline
`<style>` block; Chart.js, Tabler icons and Google Fonts come from CDNs. The Tailwind/PostCSS/Vite
toolchain is inherited Breeze scaffolding and is currently inert — a Tailwind class you add will not
apply. Put styles in the existing inline block.

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
keep it working, it is the floor under the enhancement. Two traps in the injector, both in
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

### Every state-changing action answers in two shapes
There is no `window.confirm()` in the app. Destructive and state-changing actions are real POST forms
tagged `class="js-confirm"`; the handler in `layouts/app.blade.php` intercepts `submit`, opens
`#confirmModal`, then posts over `fetch`. Copy and behaviour come from data attributes —
`data-confirm-title` / `-body` / `-label`, `data-confirm-icon`, `data-confirm-tone="neutral"` for
reversible actions, and `data-on-success` (`remove-row` / `toggle` / `reload` / `none`). Adding one
costs attributes, not another copy of the markup.

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
