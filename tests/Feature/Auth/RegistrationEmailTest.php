<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Add User email field takes a whole address, and is forgiving about how
 * it was typed.
 *
 * The `lowercase` validation rule REJECTS a capitalised address rather than
 * folding it, so "Emman@remedi.com" was refused -- and because this is a
 * js-confirm form, the refusal came back through the confirm dialog, which used
 * to print "That action could not be completed." for every validation failure
 * and never mentioned the capital letter. Two separate faults, one dead end for
 * the admin: the fold below, and failureMessage() in layouts/app.
 *
 * Nothing is APPENDED to what was typed. An earlier version completed a bare
 * name into the house domain; the field is plain now, and the placeholder shows
 * the shape of an address instead.
 */
class RegistrationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Cruz',
            'email' => 'jane@remedi.com',
            'role' => 'staff',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_an_address_is_stored_as_typed(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/register', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'jane@remedi.com']);
    }

    /** The house domain is not special: any address works. */
    public function test_another_domain_works(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/register', $this->payload(['email' => 'jane@gmail.com']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'jane@gmail.com']);
    }

    /**
     * The reported failure: a capital letter is folded, not refused.
     *
     * A trailing space arrived the same way -- both came back as "That action
     * could not be completed." before the fold and the dialog fix.
     */
    public function test_capitals_and_stray_spaces_are_folded_not_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/register', $this->payload(['email' => '  Emman@Remedi.com  ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'emman@remedi.com']);
    }

    /** Folding happens before the unique check, so case cannot duplicate. */
    public function test_a_duplicate_in_another_case_is_still_refused(): void
    {
        User::factory()->create(['email' => 'taken@remedi.com']);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/register', $this->payload(['email' => 'TAKEN@remedi.com']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'taken@remedi.com')->count());
    }

    public function test_the_field_shows_an_example_address(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get('/register')->content();

        // Scoped to the field: every authenticated page renders the bell, whose
        // audit rows can carry an address, so an unscoped assertion would pass
        // on someone else's text.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="email"[^>]*placeholder="e\.g\. jane@remedi\.com"/',
            $html
        );
    }
}
