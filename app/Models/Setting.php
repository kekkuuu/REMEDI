<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    /**
     * The demand model scored on the whole store's monthly units (JSON),
     * written by forecast:generate and shown on the Forecasting page. Not an
     * admin setting -- it lives here so every host reads the latest run.
     */
    public const STOREWIDE_ACCURACY_KEY = 'forecast_storewide_accuracy';

    /**
     * The backup password, set in Safeguard (2026-10-15). Every full backup
     * asks for it, refuses any other, and locks its zip with the password
     * typed -- so the server only ever CHECKS it, and it is stored HASHED like
     * the void passcode; the database never holds it in a readable form.
     */
    public const BACKUP_PASSWORD_KEY = 'backup_zip_password';

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

    public static function backupPasswordIsSet(): bool
    {
        return (bool) static::get(self::BACKUP_PASSWORD_KEY);
    }

    /** Hash and store a new backup password, replacing whatever was there. */
    public static function setBackupPassword(string $password): void
    {
        static::put(self::BACKUP_PASSWORD_KEY, Hash::make($password));
    }

    /**
     * False when none is set, as well as on a wrong password. A value that is
     * not a hash this app can verify (one written by an earlier version)
     * counts as wrong rather than throwing.
     */
    public static function checkBackupPassword(string $password): bool
    {
        $hash = static::get(self::BACKUP_PASSWORD_KEY);
        if (! $hash) {
            return false;
        }

        try {
            return Hash::check($password, $hash);
        } catch (\RuntimeException) {
            return false;
        }
    }

    public static function backupPasswordSetAt(): ?Carbon
    {
        $row = static::where('key', self::BACKUP_PASSWORD_KEY)->whereNotNull('value')->first();

        return $row?->updated_at;
    }
}
