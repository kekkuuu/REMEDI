<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * A plain key/value store for store-wide configuration an admin sets through
 * the UI -- not a `.env`/config() value, which would need a redeploy to
 * change, and not a column on some unrelated model, since a setting like the
 * POS void passcode is not a fact about any one row.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * The manager passcode PosController void() (staff side, see
     * SaleController::void()) requires before a non-admin can void a sale.
     * Stored HASHED, same as any other credential in this app -- a plaintext
     * 6-digit code sitting in the settings table would be the one exception
     * to "passwords are always hashed" for no reason.
     */
    public const VOID_PASSCODE_KEY = 'pos_void_passcode';

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function voidPasscodeIsSet(): bool
    {
        return (bool) static::get(self::VOID_PASSCODE_KEY);
    }

    /** Hash and store a new void passcode, replacing whatever was there. */
    public static function setVoidPasscode(string $passcode): void
    {
        static::put(self::VOID_PASSCODE_KEY, Hash::make($passcode));
    }

    /** False when no passcode has been set at all, not just on a wrong guess. */
    public static function checkVoidPasscode(string $passcode): bool
    {
        $hash = static::get(self::VOID_PASSCODE_KEY);

        return $hash !== null && Hash::check($passcode, $hash);
    }
}
