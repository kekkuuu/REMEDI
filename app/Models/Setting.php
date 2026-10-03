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

    /**
     * The shop's own GCash / InstaPay QR -- the decoded QR Ph text of the
     * "My QR" code (2026-10-01, at the user's request). The POS draws it for
     * GCash / Other QR so a customer's app can actually pay it; a QR holding
     * plain text ("REMEDI", a phone number) is refused by every e-wallet as
     * invalid. In the database, not the code: the repository is public and
     * the payload carries the account holder's details.
     */
    public const GCASH_QR_KEY = 'pos_gcash_qr_payload';

    /**
     * The demand model scored on the whole store's monthly units (JSON),
     * written by forecast:generate and shown on the Forecasting page. Not an
     * admin setting -- it lives here so every host reads the latest run.
     */
    public const STOREWIDE_ACCURACY_KEY = 'forecast_storewide_accuracy';

    public static function gcashQrPayload(): ?string
    {
        $payload = static::get(self::GCASH_QR_KEY);

        return $payload !== null && $payload !== '' ? $payload : null;
    }

    /**
     * Whether a string is a well-formed EMV / QR Ph payload: starts with the
     * format indicator, every tag-length-value parses to the end, and the
     * trailing CRC (tag 63) matches -- the same check a paying app makes.
     */
    public static function isQrPhPayload(string $payload): bool
    {
        if (strlen($payload) > 512 || ! str_starts_with($payload, '000201')) {
            return false;
        }

        $i = 0;
        $last = null;
        while ($i < strlen($payload)) {
            if (! preg_match('/^(\d{2})(\d{2})/', substr($payload, $i, 4), $m)) {
                return false;
            }
            $len = (int) $m[2];
            if ($i + 4 + $len > strlen($payload)) {
                return false;
            }
            $last = $m[1];
            $i += 4 + $len;
        }

        if ($last !== '63' || substr($payload, -8, 4) !== '6304') {
            return false;
        }

        return strtoupper(substr($payload, -4)) === self::qrPhCrc(substr($payload, 0, -4));
    }

    /** The account name a QR Ph payload carries (tag 59), for display. */
    public static function qrPhMerchantName(string $payload): ?string
    {
        $i = 0;
        while ($i + 4 <= strlen($payload)) {
            $id = substr($payload, $i, 2);
            $len = (int) substr($payload, $i + 2, 2);
            if ($id === '59') {
                return substr($payload, $i + 4, $len);
            }
            $i += 4 + $len;
        }

        return null;
    }

    /** CRC-16/CCITT-FALSE, the checksum EMV QR codes end with. */
    public static function qrPhCrc(string $data): string
    {
        $crc = 0xFFFF;
        for ($k = 0; $k < strlen($data); $k++) {
            $crc ^= ord($data[$k]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }

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
