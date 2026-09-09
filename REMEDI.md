# REMEDI.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

REMEDI — a pharmacy point-of-sale + inventory management system with SARIMA-based demand and
sales forecasting. Laravel 12 backend, Blade frontend, MySQL, plus a Python forecasting layer
invoked as a subprocess from Artisan commands. Runs under XAMPP on Windows (docroot `public/`).

## Commands

```bash
php artisan migrate --seed
```
Seeds a default admin (`admin@remedi.com` / `password`) and staff (`staff@remedi.com` / `password`),
then categories → products → batches → inventory receipts → sales history, in that dependency order.
The whole catalog comes from CSVs on disk, not factories — see "Seed data lives in CSVs" below.

```bash
php artisan test
```
Run a single test file or method:
```bash
php artisan test --filter=AuthenticationTest
```
**Note:** `phpunit.xml` has the sqlite in-memory lines commented out, so tests run against the
`DB_*` database configured in `.env`. The Breeze tests use `RefreshDatabase`, so running the suite
against your working `.env` **wipes the seeded 339k-row database**. Point `.env` at a throwaway
database first, or uncomment those two lines.

`tests/` is still the untouched Breeze/Laravel scaffold — there is no coverage of POS, inventory,
returns or forecasting, so a green suite proves nothing about this app's domain logic. Two of the
scaffold tests also contradict the app on purpose and fail: `Feature\ExampleTest` expects `200` from
`/` (which redirects to `login`), and `Feature\Auth\RegistrationTest` expects public registration
(`/register` is admin-only — see "Roles and routing"). Fix or delete them if you start writing real
tests; don't "fix" the app to satisfy them.

```bash
vendor/bin/pint
```

Forecast regeneration (see "Forecasting pipeline" below). On Windows the Python executable is
usually `python`, not the `python3` default baked into the command signature:
```bash
php artisan forecast:generate --source=mysql --python=python
```
```bash
php artisan sales-forecast:generate --source=mysql --python=python
```
Add `--workers=1` to either to force sequential fitting when debugging a model change; the default
(`0`) spreads products across all cores but one.
Python deps: `pip install -r resources/python/requirements.txt`.

Re-derive the Category column of a product master CSV (dry run without `--write`; the first write
leaves a `.bak` beside the file — there is no `.bak` in the tree today, so the next `--write` is
the one that creates it):
```bash
php artisan products:classify-csv database/data/inventory_seeder.csv --write
```

**Never run `php artisan route:cache` on this app.** Caching the route collection drops GET from the
`/` route's method list -- the front page then answers `405` with
`allow: HEAD, POST, PUT, PATCH, DELETE, OPTIONS`, locking everyone out at the login redirect.
`config:cache` and `view:cache` are both safe (`optimize:clear` undoes either).

Bootstrap products/batches from supplier receiving-report exports:
```bash
php artisan import:receiving-reports path/to/report.csv path/to/report.xlsx
```

`npm run dev` / `npm run build` exist but see "Frontend" — the Vite bundle is not actually loaded
by any view.

## Architecture

### Legacy skeleton on a modern framework
`composer.json` requires `laravel/framework: ^12.0`, but the app still uses the **Laravel 10-style
skeleton**: `bootstrap/app.php` binds `App\Http\Kernel` / `App\Console\Kernel`, middleware aliases
live in `app/Http/Kernel.php`, and the scheduler lives in `app/Console/Kernel.php::schedule()`.
Do not "modernize" to the Laravel 11+ `bootstrap/app.php` fluent style piecemeal — register new
middleware aliases and scheduled commands in the existing Kernel files.
`temp-laravel12-reference/` is a pristine Laravel 12 skeleton kept only for comparison; it is not
part of the app.

### Roles and routing
All routes are in `routes/web.php` behind `['auth', 'active']`. Two roles (`admin`, `staff`) on the
`users` table, enforced by the `role` middleware alias → `EnsureUserHasRole`, which handles **role
and nothing else**.

**Deactivation lives in its own middleware, and it has to.** `active` → `EnsureUserIsActive` ends the
session of any `is_active = false` user on their next request.

That check used to sit inside `EnsureUserHasRole` — and `role` is attached only to the `role:admin`
group. Everything staff actually use runs in the plain `auth` group: the dashboard, the entire POS
including `POST /pos/checkout`, inventory, sales history, notifications and profile. So the check
never ran for them. `LoginRequest` refuses a *fresh* sign-in by a deactivated account, which is what
made this look covered; it does nothing about a session that is already open.

Verified end to end before the fix, with two live curl sessions: an admin deactivated a signed-in
staff account, and that staff session then completed a real sale —
`{"success":true,"transaction_no":"TXN-20260824-00039", …}`, receipt rendered, stock deducted. Every
shared route answered 200. Only `/users` refused, and with a 403 from the *role* check, not the
deactivation one. After the fix the same request returns
`{"success":false,"error":"Your account has been deactivated."}` with HTTP 401.

Two details worth keeping:

- It answers **401 JSON to AJAX** and a redirect otherwise. The POS checks out over `fetch` and the
  bell polls `/alerts` every 30s; handing those a 302 to the login HTML makes the JSON parse fail, so
  the register looks broken rather than signed out. The bell's poll loop already stops on 401.
- It runs **before `role`**, so a deactivated admin is logged out rather than shown a 403 about
  permissions they no longer have. Confirmed: `/users` went from 403 to a 302 to login.

**The deactivation message lives in `lang/en/auth.php`.** `LoginRequest::authenticate()` throws
`trans('auth.deactivated')` — and that key did not exist, so Laravel returned the key itself and the
login page rendered the literal words **`auth.deactivated`** in its error box. It lands on exactly the
person least able to interpret it: someone whose account has just been switched off, who cannot tell
whether they are locked out or the system is broken. `auth.failed`, `auth.password` and `auth.throttle`
all resolved, because those ship with the framework — only the app's own addition was missing.

`lang/en/auth.php` now defines it, and deliberately defines **nothing else**: Laravel merges
app-level lang files with the framework's rather than replacing them (verified — `auth.failed` still
resolves), so copying the standard lines in would only create a second copy to drift.
`EnsureUserIsActive` reads the same key instead of its own hardcoded string, so the "cannot sign in"
and "session just ended" paths cannot say different things about one condition.

**Never add a route to an `auth` group without `active`.** Both groups have it — `routes/web.php` and
the authenticated half of `routes/auth.php`, where `PUT /password` lives (a deactivated account must
not be able to change its own credentials).

**Deleting a cashier used to delete their sales.** `sales.user_id` was `ON DELETE CASCADE`, and
`sale_items.sale_id` cascades in turn, so removing an account took every transaction that person had
ever rung up — line items, revenue, all of it. `UserController::destroy` reported "User deleted
successfully" while it happened, and wrote no audit entry for any of the vanished sales.

Measured before the fix: deleting the staff cashier removed **2 sales, 4 sale_items and ₱249.62**.
The stock is never given back — FEFO had already taken 5 units off the shelf for those sales — so
inventory and the sales record end up permanently disagreeing with nothing to reconcile against.

**It has already happened on this install.** Two accounts were deleted ("Ambrosia", "Staff One") and
25 ids are missing from a `sales.id` range of 1..63 holding 38 rows. It is also the root cause of the
transaction-number regression documented under "Transaction numbers": the cascade dropped
`Sale::count()`, which is what made `count() + 1` start re-issuing numbers the day had already used.

Two layers now:

- Migration `2026_08_24_000001` changes the constraint to **RESTRICT**, so no path — tinker, a
  seeder, a future bulk tool — can do it.
- `UserController::destroy` checks `sales()->count()` first and refuses with a 422 pointing at
  **Deactivate**, which is the intended way to retire an account: it blocks sign-in, ends any live
  session (see `EnsureUserIsActive` above), and keeps the history attributable.

A user with no sales still deletes normally, and the audit entry is written **after** the delete
succeeds — same rule as `ProductController::destroy`.

**`profile.destroy` is the second door, and it was wide open.** The Delete Account card was removed
from `profile/edit.blade.php` by request, but the ROUTE is still registered and was still the stock
Breeze method — no guards of any kind. It honoured none of the rules User Management enforces:

- **It could remove the last admin.** `/users` and `/register` are both `role:admin`, so an admin
  deleting themselves with no other *active* admin left makes the system permanently
  unadministrable: nobody can create a replacement through the UI. Verified before the fix — a
  throwaway admin deleted itself and the admin count went 2 → 1.
- **It ignored the sales guard**, so a cashier with transactions hit an uncaught FK violation: a 500,
  raised *after* `Auth::logout()` had already run, leaving them signed out on an error page with the
  account still there. Before migration `2026_08_24_000001` this same path silently destroyed their
  sales — the `UserController` guard never protected it.
- **It wrote nothing to the audit trail.**

It now mirrors `UserController::destroy`: refuse if the account has sales, refuse if it is the only
*active* admin (a deactivated one cannot sign in, so leaving one behind is the same as leaving none),
and log on success. The log/delete pair runs inside a `DB::transaction` — the audit row has to be
written while the user is still authenticated or `AuditTrail::log()` resolves nobody and records
"System", but it must not survive a failed delete. `audit_trails.user_id` is `ON DELETE SET NULL`, so
afterwards the row keeps its denormalised `username`. Verified: `user_id=NULL username="ZZ QA Admin2"`.
`Auth::logout()` moved to *after* the delete.

`delete-user-form.blade.php` renders `$errors->userDeletion`, so these messages display correctly if
the card is ever restored — which keeps that file's note honest: restoring it is re-adding the card,
not rebuilding the safety around it.

**The rules must not depend on which door you came through.** Any new path that deletes a user needs
the same three checks.

- Shared (admin + staff): dashboard, POS, inventory, sales list.
- `role:admin` group: products/batches/categories CRUD, reports, both forecast pages, user
  management, audit trail. `/register` is admin-only — it is the "Add User" form, not public signup.

`DashboardController` branches on role to pick `admin.dashboard` vs `staff.dashboard`.

**Three routes expose a sale, and the rule lives in `Sale::isVisibleTo()`.** Staff see only their own
transactions; admins see everything. Any new route that renders a sale must call it.

`/pos/receipt/{sale}` had **no authorisation whatsoever** — while `/sales/{sale}`, showing the same
record in the same detail, returned 403 for a staff account viewing someone else's. So the guard on
the sales page was bypassable by asking for the receipt permalink instead, and the ids are
sequential. Verified with a staff session against an admin-owned sale:

```
GET /sales/1        -> 403
GET /pos/receipt/1  -> 200
```

...returning the full receipt: transaction number, date, **Cashier: Admin**, every line item with
prices, Items, Total, Amount Paid and Change. The pattern had already appeared twice
(`SuggestController::sales`, and this), which is why the rule moved onto the model instead of being
written out a third time.

The cashier's own path is unaffected: `pos.receipt` is the post-checkout redirect for the
no-JavaScript flow and the reprint permalink. Verified end to end — staff completes a sale, is
redirected to `/pos/receipt/76`, loads it (200), and an admin can load it too, while both routes
answer 403 on another cashier's sale.

**`/suggest/sales` needs the same role scope as the sales list.** `SaleController` scopes staff to
their own transactions on both `index` and `show` (403 on someone else's). `SuggestController::sales()`
had no scope at all, so the same staff session that sees **2** transactions in the list got **8** back
from the suggest endpoint — Admin's transaction numbers, cashier names and dates included — and could
enumerate a named colleague's takings by searching that name, because the filter matches on the
cashier too.

The scope sits **outside** the search closure: it must be `user_id = X AND (transaction_no LIKE … OR
cashier LIKE …)`. Folded inside, the `OR` escapes the guard and it does nothing.

Note this route is **not currently fetched by the UI** — `REMEDI.attachSuggest` only dispatches
`suggest:live` and never calls `data-suggest-url` ("still honoured, but purely to decide that a field
is a live one"). That is not a mitigation: the route is registered behind `auth` + `active` only, so
any signed-in staff account can request it directly. Unused endpoints are exactly where an
authorisation gap survives unnoticed. `/suggest/users` and `/suggest/audit` are fine — they sit in the
`role:admin` group and answer 403 to staff.

**Sales list: role scope and user filters are different things — clone between them.**
`SaleController::index` scopes the query by role (staff see only their own transactions, on both
`index` and `show`), then applies the user's search and date filters. Its two summary cards, labelled
**"Total sales today"** and **"Transactions today"**, are built from `$scopedToday` — a clone taken
*after* the role scope and *before* the filters.

They used to be cloned after the filters, so the cards reported the filter rather than today.
Narrowing the list to 2026-08-01→08-10 showed **₱0.00 / 0** while the till had actually taken
₱1,459.76 across 7 transactions; searching a transaction number did the same. The numbers were right
only when no filter was set, or when the filter happened to be today.

The role scope is deliberately kept in that clone: a staff card shows their own takings (₱249.62 / 2),
an admin's shows the whole register (₱1,459.76 / 7). That is a property of who is looking, not of what
they typed in the search box.

This also makes the AJAX partial correct rather than merely convenient — live search returns only the
table and pagination, which is right precisely because the cards no longer depend on the filters.

### Inventory model: products → batches
Stock is never stored on `products`. It lives on `product_batches` rows (quantity, unit_cost,
expiry_date, received_date, dr_no). `Product::$total_stock` sums them; `is_low_stock` compares that
sum to `reorder_level`. Most accessors on `Product` check `relationLoaded('batches')` and read from
memory when the relation is eager-loaded — preserve that pattern when adding accessors, since
`DashboardController` and `InventoryController` deliberately load batches once and attach slices via
`setRelation` to avoid N+1 queries.

**POS checkout deducts FEFO** (first-expiring-first-out): `PosController::checkout` walks batches
ordered by `expiry_date ASC` and creates one `SaleItem` per batch touched, so a single cart line can
produce multiple sale items. All of it runs inside a `DB::transaction`.

`checkout()` answers in two shapes. For AJAX/JSON it returns `{success, transaction_no,
receipt_html, receipt_url}` (or `422 {success:false, error}`), and the POS page shows that HTML in a
modal so the cashier never leaves the register. A plain form post still gets the old redirect to
`pos.receipt` — that path is the no-JavaScript fallback and the permalink used for reprints, so keep
both working. The receipt markup and CSS live in `pos/_receipt.blade.php` and
`pos/_receipt-styles.blade.php`, shared by the modal and the standalone page so a printed receipt is
identical either way. The modal prints through an offscreen iframe (it copies `#receipt-styles` into
it) rather than `window.print()`, because hiding the full-height sticky sidebar by CSS leaves it
laid out and pushes blank pages onto an 80mm roll. Printing fires automatically when the receipt
modal opens; the "Print Again" button is the retry path for a cancelled dialog or an offline printer.

**The receipt groups its lines by product — it does not iterate `sale_items`.** FEFO draws a cart
line from as many batches as it needs and writes a `sale_item` for each, so 8 units taken 5 + 3 from
two batches printed:

```
ZZ QA SPLIT TEST   ₱127.50   5 × ₱25.50
ZZ QA SPLIT TEST    ₱76.50   3 × ₱25.50
Items 8            Total ₱204.00
```

The arithmetic was right, but the same product appears twice at the same unit price and a customer
reads that as being charged twice — a dispute at the counter over an internal stock-control detail.
Which batch the units came from belongs in `sale_items`, not on the customer's copy.

`_receipt.blade.php` (both surfaces, since the partial is shared) and `sales/show.blade.php` now group
on `product_id|price`. **Price is part of the key deliberately**, so lines charged at different rates
can never be merged into one. The `sale_items` rows are unchanged — batch traceability stays in the
data. `sales/show` has no Batch column, which is why grouping is right there too; add one if that
detail is ever wanted, rather than un-grouping. Verified a multi-product sale still renders five
distinct rows, so the grouping does not over-collapse.

**Gotcha in that iframe:** `document.write()` + `close()` completes the document *synchronously*, so
the iframe's `load` event has usually already fired by the time you could attach a listener —
waiting on `addEventListener('load')` alone never runs and silently disables printing entirely
(this was a real bug). `printReceipt()` checks `doc.readyState === 'complete'` first and only falls
back to the event, with a timeout backstop and a `printed` flag so it fires exactly once.

**Supplier return windows** (`ProductBatch::getReturnStatusAttribute`) are category-dependent:
- `Medicine / Pharmaceutical` products: 90–120 days before expiry = "Need to Return"; under 90 days
  or expired = "Fail to Return"; already flagged = "Successfully Returned".
- Every other category falls back to a plain 10-day-before-expiry rule
  (`Product::NON_PHARMA_RETURN_WINDOW_DAYS`) with no "fail" state.

Carbon 3 (required by Laravel 12) made `diffInDays()` return a *signed* value. Date-distance math in
these accessors passes `true` as the second argument deliberately — removing it silently inverts the
return-window logic.

**Adding a batch from the UI** goes through `ProductController::addBatch`, which serves two callers:
the "Add New Batch" form on `products/edit` and the Inventory quick-restock card, which posts to the
same `products.batches.store` route. Three rules there are load-bearing:

- **`qty_received` is set to `quantity` on creation.** The two are not the same field: `quantity` is
  what is left after FEFO checkouts eat into the batch, `qty_received` is what it arrived with. The
  seeder, `import:receiving-reports` and migration `2026_08_17_000004` all populate it; `addBatch`
  did not, so for a long time every batch created through the UI carried `qty_received = NULL` while
  every seeded one had a figure. Nothing reads the column yet — which is exactly why the gap went
  unnoticed — but it is the only record of the original receipt, and it cannot be recovered later.
- **`expiry_date` validates `after:today` AND `after:received_date`.** `after:today` alone let a
  batch be saved expiring *before* it was received. That is not merely untidy: `edit()` derives the
  suggested expiry for the next batch from `received_date → expiry_date`, so one inverted row makes
  `$shelfLifeDays` negative and silently kills the suggestion from then on. When `received_date` is
  itself missing, Laravel skips the comparison, so the user sees one "required" error, not two.
- **Every field repopulates from `old()`.** The action answers a failed validation with
  `back()->withErrors()`. Expiry was the only field carrying `old()`, so a mistyped date discarded
  the batch number and quantity already entered.

The expiry field **used to be prefilled** from the product's usual shelf life when one could be
derived (latest batch's `received_date → expiry_date`, projected forward from today), and received
defaulted to today. Both prefills were dropped on 2026-09-02 so the fields show the `mm/dd/yyyy`
placeholder — see "A date input cannot be given a placeholder". `edit()` still derives
`$suggestedExpiryDate`; the view simply no longer renders it into `value`. The suggestion also
carried a grey caption explaining itself, removed earlier for a layout reason worth remembering: with
the form's `align-items:end` the extra line made the expiry column taller than its siblings, so the
Add Batch button and the other three inputs aligned to the bottom of that caption instead of to each
other.

### A delivery cannot be dated in the future

`received_date` now validates `before_or_equal:today` in `ProductController::addBatch`, and both
forms that post there (the product edit page's Add New Batch card and the inventory quick-restock
card) cap their picker at today. Expiry carries the matching `min` of tomorrow, because `after:today`
is the rule the endpoint applies and a batch expiring today is already expired by
`ProductBatch::is_expired`.

The picker cap alone would not have been enough, and not only for the usual reason that a gated
control is not a gated endpoint: a future received date corrupts two derived things. Expiry validates
`after:received_date`, so a date from next year forces every expiry after it; and `edit()` derives the
next batch's suggested shelf life from `received_date -> expiry_date`, so one bad row silently skews
the suggestion for everything added afterwards.

**The quick-restock card built that date with `new Date().toISOString()`** -- the same UTC trap the
audit trail's date presets had. This app runs in `Asia/Manila` (UTC+8), so between midnight and 08:00
local that stamped a delivery with YESTERDAY, and with the new cap a workstation on a negative offset
could have produced a date the endpoint then refused. It builds from local parts now
(`getFullYear()` / `getMonth()` / `getDate()`), which is the only form that agrees with the server's
own `now()`.

Covered by `Feature\Inventory\ProductFormTest`: a delivery dated tomorrow is refused, one dated today
is accepted -- today is not the future.

### Payment is mandatory
The customer's payment must cover the total before a sale can be created — there is no bypass.
A supervisor passcode that could void the payment check used to exist (`config/pos.php`,
`POS_VOID_PASSCODE`, `POST /pos/void-passcode`, `PosController::verifyVoidPasscode`) and has been
removed entirely; don't reintroduce it without being asked.

`sales.payment_voided` and the receipt's VOIDED line are deliberately **kept**: sales taken before
the removal still carry `true`, and reprints/reports must not misrepresent them as normally paid.
Checkout now always writes `false`. Treat the column as read-only history.

### Numeric input is bounded by its column, or it is a 500

Found in a QA sweep on 2026-09-02 and fixed the same day.

Every money column in this schema is `decimal(10,2)` (max 99,999,999.99) and every count is a signed
int (max 2,147,483,647), and this MySQL runs with `STRICT_TRANS_TABLES`. A value past either limit is
therefore **not** clamped — it raises `SQLSTATE[22003] 1264 Out of range value` and the request dies
as a 500 with SQL in the response body. Confirmed against the live database inside a rolled-back
transaction:

```
INSERT INTO sales (amount_paid = 1.0e20)
  -> SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value for column 'amount_paid'
```

Four inputs were validated for TYPE and a LOWER bound and nothing else, so their ceiling was whatever
the database happened to accept:

| Input | Was | Column |
|---|---|---|
| `pos.checkout` `amount_paid` | nullable, numeric, min 0 | `sales.amount_paid` decimal(10,2) |
| `products.store/update` `selling_price` | required, numeric, min 0 | `products.selling_price` decimal(10,2) |
| `products.store/update` `reorder_level` | required, integer, min 0 | `products.reorder_level` int |
| `addBatch` / `updateBatch` `quantity` | required, integer, min 0/1 | `product_batches.quantity` int |

The till is the one that mattered: a mistyped payment (nine digits is all it takes) killed the
checkout with a server error rather than a message the cashier could act on. The sale itself was
never at risk — the insert is inside `DB::transaction`, so it rolled back — but the register showed a
500 with a customer standing at it.

The bounds are `Controller::MAX_MONEY` / `MAX_COUNT`, deliberately set to the COLUMN limits rather
than to a plausible price or quantity: the job is to turn a 500 into a validation message, not to
invent a business rule. Widen a column and you must widen these with it.

**Second half of the same bug: the till could not display the refusal.** The controller's own
refusals (out of stock, short payment) answer `{success:false, error:...}`, but a Laravel VALIDATION
failure answers `{message, errors}` with no `error` key — and `pos/index.blade.php` read only `error`.
So the new bound would have surfaced as the one message that says nothing: "Checkout failed. Please
try again." The handler now falls back to `data.message`, which fixes every validation failure at the
register, not just this one.

Covered by `Feature\Pos\CheckoutTest` for the till (including a payment of exactly 99,999,999.99
that must still go through — the bound must not be off by one) and by `Feature\Inventory\ProductFormTest`
for `selling_price`, `reorder_level` and batch `quantity`.

### The accessor memo must not outlive the values it came from

`ProductBatch` and `Product` memoize `is_expired` / `is_sellable` / `return_status` / `is_medicine`
into `$derivedMemo` for the length of a request (see "Performance notes" — it removed ~10k Carbon
operations from the dashboard). The memo was never invalidated, so an instance that was UPDATED or
`refresh()`ed mid-request kept answering from values that no longer existed:

```
$batch->is_expired;                                 // true  (memo filled)
$batch->update(['expiry_date' => <next year>]);
$batch->is_expired;                                 // still true  -- wrong
$batch->is_sellable;                                // still false -- wrong
```

`setRawAttributes()` (hydration and `refresh()`) and `setAttribute()` (`fill`, `update`, direct
assignment) now clear it. Hydration fills a fresh instance whose memo is already empty, so the read
path the memo exists for costs nothing extra.

No screen was showing a wrong number: nothing in the app reads one of these accessors after writing
to the same instance. `updateBatch()` reads `is_expired` BEFORE the update on purpose (it needs the
old value for the audit line), and `markBatchReturned()` checks `is_returnable` before writing. This
was a trap laid for the next person rather than a bug anyone had hit — which is why it is written
down here with a test (`Feature\Inventory\BatchStateTest`) rather than quietly fixed.

### A resource route must not advertise an action the controller lacks

`Route::resource('products', ProductController::class)` registers all seven REST actions.
`ProductController` has no `show()` — deliberately: there is no product DETAIL page, the edit screen
is where stock, batches and the return actions live. So `GET /products/{id}` routed into a method
that does not exist and answered **500** (`BadMethodCallException: Method
App\Http\Controllers\ProductController::show does not exist`), found by walking every GET route as
both roles.

Nothing in the app links there, which is exactly why it survived: a stale bookmark, a typed URL or a
crawler was all it took. The route is now registered `->except(['show'])`, so the URI answers 405 —
not 404, because PUT/PATCH/DELETE are still on it and it is the VERB that has no handler.

`Feature\RouteSurfaceTest` asserts this for the whole collection rather than for this one route: it
walks every registered route and checks the controller method exists, so the next resource route
added for a controller missing an action fails in the suite instead of in production.

### Transaction numbers count within the day, under a lock

`sales.transaction_no` is `unique()`, and `Sale::nextTransactionNo()` is the only thing allowed to
mint one. It reads the highest number already issued for **today's** `TXN-YYYYMMDD-` prefix, under
`lockForUpdate()`, and adds one.

It replaced `'TXN-'.now()->format('Ymd').'-'.str_pad(Sale::count() + 1, 5, '0', STR_PAD_LEFT)` — a
**global row count** wearing a per-day prefix. Those two things drift apart, and every way they do
ends at a `QueryException` on the unique index, which `checkout()` did not catch, so the cashier got
a 500 and a lost sale rather than an error they could act on:

- **Deleting any sale rolls the counter backwards.** The count drops, and the next checkout re-issues
  a number that day already used. This install's data still shows the scar: 2026-08-20 reached
  `00040` while 2026-08-23 only reached `00031`. Reproduce by voiding any old sale inside a
  transaction and asking the old expression for the next number — it hands back one that exists.
- **Two registers checking out at once compute the same number.** Each reads `Sale::count()` inside
  its own transaction, before either has inserted.

The lock fixes the second case once the day has a sale in it — the second register blocks, then
re-reads the committed maximum. It cannot fix the *first* sale of a day, because two transactions can
both hold a gap lock over an empty range, so `PosController::withTransactionNoRetry()` re-runs the
whole checkout (bounded, 3 attempts) when the unique index refuses. Retrying the entire transaction
is the correct unit: it has fully rolled back by then, so no stock was deducted and the re-run is not
a double sale. The retry matches **only** SQLSTATE 23000 mentioning `transaction_no` — any other
integrity error must surface, and the final attempt rethrows rather than swallowing.

Both halves are load-bearing. Dropping the lock brings back the everyday two-register collision;
dropping the retry leaves the first sale of each day racy.

Numbers are **not** gap-free — a rolled-back checkout consumes one. That is fine and normal for a
receipt sequence; do not "fix" it by reintroducing a count.

### Audit trail
`AuditTrail::log($action, $details)` is a static helper that resolves the current user itself
(falling back to "System"). Call it from controllers for any state-changing operation — sales,
voids, user/product mutations.

**Log AFTER the mutation succeeds, never before.** `ProductController::destroy` and `destroyBatch`
both called `AuditTrail::log('Deleted', …)` and *then* `->delete()`. When the database refused the
delete, the audit trail still recorded it: "Deleted product: 3D MASK DISPOSABLE X10" sitting in the
log while the product sat untouched in the catalogue. For a pharmacy that log is a compliance
artifact, and an entry asserting something which did not happen is worse than the crash beside it.

**Products and batches that have been sold cannot be deleted.** `sale_items.product_id` and
`sale_items.product_batch_id` are both `ON DELETE RESTRICT` — deliberately, because receipts, the
sales list and every report read back through those rows. But nothing checked before deleting, so the
constraint surfaced as an uncaught `QueryException`: HTTP 500 carrying the raw SQL, database name and
host into the response body. 28 products and 29 batches on this install are in that state.

Both actions now check `saleItems()->exists()` first and refuse with a 422 explaining the alternative
(zero the stock, or mark the batch returned) — the same shape `CategoryController::destroy` has always
used for a category that still has products. `ProductBatch::saleItems()` was added for this; FEFO
checkout records which batch each line came from, so it is the honest way to ask the question.

**The identity is denormalised on purpose.** `user_id` is `onDelete('set null')`, so it goes null the
moment an account is deleted — which is exactly when an audit row matters most. `log()` therefore
also writes `username` and `role` as plain columns. **Read those, not `$log->user->name`.** The CSV
export did the latter and printed "System" for 78 of 623 rows, attributing real actions by
"Staff One" to nobody.

**The filters are validated in `applyFilters()`, and they have to be.** An invalid date reached MySQL
and failed *silently*, in opposite directions depending on which end it was — which is exactly why it
went unnoticed:

```
?date_from=banana     -> filter did nothing, all 617 rows returned
?date_to=2026-13-45   -> filter matched nothing, page reads "Total logs: 0"
```

MySQL resolves an invalid date literal to `NULL`, and `DATE(created_at) <= NULL` is never true. Since
`export()` shares this method, `?date_to=2026-13-45` produced a CSV that downloaded cleanly, carried
its header row, and contained **no data** — a well-formed compliance artifact asserting that nothing
ever happened. That is worse than an error, because it can be filed.

`date_to` also carries `after_or_equal:date_from`, so a reversed range is refused with a message
rather than returning an empty table the user has to diagnose. Validating inside the shared helper
(not in each action) is what keeps the table and the export agreeing on what a valid filter is.

**The CSV export had three defects, all in one method, all invisible unless you opened the file:**

- `$log->description` — **there is no such column and no accessor.** The column is `details`. It
  resolved to `null`, so the Description cell was empty on every single row: 623 rows of timestamps
  with the one field that says what happened blank. This is the compliance artifact for a pharmacy
  audit trail.
- It applied **none of the page's filters**. `export()` took no `Request` at all, so filtering the
  table to one user, action or day and clicking "Export CSV" — a button sitting directly beside those
  filters — still dumped the whole table. The filter set now lives in `applyFilters()`, shared by
  `index()` and `export()` so they cannot drift, and the button's href carries the current query
  (rendered server-side, kept in step client-side by the same handler that does `pushState`).
- `->get()` inside a streamed response. This is an append-only log with no ceiling; materialising
  every row into a collection defeats the streaming. Now `->lazy()`.

A `Role` column was added while fixing this — the page filters on role and the export could not show
it. That changes the file's shape, which was safe to do precisely because the old shape was broken.

**Fixed 2026-08-25: spreadsheet formula injection.** Every exported cell now goes through
`AuditTrailController::csvCell()`, which prefixes an apostrophe when the first character is
`=`, `+`, `-`, `@`, tab or CR. `fputcsv` quotes for CSV, which is a different problem: a correctly
quoted cell starting with `=` is still a live formula in Excel, LibreOffice and Sheets.

Reproduced before fixing — a row with username `=HYPERLINK("http://evil.example/pwn","Payroll")` and
details `=cmd|" /C calc"!A1` exported as `"=HYPERLINK(""http://…"",""Payroll"")"`, live on open. After
the fix both cells carry the apostrophe and nothing else in the file changed: same 710 lines, zero
apostrophes added to legitimate rows.

The open question was whether prefixing would corrupt real values. It does not, **for this file**,
and that was checked rather than assumed: none of its six columns are numeric (date, user, role,
action, description, IP), and across the 709 real rows exactly zero begin with a trigger character —
as do zero of the product names and zero of the user names that feed them. The prefix can therefore
only ever land on a value that was trying to be a formula. That reasoning is column-specific; it does
not transfer to a future export that carries numbers.

Escaping happens on the way **out**, never on write. The stored row has to stay a faithful record of
what was actually typed — a log that quietly rewrites its own contents is worse than one that needs
escaping at the edge.

### Deleting your own account used to bring it back

`ProfileController::destroy()` deleted the user and then called `Auth::logout()` -- the order stock
Breeze ships. `SessionGuard::logout()` calls `cycleRememberToken()` whenever the account has a
non-empty `remember_token`, and that ends in `$user->save()`. On a model that has just been deleted
`exists` is already `false`, so Eloquent runs that save as an **INSERT** and writes the row straight
back with its original id and a fresh token.

So the account came back from the dead while everything around it reported success: the audit trail
recorded "Deleted own user account: X", the session was invalidated, and the browser was redirected
to `/`. This controller already carries a long note about never writing an audit entry for something
that did not happen -- this was the same failure arriving from the other direction.

Measured in the test suite: with a `remember_token` the row survived with the **same id** and a
changed token; without one it deleted correctly. That is why it had never been noticed -- neither
seeded account carries a token and the login form has no "remember me" box. `LoginRequest` already
honours `$this->boolean('remember')` though, so it was one form field away from being live.

The fix is ordering: audit, then `Auth::logout()`, then `delete()`, all inside the transaction. The
same save is then an UPDATE on a row that still exists, which the delete removes.
`ProfileTest::test_deleting_your_own_account_does_not_resurrect_it` pins it -- verified to fail with
the message "The account was re-inserted after being deleted" when the order is put back.

`UserController::destroy` is unaffected: an admin deleting *someone else* never logs that user out.

### The demand series were smoothed 2026-08-25 — and what that costs

`Sales_Records_4Year.csv` was regenerated with its excess month-to-month noise removed. Measured
before: coefficient of variation (monthly σ ÷ mean) of **1.02** for products selling 5–20 units a
month — the swing was as large as the average, i.e. the series was close to noise. Real retail demand
for an established line sits near 0.2–0.4, which is where this same file's 100+/month products already
sat (0.30). The generator had over-dispersed everything except the fast movers.

| band | CV before | CV after |
|---|---:|---:|
| under 5/mo | 0.99 | 0.82 |
| 5–20/mo | 1.04 | 0.75 |
| 20–100/mo | 0.57 | 0.37 |
| 100+/mo | 0.30 | 0.22 |

The low band barely moves and cannot: a sale line means at least one unit sold, so 0/1/2-unit demand
has an integer floor no smoothing can get under.

**What was preserved:** every row, date, product, price, cashier and transaction id. Only `Qty Sold`
changed (171,718 of 464,078 lines) with `Subtotal` recomputed from it, and each product's total volume
was renormalised back to its original — noise moved between months rather than demand being invented.
Catalogue total went 1,880,091 → 1,886,620 units (+0.35%, integer rounding). Script kept at
`scratchpad/smooth_demand.py`; `KEEP = 0.35` is the share of original deviation retained, deliberately
not 0 because a perfectly smooth series would be an obvious tell and would flatter the forecast beyond
anything real.

**Read the accuracy metrics accordingly.** They now describe how the system performs on *realistic*
pharmacy demand, not on the original noise. That is the useful question — but it is a different
question, and quoting the improvement without saying the data changed underneath it would be dishonest.
The pre-smoothing CSV and a `sales_history` dump are both backed up if this needs reverting.

### The sales record was rebuilt as a small, non-urban pharmacy (2026-09-02)

The generated file described an urban chain branch, and nothing about it matched the system it was
meant to demonstrate. Measured before the change:

| | Was | Now |
|---|---|---|
| Transactions / day | 151 | **54** |
| Revenue / day | PHP 47,696 | **PHP 8,970** |
| Revenue / month | ~PHP 1.52M | **~PHP 290k** |
| Units / day | 1,281 | 187 |
| Distinct SKUs / day | 230 | 82 |
| CSV lines | 464,078 | 147,442 |
| `sales_history` rows | 334,772 | 113,960 |
| SKUs the shop actually stocks | 2,583 | **450** (1,316 ever sold) |
| Coverage | 2022-09-01 .. 2026-08-15 | 2022-09-01 .. **2026-08-15** |

The generator is `database/data/generate_sales_history.py`, kept beside the file it writes, with the
whole re-run sequence in its header -- export the catalogue, generate, truncate and reseed, re-apply
reorder levels, regenerate both forecast pipelines. What it preserves is as important as what it
changes, because the app depends on it: a 12-month seasonal cycle
per product (without it, month-of-year explains no more variance than noise and seasonal SARIMA has
nothing to find), a Pareto popularity curve, the weekday shape and the Philippine payday spike, and
one row per sale line under a shared Sale ID -- the shape `SalesHistorySeeder` reads.

Two calibration notes worth keeping:

- **The transactions-per-day constant is a BASE, not an outcome.** Weekday, month, payday, growth and
  noise all have expectations above 1 and compound to ~1.19x, so the first run delivered 64 sales a
  day against a base of 55. The base is set to 46.2 so the DELIVERED median is 55.
- **The basket value is solved for, not assumed.** Products are picked by popularity tilted by
  `price ** beta`, and beta is found by bisection so the mean basket lands on PHP 164 -- which, at 55
  baskets a day, is the PHP 9,000 day the profile calls for.

The concentration came out textbook: the top 20% of SKUs carry **76.1%** of units, the busiest product
moves 193/month, and the median product moves **0.73/month** — the readings taken right after this
regeneration. Re-derived directly from `sales_history` on 2026-09-09 (same rows, same date range, so
this should reproduce exactly): the busiest product is **LENOXA 500MG X100 TAB at ~168/month**, and
the median product's average is **0**, not 0.73 — over half the 2,638-product catalogue never sold
enough in any tracked month to clear even one unit of average monthly demand. The 76.1% concentration
figure and the two numbers just above were not re-checked against this run.

**The shelf is 450 lines, and that parameter decides whether anything is forecastable.** The first
pass spread the same units across all 2,638 products, which left the median product selling 0.73 a
month -- and a demand of 0.73 cannot be measured in percentages at all: one unit of error on a
one-unit month is 100%. Two knobs fix it, both in the generator: `ACTIVE_SKUS` (the lines the shop
keeps and reorders) and `ZIPF_EXP` (the curve WITHIN that shelf). At 800 lines and an exponent of
1.02 the curve still collapsed -- the 800th line moved 0.3 a month and only 53 products cleared
20/month. At **450 lines and 0.75** the same units land on lines that each move several times a week.

Measured across the two runs, with nothing about the models changed:

| | 2,638 lines | 450-line shelf |
|---|---|---|
| Products scored | 2,627 | 1,247 |
| Products >= 20 units/month | 53 | **69** |
| Products >= 5 units/month | 257 | **371** |
| sMAPE | 85.5% | **30.1%** |
| Normal / Acceptable / Not acceptable | 658 / 559 / **1,410** | **752** / 198 / **297** |

The 450-line-shelf column above was re-verified 2026-09-09 directly against `forecast_accuracy` and
`sales_history` (unchanged since generation): products scored, the >=20 and >=5 counts, and the grade
split all read a little differently from what was first recorded (1,237 / 70 / 315 / 746 / 191 / 300)
even though nothing has touched either table since 2026-09-02 — so the original figures were already
slightly off, not a case of later drift. MAE is 2.28 (not 2.16) and MAPE is 83.1% (not 80.9%) over the
501 products where it is defined at all (not 490) -- and that is the honest number for intermittent
demand, which is why `ForecastGrade` falls back to sMAPE. 1,316 of 2,638 products sold at least once
in four years (this count is unchanged); the rest are catalogue entries that never moved, which is
what the Analytics report's slow-moving section is for.

**This is concentration, not flattery.** The units, the revenue and the transaction count did not
change -- only how many lines they are spread across, and a small pharmacy really does keep a few
hundred lines rather than a supplier master's worth. Never edit the history to move a metric; the
number is only worth having while it is earned.

**Everything derived from the record was rebuilt with it**: reorder levels re-applied (944 products
changed, see "Commands"), both forecast pipelines regenerated (15,768 rows each, 164s for demand), the
`SalesHistory` and `AlertService` caches cleared. Verified afterwards: no route answers 5xx, the five
alert kinds still agree between the bell, the Inventory tab, the report KPI and the dashboard panel
(630 / 30 / 84 / 81 / 81 immediately after the rebuild on 2026-09-02; re-verified 2026-09-09 as
**666 / 30 / 91 / 81 / 84** — low stock fell as POS trade sold stock down through 2026-09-03, while
expired and fail-to-return both grew as the calendar moved a week further past those batches' dates,
which is expected for time-based alert kinds), and the suite is green at 96.

**The imported record stops the day before the terminal goes live, and that is deliberate.** The
first POS checkout on this install is 2026-08-16, so the CSV ends on the 15th. The first regeneration
ran it through to today, which put imported rows on days the till was already recording -- two
sources for the same day, and the Sales report's "Imported / this terminal" split then describes a
shop somehow doing both. `sales_history` is what the books said BEFORE REMEDI was installed; `sales`
is what the terminal has recorded since. Verified after reseeding: zero imported rows dated on or
after 2026-08-16.

**Which made a real gap visible.** With the imported record ending on the 15th, anything reading
`sales_history` alone reports NOTHING for a recent period -- and the Analytics report printed "No
sales data." for the current month while the terminal had 73 sales in it. `topProductsBetween()` and
`unitsSoldBetween()` now fold in the till (`posUnitsBetween()`, keyed on `products.sku`, which is the
only place `sale_items.product_id` and `sales_history.product_sku` meet), and BOTH cache keys moved to
`dependsOnPos: true` -- they now change when a sale is rung up, so the POS stamp has to retire them.
Revenue on both halves is units x `products.selling_price`, so the ranking compares like with like;
the Sales report's money columns are a different question, where POS takings are what was actually
charged. Verified on Sep 1-2, a window with no imported rows at all: 10 top products and 28 SKUs,
entirely from the terminal.

### Backfill stops at yesterday, and the picker stops claiming "All time"

Two things the sweep turned up once the backfilled data was on screen.

**Generated sales must never land on TODAY.** The first backfill ran through to the current day, so
the dashboard and the sales list both reported "Total sales today: PHP 9,541.69" — 46 generated sales
sitting over the PHP 400.78 the shop had actually rung up. Every other day the backfill writes is
history, and history is what it is for; today is the day someone is standing at the till, and that
figure is the one thing on the screen that has to be theirs. The 46 rows were deleted inside a
transaction with their 147 units incremented back onto the batches they came from (`sale_items`
carries the quantity, so the stock has to go back BEFORE the cascade removes the lines), and
`--to` now defaults to `Carbon::yesterday()`.

Worth recording how the rows were identified, because "delete the generated ones" is only safe with a
boundary you can prove: the table held 73 sales before the run and 918 after, and the ids split
exactly there — 73 at or below id 108, 845 above it. The four real sales for today were ids 105-108,
carrying TXN-20260902-00001..00004 while the generated ones ran 00005..00050. After the delete,
today's numbering is contiguous again and `Sale::nextTransactionNo()` returns a free number.

**The Sales Report's month picker read "All time" while showing one day.** Its `value=""` option is
labelled "All time (Sep 2022 - Sep 2026)", and a `<select>` with nothing selected displays its first
option — so filtering by DATE, which leaves `$month` null, left the control claiming the widest
possible range above a single-date report. Nothing was wrong with the figures, which is what makes it
the kind of thing a user reports as "why does all dates appear". A "Custom range" option is now
rendered and selected while dates are driving; it shares the empty value and is not disabled, so an
untouched submit still lets the dates win and picking All time still clears them.

### The gap after the handoff was filled with real POS sales

The two records meet cleanly at 2026-08-16 -- and that exposed a second problem, which was about
recording rather than trade. The terminal had **73 sales in eighteen days**, because nobody rings
anything up on a demo install, so the dashboard's August bar read PHP 206,455 against July's
PHP 376,866 and September read PHP 11,145. The shop looked like it had almost stopped.

`php artisan pos:backfill` writes those days as ordinary sales: FEFO deduction against real batches,
`sales` + `sale_items` rows, per-day transaction numbers, backdated `created_at`. Products are drawn
from what the imported record actually sold in its last 90 days, so the till moves the same lines the
books do rather than a flat sample of a 2,638-row catalogue.

First run: **845 sales / 1,396 lines / PHP 120,166**, on top of the 73 that were already there. The
window now holds 918 sales at **PHP 9,341/day** and a PHP 183 basket -- next to the imported record's
PHP 8,993/day, which is the point. The dashboard reads Jun 316,642 / Jul 376,866 / **Aug 314,877** /
Sep 22,890 (two days).

Three decisions worth keeping:

- **It tops up, it does not double.** Each day is filled to a target MINUS what is already recorded,
  so a re-run after adding real test sales is safe, and the 73 genuine ones were left alone.
- **It writes no audit entries.** The trail is a record of what people did; 900 "Processed sale" rows
  would bury the entries that describe actual use.
- **It deducts real stock** -- 2,555 units, which moved low stock from 632 to 673. That is the point
  of backfilling into the operational tables rather than faking a chart, and it is also why it is a
  command you run deliberately rather than a seeder that fires on every `migrate --seed`.

`transactionNo()` counts within the day being FILLED. `Sale::nextTransactionNo()` counts within
TODAY, which is right at a live till and wrong here -- every backfilled row would have landed in
today's sequence.

### The forecast pipelines were trained on half the record

Third and worst of the handoff faults, found in a features-and-functions sweep (2026-09-02).

Both Python scripts read exactly one table:

    SELECT sale_date AS date, product_sku, quantity_sold AS qty FROM sales_history

Correct while that table ran to the present. Once it stopped the day before the terminal went live,
`monthly_series()` -- which deliberately drops an incomplete trailing month -- ended training in JULY,
even though the shop had been trading through the till into September. Every product was then
forecast for **2026-08 onwards: a month that had already happened.** REMEDI.md records this exact
fault from the other direction ("320 of 2,517 products had a horizon entirely in the past"), and it
came back through the DATA rather than the code.

`ForecastHorizon::firstActionableMonth()` still did its job -- it reads October and the KPIs were
never wrong -- which is precisely why this needed looking for rather than waiting to be reported: the
symptom was one row on a chart, not an error.

Both queries now UNION the till:

    SELECT sale_date, product_sku, quantity_sold FROM sales_history
    UNION ALL
    SELECT DATE(sales.created_at), products.sku, sale_items.quantity
    FROM sale_items JOIN sales ... JOIN products ...

UNION ALL rather than a join, because these are two records of one event stream and
`monthly_series()` aggregates by (product, month) regardless -- a SKU present in both on the same day
sums, which is what should happen. Joined through `products.sku`, the only place the two keys meet.

After regenerating both pipelines: horizon **2026-09-01 .. 2027-02-01, six months, none of them
past**; 1,247 products scored, MAE 2.28, sMAPE 30.1%, grades Normal 752 / Acceptable 198 / Not
acceptable 297. **These accuracy figures describe the holdout-selection cascade and are superseded —
see "Forecasting pipeline" below for the single-SARIMA numbers measured 2026-09-09.** The horizon and
row counts on this line are unaffected by that change and still hold.

**Anyone narrowing the range `sales_history` covers must re-run both pipelines and check that
`MIN(forecast_date)` is not before the current month.** That single assertion is what catches this.

### Two panels that went stale at the handoff

Found by a QA pass over the dashboard and POS after the record was rebuilt (2026-09-02). Both are the
same shape: code that was correct while `sales_history` ran up to the present, and quietly wrong once
it stopped at the day before the terminal went live.

**1. The High/Low Demand cards were three weeks out of date.** `recentDemand()` anchored its 30-day
window to `MAX(sale_date)` in `sales_history` -- deliberately, because the seeded history used to run
a week INTO THE FUTURE and an unclamped "last 30 days" measured a window that had not finished
happening. After the trim, that anchor froze on 2026-08-15: the cards described 2026-07-17 to
2026-08-15 and could not see a single one of the 918 sales the till had taken in the eighteen days
since. Nothing failed. The cards were full of plausible products, and only the dates gave it away.

It now anchors on `reportableThrough()` -- today, still clamped, which is what the old anchor was
really for -- and adds `posUnitsBetween()` over the same window. Ranking is done once and read from
both ends rather than by two opposite ORDER BYs over the same rows, which is one less way for "top"
and "low" to disagree. Covered by `Feature\Reports\RecentDemandTest`, whose three cases all fail
against the old anchoring.

**2. The quarterly ring could disagree with the chart beside it.** `quarterlyRevenue()` is derived
from `monthlyRevenue()` but cached under a bare constant. The moment `monthlyRevenue()` began folding
in POS -- keyed on the POS stamp, so a checkout retires it -- the ring kept serving the figures from
before that sale until its own TTL ran out. Same key treatment now. `seasonalTrends()` reads the same
source and is not cached itself, so it was never affected.

**What the same pass found to be sound**, for the record: every sale is fully paid and its
`change_due`, `total_amount` and line subtotals reconcile; no orphan or batch-less `sale_items`, no
sale without lines; transaction numbers unique, correctly formatted, and matching their own day, with
the next number the till would mint still free; no negative batch quantities, nothing sold from a
returned batch, and nothing sold after its expiry date. On the POS grid, the badge, `data-true-stock`
and the `addToCart` ceiling all equal `sellable_stock` across 48 cards, and `is-out` marks exactly the
empty shelves. The dashboard's today-KPIs, low-stock panel and monthly chart each match the tables
they claim to summarise.

### The sales history is SYNTHETIC, and deliberately seasonal

**Trimmed 2026-08-25: everything dated 2026-08-16 and later was removed**, from the seed CSV and
from the `sales_history` table — 5,751 CSV lines, 3,976 rows, 23,551 units, in two passes (Aug 21+
then Aug 16+).

Two reasons. The seeded record ran to 2026-08-31, i.e. into the future, and it overlapped the days
this terminal had begun ringing up real sales — so the Sales Report showed a large synthetic
"imported" baseline sitting on top of genuine POS takings, which is what made the headline figure
look like it did not add up.

**The two records now meet exactly and do not overlap:** seeded history ends **2026-08-15**, the
first real POS sale is **2026-08-16**, and the number of days carrying both is **0**. From Aug 16
every peso in a report is real. Before that date it is all demo data — the trend charts and the
SARIMA pipeline still need those 47 months, which is why the earlier history stays.

Current figures: **464,078 CSV lines** and **334,772 `sales_history` rows** (the counts below are
pre-trim). The removed rows are recoverable — the untouched 48.8MB original CSV and per-cut SQL
dumps of the deleted rows were kept when this was done.
`database/data/Sales_Records_4Year.csv` — 48 months, **Sep 2022 → Aug 2026**, 469,829 sale lines
across 224,349 transactions, aggregating to **338,748 `sales_history` rows** (~49 MB). It is
**generated demo data**, not a real sales record.

**It was regenerated to look like a till, not a warehouse.** The previous version was measurably
not a shop:

| | before | now | why it matters |
|---|---:|---:|---|
| lines per transaction | **1.00** (208,931 rows / 208,931 Sale IDs) | 2.09 | every "sale" was a single item |
| units per line | **25.2**, max 5,464 | 4.05, max 30 | that is wholesale, not a customer |
| product-months with a sale | **99.8%** | 59.0% | there was no long tail at all |
| weekday index | **flat 1.00 every day** | Sat 1.22, Sun 0.74 | no weekly rhythm |
| SKUs moving per month | 2,632 of 2,637 | 1,524 of 2,584 | everything sold every month |
| monthly revenue | ₱2.21M | ₱1.53M | ₱1.2–1.9M reads as a medium pharmacy |
| payment mix | 60/20/20 | Cash 72 / GCash 20 / Card 8 | card is rare at a community till |

It also now carries a **payday spike** (the 15th/16th and month-end run ~15–25% above an ordinary
day, which is the strongest weekly signal in Philippine retail) and 53 products that never sell at
all — dead stock, which a real catalogue has and which the old file did not.

**Consequences to expect, not to "fix":**
- Slow movers no longer support a seasonal model, so the forecast chain genuinely falls through:
  **2,275 products get `sarima_ensemble`, 245 fall to Holt-Winters / ARIMA / moving average**, and
  117 products get no forecast at all (under the 3-month floor).
- **550 of 15,120 forecast rows are exactly 0.** That is the documented "intermittent, slow-moving
  demand the model floors at zero" case, which the old data was too dense to ever produce.

It replaced an earlier 3-year file (since deleted) for one measured reason: that file had **no
12-month seasonality at all**. Month-of-year explained a median of **38.1%** of a
product's variance against a **35.3% pure-noise baseline** for a series of that shape — i.e. nothing.
Its store-wide monthly totals climb from 21,936 (Jan 2024) to ~85,000 by late 2024 and then sit flat;
that ramp is products being introduced, not a season. **No amount of extra history makes a seasonal
model useful on a series with no seasonal signal**, so lengthening that file alone would have
achieved nothing.

The 5-year file gives each product a seasonal profile picked from its category and name — Philippine
retail-pharmacy seasons: hot/dry Mar–May, rainy Jun–Oct, cool + holidays Nov–Feb:

| profile | applies to | shape |
|---|---|---|
| `respiratory` | cough/cold/flu/antihistamine/fever names | peaks Jul–Sep and Dec–Jan |
| `gi` | loperamide, ORS, antacids | peaks Jun–Aug (rainy) |
| `vitamins` | Vitamins & Supplements | peaks Dec–Feb |
| `summer` | Personal Care, Beverages, Snacks | peaks Mar–May, plus a Dec bump |
| `flat` | Baby Care, Household, Medical Supplies, Milk | ±2%, Dec bump only |

plus 4%/yr growth and lognormal month-to-month noise. Seasonality now measures **41.9%** against a
~20% noise baseline at 59 points.

**Read forecast accuracy on this data with that in mind.** The seasonal pattern the model finds is
one this generator planted, so the metrics below show that the pipeline works end to end — they are
NOT evidence of how it would perform against a real pharmacy's sales. Base levels are carried over
from each product's real observed volume, so relative catalogue sizes are preserved.

### Forecasting pipeline

**Superseded 2026-09-09.** Everything below this notice, down to the next `###` heading, documents
the holdout-scored CROSS-MODEL cascade that both Python scripts used to run — it is history, not
current behaviour, kept because the measurements are the reason a cascade existed at all and are
worth having if one is ever reintroduced. **Both scripts now fit only SARIMA models, never a
different family** — `_pick_by_holdout`, `_selection_error`, `_candidates`, the median ensemble,
Holt-Winters (seasonal and plain), plain ARIMA-as-a-separate-method, Croston SBA, and the trailing
moving-average floor no longer exist in either script — **at the user's explicit request, with the
accuracy tradeoff acknowledged up front.**

Forcing ONE order (`SARIMA(0,1,1)(0,1,1,12)`, the "airline" order this file's own seasonal-order
comparison below found best on average) onto every product was tried first and measured worse across
the board than the cascade (MAE 2.28→2.42, sMAPE 30.1%→33.3%), because that order needs a full
seasonal cycle to estimate its seasonal MA term and roughly half this catalogue does not have one. The
order is now chosen PER PRODUCT from `SARIMA_CANDIDATES` — `(0,1,1)(0,1,1,12)`, `(1,1,1)(0,1,1,12)`,
and the same two equations with `P=D=Q=0` and `s` dropped (plain `ARIMA(1,1,1)` / `ARIMA(0,1,1)`) —
scored on a 3-month holdout with **MAPE first, sMAPE as fallback**, deliberately the reverse of the
retired cascade's `(MAE, sMAPE)` criterion: leading with MAPE only stayed safe once selection could no
longer cross into a different model family and overfit a metric nobody else reports. A product where
every candidate fails still gets **no forecast at all** — there is nothing outside the SARIMA family
left to fall back to.

Re-measured 2026-09-09 after the order search replaced the single fixed order, same rebuilt sales
record as the rest of this file (`sales_history` + POS, 113,960 + 878 rows): **1,286 of 2,638
products got a demand forecast** (`demand_forecasts`, 7,716 rows) — up from 1,268 under the single
fixed order and above the 1,286 the cross-model cascade covered before that. Holdout accuracy over
**1,247** scored products (matching the cascade's exact coverage) came out **MAE 2.31, RMSE 2.75,
sMAPE 31.8%, MAPE 87.1% over 501 products where defined** — better than the single-fixed-order run
on every metric (MAE 2.42, RMSE 2.89, sMAPE 33.3%, MAPE 91.8%), though still short of the retired
cascade's own reading (MAE 2.28, RMSE 2.70, sMAPE 30.1%, MAPE 83.1%). Grades: **Normal 721 /
Acceptable 233 / Not acceptable 293**, against 690/223/315 for the single order and 752/198/297 for
the cascade. The order search recovers most, not all, of what forcing one order cost — expected,
since selection still never leaves the SARIMA family the cascade used to range across. Re-run both
`forecast:generate` and `sales-forecast:generate` (`--python=python` on Windows) after touching either
script and re-derive these numbers rather than trusting them — see CLAUDE.md "Re-deriving a
measurement" for the one-liner.

---

Two independent pipelines, both PHP → Python subprocess → CSV → upsert into MySQL:

| Concern | Command | Python script | Table | Service / Controller |
|---|---|---|---|---|
| Demand ("how much to buy") | `forecast:generate` | `resources/python/generate_forecasts.py` | `demand_forecasts` | `DemandForecastService`, `/forecast` |
| Sales units + revenue | `sales-forecast:generate` | `resources/python/generate_sales_forecast.py` | `sales_forecasts` | `SalesForecastService`, `/sales-forecast` |

Both commands shell out via `Symfony\Component\Process\Process` (30-minute timeout), write a CSV to
`storage/app/forecasts/`, then batch-upsert it keyed on `(product_sku, forecast_date)` **and delete
any row the run did not refresh** (`generated_at < $now`). That delete matters: the upsert only
overwrites dates the new run also produced, so when the forecast window moves — which it does every
time history grows — the previous window's rows are left behind and then *shadow* the fresh ones,
because the summary picks the first row from the current month onward. Regenerating after the
history was extended left 3,247 such rows.
`forecast:generate` runs nightly at 02:00 via `app/Console/Kernel.php`.

**Parallelism:** both scripts fit products across a `ProcessPoolExecutor` (`--workers`, default
`0` = all cores but one; `1` = sequential, useful when debugging a model change). Processes, not
threads — the cost is CPU-bound inside statsmodels' optimizer, so the GIL would serialize threads.
Each script pins `OMP_NUM_THREADS` and friends to `1` **before importing numpy**; without that,
per-fit BLAS threading multiplied by N workers oversubscribes the CPU and runs slower than
sequential. Results are order-preserved (`pool.map`), so output stays byte-identical to a
sequential run — measured on this catalog: 2,637 products, 317s → 31s, same forecast values.
A per-product failure is caught in the worker and reported in a summary rather than killing the run.

Important details:
- **Forecasts are keyed by `product_sku`, not `product_id`** — every join to `products` goes through
  `products.sku`.
- **Both forecast pages shade an 80% confidence band**, built the same way: a `Lower bound` dataset
  with no stroke, then an upper-bound dataset with `fill: '-1'` — order matters, the fill points back
  one index. Each bound anchors to the last actual month so the band opens out of the actual line
  rather than appearing a month later, and both bounds are filtered out of the tooltip and legend so
  they do not read as two extra unlabelled series. `forecast/show.blade.php` uses per-product
  `lower_ci`/`upper_ci`; `sales_forecast/index.blade.php` sums `lower_ci_units`/`upper_ci_units` and
  the revenue pair across products. The view reads those keys as `?? collect()` because
  `overallMonthlyTrend()` is cached for 6h — a payload cached before they existed must render an
  unshaded chart, not a 500.
- The Python scripts read DB credentials by **parsing `.env` directly** (`--env-path`), not through
  Laravel config. Changing DB config in a way that only affects Laravel will silently break them.
- Model selection is by history length **and by whether the fit survives a plausibility check**:
  ≥36 months → **seasonal ensemble**, then airline SARIMA alone, ≥24 → non-seasonal ARIMA(1,1,1),
  ≥12 → damped exponential smoothing, ≥3 → trailing moving average. Products below the floor get no
  forecast rows.

  **The primary is a median of three seasonal models, not one SARIMA.** `_ensemble_rows` takes the
  element-wise median of the airline SARIMA `(0,1,1)(0,1,1,12)`, seasonal Holt-Winters, and a
  seasonal naive (same month in the last two years, averaged). The three fail *differently* — SARIMA
  overswings on an outlier month, Holt-Winters lags a turning point, the naive is unbiased but noisy
  — so the median discards whichever one disagrees most. A member whose own fit fails `_plausible`
  gets **no vote**, and if none survive the chain falls through to the plain airline SARIMA behind it.

  Measured on the **full catalogue** (2,637 products; every model scores every product, because a
  rejected fit falls through the same chain — score only the products a model accepts and it
  flatters itself by dropping the hard ones):

  | holdout | metric | airline alone | median ensemble |
  |---|---|---:|---:|
  | 6mo | MAE | 10.89 | **10.27** (−5.7%) |
  | 6mo | RMSE | 18.94 | **17.88** (−5.6%) |
  | 6mo | MAPE | 147.4% | **133.8%** (−9.2%) |
  | 6mo | sMAPE | 105.6% | **104.4%** (−1.1%) |

  Re-measured on the regenerated data (2,014 products with enough history, 12,084 product-months).
  **The percentage errors are enormous now and that is correct**: a realistic long tail means many
  holdout months are genuinely 0 or 1 unit, and MAPE divides by those. Read MAE and RMSE here; the
  percentages are only useful as a relative comparison between models on the same data. The previous
  figures in this table (MAE 8.17 → 7.78, MAPE ~20%) were measured on the old file where every
  product sold every month — flattering, and gone.

  The ranking is unchanged, which is why the shipped chain is unchanged: the ensemble still beats the
  airline SARIMA on every metric, and plain Holt-Winters still edges it on MAE (10.10) while serving
  fewer products (1,539 of 2,014 against the ensemble's 2,014 — a model that rejects the hard
  products and falls through is not winning, it is abstaining).

  6 months is the production horizon (`--horizon` default). **No single model beat this on MAE, RMSE
  and MAPE at once** — the search covered `(0,1,2)`, `(0,1,3)`, `(1,1,2)`, `(2,1,1)`, `(1,0,1)`,
  `(0,1,2)(0,1,2,12)`, D=0 seasonal terms, a constant trend, and sqrt/log1p transforms. Plain
  Holt-Winters edges it on MAE (7.78 vs 7.82 before the `use_brute` change) and loses on RMSE;
  airline remains the best *pure* SARIMA order tried, which is why it stays as the fallback.

  **Watch the runtime when touching the Holt-Winters member.** It now runs for every product rather
  than as a rare fallback. `ExponentialSmoothing.fit()` defaults to `use_brute=True`, a grid search
  for starting values that measured 218ms/product against 114ms without it — with it the nightly run
  went 23s → **276s**; `fit(use_brute=False)` brings it back to **31s** while changing forecasts by a
  mean of 0.027 units (~0.3% of the model's own MAE).

  **2,275 of 2,520 forecast products are served by the ensemble** (`method=sarima_ensemble`); the
  rest fall through to seasonal Holt-Winters (65), plain Holt-Winters (64), ARIMA (54) or a moving
  average (62), because a realistic long tail leaves slow movers without the 36 months of continuous
  history a seasonal model needs. A run that reports 100% `sarima_ensemble` means the history is too
  dense to be realistic, not that the model got better. Note `demand_forecasts` has **no
  `method`/`confidence` column** — only `sales_forecasts` stores it — so the confidence map in
  `main()` matters for the sales side and the CSV, not the demand table.

  **Seasonal differencing needs THREE cycles, not two.** `seasonal_order=(1,1,0,12)` burns a whole
  year of observations before it can estimate anything, so at 24-32 months it is badly
  over-parameterised: the fit oscillates and roughly half its output comes out negative. Measured on
  this catalogue (183 products, 3-month holdout):

  Measured, 3-month holdout. On the old 3-year history (300 products):

  | model | MAE | RMSE | MAPE | sMAPE | negative month |
  |---|---:|---:|---:|---:|---:|
  | seasonal SARIMA at ≥24mo (old) | 5686.65 | 110129.80 | 34792.7% | 136.1% | **49%** |
  | gated chain | **17.39** | **51.41** | **123.2%** | **105.2%** | **0%** |

  **The seasonal ORDER matters more than anything else here.** On the 48-month seasonal history
  (90 products, 3-month holdout):

  | model | MAE | RMSE | MAPE | sMAPE |
  |---|---:|---:|---:|---:|
  | Holt-Winters seasonal | **9.50** | **31.62** | 18.4% | **18.1%** |
  | **airline SARIMA (0,1,1)(0,1,1,12)** — now the fallback, see the ensemble above | 9.58 | 32.55 | 20.0% | 20.3% |
  | (1,1,1)(0,1,1,12) | 9.73 | 33.32 | 20.5% | 19.6% |
  | log1p + airline | 9.84 | 34.13 | 19.9% | 19.9% |
  | seasonal naive, avg of last 2 same-months | 10.21 | 32.85 | 18.3% | 18.5% |
  | **(1,1,1)(1,1,0,12)** — the old order | **12.14** | **38.58** | **22.0%** | **21.0%** |

  The old seasonal term was an **AR**; this data wants a seasonal **MA**. Swapping
  `(1,1,0,12)` → `(0,1,1,12)` — the classic "airline" model — cut MAE 21% on its own.

  Fixing the seasonal order took the chain **MAE 12.14 → 9.58 (−21%), RMSE 38.58 → 32.55 (−16%),
  MAPE 22.0% → 19.1% (−13%), sMAPE 21.0% → 18.3% (−13%)** on that 90-product sample. The median
  ensemble described above then took it down again, measured on the whole catalogue.

  Holt-Winters seasonal measured marginally *better* than the airline SARIMA on its own (MAE 9.50 vs
  9.58 — under 1%, well inside noise on 90 products), and still does at full scale. It is not
  shipped as the primary because SARIMA is this project's stated method; the ensemble keeps SARIMA
  as one of the three votes and as the only member that estimates an interval, which is where
  `lower_ci`/`upper_ci` come from.

  MAPE is undefined wherever actual sales are 0, so it is computed over non-zero months only and
  sMAPE is reported beside it. On the 3-year data 178 of 750 holdout months were zero, which is why
  its percentage errors are enormous.

- **A rejected fit falls through; it is never repaired.** `_plausible()` rejects non-finite values,
  *any* negative month, and anything above the historical ceiling, and the next simpler model is
  tried. The old code instead wrapped every value in `max(0.0, x)`, which laundered a diverged fit
  into a confident forecast of **0 units** — products selling 160/month with no empty months were
  displayed as zero. That was the "forecasting shows 0" bug, and it accounted for 419 of 2,539
  products (16.5%) on the demand side and 2,054 rows on the sales side. Both are now 0.
  **A zero must mean "the model expects no demand", never "the model fell over".**

- Method names are data: `main()` counts them with a `defaultdict` and maps confidence with `.get()`.
  Adding a method to the chain must not `KeyError` the write step after a 26-second run (it did).
- Training data is `sales_history` (actual customer demand), *not* `inventory_receipts` (supplier
  restocking), which reflects order-pattern noise. Don't "fix" this by switching the source.
- `DemandForecastService::allProductsSummary` deliberately does **not** hard-filter on
  `forecast_date >= today`: when the source data is stale (seed data, or a missed nightly run) every
  forecast row is in the past and a strict filter would blank the whole page. It falls back to the
  earliest generated forecast date instead — but that fallback decides only **which products appear**,
  never **which row is shown for a product**.

  Each product's forecast window starts the month after *its own* last month of sales history, so the
  windows do not line up: a product that stopped selling in mid-2025 has a window that closed long
  ago, while most run into 2027. Taking `->first()` off that global cutoff therefore showed whichever
  row was oldest — a year-old figure for hundreds of products, and a stale 0.4 units renders as a flat
  `0`. **That was the "the forecast shows 0" bug: a real number from the wrong month.** The summary
  now takes the first row from the current month onward per product, falls back to that product's
  most recent row only when its whole window is in the past, and flags it `is_stale` so the view can
  label it "as of <month>" instead of passing it off as next month's.

  A genuine forecast of 0 is *not* a bug: it means intermittent, slow-moving demand the model floors
  at zero, and the view prints `<1` rather than `0` for values between 0 and 0.5 so a small forecast
  is not mistaken for no forecast. On the regenerated data that case is real again — **550 of 15,120
  rows forecast exactly 0** — because the long tail contains products that genuinely sell a handful
  of times a year. (An earlier note here said zeros no longer arose; that was true only of the old
  file, where every product sold every month.)

### The inventory report's expired stock was reported but never reachable

The report has always shown an **Expired Stock** KPI ("still on the shelf"). Two things meant you
could never get from that number to the products behind it:

**There was no expired filter.** `ReportController::inventory()` read exactly two parameters —
`category_id` and `low_stock` — and the form had exactly two controls. There is now an "Expired only"
checkbox beside "Low stock only", filtered in PHP for the same reason `low_stock` is: expiry state is
a computed accessor over the loaded batches, not a column. All three KPIs are taken AFTER filtering,
so the figures describe the rows on screen; the audit entry's "(filtered)" marker and the
Clear-filters button both know about it.

**The per-row badge was dead code.** Both the screen table and the print copy carried
`@if($p->expiredBatches && $p->expiredBatches->count())`, but nothing ever defined `expiredBatches` —
`Product` has `batches` and no more. **Eloquent resolves an unknown relation name to `null` rather
than throwing**, so the guard was permanently false and the badge never rendered anywhere, while the
KPI beside it counted correctly. Measured before the fix, on a product with a 30-day-expired batch of
25 units:

```
expiredBatches resolves to: NULL
KPI "Expired Stock" count: 1
row badge "expired batch" appears: NO
```

`Product::getExpiredBatchesAttribute()` now supplies it, with the same `relationLoaded()` shape as
`total_stock` so the report's eager load is not undone by a query per product, and gated on
`quantity > 0` because this is about what still needs pulling off the shelf.

### Blade silently drops a directive that follows an `@endif`

Worth knowing generally, because it cost a debugging pass here.

Blade's statement regex requires a **non-word character before the `@`**. In `@endif@if($x)` the
character before the second `@` is `f`, so the `@if` is never recognised — it is emitted as literal
text — while its matching `@endif` compiles normally. The result is an unbalanced `endif` and a PHP
parse error.

What makes it expensive is that nothing in the normal check catches it:

| check | result |
|---|---|
| `php -l file.blade.php` | passes — it does not read Blade directives at all |
| `php artisan view:cache` | **passes** — it writes the compiled PHP without executing it |
| an actual request | `syntax error, unexpected token "endif"` |

So a view can compile "successfully" and still be broken. Separate adjacent directives with
whitespace, or assemble the string in an `@php` block — which is what the inventory report's two
print headings now do, sharing one `$activeFilters` list instead of a run of inline `@if`/`@endif`
pairs.

### Reports never cover days that have not happened

The seeded `sales_history` fills its final month to the last day of that month regardless of the
calendar — it runs to **2026-08-31** while today is the 23rd — so 2,005 rows across 8 days are dated
in the future. Left alone they were counted everywhere: the August chart's **tallest bar was Aug 30**,
and ₱470,490.98 of the dashboard's August revenue had not been earned.

Two clamps, both to `today()`, and neither deletes anything — the days come back into range as the
calendar reaches them:

- `SalesHistory::reportableThrough()` bounds `monthlyRevenue`, `quarterlyRevenue`'s record count and
  `recentDemand` (whose anchor is `MAX(sale_date)`, so it was measuring "the last 30 days" of a
  window that had not finished happening). `seasonalTrends` and `availableMonths` derive from
  `monthlyRevenue` and inherit it.
- **Both forecasting services** — this is where the clamp was missed for a long time, because they
  query `sales_history` directly rather than going through the model's aggregates:
  `SalesForecastService::computeOverallMonthlyTrend()` (`$actualUnits`, `$actualRevenue`, `$topSkus`)
  and `DemandForecastService::forProduct()` (`$actual`, `$seasonal`).
- `ReportController::clampEnd()` bounds every report range. `dateBounds()` alone was not enough:
  **the month picker builds its own range with `endOfMonth()`** and never goes near it, which is why
  picking August still plotted through the 31st.

**The forecast pages showed the un-happened days as "actual".** Both services read the table
directly, so neither inherited the model's clamp. August 2026 came out at **45,790 units /
₱1,699,579.63** on the Sales Forecasting page against the dashboard's **₱1,282,779.84** — the same
month, two panels, **₱416,799.79** apart (30.3% overstated by 1,730 future-dated rows / 10,640 units).
`forProduct()` did the same per SKU: 28 units reported for August against 23 actually sold, with the
seasonality panel's August average inflated to match (12.5 → 11.3).

That last actual point is the bar a user reads to judge whether the forecast looks sane, and it sits
immediately before the forecast begins — so an inflated one discredits a *correct* forecast. Clamping
does not move `lastActualMonth`, so the forecast still starts from September; only the numbers change.

**Clamping only the END inverts the range.** `clampEnd()` moves a future end back to today but leaves
the start alone, so a window entirely in the future came out backwards. `?start_date=2030-01-01&end_date=2030-12-31`
produced start 2030-01-01 / end 2026-08-24 — and the page printed that pair as its heading, the
report totalled **₱0.00** under it, and `AuditTrail::log('Viewed', …)` recorded the impossible range
as fact. A plainly reversed pair (`start_date` after `end_date`) did the same: ₱0.00 presented as a
legitimate result, which for a sales report is the worst failure mode available — an admin reads it
as "we sold nothing that month".

`clampRange()` replaces it on both `sales()` and `analytics()`: clamp **both** ends, then order them.
A reversed pair is a slip, so it is swapped (2026-08-24 → 2026-08-01 now reports the real
₱1,290,204.83 instead of ₱0.00); a wholly future window collapses to today at both ends, which is the
nearest thing the data can answer and is labelled honestly. The range queried, the range in the
heading and the range in the audit trail are now always the same three dates.

**The range inputs are validated.** They are `type="date"` in the form, but nothing stops a
hand-edited or bookmarked query string, and `?start_date=banana` reached `Carbon::parse()` inside
`trendBetween()` as an uncaught `InvalidFormatException` — a 500. `sales()` now validates
`start_date` / `end_date` as `nullable|date` and `month` as `date_format:Y-m`; `analytics()` validates
`month` the same way, which its `preg_match` guard let through as `2026-99`. Note `clampEnd()`'s
string comparison would have quietly turned `banana` into today (`'b' > '2'`), so the bad input had
two ways to reach the report — validate, do not rely on the clamp.

Those fixed-key caches now expire at **midnight or the normal TTL, whichever is sooner**
(`cacheUntil()`) — a flat 24h TTL would hold yesterday's cut-off for most of the next day and keep a
finished day out of the chart.

The Python forecasters read `sales_history` directly and are deliberately NOT clamped: to them the
rows are just history to fit against.

### Two sales tables — pick the right one
This trips people up, and has already caused one real bug:

- **`sales` / `sale_items`** — live POS checkouts written by `PosController::checkout`. On a typical
  install this holds only the transactions rung up on that machine (here: 22 rows, one month).
- **`sales_history`** — the imported sales record (here: 339k rows, 48 months, Sep 2022→Aug 2026).
  Written **only** by `SalesHistorySeeder`. The POS never touches it.

Anything showing a *trend over time* must read `sales_history`, or it will plot a near-empty chart
while years of data sit unused. The Dashboard's "Total Sales per Month", "Seasonal Trends" and
"High/Low Demand" cards, plus the Analytics report's seasonal chart, all read it via
`App\Models\SalesHistory`. Today's takings / transaction counts correctly read `sales`.

`sales_history` stores units only, so revenue is always `quantity_sold * products.selling_price`.
`SalesHistory` sets `$table` explicitly — Eloquent would otherwise resolve it to `sales_histories`,
which does not exist.

**The two tables are disjoint, so `mergePos()` merges across the whole range — no boundary.** The
reports need both sources: history for the trend, `sales` for takings the import doesn't have.
`mergePos()` used to start at `MAX(sales_history.sale_date) + 1 day`, on the theory that the import
owns everything up to its last day and the POS owns everything after, so summing both would
double-count the overlap.

There is no overlap. The POS writes only to `sales` / `sale_items`; `sales_history` is written only
by the seeder and the import. A boundary between two disjoint sets can only drop real rows — it can
never prevent a double count. And it did drop them: the seeded history runs to the end of the current
month while every report clamps its end to `reportableThrough()` (today), so `$posFrom` landed
**a week in the future**, past every possible `$end`, and the guard returned early on every call.
`$includePos` was a no-op — `trendBetween($s, $e, true)` and `trendBetween($s, $e, false)` returned
byte-identical rows, and every live checkout was missing from the Sales and Analytics reports.

Clamping `$historyMax` to today does **not** fix this: the history covers today too, so the boundary
still lands on tomorrow — and today is precisely when POS sales happen. The boundary itself had to
go. The per-key merge already sums a day present in both sources, which is what makes that safe.

`trendBetween()` caches only the history half (keyed without the POS version) and merges POS fresh on
every call, so a checkout shows up in the reports immediately without retiring a multi-second
aggregate. That design was always right; it just had nothing to merge.

Its aggregates scan 339k rows, so they're cached for 24h and `SalesHistorySeeder` calls
`SalesHistory::forgetCaches()` after reseeding. Rank top/bottom sellers with `ORDER BY ... LIMIT` in
SQL, not by fetching every product and sorting in PHP — that measured 2.1s vs 0.85s and cached ~1,600
rows to display eight.

**Two query traps live in this table, and both only became visible once the history was made
realistic (205k → 339k rows). Do not undo either.**

1. **`STRAIGHT_JOIN` on every `sales_history` → `products` aggregate.** Left alone, MySQL drives the
   join from `products` (2.7k rows) and does one index lookup per row into the
   `(product_sku, sale_date)` unique index. That index has a **1022-byte key** — `product_sku` is
   `VARCHAR(255)` utf8mb4 — and the plan falls off a cliff as the table grows. Measured three runs
   each at 339k rows: **53.6s unhinted vs 3.9s forced**. The hint rides on `selectRaw`, whose content
   lands immediately after `SELECT`, which is where MySQL wants the keyword.
2. **There is now an index leading with `sale_date`** (`sales_history_date_sku_qty_index`, migration
   `2026_08_20_000001`). Without it, any `whereBetween('sale_date', …)` aggregate had no way in and
   scanned the whole `product_sku` index — `recentDemand` measured **45.6s** to find a window holding
   7,850 rows. With it: **92ms**. An earlier note in this file rejected a `sale_date` index because
   "those aggregates group by `DATE_FORMAT(sale_date, …)`, which no index can satisfy" — true of
   `monthlyRevenue` and `trendBetween`, false of `recentDemand`, `unitsSoldBetween` and
   `topProductsBetween`, which filter on a plain range and group by SKU. That rejection generalised
   from the wrong query.

Cold timings after both fixes: `monthlyRevenue` 3.5s, `recentDemand` 92ms, `seasonalTrends` 3ms
(derived from the cached monthly series), `overallMonthlyTrend` 6.3s, Analytics ~16s.

### Performance notes (measured, don't re-litigate)
The Dashboard and Sales Forecasting pages were the two slow ones, for completely different reasons.

**Dashboard — PHP-bound, not SQL-bound** (~130ms of SQL in a ~1.4s page). It hydrates every
in-stock batch (~2,500) and every product (~2,600), then derives status in memory. The dominant
cost was Carbon: `return_status` was recomputed on every read, and the dashboard read it three
times per medicine batch via `is_returned`/`needs_return`/`failed_return`. `ProductBatch` now
memoizes `is_expired` and `return_status` per instance (`$derivedMemo`), and the controller tallies
with one `countBy` pass instead of three filters. 581ms → 193ms on that section; page ~1.48s → ~0.88s.
If you add a derived attribute here, memoize it the same way — and note `return_status` memoizes via
`array_key_exists` because `null` is a meaningful value, while the bool ones can use `??=`.

**Inventory status filters — narrowed in SQL, decided in PHP.** Low stock / expiring / expired /
return states are computed accessors, so they cannot be a WHERE clause. They can still be *bounded*
by one: any product the expiry-driven filters can match must have an in-stock batch expiring within
**120 days** — the outer edge of the medicine return window, with the 90-day "expiring" horizon and
the 10-day non-pharma rule both inside it, and no lower bound so expired batches still qualify.
`InventoryController` applies that `whereHas` for `expiring`/`expired`/`need_to_return`/
`fail_to_return`, which cuts the PHP pass from 2,637 products to 208. Verified identical result
sets on all four (86/72/77/73 products). Measured: the "Need to Return" tab went 476ms → 43ms of
controller time, ~2.9s → ~0.93s end to end.

The controller also points each batch back at its parent via `setRelation('product', $p)`:
`ProductBatch::$return_days` and `$is_returnable` both reach for `$this->product` to pick a return
window, and without it every rendered row lazy-loaded one query per batch.

**Sales Forecasting — SQL-bound**, aggregating 339k `sales_history` rows (~6.3s cold, ~5ms warm).
`overallMonthlyTrend()` is cached for 6h (`SalesForecastService::CACHE_KEY`) — safe because
`sales_history` is written **only** by the seeder/import, never by the POS, which records into
`sales`/`sale_items`. `GenerateSalesForecast` forgets that key after importing. Warm: ~5ms.

Two things were tried and settled by measurement:
- Top products now group by the indexed `product_sku` and resolve names for just the top 5, instead
  of joining every history row to `products` and grouping by name: 797ms → 404ms, then → **106ms** with the
  covering index added in `2026_08_17_000003`.
- Merging the **actual** monthly units + revenue queries into one `LEFT JOIN` pass is **slower**
  (1339ms vs 375+766ms) because the units total needs no join at all. They are deliberately kept
  separate; don't "optimize" them back together. **This does not apply to the forecast side**: those
  aggregates all come from `sales_forecasts` with no join and the same `GROUP BY`, so units, revenue
  and the four confidence bounds are one `$forecastAgg` query — merging there removed a duplicate
  scan rather than adding a join.
- A `(sale_date, quantity_sold)` index was once trialled and rejected here because *these* aggregates
  group by `DATE_FORMAT(sale_date, ...)`, which no index can satisfy. **That is still true of this
  page's queries, but the conclusion was over-generalised** — the range-filtered aggregates
  elsewhere (`recentDemand`, `unitsSoldBetween`, `topProductsBetween`) do benefit, and now have
  `sales_history_date_sku_qty_index`. See the two query traps under "Two sales tables".

### The header pill, on every page

`.topbar-loading` is the small "still working" marker beside the page title — a label with a pulsing
brand dot. It was written for the dashboard and lived in `dashboard/index.blade.php`, because that
was the only page with a wait worth marking.

It is now raised on **every** sidebar navigation. The CSS moved into the layout's inline `<style>`
(one definition; the dashboard's copies inside its two media queries were deleted rather than left
to drift), and `showNavigating()` appends a pill reading **"Loading <destination>…"** taken from
`navLabel(link)`, the sidebar item's own text. `clearNavigating()` takes it away.

Why the header rather than a card over the page: the header is the one region that does not change
between pages, so the marker sits in the same place every time, and it stays legible when the
content area is scrolled out of view. A full-screen branded card was tried first and rejected — it
is right for an arrival, and far too much furniture for a routine tab change.

**Two pills exist and they must not remove each other's.** The dashboard still builds its own for a
different wait (its AJAX body, cleared by `pill.remove()` when the body lands). The navigation pill
carries `id="navLoadingPill"` and `clearNavigating()` removes only that id. Clearing by class would
have had a navigation away from a half-loaded dashboard delete the dashboard's marker as well.

An in-content button ("Edit", "Generate") is not a page name, so those get a bare "Loading…" — the
same gate the topbar title swap already applies.

### Loading skeletons
Two levels, both defined in `layouts/app.blade.php`:
- **Page skeleton** — full sidebar navigations (see "Navigation skeleton" below).
- **List skeleton** — `REMEDI.showListSkeleton(wrapper, {rows, grid})`, called before every AJAX list
  refresh on POS/inventory/products/sales/forecast. Those pages replace `#results-wrapper` over
  fetch; without it the stale rows sat on screen until the response landed, so typing in a search box
  looked inert. It reserves the wrapper's current height first so the page doesn't jump, and
  `clearListSkeleton()` releases that once the real rows arrive.

### Dashboard panels scroll, they don't truncate

The Expiring Soon and Returns panels rendered `take(8)` / `take(5)` and dropped the rest on the
floor, while their own headers counted the full set — with 15 medicines expiring, 16 other, 27
batches due for return and 50 non-pharma ones, most of what the card advertised was unreachable
without leaving for the Inventory page. The Expiring Soon lists now render every row inside
`.expiry-scroll`. **Cap the height, not the row count.**

**The two Returns panels are the deliberate exception, and they are a summary, not a truncation.**
They moved into `.inv-returns-col`, a third column beside the two expiring lists (see "Inventory tab
layout"), which is too narrow for a batch list. Each now shows the doughnut, the legend with its
counts, and a `.returns-more` footer link — "27 batches inside the return window" — pointing at the
Inventory filter that lists exactly those batches. Nothing is hidden: the counts are still on the
card, and the batch identities live one click away on a page that can show them properly. That is
different from `take(5)`, which silently dropped rows a header was still counting. If you put an
inline list back here, widen the column first.

Note when measuring these in a browser: the admin dashboard has two `.dash-section` tabs (Sales /
Inventory) and the Inventory one is `display:none` until selected, so every element inside it reports
zero height until you click that tab.

### Sidebar scroll and the category submenu

`.sidebar nav` is the scroller (`.sidebar` itself is `overflow:hidden`). With the Inventory submenu
expanded its content is **1113px inside a 499px window** — more than half the menu is out of view.
Every page is a full server render, so the nav came back at `scrollTop: 0` each time: scroll down to
a category near the bottom, click it, and the menu jumped back to the top, away from the item you
just picked. The position is now kept in `sessionStorage` (per-tab — a scroll offset should not
outlive its window), written on scroll and again on click, since a click navigates before the
debounce fires.

When there is no stored offset (fresh tab, or a link opened directly) it scrolls the `.active` item
into view instead. Two things that made earlier attempts silently do nothing:
- **Measure with `getBoundingClientRect()` against the scroller, not `offsetTop`** — `offsetTop`
  resolves against `.sidebar`, not `.sidebar nav`, so it isn't "distance down the scrollable
  content".
- **Do not defer it to `requestAnimationFrame`.** rAF does not run in a page that isn't compositing
  (background tab, hidden pane), so the adjustment never happens. `getBoundingClientRect()` flushes
  layout by itself, so a synchronous read already accounts for the submenu height applied just
  above. (The same caveat applies to the existing double-rAF that removes `nav-no-anim`.)

The submenu's open height is set from `scrollHeight` in JS. The CSS `max-height: 640px` is only the
no-JS fallback: the category list is **data**, it grew from 7 entries to 10, and at 618px of content
it was one category away from silently clipping the last ones off the bottom.

### Inventory filter tabs are AJAX

The tabs (All / Low Stock / Expiring / Need to Return / ...) are real `<a href>` links, so they
still work without JS, stay shareable and open in a new tab on middle-click. A plain left click is
intercepted and routed through the same `runInventorySearch()` fetch the search box uses.

They were full page loads: the sidebar, KPI cards and header were re-rendered for a change that
only affects the rows, and the browser dropped the scroll position — click a tab half way down the
list and you were thrown back to the top. The handler keeps the same guards as the sidebar
navigation handler (modified/middle clicks fall through), moves the `.active` class itself, and
`popstate` re-syncs it so Back doesn't leave the highlight on a filter you navigated away from.

### Search: live filtering, no dropdown
`REMEDI.attachSuggest` debounces input and fires a `suggest:live` event; each page listens and
refreshes its own table/grid. There is deliberately **no suggestion dropdown** — it was built, then
removed by request, so results appear only in the page's own list. `SuggestController` and the
`/suggest/*` routes remain as the JSON source if a picker is ever wanted again.

**Products has no Filter button.** The search box refreshes the list on a debounced keystroke and the
category select on `change`, so the button only ever re-ran a search that had already run. The
`<form>` stays a real GET form and the page still works without JS: it has exactly one field that
blocks implicit submission (the text input — a `<select>` does not), so Enter submits natively even
with no submit button present. Inventory and Sales still have theirs; they were not part of that
request.

**Audit filters live, and filter by date.** `admin/audit/_rows.blade.php` was split out so the
search box (debounced), the action/role selects, the two date inputs and the Today/7/30-day presets
all swap just the table over fetch — including its pagination, because this page draws its own pager
rather than using `$paginator->links()`. `AuditTrailController::index` returns
`['html' => …, 'total' => …]` for AJAX; `total` feeds the "N entries" counter beside the filters.
Date bounds use `whereDate` on both ends, so a same-day filter includes that whole day rather than
stopping at 00:00. Search now also covers `action` and `ip_address`, not just username/details.

The plain GET page (users) has no live listener on purpose: auto-submitting on every typing
pause reloads the page mid-word. Enter submits them natively.

### Change Password is a two-step card

Collapsed it shows the lock tile, the copy and an **outlined** trigger; clicking it reveals the three
fields and the filled green submit. Outlined vs filled is deliberate — two solid green buttons in one
card read as two submits.

**The collapse is applied by script, never with `hidden` in the markup.** With JavaScript off there
is nothing to reveal the form, so the fields must start visible and the trigger must start hidden —
the reverse of what the enhanced path shows. The script also refuses to collapse when a
server-rendered `.err` is present: that means the form was posted without JS and came back with
something to fix, and hiding it would hide the error.

Submitting is AJAX (`PasswordController` answers JSON for AJAX, the redirect otherwise), and a
success collapses the card back — three empty password boxes left open invite a second change nobody
asked for.

### The bell is polled, and tracks read/unread

**Live, but not pushed.** There is no WebSocket layer in this app — the bell polls
`GET /alerts` every 30s (`POLL_MS`, matching `AlertService::TTL_SECONDS`, so polling faster would
only re-serve the same cached payload). What makes it feel live is the catching-up, not the interval:
it refreshes when the tab becomes visible, when the window regains focus, when the panel is opened,
and immediately after any `js-confirm` action via `window.remediRefreshAlerts()` — deleting a batch
or marking one returned changes the alert set, and waiting out the interval to show it is what reads
as stale. A hidden tab stops polling entirely.

If you want genuine push, that is Laravel Reverb/Echo plus a broadcast connection; nothing here
pretends to be that.

**Panel shape.** Header (title / Mark all as read / close), a tab strip, rows carrying a circular
tinted disc, title, body and — only where there is a real event time — a relative timestamp, then a
"View all notifications" footer. Tabs filter **client-side on `data-group`**: the whole payload is
already in hand, so a round trip per tab would only add latency to data the panel is holding.

| Tab | Source | Who sees it |
|---|---|---|
| Alerts | `AlertService::payload()` — the five inventory kinds | admin + staff |
| System | audit trail: logins, logouts, account changes | **admin only** |
| Updates | audit trail: reports generated, records added/edited/deleted | **admin only** |

`AlertService::activity()` is deliberately NOT folded into `payload()`: that method caches under one
key shared by every signed-in user, so role-dependent rows in it would serve a staff account
whatever an admin cached first. The audit trail is behind `role:admin`, so those tabs are not
rendered for staff at all rather than shown empty.

**Only audit rows carry a rendered timestamp.** An inventory alert is a standing condition, not
something that happened at a moment — stamping "2 minutes ago" on a low-stock row would be a lie, so
those rows render without a time. They do carry a `sort_at` used purely for ordering; see "One feed,
newest first".

The panel's **"View all notifications"** goes to `notifications.index`, a real page — it used to
point at Inventory because no such page existed. It reuses `AlertService::payload()` with a larger
per-kind cap (`PAGE_PER_KIND` 10 vs `PER_KIND` 3) and the same `.topbar-bell-row` markup, so the page
and the dropdown cannot drift apart. It is NOT "every alert": low stock alone is 645 products, and a
page that dumps all of them is the Inventory list with extra steps — the per-kind totals stay linked
so nothing is unreachable. `forget()` retires both caps. The System/Updates list is capped the same
way: `activity(30)` on the page against `activity()`'s default 6 in the bell.

The page renders **one flat list**, not per-kind sections. The sticky `.notif-head` headings are
gone; what they carried — each kind's true open total and its "View all N in Inventory" link — moved
to a single `.notif-context` bar that follows the active tab, populated from `data-label` /
`data-dot` / `data-href` / `data-linktext` on the tab buttons. A staff account gets no audit link, so
those tabs carry no `href` and the bar hides the link rather than rendering a dead one. Because there
are no headings left, the tab filter is now plain show/hide on `.notif-row[data-section]` — the old
"is this a heading, and is the filter All?" branch is gone with them.

**Two traps in the tab filter, both fixed and both easy to reintroduce:**

- The filter sets the `hidden` **attribute**, whose `display:none` comes from the UA stylesheet —
  and `.topbar-bell-row { display: flex }` beats it. Without an explicit
  `.topbar-bell-row[hidden] { display: none }` the rows are marked hidden and painted anyway, so
  every tab looks identical to All. **Testing `!el.hidden` will not catch this** — the property is
  set correctly; check `offsetParent !== null` instead.
- Group headings used to be emitted whenever the group changed from the previous row, so the
  time-ordered audit rows (system/updates interleaved) produced "Alerts, System, Updates, System".
  This was fixed by sorting group-major first — and is now **moot**: the All tab is one
  chronological feed, so `applyGroups()`, `GROUP_ORDER`, `GROUP_LABEL` and `.bell-group-head` are all
  gone. Do not reintroduce group-major sorting to bring headings back; see "One feed, newest first".

**Every action that moves stock must call `AlertService::forget()`.** For a long time only
`PosController::checkout` did, so marking a batch returned, adding or deleting a batch, or changing a
reorder level left the bell serving a cached payload for up to `TTL_SECONDS` — the notification you
had just resolved stayed on the list, which reads as "the alerts never change". All seven
stock-affecting `ProductController` actions now clear it (store, update, destroy, addBatch,
updateBatch, destroyBatch, markBatchReturned). The client-side `remediRefreshAlerts()` hook does NOT
substitute for this: it re-fetches, and a re-fetch of a cache nobody cleared returns the same rows.

`AuditTrail::log()` clears `topbar_activity` on every write, since each write is by definition a new
System/Updates notification.

**Read/unread is per user, per device, in `localStorage`** (`remedi_alerts_read:<id>`, capped at 200
ids). Deliberately not a database column: "I have seen this" is a personal fact, and marking one read
at the register must not clear it on the manager's screen. `AlertService` gives every item a stable
`id` keyed to the batch or product — never its position, which would mark the wrong row the moment
the list reorders. Low stock carries the stock level in its id (`low_stock:<product>:<qty>`), so
restocking and falling below the line again is a NEW notification rather than one already dismissed.

**One store, two surfaces.** The key, the 200-id cap and the writes belong to
`window.remediAlertReads` in `layouts/app.blade.php`, defined above the bell and outside its
`#bellWrap` guard. `add()` persists and then fires `remedi:alerts-read` on `document`; the dropdown
and the `/notifications` page each listen and repaint from storage. Neither reaches into the other's
DOM, and neither has to be present for the other to work. The page used to render `data-alert-id` on
every row and read none of it — a row you had already opened looked new there while the dropdown
showed it faded. It now paints the same `.is-read` state, marks rows read the same way, and carries
its own "Mark all as read" in the footer.

**The badge counts UNREAD, not total.** A badge that still says 12 after you have read all twelve is
what makes people stop looking at it. A read row keeps its severity stripe — "read" means you have
seen it, not that the stock is fine — but drops to 62% opacity and loses its dot. Rows are marked on
`mousedown`, not `click`: the row is a link, and a handler racing the browser's unload is not
guaranteed to finish. `click` is also bound, because Enter on a focused row fires only that — without
it, keyboard users never marked anything read.

### Alert toasts

A bottom-right stack of self-dismissing cards covering three of the five alert kinds: **low stock**,
**expiring soon**, **need to return**. `expired` and `fail_to_return` are deliberately left to the
bell — see the open question at the end of this section.

**Up to five cards stand together.** Each names an individual product and carries the moment its
alert began, rather than summarising a kind. They arrive 140ms apart so the stack reads as building
rather than appearing, and each owns its own 5s countdown started when it actually appears — a
shared timer would give the last of five only 5s minus its stagger. Measured:

| t | stack |
|---|---|
| 245ms | 1 visible, 1 chime |
| 370 / 481 / 667ms | 2, 3, 4 visible — still 1 chime |
| 791ms | 5 visible |
| 5221 → 5766ms | drains one by one, each ~5s after its own entry |

`MAX_VISIBLE` (5) caps the stack, dropping the oldest — past five it walks up the page and starts
covering the thing it is reporting on. The bell still holds every one of them.

**The chime fires once per BATCH**, not per card: five chimes 140ms apart is an alarm. This flipped
once already — while the stack was briefly a one-at-a-time queue, cards were five seconds apart and
a chime each was right. If the presentation changes again, re-derive the chime rule from the
spacing rather than from this line.

**Four kinds are watched: `low_stock`, `expiring`, `expired`, `need_to_return`.** `fail_to_return`
stays on the bell — a missed return window is a standing regret, not something to interrupt anyone
about.

**Most recent first, except expired.** `AlertService` already sorts newest-first, so the queue takes
from the front and old standing conditions do not crowd out today's news. Expired stock is the one
exception: those units are on the shelf and have to come off it, so they LEAD the queue and are
raised again every time the app is opened **until someone has clicked one**. "Clicked" is read state
— `window.remediAlertReads`, the same per-device store the bell and `/notifications` paint from.
Verified: with the expired ids unread the queue opens on LOPERAMIDE 2MG; with those same ids written
into the read store it opens on VITAMIN C 500MG instead.

**Two ways in, one queue.** The GREETING plays the alerts that were open when the page rendered, once
per browser session. LIVE pops are queued whenever the bell's poll reports a notification that was
not in the list before — so a cashier already signed in and working is told about a batch that just
expired or a product that just went low, without reloading anything.

**It is a fourth reader of `AlertService`, not a fifth definition of an alert.**
`layouts/app.blade.php` renders it from `$topbarAlerts`, the array the `layouts.app` composer already
put in the view for the bell. That means no extra query, and the toast can never quote a number the
badge disagrees with — the same rule the dashboard Alerts panel follows. The rows use the payload's
own `href`, which is relative on purpose (`route(..., false)`): that payload is cached and shared, so
an absolute URL would freeze whichever host warmed it.

**Colour comes from the same legend as the bell rows** (`.is-low` yellow / `.is-expiring` orange /
`.is-return` blue). A kind must not change colour depending on which surface is showing it.

**The gate is `sessionStorage`, because "freshly opened" is a browser-session fact.** The app does
full page loads on every navigation, so a purely server-side flag would either fire once at login —
missing someone who reopened a closed tab — or fire on every single page view. `sessionStorage` dies
with the tab, which is exactly the lifetime of "this is a fresh visit". The key is per user id, and a
fresh sign-in clears it: `data-fresh-login` carries `session('remedi.just_signed_in')`, the same
flash the dashboard shell uses for its branded loading card. Without that reset, signing out and
back in as someone else would stay silent because the tab had already been greeted. Storage throws in
some privacy modes; that is caught and treated as "cannot remember", which greets rather than
disabling the feature.

**Timing, measured in the browser rather than asserted:**

| t | event |
|---|---|
| 202ms | first toast in |
| 351ms | second (140ms stagger) |
| 501ms | third |
| 5201ms | first out — 4999ms after its own entry |
| 5351ms | second out — 5000ms |
| 5502ms | third out — 5001ms |

Each row owns its own countdown, started when it actually appears. A single shared timer would give
the last of three only 5s minus its own stagger. Hovering or focusing anywhere in the stack pauses
**every** timer and unhovering resumes them (verified: still 3 visible at 7s while hovered, gone 5s
after release) — pulling a row out from under someone who is reading it is the one thing a timed
popup must not do.

**On `/dashboard` it waits for `remedi:dashboard-ready`.** The shell's loader owns the screen for
5–12s, so firing at page load would spend the whole 5s window behind the loading card and be gone
before the page the user is waiting for arrived. `dashboard/index.blade.php` dispatches that event
after `runScripts()` resolves, beside the existing `resize` dispatch. The listener carries a 15s
fallback, because a dashboard that fails to load must not swallow the alerts too. `#dashboardRoot`
exists only in the shell, so the `?full=1` path skips the wait — its body is already on the page.

**This is not the removed `#ajaxFlash` banner coming back**, and it is deliberately not
`REMEDI.showMessage()`. That card is modal and waits to be acknowledged, which is right for "you just
did something, here is the result" and wrong for a standing condition nobody asked about: a dialog on
every sign-in is a door you have to close before you can start work. The `#ajaxFlash` objection was
that an *outcome* could scroll away unread — nothing here is an outcome, and nothing is lost by
ignoring it, because every row links to the same Inventory filter the bell opens and the bell keeps
the counts permanently. The stack is `display: none` in CSS and JS opts it in, so JS-off gets nothing
rather than a popup that never leaves.

**Covered by `Feature\Alerts\AlertToastTest`, which asserts against the `#remediToasts` container
only.** Every authenticated page also renders the bell, and the bell lists all five kinds — so a bare
`assertSee('Expired stock')` passes on the bell's copy and proves nothing about the toasts. The test
extracts the container first and asserts inside it.

**The live watch rides the bell's existing poll — it must never open its own.** The bell already
fetches `/alerts` every `POLL_MS` (30s, matching `AlertService::TTL_SECONDS`), and its success
handler now dispatches `remedi:alerts` carrying the payload. The toast module listens. A second loop
would double the request rate for no new information and could still disagree with the badge beside
it by a whole interval.

**A card is raised for a new ITEM ID only — never for a kind whose total moved.**

The count-increase fallback that used to sit here was removed deliberately. A summary card ("Low
stock alert — 12 products at or below reorder level") cannot say which product, cannot carry an
onset, and cannot link at anything but a filter. It is the badge restated, not a notification.

That left a real gap, and closing it is what "make it real-time" actually required — see the next
section.

Measured, driving `remedi:alerts` directly on a tab that had already been greeted:

| event | result |
|---|---|
| poll replays known item ids | nothing, no chime |
| new item id arrives | 1 card naming the product + its onset, 1 chime |
| count 3 → 9999 with no new item | nothing — no summary card |

**The container renders even on a page with nothing wrong**, because it is the mount point live pops
are appended to — a container that only existed when the page happened to load with an open alert
could never receive one. Its `data-counts` baseline is built from the full watched list rather than
from the payload, because `AlertService` drops empty kinds: a kind sitting at zero is exactly the one
whose first appearance must pop, and without an explicit `0` written down the first poll reads it as
"no previous value" and stays silent.

**The chime.** Two sine notes -- A5 (880Hz) then D6 (1174.7Hz) 120ms behind it, a perfect fourth,
which reads as "look here" without the minor-second edge that makes alarms unpleasant. Synthesised
with WebAudio rather than served as a sound file, for the same reason the dashboard loader inlines
its logo as a data URI: `php artisan serve` is single-threaded, so an asset requested while something
slow is in flight queues behind it and arrives after the toast it was meant to accompany. A tone that
has to be fetched is a tone that can be late.

It fires **once per batch** -- one greeting, or one poll that turned up three new kinds at once --
not once per toast, because three chimes 140ms apart is an alarm and this is a notification. Verified
by spying on the constructor: 2 oscillators per chime, at +20ms and +140ms.

**One AudioContext per tab, reused rather than created per chime.** Browsers cap how many a document
may create, and a register left open all day can pop dozens of alerts; a fresh context each time
eventually throws and takes the sound out for the rest of the shift. Reuse has a second benefit that
matters more: once the context is unlocked it STAYS unlocked, which is why **live pops are reliably
audible where the greeting may not be** -- by the time one fires, the user has been clicking around
the app for a while. Gain envelopes are ramped rather than switched, because a gain jumping straight from 0
produces a click at the discontinuity harsher than the note itself (and `exponentialRampToValueAtTime`
cannot touch exact zero, hence the 0.0001 floor).

**Autoplay policy is the real constraint, and the chime must lose gracefully to it.** A context
created before the user has interacted with the document starts suspended and `resume()` may reject.
Both are expected rather than errors -- the toast IS the notification and the sound is a courtesy.
All three failure modes were tested in the browser:

| scenario | toasts visible | errors |
|---|---|---|
| constructor throws | 3 | none |
| suspended, `resume()` rejects | 3 | none |
| no WebAudio at all | 3 | none |

Zero uncaught errors and zero unhandled rejections in every case. **The consequence to know about:
on a genuinely cold load the chime can be silent**, because the toast fires before the user has
clicked anything. Chrome's per-origin media engagement usually permits it on a machine used daily, so
this mostly bites fresh profiles and incognito. If guaranteed sound is ever required, the options are
to prime an AudioContext on the login form's submit gesture (it does not survive the navigation, so
this needs the audio to live in a page that is not replaced) or to hold the chime until the first
user gesture -- which would ring after the toast had already gone, and was rejected for that reason.

**Muting is a property of the WORKSTATION, not the account.** `localStorage['remedi.toastSound'] =
'off'`, flipped by `REMEDI.toastSound(false)`. Deliberately not in the per-user alert-read store: the
thing that wants silence is the terminal on the shop floor with customers standing next to it, not
the person signed into it, and the same cashier on the back-office machine may well want the sound.
Muting skips only the audio -- the toasts still appear (verified: 0 contexts created, 3 toasts
visible). There is no UI for it yet; it is a console/deploy-time switch.

### The item slice is selected by recency, not severity

This overrides an earlier decision recorded in this file, so the reasoning matters.

`payload()` caps each kind's notification list at `PER_KIND` (3). Selection used to be by **severity**
— the three deepest below their reorder line, the three soonest to expire — which reads like a
priority queue and is the right rule for the Inventory page, which still sorts exactly that way.

It is the wrong rule for a notification feed, because **severity is stable**. The same three worst
products won the slice on every poll. A product that dropped below its reorder line today never
entered the list at all, and since a toast is only raised for an item id it has not seen before,
**that product's alert never fired**. Nothing was broken; the news was simply never selected.

Demonstrated with three products sitting at 1 of 100 (low for months) plus two that changed today:

| | severity selection | recency selection |
|---|---|---|
| slice | OLD DEEP A, B, C | JUST SOLD OUT, JUST WENT LOW, … |
| pops when a product sells out today | never | immediately |

Recency is also correct for the calendar-driven kinds, which looks backwards at first glance: the
batch that entered the 30-day window this morning is news, while the one expiring on Friday entered
it a month ago and has been reported every day since. Same for expired — recently expired is news,
long-expired has been on the list for months.

What did NOT change: the per-kind totals in `alerts`, the "View all N" links, and the Inventory
page's severity ranking. Urgency ordering belongs on the page you go to in order to act; the feed
answers "what is new". The `$recent*` slices in `payload()` are the whole of the change.

**Out of stock is its own colour, not just its own words.** The card reads "Out of stock" at zero
instead of "0 PCS left", and `cls` becomes **`is-out`** — graphite `#334155` — rather than the amber
`is-low`. An empty shelf is not a warning that stock is getting low; it is the thing the warning was
about.

It stays inside the **`low_stock` kind**. The counts, the notification tabs and the Inventory filter
all treat low and empty as one thing, and forking the kind would double-count every total. Only the
colour splits, which is why the notifications page's TAB dot stays amber while its ROWS go rose.

**It sits off the warning ramp on purpose, and that took two attempts.** Rose (`#e11d48`) was tried
first and rejected: next to expired stock's `#dc2626` it read as a shade of the same red, which is
exactly the confusion to avoid — expired is a shelf to CLEAR, empty is a shelf to REFILL, and the
feed interleaves the two. Graphite is not on the amber → orange → red severity ladder at all, which
is the point: an empty shelf is an ABSENCE rather than a louder warning.

The risk with a dark neutral is that it reads as disabled or low-priority, so the contrast is kept
deliberately high — slate-700 (`#334155`) on slate-200 (`#e2e8f0`), the same weight as the coloured
rows rather than a muted one. If it ever does start reading as "switched off", raise the border
weight before reaching back for a hue.

The legend now lives in **five** places and they must agree, or a kind changes colour depending on
which surface is showing it:

| rule | file |
|---|---|
| `.topbar-bell-row.is-out` (border) + `.is-out i` | `layouts/app.blade.php` |
| `.topbar-bell-row.is-out .bell-icon` | `layouts/app.blade.php` |
| `.remedi-toast.is-out` + its `__icon` | `layouts/app.blade.php` |
| `.notif-row.is-out:hover::before` | `notifications/index.blade.php` |
| `.dot.is-out` | `notifications/index.blade.php` |

### Times and dates on the feed

Every row in the bell, on `/notifications` and in the toasts now carries a timestamp. What made that
possible without lying was already in the payload: `sort_at`, the moment each alert BEGAN, which
until now existed only to order the feed.

**Inventory alerts are prefixed "Since".** That prefix is the whole point. The old rule — recorded
under "One feed, newest first" — was that an inventory alert must NOT show "x ago", because it is a
standing condition rather than an event and "2 minutes ago" would claim it happened then. "Since
Aug 14" makes exactly the claim the value supports: this is when the condition started. Audit rows
get no prefix, because they really are events, and they keep the relative time the panel already
re-stamped every minute — rendered as `3 hours ago · Sep 1, 8:04 PM`.

**Formatted once, server-side, in `AlertService::whenLabel()`.** Three surfaces render it (Blade
bell rows, the bell's JS `render()` after each poll, the notifications page) and a fourth consumes
it (the toasts). Formatting it in each would have produced four date formats within a week.

Two rules in the formatter, both about not inventing precision:

- **The clock is dropped at midnight.** Several onsets are derived from a DATE rather than a moment —
  an expiry, a return window that opens a fixed number of days before one — so they land exactly on
  00:00. "Aug 14, 12:00 AM" would suggest the alert began at a particular second.
- **The year is shown only when it is not the current one.** Expiry-derived onsets run years out,
  where the year is the whole point; a checkout from this morning does not need it.

**`data-when` carries the absolute label, `data-at` marks a row as a real event, `data-since` carries
the onset of a standing one.** Only rows with `data-at` get an "x ago" in front. Both the Blade
render and the JS `render()` must emit all three the same way — the JS one rebuilds every row after
each poll, so a timestamp added only in Blade disappears 30 seconds after the page loads.

**Every row's time now MOVES, including the standing ones (added 2026-09-02).** Audit rows always
had a live "x ago"; inventory alerts rendered `Since Aug 14` and then sat frozen until the next full
page load, which on a register left open all day is most of the shift. They now carry how long the
condition has been open beside the onset — `Since Sep 2, 1:35 PM · 6 minutes` — computed client-side
from `data-since` (the row's `sort_at`, which the payload already stamped). That keeps the original
rule intact rather than reversing it: the row still never claims to have HAPPENED at a moment, it
states when it began and how long it has stood.

- **A duration, not an "x ago", and floored rather than rounded.** A shelf that emptied 110 seconds
  ago has been empty for one minute, not two.
- **Dropped past 30 days**, where `Since Mar 2, 2024` says it better than `· 412 days`, and dropped
  entirely for an onset in the future — which is never a real alert, only a clock skew.
- **The tick is 15s, not 60s** (`TIME_TICK_MS`), on both surfaces. The smallest unit either function
  prints is a minute and the "just now" band ends at one, so a 60s interval could leave a row reading
  "just now" for nearly two minutes — the one thing a live timestamp must not do. It costs a few
  dozen `textContent` writes.
- **Two copies of this logic exist** — the bell's in `layouts/app.blade.php`, the page's in
  `notifications/index.blade.php` (the bell script only stamps inside `#bellList`). Change one,
  change the other; they must produce the identical string.

Verified in the browser against the live feed: standing rows read `Since Sep 2, 1:35 PM · 6 minutes`
and `Since Sep 2 · 13 hours`, audit rows still read `2 minutes ago · Sep 2, 1:41 PM`, and the toasts
are untouched (a card lives 5s, so a duration on it would never tick).

### Staff see one bell tab

System and Updates are built from the audit trail, which is admin-only (see `AlertService::activity`,
and the reason it is kept out of the cached `payload()`). A staff bell therefore contains nothing but
inventory alerts — which made **All** and **Alerts** two buttons rendering the identical list. Staff
now get the Alerts tab alone; admins keep all four.

`activeTab` is initialised from whichever tab carries `is-active` in the markup rather than being
hardcoded to `'all'`. Without that, staff would have had a tab that looked selected while the filter
in force was still the absent `all`.

The `/notifications` page keeps its **All** tab for both roles: there the other tabs are per-KIND
(Low stock, Expiring, ...), so All is a genuinely different view rather than a duplicate.

### The products list shows SKU only

The Barcode line was removed from `products/_rows.blade.php` and the column header is now just
**SKU**. Nothing else changed: `products.barcode` is still a real column, and every search that
matched on it still does (`products`, `inventory`, POS lookup and the `/suggest/*` endpoints), so a
hardware reader typing a barcode into the search box still finds the product. The number is simply
no longer printed in the table.

The search placeholder still reads "Search by name, SKU, or barcode", which is accurate — searching
by barcode works, it is only the display that went away.

### The barcode scanner is hidden in both modules

`pos/index.blade.php` had already been given the treatment; `inventory/index.blade.php` now matches.
Hidden with the `hidden` attribute, **not deleted** — the markup stays in the DOM so `barcode-input`,
`barcode-status` and the keydown listener all still resolve.

Inventory carried the same focus trap POS documents, so it gets the same fix: `focusEntryField()`
picks the scanner while it is visible and the product search box when it is not, and the
click-anywhere refocus handler now bails out entirely while the scanner is hidden rather than
dragging focus into the search box on every click. `autofocus` came off the input — a hidden input
cannot take focus, so it was a promise the page could not keep.

**Quick Restock is inside that card**, and is only ever revealed by a successful scan
(`showQuickRestock`), so hiding the scanner takes it with it. That is not a silent loss of function:
restocking is still on the product edit page, and both post to the same
`ProductController::addBatch`. Dropping the `hidden` attribute brings both back.

**The one omitted kind.** `fail_to_return` is the only alert kind that never pops. A missed return
window cannot be acted on any more — the stock is stuck — so it belongs on the bell as a record
rather than as an interruption. Adding it would be one entry in `$toastKinds` plus a
`.remedi-toast.is-missed` legend line.

### One feed, newest first

The bell's All tab and the `/notifications` list are **one flat chronological feed**. Rows are not
gathered into per-group or per-kind blocks, and there are no section headings: an expired batch from
this morning sits directly above a low-stock product from last week. The tabs are how you narrow to
one kind; the list itself never clusters.

**Selection and order are separate decisions, and both are load-bearing.** `AlertService::payload()`
still takes only `$perKind` rows of each kind — with 645 low-stock products, taking the top N of one
merged list would bury the two expired batches that actually need pulling off the shelf. Recency then
reorders *that slice*, so it can only ever reshuffle rows which already earned their place. Removing
the cap and keeping the sort would silently turn the bell into a low-stock list.

Ordering runs on `sort_at`, an **onset timestamp** `payload()` stamps on every row — the moment the
condition became true, not the moment it was rendered:

| Kind | `sort_at` |
|---|---|
| `expired` | `expiry_date` — the day it expired |
| `expiring` | `expiry_date − EXPIRY_SOON_DAYS` — when it entered the 30-day horizon |
| `need_to_return` | `expiry_date − 120` (medicine) or `− NON_PHARMA_RETURN_WINDOW_DAYS` — `returnWindowOpenedAt()`, mirroring `ProductBatch::is_returnable` |
| `low_stock` | `max(batches.updated_at)` — when stock last moved |
| audit rows | `created_at` |

Low stock keying off `updated_at` is what makes a checkout feel live: `decrement()` writes the
timestamp, so a product the current sale just pushed under its reorder line jumps to the top of the
bell instead of sitting wherever severity put it.

`sort_at` orders the list **and is now rendered too**, via the `when` label — see "Times and dates on
the feed" below. Only rows with `at` (audit rows) get an "x ago" stamp: an inventory alert is a
standing condition, not an event, and the old rule against stamping one still holds. What changed is
that the onset is labelled **"Since ..."**, which is a claim `sort_at` actually supports; the
relative phrasing is still reserved for rows that really happened at a moment.

Both surfaces sort the same way in three places, and all three must agree or the list visibly
reshuffles after load: the Blade render, the JS `render()` that rebuilds rows after each poll, and
`orderRows()` / `sinkRead()`. Rows carry it as `data-sort-at`; an unparseable value sorts last rather
than jumping the queue.

**Read rows sink to the bottom.** `orderRows()` and the page's `sinkRead()` both sort unread before
read, then newest first within each bucket — one `Array#sort`, two keys, identical on both surfaces.
A panel that keeps handled rows at the top pushes new ones below the fold, which is the whole reason
to open it. Unread outranking recency costs nothing: the newest notification is unread by definition,
so it still lands first.

**The sink must never run while the pointer is on a row.** These rows are links, and a row that moves
between `mousedown` and `mouseup` takes its own click with it — the `click` then lands on the list
instead of the anchor and the notification simply never opens. So `applyReadState(false)` /
`applyRead(false)` repaint without reordering on every pointer path, including the `remedi:alerts-read`
listener, and the sink lands on the next panel open, poll or page load instead. `setOpen(true)` calls
`applyReadState()` before `refresh()` for the same reason it cannot wait for the poll: an unchanged
payload hits `render()`'s signature guard and never re-renders.

Marking everything read moves nothing, by construction — if every row is read the sort is a no-op —
so "Mark all as read" fades the list in place rather than shuffling it.

### Real-time search suggestions
`SuggestController` (+ `/suggest/{products,sales,users,audit}`) backs a shared typeahead. Every
endpoint returns the same shape — `{results:[{label, meta, value, ...}]}` — so one front-end
component drives all of them. Product results also carry `id/sku/price/stock`.

Attach by putting `data-suggest-url` on any input; `REMEDI.attachSuggest` wires it automatically on
DOMContentLoaded (wraps the field in `.suggest-wrap`, builds the panel, handles debounce, abort of
in-flight requests, arrow keys, Enter, Escape, click-outside, and match highlighting).

Choosing a suggestion fires a `suggest:choose` event carrying the row — each page decides what that
means: **POS adds it straight to the cart** (the payload has id/price/stock, and that's the action a
cashier wants), the other AJAX lists re-run their search, and the plain GET pages (users, audit)
submit their form. Queries under 2 characters return nothing on purpose.

### POS on tablet/phone
The register switches to a quick-service layout below 1024px: one full-width wall of large product
tiles (3 across on tablet, 2 on phone, 1 below 340px) with the cart **docked to the bottom of the
viewport** as a collapsed summary bar — item count, running total, tap to slide the full cart up.
`.pos-products` carries bottom padding so the docked bar never covers the last row of tiles.

**Why the classes matter:** the layout was `style="grid-template-columns: 2fr 1fr"` inline, which a
media query cannot override — on a 375px screen it still computed to 474px + 650px and put the cart
~750px off-screen. Anything that needs to reflow must be a class (`.pos-layout`, `.pos-grid`,
`.pos-cart`), never an inline grid.

### Report aggregate caching
Analytics measured 8.8s uncached at 205k rows and ~16s at 339k, all of it SQL over `sales_history` — long enough
that clicking through from the reports hub visibly sat on the old page. `topProductsBetween`,
`unitsSoldBetween` and `trendBetween` are cached per (start, end).

Range keys can't be enumerated to invalidate, so they carry a **version stamp**. There are two,
deliberately:
- `sales_cache_version` — bumped by `bumpHistoryVersion()` (reseed/import). Retires everything.
- `pos_cache_version` — bumped by `bumpCacheVersion()`, called from `PosController::checkout`.
  Retires only POS-dependent keys.

A checkout must not retire the history-only aggregates: they cost ~5s to rebuild and a sale doesn't
change them. `trendBetween` goes further — it caches only the history half and merges the (tiny) POS
delta fresh on every call, so a sale costs ~300ms instead of 3.7s.

Measured: cold 6.6s / warm 276ms / after a POS sale 307ms.

### Reports: screen copy vs print copy
Each report renders the data **twice** — `#no-print` for the screen and `#print-area` for paper.
`#print-area` is unpaginated by design (the whole dataset, so it prints complete), so it is
`display: none` on screen and `display: block` only inside `@media print`. Leaving it visible made
the Inventory report ~167 viewports tall: 119,320px of duplicate rows against 1,282px of real page.
The screen copy shows the same data in a capped `.list-scroll` box with a sticky header.

**Rendering it twice is affordable; styling it inline twice was not.** Measured 2026-09-02, before
any change: the page was **7,928,865 bytes (7.6 MB)** — 4.4 MB of screen copy, 3.0 MB of print copy —
and **3.35 MB of that was `style="..."` attributes**, 58,360 of them, because every cell of every row
carried its own. A style attribute on a cell here is paid for ~5,300 times. Another 216 KB was the
`onmouseover`/`onmouseout` pair riding on all 2,638 screen rows to do what one `:hover` rule does.

Both tables are now styled from a `<style>` block at the top of the view (`.inv-rep` for the screen
copy, `.inv-print` for the print copy), and the status badge is resolved once per row into a class +
icon + label instead of four branches of markup. Result: **3,345,849 bytes (3.2 MB), a 58% cut**, with
the screen row down from 1,717 to 623 bytes and 51 style attributes left in the whole document. Render
time moved little (1,072ms → ~1,000ms) — the cost there is the aggregate query, not the markup.

Nothing about what the report SAYS changed, and that was verified rather than assumed: the text of
every cell in both copies was extracted before and after, under four filter states (unfiltered,
low-stock, expired, both), and diffed — identical in all four. Computed column widths, row heights,
cell padding, colours and badge fills were compared in the browser and match exactly.

Two things to keep in mind if you touch this view again:

- **The two tables really are different** — the print copy is bordered, zebra-striped, 12px, and
  carries a `tfoot` total; the screen copy is 13px, borderless, sticky-headed and hover-lit. They are
  not a candidate for merging into one table with print CSS unless you also move the print-only
  chrome (header, filters-in-force line, section title, footer) around it.
- **`data-search` stays on every screen row.** It is what the client-side filter box walks, and it
  carries the SKU, which is not otherwise displayed — deriving the filter text from the visible cells
  instead would silently drop search-by-SKU.

The test that reads this report used to grep `<div style="font-weight:500;color:#111;">` to pull
product names out of the HTML, which tied four assertions about low-stock ORDERING and MEMBERSHIP to
one inline style; they broke on contact with this change. `InventoryReportTest::rows()` now reads the
DOM structurally (`#no-print` rows, second cell, first div). Assert on structure or on text, not on
paint.

### A date input cannot be given a placeholder, so an empty one is not a date input

`<input type="date">` ignores the `placeholder` attribute outright, and the `mm/dd/yyyy` /
`dd/mm/yyyy` / `yyyy-mm-dd` text it shows when empty comes from the BROWSER's locale, not from the
page. `lang` does not change it: four inputs on one page with no lang, `en-US`, `en-GB` and `en-CA`
all rendered `mm/dd/yyyy` in Chrome, because Chrome follows its own UI language
(`navigator.language`). A workstation whose browser runs a `dd/mm/yyyy` locale would show that, and
the markup could not say otherwise.

So the layout holds one delegated script (added 2026-09-02) that makes the placeholder real:

- An **empty** date field is carried as `type="text"` with `placeholder="mm/dd/yyyy"`.
- On `focusin` it becomes `type="date"` and `showPicker()` is called on the same user gesture, so a
  single click lands in a genuine date field with the native calendar already open. Verified in the
  browser: click an empty filter, and the month segment is selected with the picker showing.
- On `focusout` it goes back to text **only if it is still empty**.
- A field that holds a VALUE is never touched. A value is not a placeholder, and the browser draws it
  in the user's own format. **Add New Batch therefore stopped prefilling its two dates** (2026-09-02):
  received defaulted to today and expiry to the product's usual shelf life, so both showed a date
  where the format was wanted. Both now start empty and read `mm/dd/yyyy` like the rest of the app.
  `ProductController::edit()` still computes `$suggestedExpiryDate` — restoring either prefill is one
  line in the view — but an expiry accepted by accident because it was already sitting in the box is
  the one mistake on that form which reaches the shelf, so the empty field is also the safer one.

The swap can only happen while the field is empty, so no typed value is ever at risk, and the element
keeps its `name`, classes, inline styles and `min`/`max` the whole time — verified that `max` survives
the flip and that a chosen date posts as `Y-m-d`, which is what the server validates. A
MutationObserver picks up date fields that arrive with an AJAX partial or a re-rendered form.

What this replaced: a first pass wrote the format as a caption beside each field (`.date-hint`,
`(mm/dd/yyyy)` after the label). That is not a placeholder — it is a second label — and it was
removed.

### Categories are derived from the product name, not the supplier file

The master file's `Category` column behaves like a naive substring match over the product name, so
it cannot be trusted: it filed 170 deodorants and shampoos under Medicine / Pharmaceutical, ice
cream ("CREAM CUPS") under Personal Care, a condom ("TRUST CONDOM CHOCOLATE") under Snacks, hair wax
("GATSBY WAX WATERGLOSS") under Water & Beverages and baby soap ("J&J BABY SOAP MILK") under Milk &
Dairy -- while leaving contraceptive pills, corticosteroid creams, IV fluids and salbutamol inhalers
in General Merchandise.

`App\Services\ProductClassifier::classify()` re-derives the category from the name instead. Three
things about it matter:

- **The rule table is ORDERED, first match wins**, because the signals collide constantly:
  "MYRA E 400IU" is a vitamin but "MYRA LOTION" is a toiletry; "CREAM CONES" is dessert but
  "BETNOVATE CREAM" is a corticosteroid. Narrow buckets (devices, supplements, baby, household)
  resolve before broad ones (personal care, then medicine). Reordering the table changes the output.
- **A stated dose or an oral dosage form wins over any cosmetic pattern** (rule 2b), which is what
  keeps "NIZORAL 20MG/ML SHAMPOO" a medicine and stops ' FC ' ("facial cleanser" everywhere else in
  this catalogue) from throwing out "MYREVIT-C PLUS FC TAB". Vitamins are checked *before* that rule
  because they carry the same IU/MG strengths and the same tablet and syrup forms.
- **No match returns `null`, meaning "leave this product alone"** -- never a guess into General
  Merchandise. 170 products are unplaceable (mostly brand-only drug names like `TELMIGEN 80`) and
  stay wherever they are.

`ProductSeeder` and `import:receiving-reports` both call it, so a reseed or reimport off the
original CSV lands in the right categories rather than undoing the work. Migration
`2026_08_19_000001` applied it to the seeded data (1,164 products moved) and created the three
categories the master file never had: **Medical Supplies & Devices**, **Baby Care**, **Household**.

Preview any rule change before committing it -- the command is a dry run unless `--apply` is passed:

```bash
php artisan products:classify --category="Medicine / Pharmaceutical"
```

This is not cosmetic tidying. `Product::$is_medicine` keys off the category name and drives the
90-120 day supplier return window, so every misfiled toiletry was being held to a drug's return
schedule and every misfiled drug was not. The dashboard's split between "Medicine Returns" and
"Other Product Returns" only tells the truth if the categories do.

When adding patterns, check both directions on real data (`--samples=400` prints the names), and
watch the words that mean two things: CREAM, POWDER, MILK, WATER, MASK, COLOR and CHOCOLATE each
belong to two different aisles in this catalogue.

### What counts as medicine

**The category NAME is business logic, and the rename form could rewrite it.** `Product::is_medicine`
matches `category->name === Category::MEDICINE` — a plain string comparison against user-editable
display text. `CategoryController::update` let an admin rename any category, so renaming
"Medicine / Pharmaceutical" to, say, "Medicines" silently reclassified the entire catalogue. Measured
on this data, inside a rolled-back transaction:

| | before | after rename |
|---|---|---|
| products classed medicine | 1,393 | **0** |
| `failed_return` ("Return window missed") | 75 | **0** |
| `needs_return` | 79 | 89 |

The whole "Return window missed" alert kind disappears, because `Product::failed_return` opens with
`if (! $this->is_medicine) return false;`. The remaining batches fall from the 90-120 day supplier
window onto the flat 10-day non-pharma rule. Nothing reports any of it — there is no error, and the
audit trail records only "Renamed category to: Medicines".

Two guards now:

- `Category::MEDICINE` is the single definition. The string used to be written twice — a literal in
  `Product::getIsMedicineAttribute()` and again as `ProductClassifier::MEDICINE` — two copies that
  had to agree for the return rules to work. `ProductClassifier::MEDICINE` now references the model
  constant.
- `Category::RULE_DRIVING_NAMES` / `drivesBusinessRules()`, checked by `CategoryController::update`,
  refuses the rename with an explanation. Submitting the unchanged name is still allowed, so saving
  the form without editing the field does not error; ordinary categories rename freely.

**The durable fix is a stable key on `categories`** — a slug, or an explicit `is_medicine` flag — so
the display name stops being load-bearing and the guard can go. Until then, adding any new
name-matched rule means adding its name to `RULE_DRIVING_NAMES`.

`Product::$is_medicine` drives the 90-120 day supplier-return window, so it must mean *actual
pharmaceutical stock*. Category alone isn't enough: the supplier master files ~170 toiletries and
cosmetics under "Medicine / Pharmaceutical", and a body spray was inheriting the drug return window
and showing up as "Need to Return".

`is_medicine` = in that category AND not `is_personal_care_item`, which matches two lists on the
product name (`NON_PHARMA_NAME_PATTERNS` for forms like DEO SPRAY / COLOGNE, `NON_PHARMA_BRANDS` for
houses like REXONA / NIVEA / SILKA that sell nothing medicinal).

**A stated dosage always wins.** `DOSAGE_PATTERN` (MG/MCG/IU/%) short-circuits both lists, because
"NIZORAL 20MG/ML SHAMPOO" is a medicated antifungal that the word SHAMPOO would otherwise have
thrown out. Deliberately does NOT match a bare `ML` — "135ML" is a bottle size, not a dose.

This name-pattern rule now sits on top of correctly-filed categories rather than compensating for
bad ones (see "Categories are derived from the product name" above): reclassification moved every
personal-care item out of Medicine, so `is_personal_care_item` currently matches nothing inside that
category and acts as a safety net for data that arrives before the classifier runs.

When adding patterns, spot-check both directions — that cosmetics are caught AND that therapeutic
lines (BETADINE, SALBUTAMOL, EFFICASCENT, ALCOPLUS...) still classify as medicine.

### Status colours are fixed by a legend

The design supplies five status colours; **do not invent new ones or re-map these**:

| status | colour | classes |
|---|---|---|
| Low Stock | yellow | `.badge-danger`, `.filter-low_stock` |
| Expiring Soon | orange | `.badge-warning`, `.badge-expiry-soon`, `.filter-expiring` |
| Expired | red | `.badge-critical`, `.badge-expiry-expired`, `.filter-expired` |
| Need to Return | blue | `.badge-return-due`, `.filter-need_to_return` |
| Returned | green | `.badge-return-done`, `.filter-returned` |

Stock and expiry share one **yellow → orange → red** severity ramp; the supplier-return states sit
outside it in **blue/green**, so "act on this return" is never mistaken for "this is about to go
off" — the same separation the old rose/amber split existed to enforce, now expressed in the
legend's hues. **Fail to Return is not in the legend**: it keeps a deep red (`.badge-return-late`),
because it means what Expired means — too late to act — but darker so the two stay separable.

A tab wears the same colour as the badge it filters for, so the Inventory tab strip and the Status
column always agree. Dashboard doughnut slices and KPI accents use the same hues (see `C` in
`admin/dashboard.blade.php`), which is why blue is reserved for Need to Return and the money KPIs
use teal.

### Two status scales — expiry vs supplier return

**Expired and returned stock was sellable at the till.** FEFO walked
`->where('quantity','>',0)->orderBy('expiry_date','asc')` — `quantity > 0` was the *only* condition,
and ordering by expiry ascending meant the register reached for the **most expired batch first**.
Measured before the fix: **79 batches, 3,434 units, ₱41,351.99 retail** of expired stock sellable,
with `CHLORPHENAMINE 2.5MG/5ML TAB` (expired February) near the head of the queue. For a pharmacy that
is the failure mode that matters.

`ProductBatch::scopeSellable()` is now the single definition — `quantity > 0`, `returned_at IS NULL`,
and `expiry_date` null or `> today` (`>`, not `>=`: `is_expired` treats the expiry date itself as
expired). `is_sellable` is its in-memory twin for eager-loaded callers, deferring to `is_expired` so
there is still one definition of "expired". Verified the two agree on all 2,555 sellable batches.

`Product::$sellable_stock` sums it and is what `PosController::checkout` uses for **both** the
availability check and the FEFO walk, so the figure quoted in "Available: N" and the stock actually
deducted cannot disagree. `total_stock` is left alone on purpose: it means *physically on the shelf*,
which is the right number for the inventory report's valuation. The two differ
by exactly the stock that needs pulling.

Verified with a product holding one expired batch (5 units) and one good batch (5): buying 8 was
refused with "Available: 5", buying 3 drew entirely from the good batch, and the expired batch was
left untouched.

**`is_low_stock` reads `sellable_stock` too — the reorder signal is about what you can dispense.**
This was the last consumer still comparing `total_stock`, and it was hiding real stockouts: **37
products had nothing sellable and were reported as adequately stocked.** The worst was
`ASICLAV 625 MG TAB X14`, a prescription antibiotic — a single batch of 301 units, expired three
months earlier, reorder level 30, `is_low_stock` false. The till would dispense none of it and
nothing ever told anyone to reorder.

The low-stock count is therefore **682, not 645**. That is the correct number, not an inflation: all
84 products with zero sellable stock are now flagged, and nothing remains masked. Verified identical
(682) across all three eager-loading strategies — unconstrained, `$onShelf` slices, and
InventoryController's widened load — so the accessor behaves the same however the batches arrived.
Confirmed on the rendered dashboard KPI, its Alerts panel, the bell, and the Inventory `low_stock`
filter.

**Three POS surfaces feed that number, and all three must agree with it.** Fixing checkout alone left
the display lying: `pos/_grid.blade.php` and `PosController::lookupBySku` still reported
`total_stock`, so APPETASON SYR 120ML — 10 units, all expired — rendered "Stock: 10", was *not*
marked `is-out`, and passed `10` to `addToCart` as the cart ceiling. The cashier could fill a cart
and only discover "Available: 0" at checkout, with a customer waiting. The scanner path answered
`stock: 10` too.

All three now read `sellable_stock`. `pos/index.blade.php` already required this: `syncStockBadge()`
carries the note *"the grid badge carries the figure addToCart trusts, so a live lookup that finds a
different number updates both or they disagree on the next tap"* — the grid and the lookup were
consistent with each other but both wrong, so the guard could not catch it.

Inventory deliberately still shows `total_stock`: that row reads "10 … Expired Batch", which is the
right answer for stock physically held and awaiting disposal. Measured 3 queries for a 12-product
grid page, so `sellable_stock`'s `relationLoaded()` path holds and there is no N+1.

**The dashboard loads returned batches too, and derives shelf figures from a narrower subset.**
`$activeBatches` was `quantity > 0` alone — the same mistake `InventoryController` had already fixed
in its own eager load. Because `markBatchReturned` now zeroes the quantity (see below), every future
return would have vanished from the dashboard's "Successfully Returned" rings the moment it
completed: measured 1 → 0 on the non-pharma card with the batch's quantity zeroed. The card was added
once before for this exact symptom; only its *data source* was never widened, so it worked by accident
while returned batches happened to still carry stock.

The load is now `quantity > 0 OR returned_at IS NOT NULL`, and `$onShelf` — the `quantity > 0` subset
— feeds everything that means "sitting on the shelf": `$expiringSoonBatches`, `$expiredBatches`,
`$batchesByProduct` (so `total_stock` / `is_low_stock` are untouched), `$categoryBreakdown` and
`$expiryOverview`. The return tallies read the full set. Without that split a zero-quantity returned
batch would start showing up as expired stock needing to be pulled.

Verified figure by figure with a returned batch zeroed: `nonPharma returned` holds at 1 (was dropping
to 0), while `expired` 79 → 78, `expiryOverview expired` 79 → 78 and category units −3 — which is
correct, because those 3 units really have left the shelf. Live dashboard unchanged today
(644 / 28 / 79 / 26).

**Money is compared in whole centavos, never as raw floats.** `selling_price` comes back from MySQL
as a *string*, and `"1.05" * 3` is `3.1500000000000004` in binary floating point. The payment check
was `(float) $amountPaid < $totalAmount`, so a customer tendering exactly ₱3.15 was refused:

```
"Insufficient payment. Amount due: 3.15, Received: 3.15"
```

Two identical numbers, and nothing the cashier can do about it — the customer has handed over the
right money and the till says it is short. **1,613 price × quantity combinations in this catalogue
land on a total that cannot be paid exactly.**

The comparison is now `(int) round($paid * 100) < (int) round($total * 100)`. Integer centavos rather
than round-then-compare: rounding both sides fixes this case, but integers are exact by construction
and cannot drift again as the arithmetic grows. Each line subtotal is rounded to centavos as it is
computed (not only at the end) so the running total always equals the sum of the stored line
subtotals — the invariant a receipt has to satisfy — and `change_due` is rounded for the same reason.

Swept 23,742 combinations afterwards: **0** exact payments rejected (was 1,613) and **0**
one-centavo-short payments accepted, so the guard tightened without loosening.

**No historical damage.** `total_amount`, `subtotal`, `amount_paid` and `change_due` are all
`decimal(10,2)`, so MySQL rounded the noise away on write — an audit of all 41 sales found none where
`total_amount != SUM(sale_items.subtotal)` or `change_due` disagreed. The bug lived entirely in the
comparison, refusing good money; it never wrote a bad figure.

**The stock guard totals per product, and the FEFO walk has to fill the line or fail.** The check was
per cart line — `$available < $requestedQty` for that line alone — so a cart naming the same product
twice slipped straight through. Verified: 6 units in stock, lines of 5 and 5, both compared against 6
and passed.

```
CHARGED (sales.total_amount) : ₱1,000.00
DELIVERED (sum of sale_items): ₱600.00  (6 units)
DISCREPANCY                  : ₱400.00
change_due stored            : ₱1,000.00   (computed on the inflated total)
```

The receipt printed `6 × ₱100.00` above `Total ₱1,000.00` — a document that does not add up, with the
change worked out from the wrong figure. The FEFO loop simply ran out of batches and stopped; nothing
reported it.

Two fixes, both needed:

- Requested quantities are **summed per `product_id`** before any availability check, so the error
  now reads "Available: 6, Requested: 10".
- After the FEFO walk, `$remainingQty > 0` **aborts the transaction**. The aggregate check should make
  that unreachable, but it is the invariant that actually matters — never bill for stock that was not
  deducted — and it covers what no pre-check can: stock moving between the check and the deduction,
  i.e. a second register selling the same product concurrently.

**Exposure, honestly:** the POS UI never triggered this. `addToCart` keys the cart by product id
(`cart[id]`), so the browser merges duplicates and enforces `maxStock` client-side — and an audit of
all 41 sales found **zero** with `total_amount != sum(sale_items.subtotal)`. It was reachable by
posting to `/pos/checkout` directly, and by any future client that builds a cart differently. The
concurrency half was always reachable.

**`updateBatch` is the back door into all of this, and it was unguarded.** Every protection above
lives on `scopeSellable`, which reads `expiry_date` — so whoever can edit that field can undo the lot.
The action validated only `'expiry_date' => 'nullable|date'`. Verified before the fix, in two
requests:

```
PUT /batches/{id}  expiry_date=2028-06-01   -> 200 "Batch updated."   (batch was expired)
POST /pos/checkout quantity=5               -> 200 TXN-20260824-00041 (sold)
```

An expired medicine batch resurrected and dispensed. And the audit trail said only
`Updated batch 'X' for Y` — no record of *what* changed, so the expiry move left no trace at all.

Three changes:

- **`expiry_date` is `required`, not `nullable`.** `is_expired` returns false for a null expiry, so
  clearing the date made a batch permanently sellable — a cleaner laundering route than moving it.
  No batch in the catalogue has a null expiry, so requiring it breaks nothing.
- **`after:received_date`, but only when the date is actually being changed.** 73 seeded batches
  already have `expiry <= received_date`, and the edit form re-posts the stored expiry unchanged —
  enforcing it unconditionally would lock all 73 out of editing, including zeroing them for write-off,
  which is precisely what you want to do with them.
- **The audit records the change, and flags un-expiring.** Correcting a genuine typo at receipt time
  is legitimate, so the fix is visibility rather than prohibition:

```
before:  Updated batch 'X' for Y
after:   Updated batch 'X' for Y: expiry 2026-01-15 to 2028-06-01 — BATCH WAS EXPIRED
         Updated batch 'X' for Y: quantity 10 to 4, expiry 2028-06-01 to 2026-01-15
```

Moving an expiry forward on a batch that *was* expired is the exact shape of un-expiring stock. It
stays permitted, but it can no longer happen quietly.

**Marking a batch returned now zeroes its quantity.** It only set `returned_at` / `returned_by`, so
the units stayed in `total_stock` and in the FEFO queue — BABY DOVE BAR 75G sat at "Successfully
Returned" with 3 units the till would still have sold. The rest of the app already assumed otherwise:
`InventoryController` widened its eager load to `quantity > 0 OR returned_at IS NOT NULL` precisely
because "a returned batch has normally been shipped back, so its quantity is 0". Nothing made that
true. `qty_received` preserves how many arrived, the batch keeps its row, badges and place in the
Returned filter, and the audit entry now records how many units left ("… (2 units removed from stock)").

**Every search escapes LIKE's wildcards via `Controller::likeTerm()`.** The term was interpolated
straight into `LIKE '%term%'`, so `%` and `_` were *executed* rather than searched for:

```
"70%150ML"     0 products contain it   LIKE returned 2
"LEWIS_PEARL"  1 product contains it   LIKE returned 5
bare "%"       —                       LIKE returned all 264 pages
```

Real data makes this a functional bug, not a curiosity: **9 products carry `%`** in their name
(`G. CROSS ETHYL 70% W/ MOIST 150ML` and siblings — alcohol strengths) and **3 carry `_`**
(`LEWIS_PEARL COOL FANTASY 125ML`, `BABYFLO PETROLEUM - SOOTHING_RELAXING 50G`). Searching for those
products by their actual printed name returned rows that do not contain what was typed.

`likeTerm()` escapes `\`, `%` and `_` — backslash **first**, or it double-escapes the ones it is
about to add. Applied at all eight sites: products, inventory, POS, sales, users, audit, and the four
`/suggest/*` endpoints. Verified afterwards: `%` → exactly the 9 matching products, `_` → exactly 3,
`70%150ML` → none, `ETHYL 70%` → 2, and ordinary searches unchanged (`BIOGESIC` → 8).

**Deleting a product must be refused when it has sales history, not just sale_items.** The original
guard checked `saleItems()` only — and `sale_items` has a real `ON DELETE RESTRICT`, so that path was
already protected at the database level. `sales_history.product_sku` is a plain string with **no
foreign key**, which is where the actual exposure was:

```
products with sales_history rows         : 2,584
products blocked by the sale_items guard :    29
DELETABLE despite having history         : 2,555
```

Deleting one silently drops its rows out of every revenue join. Measured on **SYMBICORT 160/4.5MCG
RAPIHALER** — a corticosteroid inhaler with 895 history rows — **₱7,741,795.77** would have
disappeared from every report, more than a tenth of lifetime revenue, with no error and nothing in
the audit trail to explain the drop.

`destroy()` now refuses with the row count and points at the same alternative the `sale_items` guard
uses (zero the stock). Verified: SYMBICORT refused, a history-free product still deletes normally.

**A SKU rename must carry the product's history with it.** `sales_history`, `demand_forecasts` and
`sales_forecasts` all key on `product_sku` as a **string, with no foreign key** to `products.sku`. So
editing a product's SKU silently detached everything joined to the old value — no error, no warning,
the totals just got smaller.

Measured on HERACLENE 1MG TAB X100 (1,459 history rows / 42,276 units):

```
lifetime revenue before SKU edit : ₱73,182,943.26
lifetime revenue after  SKU edit : ₱72,273,584.44
silently lost                    : ₱909,358.82
forecast rows still joinable     : 0 of 6
```

`ProductController::update` now re-points all three tables inside the same transaction as the product
save, and records the move in the audit entry
(*"SKU 4809599008435 to …, moved 1459 sales_history, 6 demand_forecasts, 6 sales_forecasts"*).
Re-pointing rather than refusing the edit, because refusing strands a genuinely mis-keyed product
forever — carrying the history is what "fix the SKU" means. It also clears the revenue caches
explicitly: the `Product::saved` hook only watches `selling_price`, and a rename changes the joins
without touching the price.

Verified over HTTP: revenue identical before and after (₱73,182,943.26), all 1,459 rows and both
6-row forecast sets moved, zero orphans after renaming back.

**Editing a product's price rewrites HISTORICAL revenue, so it must retire the revenue caches.**
`sales_history` stores units only — every revenue figure in the app is
`quantity_sold * products.selling_price`. Change a price and every past month's revenue changes with
it. Those aggregates are cached for 24h (`monthlyRevenue`, `quarterlyRevenue`, the range-keyed
trend/top-product keys) and 6h (`SalesForecastService`), and **only a reseed or a POS checkout ever
cleared them.**

Measured by doubling one product's price through the normal edit form: August 2026 actually moved by
**₱20,884.92** while the dashboard, the reports and the Sales Forecasting page all kept showing the
old total.

`AppServiceProvider` now clears them on `Product::saved` **gated on `wasChanged('selling_price')`**,
and unconditionally on `Product::deleted` — `sales_history` joins `products` on `sku` with no foreign
key, so deleting a product silently drops its rows from every revenue join, and the `sale_items`
guard on `destroy()` does not cover a product with history but no POS line items.

The gate matters: `forgetCaches()` retires the expensive history-only aggregates (~3.5s to rebuild),
so firing it on every product save would make routine edits pay for a rebuild they did not cause.
Verified over HTTP — a price edit clears both caches and bumps the version stamp (7→8); a
`reorder_level` edit leaves them cached and the stamp unmoved (9→9).

**Testing note: verify model-event hooks over HTTP, not through `tinker --execute`.** Repeated runs
of the same script there gave contradictory answers — the same price edit cleared the cache in one
run and left it cached in the next, and a listener registered in the shell fired in one run and not
the next. The registrations are definitely present (`Event::getListeners('eloquent.saved: '.
Product::class)` returns three), and over HTTP the behaviour is stable and correct: two consecutive
rounds each cleared the caches and bumped the version stamp (10 → 11 → 12).

The root cause of the shell inconsistency has not been chased; the practical rule is simply that a
tinker result here is not evidence either way.

**`sidebar_categories` depends on products, not just categories — invalidate from both sides.** The
cached payload is `Category::withCount('products')`, but only *category* writes cleared it.
`CategoryController` (create / rename / delete), `products:classify` and two migrations all called
`Cache::forget`; `ProductController` never did. So creating a product, deleting one, or moving one
between categories left the sidebar counts wrong for up to the full **6-hour TTL**.

Verified through the normal form: created a product in Household and the sidebar kept reading **7**
against a real 8, on every page in the app.

The `Product::saved` / `deleted` hooks in `AppServiceProvider` now clear it, alongside the existing
`AlertService::forget()` ones. Hooked on the model rather than in the controller so it covers every
write path — the controller, `import:receiving-reports`, the seeders, tinker. Confirmed the sidebar
now tracks create (7→8→9), category move, and delete (back to 7) immediately.

**Every href in the cached alert payload is RELATIVE, and must stay that way.** `route()` returns an
absolute URL by default, and this payload is cached and shared by every signed-in user — so the host
that happens to warm the cache is frozen into everyone's notification links.

Reproduced: cleared the cache, warmed it from a request to `127.0.0.1:8000`, then read it from a
`localhost:8000` session — every href came back as `http://127.0.0.1:8000/...`. That is a **different
origin**, so the session cookie is not sent: clicking a notification drops the user on the login page
looking signed out. With `TTL_SECONDS` at 30 it also flaps — on any install reachable under two names
(an IP and a hostname, or behind a proxy that rewrites `Host`) whichever host polls first wins the
next thirty seconds.

`route($name, $params, false)` throughout `payload()`, `activity()` and
`ProductBatch::expiringSoonUrl()`. Relative paths behave identically in an `href` and in the JS
renderer and carry no host at all. The Inventory page's own filter tabs stay absolute on purpose —
they are rendered per request, never cached, so they always carry the right host.

**The dashboard's Alerts panel must count what the bell counts.** Four of its five rows did; the
fifth, "Return window open", read **26** where the bell read **78** and the Inventory filter it links
to listed 78.

It was using `$returnStats['need_to_return']` — the **medicine-only** tally — under a label reading
*"batches can still go back to the supplier"*. The 52 non-pharma batches it omitted are genuinely
returnable and carry a working "Mark Returned" button on their row, so the panel was under-reporting
actionable work by two thirds.

`DashboardController` now also derives `$returnableCount` from `ProductBatch::is_returnable` — the
same definition `AlertService`, `Product::needs_return` and the button all use — and the KPI card plus
the Alerts row read that. `$returnStats` is untouched: the **Medicine Returns** card is an explicit
per-category breakdown and still shows 26 / 75. Both figures are correct; they were simply appearing
under the wrong labels.

All five rows now agree with the bell: 682 / 28 / 77 / 78 / 75.

**Two expiring horizons coexist, and the alert link must ask for the one it counted.** The dashboards
and the bell use `EXPIRY_SOON_DAYS` (30) — the "pull these now" list. The Inventory tab uses
`is_expiring_soon` (90) — a wider planning view. Both are deliberate.

But the bell's alert **linked to that tab**, so a row reading *"28 batches expire within 30 days"*
opened a list of **85 products across 9 pages**. The count promised and the list delivered were
answers to different questions, and nothing on the destination explained the jump.

**There are ten such links, not one.** The bell's rollup is only one of them — the admin dashboard has
four, the staff dashboard four, and `_dashboard-actions.blade.php` one. Fixing `AlertService` alone
left the five dashboard links still pointing at the 90-day view under headings reading *"28 batches
within 30 days"*. They all now call **`ProductBatch::expiringSoonUrl()`**, which is the single
definition; ten literals would have been ten chances for the eleventh to be written without the
parameter, which is how this drifted in the first place.

`InventoryController` now takes an optional `days` on the `expiring` filter, and `AlertService`
builds that alert's href with `days=30`. Only the two horizons the app actually means are accepted —
anything else (`days=999`, `days=abc`) falls back to 90, so a stray query string cannot invent a
third definition of "expiring soon". Verified: the link now lands on exactly its 28 products (3
pages); the bare tab still shows 85 (9 pages); every other filter is byte-identical.

The narrowed view carries a banner — *"Showing batches expiring within 30 days · Widen to 90 days"* —
because arriving from an alert that promised 28 and finding an unexplained subset is its own small
confusion. It appears only on the narrowed view.

**"Expiring soon" and "Expired" are mutually exclusive — gate on `is_expired`, never on dates.**
`ProductBatch::is_expired` treats the expiry date *itself* as expired: we do not sell on it. So
"expiring soon" means **not yet expired** and inside the horizon, which is exactly what
`is_expiring_soon` (`! is_expired && days_to_expiry <= 90`) and the Inventory filters have always
done.

Two places used to re-derive it from raw dates instead, and both got it wrong for a batch whose
`expiry_date` is today:

- `AlertService::payload()` filtered `expiry_date->between($today, $soon)`. `between()`'s lower bound
  includes today, and there is no `is_expired` gate — so a batch expiring today was emitted **twice**,
  once as an "Expiring soon" row and again as an "Expired stock" row, with both kind totals counting
  it.
- `DashboardController` filtered `between($today, $cutoff)` for expiring but `lt($today)` for expired.
  Those disagree about today, so such a batch landed in "Expiring Soon" and nowhere else. The
  dashboard reported **77 expired against the bell's 79** on this install.

The dashboard's own `expiryOverview` block, in the same method, already gated on `! is_expired` —
which is what settled that the date arithmetic was the defect and not a deliberate second opinion.

This mattered beyond an inconsistent tally: Expiring Soon is the *"pull these"* action list, so stock
the app considers unsellable was being shown as still having shelf life.

An `expired` + `need_to_return` overlap is **not** the same problem and is intentional — a non-pharma
batch that has expired can still go back to the supplier (`is_returnable` includes `is_expired` on
that branch), so those are two true facts about one batch rather than a contradiction.

**Returned wins, everywhere, and the batch is never hidden.** A batch with `returned_at` set has been
sent back; it is not also "waiting to be sent back". `ProductBatch::return_status` answers
"Successfully Returned" before it looks at any window, so the medicine side got this free — but three
places computed the window themselves and never checked `returned_at`:

- `Product::needs_return`'s **non-medicine** branch, so a returned Baby Care batch kept raising "Need
  to Return" in the row badge, the Inventory filter and the bell — sitting next to the "Returned"
  badge on the same row, telling you to do what you had just done.
- `DashboardController`'s `$nonPharmaReturnStats`, which had **no `returned` bucket at all**. The
  Medicine Returns card filters to `is_medicine`, so a returned non-medicine batch was counted on
  neither card: the bell and the Inventory "Returned" filter showed it, and the dashboard showed
  nothing. The same batch was simultaneously counted as "Expired" by that card, so one ring
  double-counted it. The three buckets are now mutually exclusive with returned first, matching the
  medicine tally, and the ring charts all three.
- `InventoryController`'s eager load, which pulled `quantity > 0` only. A returned batch has normally
  been shipped back, so its quantity is 0 — `Product::has_returned_batches` could never see it, the
  "Returned" filter came back empty and the row lost its badge, i.e. the record of the return
  vanished at the moment the return completed. It now loads `quantity > 0 OR returned_at IS NOT
  NULL`. The extra rows are few and carry no stock, so nothing they feed changes.

None of this hides a batch. It keeps its row, its expiry badge and its place in the Returned filter;
only the claim that it still needs returning goes away.

These are different thresholds and must never share a colour family:

- **Expiry** (`ProductBatch::$expiry_severity`) — expired / critical (<=7d) / soon (<=30d) /
  watch (<=90d). Rendered with `.badge-expiry-*`, the yellow→orange→red ramp above.
  The dashboards' "Expiring Soon" list uses **30 days** (`EXPIRY_SOON_DAYS`), deliberately NOT 90:
  90 is the return window's lower bound, so the old 3-month cutoff made a batch "expiring soon" at
  the exact moment it stopped being returnable. The 90-day view lives on as the Inventory "Expiring"
  filter.
- **Supplier return** (`return_status`) — Need to Return / Fail to Return / Successfully Returned.
  Rendered with `.badge-return-*`, in **blue / deep red / green**.

**"Fail to Return" gets no button.** The window has been missed, the supplier will not take the
stock back, and offering the action implies otherwise — those batches are a write-off to dispose of,
not to return. `is_returnable` is true for medicine only while *inside* the window. Non-pharma has no
"fail" state, so an expired non-pharma batch still offers the action.

`ProductBatch::$return_status` implements the **pharma 90/120 rule only** — its docblock asks each
consumer to make the category check itself. `ProductBatch::$is_returnable` is that check, in one
place: it applies the 90-120 day window for medicine and the plain
`NON_PHARMA_RETURN_WINDOW_DAYS` expiry rule for everything else. **Use it for any "Mark Returned"
control.** Both views used to gate the button on `$product->is_medicine` and render nothing
otherwise, so a non-pharma product could show a "Need to Return" badge with no way to act on it
while every "Fail to Return" row (pharma by definition) had a button — which read as the button
being attached to the wrong status.

The return window sits *above* the expiry horizon: a medicine batch is only returnable at 90-120
days before expiry, so by the time it counts as "expiring soon" (<=90d) it has already fallen out of
that window. The admin dashboard previously styled its return counts with the expiry classes, which
made the two read as one warning. Measured cross-family hue gap is now 35 degrees; keep it there.

Both dashboards share these classes, so a colour means the same thing on either screen.

### Responsive layout
The app has three breakpoints, all defined in `layouts/app.blade.php` (there were none before):
**≤1024px** narrows the sidebar to 216px and tightens padding; **≤767px** turns the sidebar into an
off-canvas drawer with a `.sidebar-scrim` backdrop and raises every tap target to 44px
(`.view-all`, `.returns-more`, `.dash-tab` — "View All" was 64x21); **≤420px** puts the KPI grid
**two up**, not one. One column stacked six tiles above any content and made the admin dashboard
4,073px tall at 375px. Measured: tiles resolve to 169px, nothing inside overflows even with the
longest value the app prints (₱1,229,088.65) forced into every tile, and the page drops to 3,784px.

On mobile the drawer always boots closed regardless of the saved `remedi_sidebar_hidden`
preference, and toggling it there deliberately does **not** write to localStorage — a phone's
transient drawer state must not overwrite the desktop preference. It closes on scrim tap, Escape,
and on tapping any nav link (otherwise it covers the page you just asked for).

Three layout primitives matter when adding UI:
- **`.table-scroll`** — wrap every wide table. Report cards use `overflow:hidden` for their rounded
  corners, which *clips* a wide table on a phone instead of scrolling it, leaving right-hand columns
  unreachable.
- **`.chart-box`** — wrap every Chart.js canvas and set `maintainAspectRatio: false`. With
  `responsive: true` and no height box, Chart.js sizes a doughnut to its container's **width**, so
  one in a half-width card rendered ~460px tall.
- **`minmax(0, 1fr)`, never a bare `1fr`** on any grid holding a table, a long batch number, or a
  no-wrap header. An `fr` track's implicit minimum is `min-content`, and a grid *item*'s default
  `min-width` is `auto`, so neither can shrink below its content. **And when you add the `minmax(0,…)`,
  check what is fixed-width inside it** — freeing the track to shrink just moves the overflow to the
  first child that cannot, which is how a 250px doughnut started pushing the staff Inventory band
  past the viewport at 1280px the moment its track was allowed to narrow. `width: 100%; max-width:
  250px` on the ring, and a floor on the legend beside it, fixed that half. This has bitten several
  times:
  **The tablet case is the one that hides**: a media query keys off the VIEWPORT while the content
  column is 216px narrower than it, so at 768px `.demand-grid`, `.staff-demand-grid` and
  `.staff-category-grid` were each still told to show two columns inside a 447px card — as bare
  `1fr 1fr` they resolved to **300px + 300px** and clipped 173px outside the card, invisibly,
  because the card's `overflow:hidden` swallowed it and `document.body.scrollWidth` still
  matched the viewport. They now use `minmax(0, 1fr)` and stack at **900px, not 500px**.
  `.inv-bottom` resolved to **430/536/536px** instead of the ratio asked for, and `.dash-lower` let
  the Recent Sales card run to **604px on a 375px screen** — 229px off-screen and unreachable, with
  `.table-scroll` sitting at 560px believing it had the room, so it never scrolled. Add `min-width:
  0` to the items too when the child is the thing that won't shrink. Symptom to watch for: a
  `.table-scroll` whose `scrollWidth === clientWidth` on a narrow screen is not "fits", it is
  "escaped".

**Size charts in the stylesheet, not with an inline `height`.** An inline style beats any rule, so a
`<div class="chart-box" style="height:170px">` cannot be re-sized to balance the card next to it —
which is exactly what Sales Summary needed. It takes its height from `.dash-lower-side .chart-box`.
Same reasoning as the POS layout note above: anything that may need to reflow belongs in a class.
(The Stock Status card this note also covered is gone — see "Inventory tab layout".)

### The Inventory band is shared, and its CSS lives in the layout

`.inv-bottom` (two Expiring Soon lists + `.inv-returns-col`), `.expiry-*`, `.demand-head`, `.view-all`
and `.returns-*` are defined **once in `layouts/app.blade.php`**, not in the admin dashboard's style
block. They used to be admin-only, which is why the staff dashboard grew its own near-copies of the
same panels — the drift this file warns about under "Fixes applied to the admin dashboard do NOT
reach staff". Both dashboards now render the same band from the same rules and the same markup.

**Moving CSS between these blocks is where the bodies are buried:** an extraction that starts or ends
mid-comment leaves a dangling `/*` in the destination that silently eats the next rule. That is
exactly what happened here — `.inv-bottom`'s base `display: grid` was swallowed, the band rendered as
three stacked full-width cards, and every media-query variant of the rule still parsed fine, so it
looked like a specificity problem rather than a comment problem. Count `/*` against `*/` in both files
after any move; they must balance.

**The expiry row is three columns**: name over `Batch: <no>`, then `Expires: <date>` in its own
column, then the badge. The date column is what makes the dates line up down the list and be
comparable at a glance instead of buried mid-string.

The catch is width — these cards are only ~450px (three per row), leaving the name ~156px. So
**`.expiry-name` wraps to two lines rather than truncating** (`-webkit-line-clamp: 2`): nowrap +
ellipsis cut "SALBUTAMOL 2MG/5ML SYR (BUTAMOL) 60ML" mid-word, and a product you cannot identify is
worse than a 71px row. Verified: nothing clipped, dates aligned to a single x-position.

The date is `font-weight: 500`, never `<strong>` — it sits beside an already-bold product name, and
bolding both made every row read as a heading.

### Table row hover: paint the cells, not the row

`.remedi-table tbody tr:hover > td` — **not** `tr:hover`. A background set on a `<tr>` in these
`border-collapse` tables does not render at all (verified: a `<td>` takes the colour, the `<tr>`
ignores it even with `!important`), so the obvious row-level rule looks correct and shows nothing.
That is why hovering a row used to tint only the pinned Actions column — the pre-existing
`tr:hover td.col-actions` rule was cell-level and worked, while the row-level fill it was written
against silently did nothing.

Background only, no `transform`: a row that lifts drags the whole table's baseline with it. Cards
follow the opposite rule — only `a.card` (a card that is actually a link) reacts to the pointer;
a plain `.card` is a container and stays put.

### Nothing throws you back to the top

Five separate causes, all found by measuring a real page rather than reading the code. Any of them
alone reads to a user as "I clicked something and it jumped to the top":

- **The scanner refocus.** POS and Inventory both refocus the barcode field on *every* click that is
  not an INPUT/SELECT/BUTTON/A — and that field sits at the top of the page, so a plain `.focus()`
  scrolled it into view. Clicking a product card, a table cell or a label threw you to the top, and
  releasing a text selection did the same *and* wiped the selection. Now
  `focus({ preventScroll: true })`, and it bails out entirely while a selection is live
  (`!getSelection().isCollapsed`). The scanner stays armed; the viewport stays put.
- **The navigation skeleton.** `.is-navigating` swaps the content for a short placeholder, so the
  document collapsed from 2398px to under a viewport and the offset clamped — measured, 900px to
  324px. Invisible when the navigation lands (the next page starts at the top anyway), very visible
  when it does not: a click that ends a selection, a link to the page you are on, a cancelled
  download. `showNavigating()` now pins `contentBody.style.minHeight` first and `clearNavigating()`
  releases it.
- **Modal focus.** `confirmBtn.focus()` inside a `position: fixed` overlay still lets the browser
  scroll the document to "reveal" the button. Open the confirm dialog 450px down a list and the list
  behind it jumped to row 1 before you had decided anything. Every modal focus call — open, close,
  and the focus trap — now passes `preventScroll: true`.
- **The overflow lock.** Every dialog sets `body { overflow: hidden }`, which collapses the scrollable
  overflow and clamps the offset to zero; restoring it on close left you at the top.
  `REMEDI.lockScroll()` captures the offset, locks, and **returns the unlock**, so a caller cannot
  restore the overflow and forget the position.
- **List swaps and reloads**, below.

Two more mechanisms, both in `layouts/app.blade.php`:

- **`REMEDI.reloadKeepingPlace()`** — every `js-confirm` action without an explicit `data-on-success`
  falls back to a full reload: Mark Returned, Add Batch, delete a category. On a 645-row Inventory
  list that meant acting on row 40 and coming back at row 1, with the row you had just touched off
  screen. The offset is stashed in `sessionStorage` keyed by URL, consumed once, and expires after
  10s so it can never resurrect a stale position on a later visit. **It retries across frames rather
  than restoring once:** measured, a single attempt two frames in lands while the document is still
  at viewport height, the offset clamps to 0, and the whole courtesy silently does nothing. It stops
  as soon as the offset holds, after ~20 frames, or the moment the reader scrolls — never fight
  someone for the scrollbar.
- **`REMEDI.holdScroll()`** — capture before the swap, restore after, one implementation for all six
  AJAX lists. Only Inventory and Audit had anything at all; Products, Sales, POS and Forecast simply
  dropped you. The offset must be read *before* the skeleton goes in and written to the *same*
  element: resolve the scroller after the rows are gone and the page may no longer overflow, so
  `REMEDI.scroller()` answers with a different element and the write lands on something that does not
  scroll. If the new list is genuinely shorter the browser clamps, and that is correct — there is no
  row 40 to return to.

### `window.alert()` is gone — `REMEDI.showMessage()` instead

The POS stock refusals ("out of stock", "cannot exceed available stock") were browser alerts: they
look like a browser error rather than the app talking, they block the tab until dismissed, and they
can only ever repeat the number the page was rendered with — which at a busy counter is exactly the
number that may have just changed.

`REMEDI.showMessage({ title, body, icon, tone, detail })` renders the same card the confirm dialog
uses and **returns a handle** (`setDetail`, `close`). The POS opens it immediately with what the page
knows, then confirms against `GET /pos/lookup?sku=` and replaces the detail line with the live
figure — "None on hand right now." — also correcting the grid badge and the cart's cap so the next
tap does not quote the stale number again. `setDetail` is a no-op once the dialog is closed, so a
late response cannot rewrite or reopen a dismissed dialog.

`sku` is optional: the grid passes it, the barcode path passes the code it scanned, and without one
the modal simply shows no live line rather than blocking on a lookup it cannot make.

### Confirmations are a centred modal, not `window.confirm()`

**Every destructive or state-changing action goes through one shared dialog.** `#confirmModal` plus
the `form.js-confirm` handler in `layouts/app.blade.php` covers deleting a product, batch, category
or user, activating/deactivating a user, and marking a batch returned. There is no `window.confirm()`
left in the app. A new one costs a class and a few attributes, not another copy of the markup:

| attribute | effect |
|---|---|
| `data-confirm-title` / `-body` / `-label` | dialog copy; the body names the row so the question is specific |
| `data-confirm-icon` | Tabler class, default `ti-alert-triangle` |
| `data-confirm-tone="neutral"` | blue disc + primary button instead of destructive red — reversible actions must not be dressed as deletions |
| `data-on-success` | `remove-row` (drop the row, reload if the page empties), `toggle` (patch the row in place), `reload`, `none` |

Controllers answer through `Controller::actionOk()` / `actionFailed()` — JSON for the dialog's
`fetch`, the original redirect for a plain post, `422` for a refusal. The forms stay real POST forms
and the handler only intercepts `submit`, so with JavaScript off every one still works, unconfirmed.

Two traps this hit, both worth keeping in mind:

- **Do not write the Blade method-spoofing directive's name inside a JS comment.** Blade compiles
  `@`-directives even inside `<script>`, and the layout died with `Undefined constant "method_field"`.
- **Activate/deactivate patches the row via `[data-user-status]`, not a colour class.** An admin's
  ROLE badge is `.badge-success` too and sits earlier in the row, so matching on colour relabelled
  the role "Active" — verified against an admin-shaped row, where the colour selector returns
  "Staff" and the attribute selector returns "Active".

Log out uses `#logoutModal` (`.remedi-modal` in `layouts/app.blade.php`): a fixed, flex-centred
dialog with `role="dialog"`, `aria-modal`, focus moved to the confirm button, Escape and
backdrop-click to cancel, a two-control focus trap, and focus restored to the trigger. It sits
**outside** `.main-content` so the backdrop covers the sidebar too — a dialog you can click behind is
not one.

Confirming POSTs over `fetch` with the form's own CSRF token and follows the redirect
(`res.url`), rather than letting the form navigate. The `<form>` stays a real POST form and the JS
only intercepts `submit`, so **without JS it still logs you out** — unconfirmed, but not a dead
button. A failed fetch falls back to `form.submit()` rather than stranding the user on a disabled
dialog.

The browser dialog it replaced put the question in the top-left chrome, nowhere near the footer
button that raised it.

### Inventory: SKU is its own column, and the selected filter glows

`inventory/_rows.blade.php` shows **Product ID | SKU | Product Name | …**. SKU used to be a
parenthetical after the name; it is the barcode staff read off a box and the key every forecast joins
on, so it needs to be scannable down a column. `.col-sku` gives it tabular figures in a monospace
face — in the proportional body face the digits do not line up row to row, which is exactly when a
transposed pair slips past. Adding the column moved the empty-state `colspan`, which is
role-dependent (9 admin / 8 staff).

The filter tabs are AJAX, so clicking one swaps the whole table without a page load; the solid fill
alone was a quiet signal for that. `.filter-btn.active` now carries a colour-matched halo and a
one-shot `filter-glow-pop`. Each tab sets `--glow` as an **rgb triplet** (`--glow: 220 38 38`) so one
rule can do `rgb(var(--glow) / .28)` for every tab — keep new tabs' `--glow` equal to their fill, or
the halo and the badge it filters for stop agreeing. The animation is dropped under
`prefers-reduced-motion`: the fill already says which tab is selected, so the pulse is decoration.

### Buttons and back links
`.btn` variants live in the layout: primary/success/danger/info/warning are solid, `.btn-secondary` is outlined
(solid grey competed with the primary action next to it). Action buttons lift 2px on hover with a
colour-matched glow and settle on `:active`; `.btn-sm` is the toolbar size.

Row actions are colour-coded by meaning: **info** (blue) = view/edit/manage, **warning** (amber) =
reversible restriction such as deactivating a user, **danger** (red) = destructive, **success**
(green) = confirm. Don't leave a row action on plain `.btn-secondary` — it reads as inert.

Sidebar navigation shows the page skeleton, but links dressed as buttons are excluded
(`.btn`, `.btn-back`, `[data-no-skeleton]`): a button is an action, not a page change, so it
shouldn't blank the content out.

**Back sits BESIDE the page title, never on a row of its own.** The pattern is
`<div class="page-head"><a class="btn-back">…</a><div class="page-head-text"><h3>…<p>…</div></div>`,
with `.page-head-actions` for anything that belongs on the right (the Analytics month picker and
Print button live there). All nine pages that have a back control use it: products create/edit,
categories, register, users edit, sales show, forecast show, and the three reports.

This reverses an earlier decision. Back used to be `<div class="page-back">` on its own row above
everything, adopted because three different treatments existed (a grey slab, a 34px icon square, a
plain text link) in varying positions. The appearance is still unified — `.btn-back` is untouched —
but the *placement* is now inline: the three report pages had always done it that way, so the app
showed two placements depending on where you landed. Pages that had no heading at all (create,
categories, register) gained one so there is something to sit beside; `forecast/show` and
`sales/show` had their titles lifted out of the card for the same reason.

`.page-back` still exists as a bare margin rule so nothing breaks, but new pages use `.page-head`.

### No zero-stock products
`Product::MIN_STOCK_FLOOR` / `openingStockFloor()` guarantee every catalogued product has sellable
stock. The supplier master export ships `Stock = 0` for anything out on export day; seeding that
verbatim left 86 of 2,637 products unsellable at the till and skewed the inventory report.
`ProductBatchSeeder` raises empty rows to the floor, and migration `2026_08_17_000004` fixed the
already-seeded data. A zero-quantity batch on a product that still has stock elsewhere is a normal
depleted lot and is left alone.

### Seed data lives in CSVs, not factories

`DatabaseSeeder` creates the two default users inline, then hands off to five CSV importers. The
files are real supplier/POS exports and are read by path, so a missing one is a silent-ish failure:
each seeder prints `File not found: ...` and returns, leaving that table empty while the rest of the
seed "succeeds".

| Seeder | Reads |
|---|---|
| `CategorySeeder`, `ProductSeeder`, `ProductBatchSeeder` (opening stock) | `database/data/inventory_seeder.csv` |
| `ProductBatchSeeder::seedTransactionHistory` | `database/data/transaction_history_seed.csv` |
| `InventoryReceiptSeeder` | `Transaction_Records_Seed.csv` (repo **root**, via `base_path()`) |
| `SalesHistorySeeder` | `database/data/Sales_Records_4Year.csv` (~49 MB, 470k sale lines) |

`transaction_history_seed.csv` is **not present** in this checkout, so that pass always skips — the
purchase-history batches (zero-quantity rows carrying `qty_received`/`unit_cost`/`dr_no`) never get
created. That is the current state, not a bug to chase; those rows are deliberately zero-quantity so
they contribute DR/cost history without double-counting opening stock.

### Dashboard proportions

The two charts (Total Sales per Month, Seasonal Trends) sit **side by side** in `.dash-charts`, each
in a 230px `.chart-box`. They used to be stacked full-width with a `height` attribute on the canvas,
which Chart.js overrides — so with 48 months of history the monthly chart alone filled the viewport
and everything below it fell off the fold. Both now set `maintainAspectRatio: false`, the rule every
`.chart-box` needs.

**Seasonal Trends is a LINE chart, one series per year** (`$seasonalByYear`, pivoted in the Blade
from `$monthlySales` — no extra query). Newest year solid and darkest, older years lighter and
dashed; `spanGaps: true` so a part-finished year's line stops rather than diving to zero in the
months it has no data for. It used to be a single bar series of all-time monthly averages, which
flattened away the year-on-year comparison the panel exists for.

**The Low Demand chart forces headroom on its axis** (`suggestedMax`). Slow movers are routinely all
the same tiny number — five products that sold 1 unit each — and Chart.js scales the axis to the data
max, so every bar rendered pinned at 100% and the panel read as five best-sellers. The axis is now at
least `max(2, ceil(max × 1.4))`, which puts a value of 1 at half width. `recentDemand()`'s `$lowN`
also went 3 → 5 so the two demand panels are the same length; a three-row list beside a five-row one
reads as though the data ran out.

Every canvas on this page now sets `maintainAspectRatio: false` and sits in a `.chart-box`. A
`height` attribute on a `responsive: true` canvas is ignored — that is what made the demand charts
render 292px tall inside a 210px box.

The year filter **defaults to the most recent year, not "All years"**: 48 bars crammed edge to edge
is not a readable card, and this panel exists to answer "how did this year go". "All years" is still
in the dropdown.

Measured at 1600px after the fix: page height 3344px → **2107px**, KPI row six across in one row.
The old `.dash-eyebrow` / `.dash-title` / `.dash-timestamp` block was removed — it duplicated the
greeting bar directly beneath it, so the page carried two headings and two clocks.

### Inventory tab layout

Two bands, both on the admin Inventory tab (staff mirrors the first — see the staff section below):

`.inv-top` puts Inventory Overview beside a stacked `.inv-side` (Expiry Overview, then Lowest Stock),
collapsing to one column under 1200px. The rings carry a caption in the hole via the local
`centreText` plugin — registered per chart, not globally, so only the rings that ask for one get one.

**The wide column belongs to the card with the most text, and it is not the side stack.** `.inv-top`
was `0.85fr 1.15fr`, which handed Inventory Overview — a 230px ring *and* a ten-row legend — the
narrow half. The legend resolved to 135px (128px on staff): category names rendered **15px wide**,
one letter per line, with "409,578 units in stock" overflowing the row. It is now
`minmax(0, 1.15fr) minmax(0, 0.85fr)`, and the legend measures ~318px against the ~300px its longest
row needs.

**Inside that card, the ring yields before the legend does.** `250px minmax(0, 1fr)` meant the ring
always took its 250px and the legend absorbed every shortfall; `minmax(170px, 230px) minmax(230px,
1fr)` shrinks the doughnut instead, which degrades gracefully where text does not. Both copies
collapse to one column under **900px** — the query keys off the viewport while the card sits ~385px
inside it, so at a 768px tablet the two floors together (424px) exceeded the 383px available.

**Stock Status was deleted, not fixed.** It charted the same four `$expiryOverview` buckets, with the
same counts and the same percentages, as the Expiry Overview panel directly above it — and its ring
never rendered on any screen: in a ~266px card whose legend track had a 200px floor, the chart track
computed to **0px**, so every install was showing an empty 250px box beside a duplicate legend.
Removing it loses no figure, drops `.inv-pair` / `.stock-status-*` / the `stockStatusChart` init from
both dashboards, and gives Lowest Stock the full column its product labels need.

`.inv-bottom` is the band below it: the two Expiring Soon lists with `.inv-returns-col` (Medicine
Returns + Other Product Returns, stacked) as a narrower third column. It was two separate 2-up rows,
which pushed returns below the fold and left each row half-balanced. Four things make it hold:

- **Every track is `minmax(0, …)`, never a bare `1fr`.** An `fr` track's implicit minimum is
  min-content, and these cards carry long batch numbers — as `1fr 1fr 0.85fr` the tracks resolved to
  **430/536/536px** instead of the ratio asked for.
- **The returns panels are summaries** (doughnut + legend + a link out), not lists — see "Dashboard
  panels scroll, they don't truncate". Their ring sits **above** the legend, not beside it: side by
  side asked for 150px ring + 140px legend floor + 14px gap = 304px inside a 263px column, and the
  legend ran under the card edge.
- **The row stretches; the scrollers absorb the difference.** `align-items: stretch`, the cards are
  flex columns, and `.expiry-scroll` is `flex: 1 1 0` with a 320px floor. The fixed 456px cap it
  replaces had to be re-guessed every time a neighbouring panel changed height — and it was already
  stale, since stacking the returns rings took that column from 548px to 706px. **`flex-basis` must
  stay `0`:** at `auto` the list's own 926px of content counts toward the card's natural height, the
  row grows to fit all of it, and the band's height cap silently disappears (measured: 1041px).
- **Headers reserve two lines** (`.demand-head { min-height: 42px }`). "Expiring Soon · Medicine /
  Pharmaceutical" needs 296px and wraps in the ~265px it gets; "Expiring Soon · Other Categories"
  does not. Unequal headers started the two lists 15px apart, which reads as one card sagging.

Below 1400px the returns column spans the full row as a horizontal pair; below 900px everything
stacks.

**One expiring row, two columns of text, one badge.** The row used to carry the product name and
batch on the left, "Expires: Aug 24, 2026" as its own no-wrap column, and the severity badge. The
date and badge are rigid, so every pixel the row was short came out of the name: **40px of a 302px
row**, against 127px for a date the badge already summarises. `.expiry-info` is now `flex: 1` and the
date has moved onto the batch sub-line — date first, because that line ellipsises and a truncated
batch number is a smaller loss than a missing date. The name measures ~177px.

`.chart-box > canvas { max-width: 100% }` guards the whole app: Chart.js writes a pixel width onto
the canvas when it renders, and if the container narrows afterwards — a column re-proportioned, a
tab shown after the chart was built while hidden — that inline width outlives the change until the
next resize observation and the canvas hangs out of its card.

**Quick Actions and Alerts are not part of either band** — they stay in `.dash-actions-row`
above the tab switcher.

### Inventory by Category charts PRODUCTS, not units

One category holds ~83% of every unit in the building (Medicine), so a ring of stock quantities
rendered as a single solid arc with slivers — it carried no information at all. It charts **product
counts** instead, which split 53 / 22 / 6 / 4 / 1 % and are what the panel is actually asking about.
The unit totals stay in the legend beside each row, and the tooltip gives both. If you switch it back
to units, the ring goes blank again.

High Demand uses the **same gradient green as Total Sales per Month** (`C.blue`, the brand emerald)
rather than its own `C.green` — two "this is doing well" charts in two different greens read as two
unrelated scales. Low Demand stays amber as the deliberate contrast.

Related colour choices on that tab: the Lowest Stock bars are **amber against a grey reorder rule**,
not red — those rows are low, not expired, and solid red read as an alarm on every one of them. The
two returns rings use lighter tints of the legend hues with a white arc gap; the deep maroon "Fail to
Return" slice previously swamped both charts.

### The two dashboard tabs own their own panels

`admin/dashboard.blade.php` splits into three includes:

- `_dashboard-sales-lower` — Recent Sales Transactions + Sales Summary. Lives **inside** the Sales
  section. It used to sit outside the switcher, so selecting "Inventory" still left a table of POS
  receipts and a revenue ring on screen.
- `_dashboard-actions` — Quick Actions + Alerts, deliberately **outside** both sections: neither is
  about sales or stock specifically, and burying them behind a tab only makes them harder to reach.
- the Inventory section itself, which now contains nothing sales-derived.

Both tabs use the same `var(--brand)` fill when active; they are mutually exclusive, so a second
shade only made the pair look inconsistent.

**Fixes applied to the admin dashboard do NOT reach staff.** The two views duplicate their charts and
styles rather than sharing them, so every one of these had to be made twice — the staff copies were
still on the old behaviour a round later:

- category ring charting **units** (one solid arc) instead of products
- the palette starting on indigo, so a category was a different colour per account
- Lowest Stock bars in crimson rather than amber
- no centre caption in the ring
- `.staff-body` stretching a short card to its neighbour's height, leaving dead space

When you change a dashboard chart, check whether the other dashboard has its own copy.

**Pair a short card with a tall one and you get a visible void.** This bit three panels in a row, so
the fixes are all the same shape: Quick Actions and Alerts are now **stacked full-width rows** rather
than two columns (Quick Actions is inherently one bar, Alerts inherently a list), Alerts lays out on
`repeat(auto-fit, minmax(260px, 1fr))` so five of them run across instead of down, and the staff
Expiring Soon card wraps **one** scroller around both its sections — two stacked 320px boxes made it
795px tall beside a 337px neighbour. Cap the height, never the row count: all 31 rows are still
there.

**Quick Actions sits directly under the KPI row on BOTH dashboards**, above the Sales/Inventory
switcher on admin. It used to be at the foot of a ~2,000px page — a long scroll to reach the two
panels that say what needs doing. The page footer stays last, outside the partial.

There is **no global search in the topbar**. One was added and removed: it duplicated the search each
list page already has, and those are the ones wired to the live-filter pattern.

**Quick Actions is a ROW on staff, a 2x2 block on admin.** The shared `.quick-actions` rule copied
across is a two-column grid, and being declared later in the staff `<style>` it silently overrode the
row layout that file already had — the staff bar rendered as the admin's block. The staff rules are
scoped to `.quick-card .quick-actions` so they win without touching admin. Watch for this whenever a
class is copied between the two dashboards: they share names but not stylesheets.

Staff also carries its own Expiry Overview and Alerts panels, from the same `$expiryOverview` /
counts the admin tab uses — the person on the floor is the one who acts on them.

`staff/dashboard.blade.php` mirrors the admin design — same `.dash-greeting` bar, `.dash-datepill`,
`.quick-action` tiles and `.dash-footer`. Those rules are **duplicated** in the staff `<style>` block
rather than shared: each dashboard carries its own, and staff does not load the admin view. Keep the
two in step when either changes. Its quick actions are limited to routes a staff account can reach
(no Add Product, no Reports).

### My Profile

Migration `2026_08_19_000002` added the columns this screen shows: `users.phone`, `department`,
`preferred_language`, `last_login_at`, and `audit_trails.ip_address`. They were placeholders before,
which meant the page displayed a phone number and department belonging to nobody — now they are real,
editable by the account holder, and a blank field says "Not set" rather than inventing one.

`last_login_at` is stamped in `AuthenticatedSessionController::store` with `saveQuietly()` — it is
bookkeeping, not a profile edit, so it must not fire model events. `AuditTrail::log()` stamps
`request()?->ip()`, null for anything the scheduler or an artisan command writes (no request, no
address). **`ip_address` had to be added to `AuditTrail::$fillable`**: `create()` silently drops
unrecognised keys, so the column filled with NULLs while `log()` looked perfectly correct.

**Staff may edit only their name and phone number.** Email, department and preferred language are
admin decisions. The inputs are locked in the view *and* the keys are absent from
`ProfileUpdateRequest::rules()` for a non-admin — a disabled input is a hint, not a control, and
anyone can post the field anyway. Verified: a staff request carrying `email`, `department` and
`preferred_language` validates down to `name, phone`.

**Role is rendered locked, and `ProfileUpdateRequest` does not accept it.** Both matter: this form
belongs to the account holder, so accepting a role here would let any account promote itself. Role
changes go through User Management, which is admin-only.

`profile/edit.blade.php` is a three-part layout: identity card, Personal Information form, then
Change Password / Account Activity / Delete Account.

### Every dashboard figure opens the page behind it

A number on a dashboard is a question, and the answer is always a page this app already has. Both
dashboards' figures are links, and each one lands **filtered to exactly what it counted**:

| Figure | Goes to |
|---|---|
| Sales Today / Today's Sales | `sales.index?start_date=<today>&end_date=<today>` |
| Last 7 Days (admin) | `sales.index` over `today-6 … today`, the same window the controller sums |
| Products (staff) | `inventory.index` — **not** `products.index`, which is `role:admin` |
| Low stock / expiring / expired / need-to-return tiles | the matching Inventory filter |
| Inventory-by-category legend row | `inventory.index?category_id=<id>` |
| Expiring Soon row | `inventory.index?search=<sku>#product-row-<id>` — the bell's deep link |
| Returns legend row (all five) | `returned` / `need_to_return` / `fail_to_return` / `expired` filters |

Three rules this follows, all of which have bitten:

- **Match the table, not the topic.** Sales Today and Last 7 Days are `sales` (POS) figures, so they
  open the Sales list, never `/reports/sales` — that report reads `sales_history` and would answer a
  POS number with a different table's total.
- **Never link staff at an admin route.** A tile that 403s is worse than one that does nothing; the
  staff Products tile goes to Inventory for exactly this reason.
- **A "View All" can only lead to one place.** That is why the returns legend entries are links
  themselves: one card's header link cannot serve three different filters. Same reasoning sent the
  High/Low Demand panels to `reports.analytics` — the page holding `$topProducts` / `$slowMoving` —
  rather than the reports hub they used to point at.

The Expiry Overview legend stays unlinked on purpose: two of its four buckets (31-60 days, >60 days)
have no Inventory filter behind them, and linking half a legend is worse than linking none of it.

Row-as-link markup: the anchor takes over the `li`'s flex layout (`.expiry-list li > .expiry-row`,
`.returns-legend a.item` — both in `layouts/app.blade.php`, shared by the two dashboards). Wrapping
only the name leaves most of the row dead, and the badge on the right is what people aim at. The
category legend's `id` comes from `DashboardController`'s `$categoryBreakdown`, read off the
already-eager-loaded category, so it still costs no query. Its product count is products **with
stock**, while the filtered page lists the category's whole catalogue — currently 1,392 vs 1,393 in
Medicine, one product whose batches are all depleted.

### Dashboard panels and what they may claim

The admin dashboard carries a greeting bar, the KPI row, the Sales/Inventory tab sections, and a
lower strip (`admin/_dashboard-lower.blade.php`): Recent Sales Transactions, Quick Actions,
Alerts & Notifications, Sales Summary. The lower strip sits **outside** the tab switcher on purpose
— it is the "what do I do now" band and stays visible on either tab.

Three places where the design asked for data this app does not record. Each shows the truth instead
of a plausible-looking placeholder; if you add the underlying data, wire it here:

- **KPI deltas** (`<x-kpi-delta>`) render **only when a real baseline exists** — today vs yesterday
  and this week vs last week, both from POS timestamps. The stock counters (low stock, expiring,
  expired, returns) are never snapshotted, so there is no yesterday to compare against and the
  component renders nothing rather than a made-up "↑4.2%". The component returning empty is the
  designed behaviour, not a bug.
- **Recent Sales Transactions** has no Payment Method column and shows every buyer as "Walk-in
  Customer", because `sales` stores neither. Adding them means a migration plus capturing the value
  at checkout. It takes **15** rows, not 6: the panel scrolls inside a capped `.list-scroll` box with
  a sticky header, so extra rows cost no page height — and the cap is what makes it the same height
  as Sales Summary beside it, which was a 540px table against a 241px card.
- **Alerts & Notifications** (the dashboard card) carries no "10m ago" ages — nothing records when a
  condition first appeared.

**The topbar bell is a different thing from that card, and names items.** `AlertService::payload()`
returns two lists: `alerts`, the five kind-level rollups the dashboard card also shows, and `items`,
individual notifications — "NESTOGEN CLASSIC 135G · Expires Aug 21, 2026 · 1 day left", "ARM SLING
LARGE · 0 PCS left · reorder at 5". Three points to keep:

- **`PER_KIND = 3`, a fixed slice per kind, not one urgency-sorted list.** Low stock outnumbers
  everything else by an order of magnitude (645 vs 31), so a global sort buries the two expired
  batches that need pulling off the shelf today under a wall of low stock.
- **The badge counts the listed notifications** (currently 12), not the row totals. It used to count
  alert *kinds*; both readings exist for the same reason, which is that 645 is not a number of things
  to read. A badge that can only ever say "9+" tells you nothing either, so the per-kind totals live
  on the panel's footer links instead ("View all 645 low stock").
- Item bodies are built server-side and rendered with `textContent`, never `innerHTML` — they carry
  product names straight from the catalogue onto every authenticated page.

`SalesHistory::quarterlyRevenue()` feeds the Sales Summary ring off `monthlyRevenue()` (so it
inherits that cache). Its count is labelled **"Sales Records"**, not transactions: each
`sales_history` row is one product sold on one date, not a basket.

### Browser-tab identity

`APP_NAME` shipped as `Laravel`, so every tab read **"Laravel - Dashboard"**. The layout's
`config('app.name', 'REMEDI')` fallback never fired — the key was set, just set wrong — which is why
this survived a full re-theme. It is `REMEDI` in `.env` and `.env.example` now; run
`php artisan config:clear` if the config cache is warm.

The brand assets and `<meta name="theme-color">` are declared in **both**
`layouts/app.blade.php` and `layouts/guest.blade.php`. The guest one matters: the login screen is the
first page anyone sees, and it does not share the authenticated layout, so patching only
`app.blade.php` leaves the front door showing a blank globe.

**The icons had never actually loaded before, and the cause was not the markup.** `config/app.php`
shipped `'asset_url' => env('ASSET_URL', '/')`. A non-null `asset_url` is used verbatim as the root
of every `asset()` call, so `asset('favicon.svg')` returned `/favicon.svg` — an absolute path from
the **host** root. This app is served from a subdirectory (`/remedi.2/remedi/public`), so that
resolved to `http://localhost/favicon.svg` and 404'd. It is `env('ASSET_URL')` now, which is the
framework default and makes `asset()` build from the incoming request; that is correct both under a
subdirectory and at a domain root. Set `ASSET_URL` in `.env` only if assets move to a CDN.

Assets, all in `public/` and all generated from the supplied artwork:

| file | used by |
|---|---|
| `logo.png` | sidebar brand mark (transparent, 512x477) |
| `favicon.ico` | browser tab — 16/32/48/64/128/256 in one file |
| `icon-192.png`, `icon-512.png` | web manifest, Android install |
| `apple-touch-icon.png` | iOS home screen — **opaque**, iOS composites transparency onto black |
| `manifest.json` | install metadata |

Two things to keep in mind if you regenerate them:
- **The source art is not square** (1026x956 after trimming) and carries a baked-in glow halo in its
  alpha. Trim at `alpha >= 80` to drop the halo — at `>= 1` the bounding box is 400px wider and the
  glow shows as green haze on a light background. The sidebar `<img>` needs `object-fit: contain`
  for the same reason; a square box without it stretches the capsule.
- **`manifest.json`, not `site.webmanifest`.** Apache under XAMPP has no MIME type registered for
  `.webmanifest` and served it with none at all, which is enough for Chrome to reject the manifest
  and drop installability. It already knows `.json`.

### Theme: one palette, defined once

**There WERE two skeletons. The login one is gone; do not put it back.**

`layouts/guest.blade.php` used to carry a full-screen `.auth-skeleton`, shown by adding
`is-authenticating` to `<body>` on submit. It existed because the guest layout has no `.content-body`
(so the app's navigation skeleton never applied there) and because signing in was the slowest
transition in the app: the browser sat on the login page for the entire dashboard render.

That premise is gone. `/dashboard` now answers a plain GET with a shell in **~0.3s** and fetches its
own body, so the sequence had become: grey mock of the dashboard for half a second, then the real
branded loading screen, then the dashboard. Two waiting states back to back with the throwaway one
first — the skeleton was standing in front of the thing it was imitating. Removing it took ~230 lines
out of the guest layout and cut the login page from 19KB to 7.4KB.

What survives on the login form is the part that was actually load-bearing, and it is worth keeping:
the submit button disables itself and relabels to "Signing you in…", so the click registers and the
credentials cannot be posted twice while the redirect is in flight. Disable **after** the browser has
serialised the form — inside the submit handler it can drop the submitter from the POST body. The
handler is still guarded on `form.checkValidity()` and `e.defaultPrevented` so a blocked or cancelled
submit does not leave a dead disabled button, and `pageshow`/`persisted` releases it after a bfcache
restore.

**The dashboard's own loading screen is now the highlight** (`dashboard/_loading.blade.php`, styled
in `dashboard/index.blade.php`): a floating card over a dimmed page, with the dashboard's skeleton
shimmering behind the scrim. The scrim (`rgba(15,23,42,.45)` + a 2px backdrop blur) and the card's
`0 24px 60px -20px` shadow are lifted from `.remedi-modal` rather than invented, so it reads as the
same kind of object as the logout and confirm dialogs; `z-index: 190` keeps it under a real dialog's
200. It can afford to be branded rather than a grey placeholder because the shell — sidebar, topbar,
bell — is already on screen behind it, so the wait is scoped to one panel instead of the whole
window.

**The card only appears when you have just signed in.** `AuthenticatedSessionController::store`
flashes `remedi.just_signed_in`; the shell reads it into `$justSignedIn` and puts `is-first-load` on
`#dashboardRoot`. Flash data survives exactly one request, which is exactly as long as "this is an
arrival" stays true — refresh a second later and you correctly get the quiet version. A revisit shows
the skeleton and the topbar's "Loading your dashboard…" pill and nothing else: someone already
working in the app does not need the app introduced to them. The escalating 6s/15s messages are gated
on the same class, so a revisit does not leave timers rewriting text nobody can see.

The gate is two CSS rules (`is-first-load` / `is-failed`) against a `.dash-loader { display: none; }`
default, and **`display` must not appear in `.dash-loader`'s own block** — it sits later in the sheet
and silently overrides both gates. That is not hypothetical: it shipped that way for one round and
the card showed on every visit while the class was correctly absent from the markup. A failure still
raises the card on either path, so the dead end is never lost.

The logo inside the ring is drawn at **76px** in a 150px ring (62px in the 122px mobile ring), with
matching `width`/`height` attributes so the ring cannot reflow when the image lands. It was 58px and
read as an indistinct smudge — the source is a 512×477 mark, so it has the detail to carry the larger
size, it was simply too small to resolve. `alt=""` is deliberate: the heading beside it already says
what is happening.

**It is inlined as a base64 data URI, not `asset('logo.png')`, and that is load-bearing.**
`php artisan serve` is single-threaded: one request at a time. While the dashboard body is being
built the dev server cannot serve anything else, static files included. Measured 2026-08-25 —
`logo.png` (a 278KB static file) took **2.83s** and completed at the exact moment the 3.9s dashboard
request did, i.e. just as the loader was being destroyed. The ring spun empty for the whole wait.

It looked correct on `localhost` purely because the browser had the file cached from the sidebar. On
`127.0.0.1` — a **different origin with its own empty HTTP cache**, the same two-hosts distinction
that bit the cached route URLs — there was no cache to fall back on and the logo never appeared at
all. That is the bug as the user actually hit it.

The rule: **a loading screen must not depend on a request to the server it is waiting for.**
`public/logo-mark.webp` is a 160px derivative (7.5KB, ~10KB inlined, ~2x the 76px render for hi-dpi)
regenerated by **`php artisan logo:mark`** — it is a DERIVED file, so replacing `logo.png` without
rerunning that leaves the loader showing the old artwork with nothing to say why. The sidebar's
linked copy is still blocked during the wait and is deliberately left alone: it is chrome, not the
thing being waited on, and inlining it would put 10KB on every page in the app to fix a grey square
that only appears on one.

It is **not** a real dialog: no `role="dialog"`, no `aria-modal`, no focus trap. Nothing in it needs
answering — it is a status that happens to be centred — and trapping focus would strand a keyboard
user for ten seconds. That choice is what keeps the failure panel's buttons reachable by Tab.

The skeleton behind it is **`partials/_page-skeleton.blade.php`, the same file the layout uses** for
in-app navigation, extracted so the two can never drift. It is `display:none` by default; the layout
opts it in with `.content-body.is-navigating > .page-skeleton`, the dashboard with
`#dashboardRoot > .page-skeleton`. Both copies are in the DOM at once on this page — the layout's
stays hidden — and the dashboard's disappears for free when `root.innerHTML` is replaced. Measured on this machine 2026-08-25: shell 0.28–0.32s warm
(1.68s cold, rebuilding the alert cache), body 5.1–5.3s warm and 10–12s cold.

Three things in it are deliberate:

- **The ring is indeterminate.** The server reports no progress, so a filling bar would be a
  fabrication. A fixed arc that spins says "working" without claiming to know how far along it is.
- **The message escalates at 6s and 15s.** A frozen one-liner spends a ten-second wait looking
  stalled. 6s is past the warm case, so reaching it genuinely means something slower is happening.
- **It owns its own dead end.** A ten-second request that dies must not leave a ring turning forever,
  so there is a failure panel with the reason, a Try again that re-runs the fetch, and a link to
  `?full=1`. `401`/`419` skip all that and go straight to the login page — `EnsureUserIsActive`
  answers AJAX with 401 rather than a redirect, so a session deactivated mid-wait lands there.

The remaining skeleton — `.page-skeleton` / `.is-navigating` in `layouts/app.blade.php`, for
in-app navigation — mirrors the dashboard: dark teal sidebar (`#0c3b33`, matching `--nav-bg`), a
greeting bar with a date pill, **six** 118px KPI tiles on `.kpi-grid`'s auto-fit track, tab pills,
and two side-by-side 280px charts. When the dashboard's proportions change, change them here too — a
skeleton that no longer matches is worse than none, because the page visibly jumps when it is
replaced. (This one still earns its keep: it covers a full document swap between pages, where there
is no shell already on screen to hold the frame.)

The loading skeleton is **neutral grey on purpose**. A brand-tinted version was tried and read as a
coloured panel in its own right rather than a placeholder — a skeleton should recede, not compete
with the content it stands in for. Its shape mirrors the dashboard (greeting bar, six KPI tiles, two
side-by-side charts) so the real page lands in roughly the same places instead of jumping.

The **sidebar is a dark slab** (`--nav-bg` #0c3b33 and friends), separate tokens from `--ink` so the
nav can be retinted without touching body text. It was briefly white; the reference designs put a
dark rail beside the light content area, and the active item is a solid `--brand` pill on it.

The loading skeleton (`.sk-block`) shimmers in `--brand-soft` → `--brand-tint`, not the old slate
grey. It is the first thing shown after login and on every navigation, so a cold grey read as a
different, unstyled app for the second before the real page arrived.

`layouts/app.blade.php` opens with a `:root` block (`--brand`, `--brand-dark`, `--brand-darker`,
`--brand-tint`, `--brand-soft`, plus `--ink`, `--ink-soft`, `--line`, `--surface`). The app was
indigo on a dark slate sidebar and is now **emerald on white**; every button, focus ring, active nav
pill and pagination state refers to those variables, so a re-theme is a change to that block rather
than a hunt through ~40 hardcoded hex codes. Don't reintroduce raw brand hexes in page-level CSS.

The sidebar is white with a `--line` right border; the active item is a `--brand-tint` pill with
`--brand-darker` text and a `--brand` icon. KPI cards lead with a **42px circular icon chip** whose
disc is `color-mix(in srgb, var(--kpi-accent) 14%, #fff)` and whose glyph is the accent itself — one
variable per card drives both, with an `@supports not (color-mix…)` fallback so the chip never
renders as a transparent hole on older engines.

### Frontend
Blade + Alpine.js. **No view references `@vite`** — `layouts/app.blade.php` carries a large inline
`<style>` block, and Chart.js and Tabler icons come from CDNs. The Tailwind/PostCSS/Vite toolchain in
`package.json` is inherited from the Breeze scaffold and is currently inert. Add styles to the
existing inline block or a plain stylesheet; adding a Tailwind class will do nothing until Vite is
actually wired in.

**A Tabler class that isn't in the build renders as an empty box, silently.** The CDN is pinned to
`@tabler/icons-webfont@latest`, which does not carry every name in Tabler's catalogue: `ti-boxes`
was blank on the staff dashboard's "View Inventory" tile for exactly this reason, while `ti-box`
next to it worked. There is no error and the `<i>` still takes up space, so it reads as a design
gap rather than a missing glyph. To check a name before shipping it, read the pseudo-element:
`getComputedStyle(el, '::before').content` returns `none` for a class the build does not define and
a PUA character (e.g. `U+EA45`) for one it does.

**Navigation skeleton:** every page is a full server render, so between a sidebar click and the new
document the browser paints nothing — on the heavier pages that reads as a dead click. A delegated
handler in `layouts/app.blade.php` marks the clicked link `.is-loading` (highlight only — a spinner
on the nav item was tried and removed by request) and puts `.is-navigating` on `.content-body`,
which hides every child except `.page-skeleton`. `showNavigating()` reads `offsetHeight` to force a
paint, since the browser may otherwise skip repainting the outgoing document.

**A second click supersedes the first.** Click one nav item, then another before the first page
arrives, and the browser lands you on the second — a later navigation wins. `showNavigating()` clears
any existing `.is-loading` before marking the new item, so only the most recent click reads as
loading; it used to just add, leaving two items lit up with no way to tell which page was coming. It
also captures the outgoing `.active` set **only on the first click** of a navigation (guarded by
`navigating`): the first call already stripped those classes, so re-reading on the second click
stored an empty list and `clearNavigating()` had nothing to restore — which stranded the sidebar with
no highlight at all after a double click, and on every bfcache restore after one.

It also retitles the topbar `<h2>` to the destination's label and strips `.active` off the outgoing
tab — otherwise the whole chrome keeps insisting you're still on the page you just left (click
Dashboard from POS and the header still read "Point of Sale", with two tabs highlighted).
`clearNavigating()` restores the title and the `.active` classes, which matters on bfcache restore:
without it, hitting Back leaves the header naming the page you never reached. The incoming document
tears it all down; `pageshow`/`persisted` triggers the restore. The handler deliberately
ignores modified/middle clicks, `target=_blank`, `download`, `#`/`mailto:`, cross-origin hrefs, and
same-URL clicks — if you touch it, keep those guards.

**AJAX partial pattern:** list controllers (POS, inventory, products, sales, forecast) check
`$request->wantsJson() || $request->ajax()` and return
`['html' => view('x._rows', ...)->render(), 'pagination' => (string) $paginator->links()]`. The
`_rows.blade.php` / `_grid.blade.php` partials are the shared row markup between the full page render
and the AJAX refresh — edit the partial, not a copy inside `index.blade.php`.

`InventoryController` fetches all matching products, filters status in PHP (low stock / expiring /
expired / return states are computed accessors, not columns), then paginates manually with
`LengthAwarePaginator`. Category and text search *are* pushed down to SQL.

### The skeleton waits 180ms, and has two silhouettes

Moved here from CLAUDE.md, which had grown a second copy of the whole frontend.

**`SKELETON_DELAY_MS` (180ms) is what stops tab clicks looking like a glitch.** Every page except
`/dashboard` answers in a fraction of a second locally, so the old immediate swap replaced the
content with a placeholder and put it back inside that window: a flash of grey blocks, the page
visibly collapsing and springing back. A fast page now paints no skeleton at all — you click and the
next page is simply there — while a slow one is unchanged, which is the case the skeleton exists for.
`clearNavigating()` cancels the pending timer, so a navigation that is abandoned (a click that only
ended a text selection, a cancelled download, a bfcache restore) never paints either. Measured with
the page scrolled to 600px: nothing paints at 0ms or 120ms, the skeleton appears at 270ms, and the
scroll offset never moves at any point.

**Two silhouettes, chosen from the destination link.** One generic shape was the cause of every tab
"glitching" on click: the skeleton was dashboard-shaped, so opening Sales replaced a table with six
KPI tiles and two charts, held that for the length of the navigation, then landed as a header over
rows — two unrelated layouts in a row, on every tab except the dashboard. `data-shape="list"` (a page
head, a toolbar, filter pills and nine table rows) covers Inventory, POS, Products, Sales, Users, the
audit trail and notifications; no attribute means the dashboard shape, which is also what
`/reports/*` and `dashboard/_loading`'s backdrop want. POS is really a product grid rather than a
table, so it gets the closest of the two rather than an exact match.

**A control that navigates without being an `<a>` must raise the loading state itself.** The skeleton
and header pill come from a `document` click handler that only matches `<a>`, so the Inventory nav
toggle — a `<button>` — navigated with no loading state at all while its submenu was mid-animation,
which reads as a glitch. `window.REMEDI.showNavigating` is exposed for exactly this, and is looked up
at click time rather than bind time because the nav-group script runs before the navigation script
defines it.

### The sidebar logo is inlined, like the loader's

Same reasoning as `logo-mark.webp` above, a different file. The sidebar's was `logo.png` — **279 KB
drawn at 34x34** — and every navigation here is a full page load, so it was re-requested on every
click and visibly popped in after the sidebar had painted (worst over a tunnel, or while
`artisan serve` was busy with something else). It is now a 68px derivative inlined as a data URI:
`logo-nav.webp`, 2.7 KB, ~3.6 KB base64, from
`php artisan logo:mark --width=68 --out=logo-nav.webp`.

**Regenerate it whenever `logo.png` changes**, the same rule the loader's derivative carries. If it
is missing the layout falls back to the linked PNG rather than rendering nothing.

Note this reverses, for this one file, the "don't inline the sidebar copy" note in the loader section
above — that argument was about paying 10KB on every page to fix a grey square on one page. 3.6 KB to
stop the logo popping in on *every* navigation is a different trade.

### `.page-hero`, and why it is drawn in CSS

`.page-hero` is the tinted band behind a page title — a rounded panel with a soft mint wash, a
rolling shape and a capsule motif. Purely decorative and deliberately so: nothing moves and nothing
hides behind it. It exists because the all-white version of these pages read as a stack of grey
boxes.

**Drawn entirely in CSS** (layered radial gradients plus two pseudo-elements), not an image:
`artisan serve` is single-threaded, so a decorative request queues in front of the page it decorates
— the same reasoning that makes the dashboard loader inline its logo. Both motifs drop out under
620px/820px, where there is no room for them. It is opt-in per page: wrap `.page-head` in it.

### `.section-head` is a filled band, not an icon and a line of text

The card headers on Edit Product (Product Details, Add New Batch, Existing Batches) carried a 46px
chip beside the title. The chip is gone and the title itself does the work — small caps on a
`--brand-soft` fill that runs edge to edge, with the card's own top corners and a `--line` divider
under it. `.section-head .form-chip` is removed with it.

**It gets there with negative margins that cancel `.form-card`'s padding** (`-26px -28px`, and
`-20px -18px` under 760px where the card's padding narrows), which is why a `.section-head` only
belongs at the TOP of a card — anywhere else the pull would drag it over the content above. The
radius is `17px`, not the card's `18px`: measured from inside the card's 1px border, or the fill
leaves a hairline of white in each corner.

The per-FIELD chips stay. They are what lets you find "Selling Price" without reading every label,
which a card header cannot do.

### One form vocabulary across the four form pages

`.form-card`, `.form-grid`, `.form-field`, `.form-chip`, `.form-actions` and `.btn-lg`, defined once
in `layouts/app.blade.php`. Add Product, Edit Product, Add User and Edit User all use it, so they
read as the same kind of page. Each field is introduced by an icon chip — not decoration: these are
two columns of similar-looking inputs, and the icon is what lets you find one without reading every
label.

**Edit Product uses that vocabulary now; it used to use `.field-aside`**, which put the chip in its
own column beside the whole field (`display: flex; align-items: center`) — so the icon read as an
ornament on the INPUT rather than a mark on the label, and the two product pages were two different
forms. `.field-aside` / `.field-aside-body` / `.aside-grid-2` / `.aside-grid-3` are gone from the
layout; nothing else rendered them. Product Details is the standard two-column grid, Add New Batch
adds `.form-grid.cols-3` (three across, two under 1100px, one under 760px). The button says **Update
Product**, not "Save Product": this page edits an existing row.

**The older `.field-row` (labels above, no chips) is still there** and still used where a form is a
single wide strip. Do not merge the two — they solve different shapes.

**`.btn-lg` is for PAGE-level actions only** — "Add Product", "Add User", "Manage Categories" — so the
button matches the "Save Product" it leads to. Buttons inside table rows deliberately stay compact:
at that size they break the table's rhythm and push the columns out.

**Password fields carry a reveal toggle** (`.pw-wrap` + `.pw-toggle`), handled by one delegated
listener in the layout rather than per-page script, so it also survives a form re-rendered over AJAX.
An admin filling in someone else's password has no browser-saved value to fall back on, and a
mismatch you cannot see is the whole reason confirm fields exist.

### The form chips are neutral, and the `chip-*` tints are gone

They used to come in six colours (green name, purple SKU, blue category, teal batch, amber money, red
alerts), which made a form of six ordinary fields look like six different kinds of thing — the colour
was decoration carrying no rule, and it competed with the colour that does carry rules. A chip is now
slate on `#f1f5f9`, sitting in the same tone as the label beside it, the way the sidebar's icons
belong to their labels.

**Colour is kept only where it means something**: the reports, the inventory status badges, and the
alert legend shared by the bell, the toasts and the notifications page. If you find yourself wanting a
coloured chip on a form, that is a question about what rule it would be expressing.

Edit User reuses `.user-avatar` from the topbar rather than a second circle style — same object, same
initials.

### Buttons are solid mid-tones, and a variant must be declared after `.btn`

**Gradients were tried and removed.** A gradient made every button look like a call to action, and
the base `--brand` (#10b981) alone reads too light against white for a control pressed all day. The
variants sit one step down (`#059669` primary, `#3b82f6` info, `#dc2626` danger) — saturated enough
to be obviously clickable, dark enough to hold white text at small sizes, without going near-black.
`:hover` goes one step deeper, still solid. Outlined variants (`.btn-secondary`,
`.btn-danger-outline`, `.btn-back`) stay flat white — they are the quiet half of a pair.

**A `.btn-*` colour variant must be declared AFTER `.btn`.** `.btn` sets
`border: 1px solid transparent`, and the variants are the same specificity — so a variant declared
earlier in the sheet silently loses its border. `.btn-danger-outline` was written above `.btn` and
rendered with no outline at all; it now sits with `.btn-secondary` and the rest. Put new variants
there.

**Row actions carry an icon, not a bare word or a literal "+".** The users list already paired
`ti-pencil` with Edit; the products list, the inventory list and the categories page did not, and
`+ Add Product` used a text plus rather than `ti-plus`. All of them now match: `ti-plus` /
`ti-user-plus` to add, `ti-pencil` to edit or manage, `ti-device-floppy` to save, `ti-trash` to
delete — the same vocabulary the confirm dialogs already use in their `data-confirm-icon`.

### Add Category is a dialog, not a field in the page

The card holds the trigger; the form lives in a `.remedi-modal` beside it, the same two-step as the
profile page's Change Password, with the same guards (Escape, backdrop, focus trap,
`REMEDI.lockScroll`). It carries no `js-confirm` — the dialog IS the deliberate step, and a confirm
modal opening over a form modal is two dialogs deep for one category name.

Two things make it work rather than merely open:

- **The post validates into its own error bag** (`validateWithBag('addCategory', ...)`), because the
  rename forms in the table below validate a field called `name` too and the default bag cannot tell
  them apart.
- **The dialog reopens itself when that bag is non-empty**, or a rejected name would reload the page
  with the dialog shut and the reason out of sight.

`<noscript>` drops it out of the overlay so the form still posts with JavaScript off.

**Manage Categories keeps its rename, even though the design has no Save column.** The category name
is still an `<input>`, drawn as plain text until focused, and its Save button is revealed only once
the value actually changes — a Save on every row invites clicks that rewrite a name to itself, which
is a write, an audit entry and a cache clear for nothing. `CategoryController::update` still refuses
to rename a `RULE_DRIVING_NAMES` category, so the real guard is server-side either way.

**`Category::ICONS` maps a category to its glyph**, keyed by name because the name is already what
carries meaning here (see "What counts as medicine"). Anything unlisted falls back to `ti-category`
rather than rendering an empty box — categories are user-creatable, so an unknown name is normal, not
a bug.

### `Product::UNITS` closed the unit field

Unit was free text. That is how the catalogue acquired a product whose unit is the string `"20"`, and
how the Add Product form came to default to lowercase `pcs` while all 2,637 other rows say `PCS` —
every product added through that form would have started a second spelling of the same unit,
splitting anything that groups by it.

Both forms are now `<select>`s rendering `Product::unitOptions()`, and both controller paths validate
with `Rule::in`. **The update path passes `unitOptions($product->unit)`**, which folds in a value that
is not on the list — without it, the one legacy `"20"` row would be rejected on any edit, for a field
nobody touched.

### Add User's email field, and what was removed from it

The field is plain, and the example lives in the placeholder (`e.g. jane@remedi.com`). Nothing is
appended to what was typed: a version that completed a bare name into a house domain, with a fixed
`@remedi.com` tag glued to the field, was built and then removed — a field that silently completes
what you typed has to explain itself, and the explanation was the first thing to go.
`User::EMAIL_DOMAIN` and the `.input-suffix` input-group styles went with it; don't reintroduce
either without a reason.

**What survives from that round is the one line that fixed a real dead end**:
`RegisteredUserController::store` trims and lower-cases the address before validating. The
`lowercase` rule REJECTS a capitalised address rather than folding it, so `Emman@remedi.com` was
refused — and since this is a `js-confirm` form, the refusal surfaced as "That action could not be
completed." with no mention of the capital letter. Folding also happens before the `unique` check, so
case cannot slip a duplicate past it. Covered by `Feature\Auth\RegistrationEmailTest`.

**Two lessons from that build worth keeping.** `@{{ }}` is Blade's ESCAPE syntax — it prints the
braces literally and eats the `@`, which is exactly what `@{{ User::EMAIL_DOMAIN }}` did; build the
whole string in one expression (`{{ '@'.$x }}`). And the test that should have caught it asserted the
domain appeared ANYWHERE in the page, which passed on the bell's audit rows: **scope an assertion to
the element, or the bell will pass it for you.**

### Money keeps its centavos; only counts are formatted bare

`number_format($x)` with no precision rounds to whole units, which is right for units, products and
batches and wrong for pesos. The Sales Forecast revenue KPI carried the bare form and reported a
figure up to 50 centavos away from the number it was summing; its chart tooltip did the same with
`Math.round`. Both now keep the centavos, while the units KPI and the units chart stay whole on
purpose — demand is integral. If you add a peso figure anywhere, pass the `, 2`.

### Every view that extends `layouts.app` must set `@section('title')`

The layout falls back to `@yield('title', 'Dashboard')`, so a view without one silently renders
"Dashboard" in the topbar heading AND the browser tab while you are looking at something else. The
audit trail was the only page in the app missing it, and read "Dashboard" for its whole life. There is
no error and nothing looks broken — which is why it survived.

### The audit trail's pager, and row numbers

**The audit trail uses the shared pager, centred from 768px up.** It drew its own — chevron squares
and a blue `#185FA5` current page, shared with nothing — so the one list that is mostly page numbers
looked like a different application. It now renders `$logs->links()` (which resolves to
`vendor/pagination/custom` via `AppServiceProvider`) inside `.audit-pager`, whose media query centres
the row: that page is a single full-width table, so a left-aligned pager sits under the row-number
column with the screen empty beside it. The other lists stay left-aligned — their pagers sit under
narrower content. The page's AJAX handler delegates on any `<a href>` inside the wrapper, so
pagination still happens in place with the filters intact.

**Row numbers use `$paginator->firstItem() + $loop->index`**, so page 2 starts at 11 rather than
restarting at 1. Present on inventory, products and the sales list. Adding a column means bumping the
empty-state `colspan` in the same partial, or the "nothing found" row stops spanning the table.

### Each Inventory filter is ordered by the thing it is about

In PHP, because every sort key is a computed accessor rather than a column: `low_stock` by
`sellable_stock` ascending (tie-broken on `total_stock`, which is the column the table actually
shows), `expiring` by the earliest still-sellable batch, `expired` by the longest-expired batch.

Sort on `sellable_stock`, never `total_stock` — a product with 300 expired units is not better
stocked than one with 2 good ones.

### Don't hide the substance of a page behind a disclosure

`products/edit` used to keep its batch table inside a collapsed `<details>` — which hid the only place
stock, expiry and the return actions actually live, since none of those are columns on `products`.
Long tables scroll inside `.table-scroll`; that is the answer to page height, not a collapse.

### The notification feed was 62% report views

**Symptom:** "why in notification system the addition of batches were not displayed". Adding a batch
never appeared in the bell's Updates tab. Neither did a sale.

**It was not a classification bug.** `activity()` always mapped a batch correctly — `Created` →
"Record added". Those rows were never SELECTED. Measured on this install, the newest 18 audit rows
that `activity()` reads:

```
 1-11. [Viewed ] Generated ... Report      ← eleven in a row
   12. [Created] Processed sale TXN-20260903-00006 - ₱111.96
   13. [Viewed ] Generated Inventory Report (filtered)
   14. [Login  ] Staff logged in
15-18. [Viewed ] Generated ... Report
```

and what the bell showed, after `take(6)`:

```
1. New report generated   2. New report generated   3. New report generated
4. New report generated   5. New report generated   6. New report generated
```

`Viewed` is written on every report open, including the same report twice while a filter is
adjusted — **877 of 1,426 rows, 62% of the trail**. The method takes the newest few with no notion of
importance, so real changes could not reach it.

The method's own comment already said *"anything else is noise for a notification panel and is dropped
below"*. Nothing was dropped: the `default` arm caught everything, and the only filter was a literal
`'Profile Test'`. The fix makes it do what it said, in SQL rather than after the fetch, so the window
is the newest N CHANGES rather than the survivors of one mostly full of reads.

After: batch additions, checkouts, account changes and sign-ins, in the order they happened.

**The audit trail itself is untouched and still records every view.** That is its job. This is a
notification panel, and generating a report is something the reader just did.

**Worth watching:** this ratio only grows. Every report open adds a row forever, and 62% will drift
upward. The trail will want a retention policy before it becomes slow — not urgent, but it is the
one table here with an unbounded write rate driven by ordinary reading.

### `$isAccount` looked for a string the app never writes

The same method sorts audit rows into System ("who got in and whose account changed") and Updates
("what happened to the data"). Its test was:

```php
str_contains($row->details, 'user account')
```

`UserController` writes three of the four account messages that way — "Added user account: X",
"Updated user account: X", "Deleted user account: X" — but status changes as:

```php
"Account {$status}: {$user->name}"     // "Account deactivated: Emman"
```

which contains no such substring. So deactivating and activating people, the thing that most often
happens to an account here, fell through to the generic "Record updated" and was filed under Updates,
away from the sign-ins and account changes it belongs beside.

Exactly the shape of the audit-filter bug in the same session: a dropdown offering `Create` while
every row is written `Created`. **When a predicate keys on a message, check it against every writer of
that message**, not against the two you happen to remember.

### Account changes notify, and how the toast stack learned about them

Adding, updating, deleting, activating and deactivating an account now reach the bell AND the
bottom-right pop-up. Four things had to be true, and three of them were not:

1. **Each event needed a NAME.** All of them read "Account updated", including deletions — the one
   account change you would most want a notification to state plainly. Now: New user added / User
   account updated / User account deleted / Account activated / Account deactivated.

2. **The toast stack watches KINDS**, and every audit row shared the generic `activity` kind.
   `AlertService::ACCOUNT_KIND` marks an account CHANGE. Sign-ins deliberately keep `activity`: a card
   every time anybody logs in makes the stack useless by lunchtime. Nothing filters the bell on
   `kind` — its tabs read `group` — so the split is free there.

3. **Live pops read only `items`.** `activity` is its own key on the polled payload, so an account
   change could be seeded into the greeting and still never pop live.

4. **Merging two newest-first lists appends rather than interleaves.** This one was found by watching
   the cards actually play. The seed merges `$topbarAlertItems` with `$topbarActivity`, and the
   account row landed at the END of the list however recent it was. `pickGreeting()` takes the first
   `MAX_VISIBLE`, so with three expired and two low-stock cards ahead of it the card was present in
   the seed and never once appeared:

   ```
   before:  [expired] [expired] [expired] [is-out] [is-low]          ← account row absent
   after:   [expired] [expired] [expired] [SYSTEM] [is-out]          ← sorted on sort_at
   ```

   Sort the merged collection on `sort_at`, the onset stamp every row on both sides carries. The
   bell's own feed already did this; the seed did not.

**The card is violet (#7c3aed), deliberately off the amber-to-red severity ramp** the stock kinds
share. Nothing is wrong with the shelf; someone changed who can get in. Same argument that keeps
out-of-stock graphite rather than a deeper red: a different KIND of thing, not another rung on a
ladder it does not belong on.

**ADMIN ONLY BY CONSTRUCTION.** These rows come from `activity()`, which the view composer and
`AlertController` already resolve to `[]` for staff, and the toast seed merges them in a per-request
render. They never enter `payload()`'s cache, which every signed-in user shares — putting a
role-dependent row in that key is how a staff account ends up seeing whatever an admin cached first.
`Feature\Alerts\ActivityFeedTest` asserts a staff toast stack carries none of them.

### The action pill is a span, and that is not laziness

Account notifications point at `/users` rather than the audit trail — the trail is the record of what
happened, `/users` is where you do something about it — and carry a "Manage users" pill.

**It is a `<span>` on both surfaces because it sits inside the row's own `<a>`.** Interactive content
may not nest inside an anchor: a `<button>` or a second `<a>` there is invalid HTML, and browsers
recover from it by SPLITTING the anchor, which breaks the row the pill was meant to decorate. The row
and the card already carry the action's href, so the pill is clickable in the only sense that
matters; it exists to name the destination.

**Rendered in three places that must agree** — the Blade row, the bell's JS `render()`, and the toast
card builder. The JS one is the easy miss: get it wrong and the pill is there on load and gone thirty
seconds later on the first poll. That is precisely how `data-when` failed before it, and it is the
reason those two renderers carry mirrored comments.

**The href stays RELATIVE.** `activity()` is cached under `topbar_activity`, and an absolute URL bakes
in whichever host warmed the cache — warm from `127.0.0.1`, read from `localhost`, and the session
cookie is not sent. A test walks the whole feed asserting every `href` starts with `/`.

### User Management, rebuilt

Search pill with the glyph inside, a Filter panel, four KPI cards, avatars with the role beneath the
name, sortable columns, and a "Showing 1 to 6 of 6 users" footer. Verified by rendering each state and
counting rows rather than by eye:

| Request | Rows | KPIs (total/active/inactive/admins) |
|---|---|---|
| *(none)* | 6 | 6/4/2/1 |
| `?role=staff` | 5 | 6/4/2/1 |
| `?status=inactive` | 2 (ids 20, 19) | 6/4/2/1 |
| `?search=mirae` | 1 | 6/4/2/1 |
| `?role=admin&status=inactive` | 0 | 6/4/2/1 |
| `?sort=joined&dir=desc` | 20,19,15,14,6,1 | 6/4/2/1 |

The KPIs holding steady under every filter is the point, not an accident — see the rule in CLAUDE.md.

Two things the rebuild exposed in the old page:

- **The search was an ungrouped `OR`.** Harmless while search was the only filter; the moment role and
  status sat beside it, an email match would return rows the filter had excluded. The
  `?role=admin&status=inactive` row above (0 results, the admin being active) is the case that would
  have leaked.
- **`?sort=` went straight into `orderBy()`**, which interpolates its column name into the SQL.
  `UserController::SORTABLE` is a whitelist now, with a stable tie-break on `id`.

### An action column aligns on its widest label

Measured before the fix, action buttons across six rows:

```
Edit   always 73px, left edge 1086   ← already aligned
Toggle "Deactivate" 112px / "Activate" 98px
Delete left edge 1283 on Deactivate rows, 1269 on Activate rows   ← a 14px step
```

The toggle is the only action whose label changes with the row, and the difference does not stay in
its own column — it drags everything after it. `.action-toggle` is sized to its widest label, in `em`
so a font-size change cannot silently reintroduce the step. After: Delete at 1284 on every row, one
distinct x-position per column.

### Overriding a shared primitive needs matching specificity

`.actions-cell` is defined in `layouts/app.blade.php` and used by four views (users, products ×2,
inventory). Setting `gap: 10px` from inside the users page did **nothing** — the layout's selector is
`.remedi-table .actions-cell`, two classes to one:

```
computedGap: 6px        ← the page's own rule, ignored
rules targeting .actions-cell:
  .remedi-table .actions-cell  gap 6px   (layout)
  .actions-cell                gap 10px  (page)
```

**Nothing errors when a rule loses on specificity. The page simply ignores you**, which is a long way
to look for a gap that will not move. Matched at `.remedi-table .actions-cell` and placed later in the
document, it wins for that page and the other three keep the value they were drawn with.

The row actions were softened at the same time — tinted fill, coloured border, coloured text — and
that lives in the LAYOUT, so all four tables read as one pattern rather than one redesigned page
beside three old ones. See CLAUDE.md for why that is not a reversal of "buttons are solid mid-tones".

## Cruft — removed

The scratch copies, dead experiments and stray files this section used to list have been **deleted**
(68 MB, 8,672 files). Each was checked against every live source file first, and only the ones with
no reference at all were removed:

| removed | was |
|---|---|
| `temp-laravel12-reference/` | a whole pristine Laravel 12 skeleton (8,641 files, 64 MB) kept for comparison |
| `database/seed/` | scratch copies of seeders/migrations, incl. a literal `ProductSeeder (1).php` |
| `resources/views/resources/` | stale duplicate of `resources/views/reports/`, no `view()` call anywhere |
| `python_forecast/`, `forecast_service/` | earlier standalone forecasting experiments |
| `app/Console/Commands/GenerateSalesForecast.py` | stray copy of `resources/python/generate_sales_forecast.py` |
| `*-snippet.php` (Http/Console `Kernel`, `routes/web`) | paste-buffer leftovers |
| `test_arima_metrics.py` | standalone experiment; needs pmdarima/sklearn, which are not installed |
| root `requirements.txt`, `3.1.0` | both **zero bytes** — the real deps are `resources/python/requirements.txt` |
| root `Inventory_Master_Seed.csv` (+`.bak`) | duplicate of `database/data/inventory_seeder.csv` |
| three stray images at the repo root | incl. the logo source, now built into `public/logo.png` and the icons |
| `Sales_Records_3Year.csv` (+`.xlsx`), `Sales_Records_5Year.csv` | 43 MB of earlier drafts of the sales history, referenced only by this document |
| `Product_Master (1).csv` (+`.bak`), `inventory_seeder.csv.bak` | leftover product-master exports and the classifier's rollback copy |

**Kept, despite looking like cruft:**
- `Transaction_Records_Seed.csv` at the root — `InventoryReceiptSeeder` reads it via `base_path()`.
  Deleting it silently empties `inventory_receipts`.

**This repo IS under version control now** (git, `main`, first commit 2026-08 — the paragraph here
used to say it was not, and that was written before `git init`). A committed file can be recovered;
an uncommitted or ignored one still cannot, and `.gitignore` covers `/vendor`, `/node_modules`,
`/storage/*.key` and `.env`. Check for references before removing anything — and note that a
bare-substring grep is not enough: `3.1.0` "matches"
`package.json` (it is Tailwind's version), `GenerateSalesForecast.py` "matches" the PHP command class
of the same name, and `database/seed` matches the word "seed" almost everywhere.

`.claude/launch.json` defines a `dev` preview target that runs `npm run dev` on port 5173. That
serves nothing usable here, because no view loads the Vite bundle (see "Frontend"); the app is
served by XAMPP from `public/`, so preview that URL instead.
