<?php

namespace Tests\Feature\Auth;

use App\Mail\NewAccountCredentialsMail;
use App\Models\User;
use App\Rules\PhilippineMobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The phone field on Add User, Edit User and My Profile: Philippine mobile numbers
 * only, however they are typed. It used to take any text up to 40 characters.
 */
class PhilippinePhoneTest extends TestCase
{
    use RefreshDatabase;

    public static function goodNumbers(): array
    {
        return [
            'plain' => ['09171234567'],
            'dashes' => ['0917-123-4567'],
            'spaces' => ['0917 123 4567'],
            'brackets' => ['(0917) 123 4567'],
            'plus 63' => ['+639171234567'],
            'plus 63 with spaces' => ['+63 917 123 4567'],
            '63 no plus' => ['639171234567'],
            'no prefix' => ['9171234567'],
        ];
    }

    public static function badNumbers(): array
    {
        return [
            'words' => ['hello'],
            'letters in a number' => ['0917abc4567'],
            'too short' => ['0917123456'],
            'too long' => ['091712345678'],
            'landline' => ['0281234567'],
            'not a mobile prefix' => ['08171234567'],
            'another country' => ['+14155552671'],
            'plus with a leading zero' => ['+09171234567'],
            'only punctuation' => ['---'],
            'a line break after it' => ["09171234567\nx"],
        ];
    }

    #[DataProvider('goodNumbers')]
    public function test_a_philippine_mobile_is_accepted_however_it_is_typed(string $number): void
    {
        $this->assertTrue(PhilippineMobile::matches($number));
    }

    #[DataProvider('badNumbers')]
    public function test_anything_else_is_refused(string $number): void
    {
        $this->assertFalse(PhilippineMobile::matches($number));
    }

    private function addUser(string|array $phone)
    {
        Mail::fake();

        return $this->actingAs(User::factory()->admin()->create())->post('/register', [
            'name' => 'Jane Cruz',
            'email' => 'jane@remedi.com',
            'role' => 'staff',
            'phone' => $phone,
            'personal_email' => 'jane@example.test',
        ]);
    }

    public function test_add_user_refuses_a_bad_number_and_creates_nothing(): void
    {
        $this->addUser('hello')->assertSessionHasErrors(['phone' => PhilippineMobile::MESSAGE]);

        $this->assertNull(User::where('email', 'jane@remedi.com')->first());
        Mail::assertNotSent(NewAccountCredentialsMail::class);
    }

    public function test_add_user_keeps_a_good_number_as_typed(): void
    {
        $this->addUser('+63 917 123 4567')->assertSessionHasNoErrors();

        $this->assertSame('+63 917 123 4567', User::where('email', 'jane@remedi.com')->value('phone'));
    }

    public function test_edit_user_refuses_a_bad_number_and_may_still_leave_it_blank(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['phone' => '09171234567']);
        $payload = ['name' => $staff->name, 'email' => $staff->email, 'role' => 'staff'];

        $this->actingAs($admin)->put(route('users.update', $staff), $payload + ['phone' => 'hello'])
            ->assertSessionHasErrors('phone');
        $this->assertSame('09171234567', $staff->fresh()->phone);

        $this->actingAs($admin)->put(route('users.update', $staff), $payload + ['phone' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($staff->fresh()->phone);
    }

    public function test_my_profile_refuses_a_bad_number_and_may_still_leave_it_blank(): void
    {
        $user = User::factory()->create(['phone' => '09171234567']);
        $payload = ['name' => $user->name, 'personal_email' => 'me@example.test'];

        $this->actingAs($user)->patch('/profile', $payload + ['phone' => 'hello'])
            ->assertSessionHasErrors('phone');
        $this->assertSame('09171234567', $user->fresh()->phone);

        $this->actingAs($user)->patch('/profile', $payload + ['phone' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->phone);
    }

    public function test_an_array_is_refused_rather_than_crashing(): void
    {
        $this->addUser(['09171234567'])->assertSessionHasErrors('phone');
    }

    public function test_the_forms_ask_for_a_phone_keypad(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/register')
            ->assertSee('type="tel"', false);
    }
}
