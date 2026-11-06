<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Philippine mobile number of at most 11 digits: 09171234567, or the same with
 * spaces or dashes, or 9171234567 (2026-11-04, at the user's request: the field took
 * any text up to 40 characters, "hello" included). The +63 / 63 forms were accepted
 * for two days and are refused since 2026-11-06, also at the user's request ("not
 * exceeding 11 digits"); the forms' boxes stop at 11 characters to match.
 *
 * It checks the shape, not whether the number is in service, and the number is
 * stored as typed: SmsService::normalisePhNumber() puts it in one form when sending.
 * Pair it with 'bail' and 'string', so an array never reaches it.
 */
class PhilippineMobile implements ValidationRule
{
    public const MESSAGE = 'Enter a Philippine mobile number of up to 11 digits, such as 09171234567.';

    public static function matches(string $number): bool
    {
        $compact = preg_replace('/[\s().-]/', '', $number) ?? '';

        return preg_match('/^0?9\d{9}\z/', $compact) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::matches($value)) {
            $fail(self::MESSAGE);
        }
    }
}
