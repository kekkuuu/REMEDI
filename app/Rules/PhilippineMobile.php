<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Philippine mobile number, the way people type one: 09171234567,
 * 0917-123-4567, +63 917 123 4567, 639171234567 or 9171234567 (2026-11-04, at the
 * user's request: the field took any text up to 40 characters, "hello" included).
 *
 * It checks the shape, not whether the number is in service, and the number is
 * stored as typed: SmsService::normalisePhNumber() puts it in one form when sending.
 * Pair it with 'bail' and 'string', so an array never reaches it.
 */
class PhilippineMobile implements ValidationRule
{
    public const MESSAGE = 'Enter a Philippine mobile number, such as 0917 123 4567.';

    public static function matches(string $number): bool
    {
        $compact = preg_replace('/[\s().-]/', '', $number) ?? '';

        return preg_match('/^(?:\+?63|0)?9\d{9}\z/', $compact) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::matches($value)) {
            $fail(self::MESSAGE);
        }
    }
}
