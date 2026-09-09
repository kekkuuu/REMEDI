<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sales list's date filter.
 *
 * Every failure this covers was silent: the page rendered a perfectly normal
 * table, so nothing told the user their filter had not been applied the way
 * they meant. ReportController and AuditTrailController were both hardened
 * against this class of bug; the sales list was missed.
 */
class SalesListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A sale on a given calendar day.
     *
     * `created_at` and `payment_method` are not in Sale::$fillable, so passing
     * them to create() drops them silently and every fixture sale lands on
     * today -- which makes a date-filter test pass or fail for reasons that
     * have nothing to do with the filter. forceFill + saveQuietly puts the
     * timestamp where the test needs it without firing model events.
     */
    private function sale(User $cashier, string $date): Sale
    {
        $sale = Sale::create([
            'user_id' => $cashier->id,
            'transaction_no' => 'TXN-'.str_replace('-', '', $date).'-'.str_pad((string) (Sale::count() + 1), 5, '0', STR_PAD_LEFT),
            'total_amount' => 100,
            'amount_paid' => 100,
            'change_due' => 0,
        ]);

        $sale->forceFill([
            'created_at' => $date.' 10:00:00',
            'updated_at' => $date.' 10:00:00',
        ])->saveQuietly();

        return $sale->refresh();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function rowsFor(User $user, array $query): int
    {
        $response = $this->actingAs($user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson('/sales?'.http_build_query($query));

        $response->assertOk();

        return substr_count($response->json('html'), '<tr>') - 1; // minus the header row
    }

    public function test_a_reversed_range_is_put_the_right_way_round_instead_of_returning_nothing(): void
    {
        $admin = $this->admin();
        $this->sale($admin, '2026-08-05');
        $this->sale($admin, '2026-08-10');

        // Picking the later date first is an easy slip with two date pickers
        // side by side. Unordered, `>= 2026-08-10 AND <= 2026-08-05` can never
        // match anything, and the page said "No transactions found."
        $reversed = $this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson('/sales?start_date=2026-08-10&end_date=2026-08-05');

        $reversed->assertOk();
        $this->assertSame(2, substr_count($reversed->json('html'), '<tr>') - 1);

        // And it reports back the range it actually queried, so the two inputs
        // can correct themselves rather than showing a period the table below
        // them does not match.
        $this->assertSame('2026-08-05', $reversed->json('range.start'));
        $this->assertSame('2026-08-10', $reversed->json('range.end'));
    }

    public function test_an_unparseable_date_is_refused_rather_than_silently_ignored(): void
    {
        $admin = $this->admin();
        $this->sale($admin, '2026-08-05');

        // MySQL cannot compare a date column against 'banana', so the predicate
        // was dropped and every row came back as though no filter were set.
        $this->actingAs($admin)
            ->get('/sales?start_date=banana')
            ->assertSessionHasErrors('start_date');

        $this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson('/sales?start_date=banana')
            ->assertStatus(422);
    }

    public function test_an_impossible_date_is_refused_rather_than_matching_nothing(): void
    {
        $admin = $this->admin();
        $this->sale($admin, '2026-08-05');

        // This one matched nothing and rendered the ordinary empty state, which
        // reads as "there were no sales" rather than "that is not a date".
        $this->actingAs($admin)
            ->get('/sales?end_date=2026-13-45')
            ->assertSessionHasErrors('end_date');
    }

    public function test_a_valid_range_still_filters(): void
    {
        $admin = $this->admin();
        $this->sale($admin, '2026-08-01');
        $this->sale($admin, '2026-08-15');
        $this->sale($admin, '2026-08-30');

        // No filter defaults to TODAY (see the next test) -- none of these
        // fixture sales are dated today, so ?all=1 is what reaches all three.
        $this->assertSame(3, $this->rowsFor($admin, ['all' => 1]));
        $this->assertSame(1, $this->rowsFor($admin, ['start_date' => '2026-08-10', 'end_date' => '2026-08-20']));
        $this->assertSame(2, $this->rowsFor($admin, ['start_date' => '2026-08-10']));
    }

    public function test_the_list_defaults_to_today_and_all_shows_everything(): void
    {
        $admin = $this->admin();
        $this->sale($admin, '2026-08-01');
        $this->sale($admin, now()->toDateString());

        // Landing on the whole history is not a useful first view, so no
        // filter at all means today only -- not "no filter applied".
        $this->assertSame(1, $this->rowsFor($admin, []));

        // The explicit way past that default.
        $this->assertSame(2, $this->rowsFor($admin, ['all' => 1]));
    }

    public function test_the_range_is_normalised_to_the_format_the_date_inputs_can_display(): void
    {
        $admin = $this->admin();
        $this->sale($admin, '2026-08-15');

        // `nullable|date` accepts far more than the date inputs emit, and
        // comparing those as plain strings orders them alphabetically rather
        // than chronologically.
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson('/sales?'.http_build_query([
                'start_date' => 'August 1, 2026',
                'end_date' => 'August 25, 2026',
            ]));

        $response->assertOk();
        $this->assertSame('2026-08-01', $response->json('range.start'));
        $this->assertSame('2026-08-25', $response->json('range.end'));
        $this->assertSame(1, substr_count($response->json('html'), '<tr>') - 1);
    }
}
