<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The shared hash of the password every factory user gets.
     *
     * Hashed at runtime, NOT hardcoded. The literal `$2y$10$...` that used to
     * sit in definition() was a cost-10 bcrypt hash, while phpunit.xml sets
     * BCRYPT_ROUNDS=4 to keep the suite fast. User casts `password` to
     * `hashed`, and that cast runs Hash::verifyConfiguration(), which rejects a
     * hash whose cost does not match the configured rounds -- so every test
     * that created a user died with "Could not verify the hashed value's
     * configuration." That was 22 of 25 tests, from a fixture, with nothing
     * wrong in the application at all.
     *
     * Memoized because bcrypt is deliberately slow and this would otherwise be
     * paid once per created user rather than once per process.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Always hashed with whatever driver and cost are configured for
            // the current environment. The plain text stays 'password', which
            // is what every test signs in with.
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            // `role` and `is_active` are this app's own columns, and the
            // factory has to set them even though the migration defaults both.
            //
            // create() does not read the row back, so a column left to its
            // database default is simply ABSENT from the model in memory --
            // and actingAs() authenticates that in-memory instance rather than
            // reloading it. EnsureUserIsActive then saw a falsy is_active and
            // ended the session, so every test of an authenticated route died
            // with "This account has been deactivated." The middleware was
            // right to fail closed; the fixture was handing it a user the
            // application cannot use.
            'role' => 'staff',
            'is_active' => true,
        ];
    }

    /**
     * An administrator.
     *
     * The role gate (`role:admin`) covers products, reports, forecasting, user
     * management and the audit trail -- most of the app -- and none of it can
     * be tested with the staff default above.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * A deactivated account, for asserting that EnsureUserIsActive bites.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return $this
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
