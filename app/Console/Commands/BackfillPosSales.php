<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesHistory;
use App\Models\User;
use App\Services\AlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fill the gap between the imported record and today with real POS sales.
 *
 * `sales_history` stops the day before this terminal went live (2026-08-16),
 * which is the correct shape for the two records -- but the till itself had
 * only 73 sales in the eighteen days since, because nobody has been ringing
 * anything up on a demo install. Every report that spans the handoff therefore
 * shows a cliff: the dashboard's August bar reads PHP 206k against July's
 * PHP 377k, and September reads PHP 11k, not because trade stopped but because
 * the recording did.
 *
 * This writes the missing days as ordinary sales: FEFO deduction against real
 * batches, per-day transaction numbers, line items pointing at the batch they
 * came out of. It is a BACKFILL of demo data, not a fixture -- the stock it
 * sells is really deducted, which is the point. Run it once; re-running tops
 * each day up to the target rather than doubling it.
 *
 *   php artisan pos:backfill --from=2026-08-16 --per-day=54
 *   php artisan pos:backfill --dry-run
 *
 * It fills up to YESTERDAY. Today belongs to whoever is on the till; generated
 * sales under "Total sales today" are the one place this data would be read as
 * a claim about the present rather than as history.
 *
 * It deliberately writes NO audit entries. The trail is a record of what people
 * did, and nobody did this; 900 "Processed sale" rows would bury the entries
 * that describe actual use.
 *
 * A SALE CANNOT BE ATTRIBUTED TO AN ACCOUNT THAT DID NOT EXIST YET. The first
 * version picked a cashier at random from every active user, resolved once
 * before the day loop, so an account created on 2026-09-02 was credited with
 * sales going back to 2026-08-16 -- 611 of 874 rows on this install. It is
 * visible on the sales list, on /sales/{id} and on every reprinted receipt,
 * which all print `$sale->user->name`, and it is the kind of wrong that a
 * reader spots immediately: the cashier column names someone who was hired
 * yesterday above a transaction from a fortnight ago. Attribution is now made
 * per sale against `users.created_at`, and `--fix-attribution` repairs rows
 * already written that way.
 */
class BackfillPosSales extends Command
{
    protected $signature = 'pos:backfill
                            {--from= : First day to fill (default: the day after the imported record ends)}
                            {--to= : Last day to fill (default: YESTERDAY, since today belongs to the till)}
                            {--per-day=54 : Target transactions per day, before the weekday/payday shape}
                            {--seed=20260902 : RNG seed, so a re-run is reproducible}
                            {--fix-attribution : Re-point sales credited to an account created after them, and write nothing else}
                            {--dry-run : Report what would be written and change nothing}';

    protected $description = 'Backfill POS sales for the days between the imported record and today';

    /** Sun..Sat, matching the shape the imported record was generated with. */
    private const WEEKDAY = [1 => 1.00, 2 => 0.95, 3 => 0.95, 4 => 1.00, 5 => 1.10, 6 => 1.25, 0 => 0.70];

    public function handle(): int
    {
        if ($this->option('fix-attribution')) {
            return $this->fixAttribution((bool) $this->option('dry-run'));
        }

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : Carbon::parse(SalesHistory::max('sale_date'))->addDay();

        // YESTERDAY, not today.
        //
        // Today is the day someone is actually standing at the till, and the
        // dashboard's "Total sales today" is the one figure on the screen that
        // has to be theirs. The first run filled through today and put PHP
        // 9,541.69 of generated sales under that heading, over the PHP 400.78
        // the shop had really taken. Backfill stops where the live day starts.
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::yesterday();

        if ($from->gt($to)) {
            $this->error("Nothing to do: {$from->toDateString()} is after {$to->toDateString()}.");

            return self::FAILURE;
        }

        $perDay = max(1, (int) $this->option('per-day'));
        $dry = (bool) $this->option('dry-run');
        mt_srand((int) $this->option('seed'));

        // The roster is id => WHEN THE ACCOUNT WAS CREATED, not a flat list of
        // ids, because eligibility is a property of the moment a sale happened.
        // ringUp() narrows it per sale; see cashiersAsOf().
        $roster = $this->roster();

        if (! $roster) {
            $this->error('No active users to attribute sales to.');

            return self::FAILURE;
        }

        // Fail here rather than silently dropping the first stretch of the
        // window one abandoned basket at a time.
        $earliest = min($roster);

        if ($earliest->gt($from->copy()->endOfDay())) {
            $this->error(sprintf(
                'The earliest account was created %s, after the whole window opens (%s). Nobody could have rung these up.',
                $earliest->toDateTimeString(), $from->toDateString()
            ));

            return self::FAILURE;
        }

        // What the shop actually sells, from the imported record's last 90 days:
        // the till should move the same lines the books do, not a flat sample of
        // a 2,638-row catalogue.
        $weights = SalesHistory::query()
            ->where('sale_date', '>=', Carbon::parse(SalesHistory::max('sale_date'))->subDays(90)->toDateString())
            ->selectRaw('product_sku, SUM(quantity_sold) AS qty')
            ->groupBy('product_sku')
            ->pluck('qty', 'product_sku')
            ->map(fn ($q) => (float) $q)
            ->filter(fn ($q) => $q > 0);

        $products = Product::whereIn('sku', $weights->keys())->get()->keyBy('sku');
        $weights = $weights->only($products->keys())->all();

        if (! $weights) {
            $this->error('No products with recent demand to sell.');

            return self::FAILURE;
        }

        $this->info(sprintf('Filling %s .. %s at ~%d/day from %d products.',
            $from->toDateString(), $to->toDateString(), $perDay, count($weights)));

        $madeSales = 0;
        $madeLines = 0;
        $revenue = 0.0;
        $short = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $target = (int) round(
                $perDay
                * (self::WEEKDAY[$day->dayOfWeek] ?? 1.0)
                * (in_array($day->day, [15, 16, 30, 31, 1], true) ? 1.35 : 1.0)
                * (0.85 + mt_rand(0, 300) / 1000)
            );

            $existing = Sale::whereDate('created_at', $day->toDateString())->count();
            $wanted = max(0, $target - $existing);

            if ($dry) {
                $this->line(sprintf('  %s  have %-3d target %-3d -> %d new', $day->toDateString(), $existing, $target, $wanted));
                $madeSales += $wanted;

                continue;
            }

            for ($i = 0; $i < $wanted; $i++) {
                $result = $this->ringUp($day, $roster, $weights, $products);

                if ($result === null) {
                    $short++;

                    continue;
                }

                $madeSales++;
                $madeLines += $result['lines'];
                $revenue += $result['total'];
            }
        }

        if ($dry) {
            $this->info("Dry run: {$madeSales} sales would be written.");

            return self::SUCCESS;
        }

        // decrement() fires no model events, exactly as PosController::checkout
        // does not -- so the alert payload has to be retired by hand, and the
        // POS-dependent aggregates with it.
        SalesHistory::bumpCacheVersion();
        AlertService::forget();

        $this->info(sprintf('Wrote %s sales / %s lines / PHP %s.%s',
            number_format($madeSales), number_format($madeLines), number_format($revenue, 2),
            $short ? " {$short} baskets abandoned: nothing sellable left." : ''));

        return self::SUCCESS;
    }

    /**
     * One basket: pick lines, walk FEFO, write the sale.
     *
     * Returns null when nothing could be sold -- a day late in the window can
     * run the shelf down, and an empty sale is not a sale -- or when no account
     * existed yet to ring it up.
     *
     * @param  array<int, Carbon>  $roster  user id => when the account was created
     */
    private function ringUp(Carbon $day, array $roster, array $weights, $products): ?array
    {
        // Trading hours, weighted toward late afternoon the way a counter is.
        $at = $day->copy()->setTime(8, 0)->addMinutes(mt_rand(0, 12 * 60))->addSeconds(mt_rand(0, 59));

        // Who was on the payroll AT THAT MOMENT. Narrowed per sale rather than
        // per day: an account created at 20:52 was not taking money at 09:00.
        $cashiers = $this->cashiersAsOf($roster, $at);

        if (! $cashiers) {
            return null;
        }

        $lineCount = $this->pick([1 => 45, 2 => 33, 3 => 16, 4 => 6]);
        $wanted = [];

        for ($i = 0; $i < $lineCount; $i++) {
            $sku = $this->weighted($weights);
            $qty = $this->pick([1 => 55, 2 => 25, 3 => 10, 4 => 5, 5 => 3, 10 => 2]);
            $wanted[$sku] = ($wanted[$sku] ?? 0) + $qty;   // per PRODUCT, like checkout
        }

        return DB::transaction(function () use ($at, $cashiers, $wanted, $products) {
            $lines = [];
            $total = 0.0;

            foreach ($wanted as $sku => $qty) {
                $product = $products[$sku];

                // Sellable AS OF THE SALE DATE: a batch that expires next week
                // was good the day it was sold, even if it has expired since.
                $batches = ProductBatch::where('product_id', $product->id)
                    ->where('quantity', '>', 0)
                    ->whereNull('returned_at')
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', $at->toDateString()))
                    ->orderBy('expiry_date')
                    ->lockForUpdate()
                    ->get();

                $available = (int) $batches->sum('quantity');

                if ($available <= 0) {
                    continue;
                }

                $remaining = min($qty, $available);

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, (int) $batch->quantity);
                    $batch->decrement('quantity', $take);
                    $remaining -= $take;

                    $subtotal = round((float) $product->selling_price * $take, 2);
                    $total = round($total + $subtotal, 2);

                    $lines[] = [
                        'product_id' => $product->id,
                        'product_batch_id' => $batch->id,
                        'quantity' => $take,
                        'price' => $product->selling_price,
                        'subtotal' => $subtotal,
                    ];
                }
            }

            if (! $lines || $total <= 0) {
                return null;
            }

            // What a customer hands over: the note above, or the exact amount.
            $paid = mt_rand(0, 100) < 25
                ? $total
                : (float) (ceil($total / 20) * 20 + (mt_rand(0, 100) < 30 ? 0 : 0));

            $sale = new Sale([
                'transaction_no' => $this->transactionNo($at),
                'user_id' => $cashiers[array_rand($cashiers)],
                'total_amount' => $total,
                'amount_paid' => $paid,
                'change_due' => round($paid - $total, 2),
                'payment_voided' => false,
            ]);

            // Backdated on purpose: these belong to the day they are filling in,
            // not to the moment the command ran.
            $sale->created_at = $at;
            $sale->updated_at = $at;
            $sale->save();

            foreach ($lines as $line) {
                SaleItem::create($line + ['sale_id' => $sale->id]);
            }

            return ['lines' => count($lines), 'total' => $total];
        });
    }

    /**
     * Every account that could be standing at the till, with the moment it
     * was created.
     *
     * `is_active` is the CURRENT state and says nothing about the past, but it
     * is the right filter anyway: a deactivated account is one this install has
     * retired, and crediting fresh demo sales to it would make the sales list
     * argue with the reason it was deactivated.
     *
     * @return array<int, Carbon>
     */
    private function roster(): array
    {
        return User::where('is_active', true)
            ->orderBy('created_at')
            ->pluck('created_at', 'id')
            ->map(fn ($createdAt) => Carbon::parse($createdAt))
            ->all();
    }

    /**
     * The subset of the roster that existed at a given moment.
     *
     * @param  array<int, Carbon>  $roster
     * @return array<int, int> user ids, re-indexed so array_rand() is uniform
     */
    private function cashiersAsOf(array $roster, Carbon $at): array
    {
        return array_values(array_keys(
            array_filter($roster, fn (Carbon $createdAt) => $createdAt->lte($at))
        ));
    }

    /**
     * Re-point sales credited to an account that did not exist yet.
     *
     * The predicate is `sales.created_at < users.created_at`, which can only
     * ever match rows this command wrote: a real checkout is attributed to
     * whoever is signed in, and they cannot be signed into an account that has
     * not been created. So this touches no genuine transaction, and there is
     * nothing to distinguish by hand.
     *
     * Only `user_id` moves. Totals, line items, stock and transaction numbers
     * are all correct already -- it is the name on the row that is impossible.
     * No audit entries, for the same reason the backfill writes none, and no
     * cache invalidation: every aggregate this app caches is keyed on product,
     * date or money, none of which changes here.
     */
    private function fixAttribution(bool $dry): int
    {
        $roster = $this->roster();

        if (! $roster) {
            $this->error('No active users to attribute sales to.');

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));

        $impossible = Sale::query()
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->whereColumn('sales.created_at', '<', 'users.created_at')
            ->orderBy('sales.created_at')
            ->get(['sales.id', 'sales.created_at', 'sales.user_id', 'users.name AS cashier']);

        if ($impossible->isEmpty()) {
            $this->info('Nothing to fix: every sale is credited to an account that already existed.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%s sales are credited to an account created after them.', number_format($impossible->count())));

        $moved = [];
        $stranded = 0;

        foreach ($impossible as $sale) {
            $at = Carbon::parse($sale->created_at);
            $cashiers = $this->cashiersAsOf($roster, $at);

            if (! $cashiers) {
                $stranded++;

                continue;
            }

            $to = $cashiers[array_rand($cashiers)];
            $moved[$to] = ($moved[$to] ?? 0) + 1;

            if (! $dry) {
                // Not save() and not touch(): created_at is the sale's own
                // backdated timestamp and must not be dragged to now.
                Sale::whereKey($sale->id)->update(['user_id' => $to]);
            }
        }

        $names = User::whereIn('id', array_keys($moved))->pluck('name', 'id');

        foreach ($moved as $id => $count) {
            $this->line(sprintf('  %s#%d %-18s <- %d sales', $dry ? 'would move ' : '', $id, $names[$id] ?? '?', $count));
        }

        if ($stranded) {
            $this->warn(sprintf(
                '%d sales predate EVERY account and were left alone. Nobody could have rung them up; delete them or move the backfill window.',
                $stranded
            ));
        }

        $this->info($dry
            ? 'Dry run: nothing was written.'
            : sprintf('Re-pointed %s sales.', number_format(array_sum($moved))));

        return self::SUCCESS;
    }

    /**
     * TXN-YYYYMMDD-NNNNN, counted within ITS OWN day.
     *
     * Sale::nextTransactionNo() counts within TODAY, which is the right rule at
     * a live till and the wrong one for a backfill: every row would land in
     * today's sequence. Same format, same per-day counting, different day.
     */
    private function transactionNo(Carbon $at): string
    {
        $prefix = 'TXN-'.$at->format('Ymd').'-';

        $last = Sale::where('transaction_no', 'like', $prefix.'%')
            ->orderByDesc('transaction_no')
            ->value('transaction_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /** @param  array<int|string, int>  $spread  value => relative frequency */
    private function pick(array $spread)
    {
        $roll = mt_rand(1, array_sum($spread));

        foreach ($spread as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_first($spread);
    }

    /** @param  array<string, float>  $weights */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(0, 1000000) / 1000000 * array_sum($weights);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }
}
