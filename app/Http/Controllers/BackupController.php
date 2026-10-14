<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ZipArchive;

class BackupController extends Controller
{
    /**
     * The tables a backup actually needs to carry.
     *
     * `sales_history` and `inventory_receipts` are deliberately excluded --
     * both are IMPORTED data with their own source of truth sitting outside
     * the database (the sales-history CSVs under database/data, loaded by
     * `sales-history:import` / the seeders, and `Transaction_Records_Seed.csv`).
     * Neither is written by the app itself, so neither can be LOST by the app,
     * and including them would multiply the dump many times over for no data
     * actually at risk -- on a serverless request, the difference between a
     * backup that finishes in seconds and one that hits the platform timeout.
     *
     * `demand_forecasts` / `sales_forecasts` / `forecast_accuracy` ARE
     * included even though they can be regenerated: regenerating costs a
     * SARIMA fit per product, not a re-import.
     *
     * `stock_movements` (the stock card ledger), `stock_reports`,
     * `qr_payments` and `settings` (the void passcode hash, the till's GCash
     * QR) were added 2026-10-15: all four are written by the app and exist
     * nowhere else, so a backup without them could not put the shop back.
     *
     * Deliberately excluded for a different reason: `cache`, `cache_locks`,
     * `sessions`, `password_reset_tokens`, `personal_access_tokens`,
     * `failed_jobs`, `migrations` -- framework bookkeeping and ephemeral
     * state. Restoring a stashed session or a stale reset token later would
     * be a security bug, not a recovery.
     */
    public const BACKUP_TABLES = [
        'users', 'categories', 'products', 'product_batches',
        'sales', 'sale_items', 'stock_movements', 'stock_reports', 'qr_payments',
        'settings', 'audit_trails',
        'demand_forecasts', 'sales_forecasts', 'forecast_accuracy',
    ];

    /**
     * Tables a SANITIZED test copy carries the structure of but no rows:
     * the audit trail is names, IP addresses and free text about people;
     * `qr_payments` carries PayMongo payment ids; `settings` carries the void
     * passcode hash and the shop's GCash QR (its account details).
     */
    private const SANITIZED_EMPTY_TABLES = ['audit_trails', 'qr_payments', 'settings'];

    /** A full backup's zip password: AES-256 is only as good as this. */
    public const MIN_PASSWORD_LENGTH = 10;

    /**
     * The backup page: what a backup contains, how to handle it, and the
     * two kinds on offer. Behind password.confirm with the download itself
     * (routes/web.php), since the full backup carries the users table.
     */
    public function index()
    {
        return view('admin.backup', [
            'tables' => self::BACKUP_TABLES,
            'emptyInCopy' => self::SANITIZED_EMPTY_TABLES,
            'canEncrypt' => self::canEncrypt(),
            'minPassword' => self::MIN_PASSWORD_LENGTH,
            // Set in Safeguard (Setting::BACKUP_PASSWORD_KEY). "Set but not
            // readable" means it was saved under another APP_KEY.
            'savedPassword' => Setting::backupPassword() !== null,
            'savedPasswordUnreadable' => Setting::backupPasswordIsSet() && Setting::backupPassword() === null,
            'savedPasswordAt' => Setting::backupPasswordSetAt(),
        ]);
    }

    /**
     * Two kinds of file, and the difference is the point:
     *
     * - `full`: everything, restorable, and therefore everything sensitive --
     *   account emails and phone numbers, password and reset-code hashes, the
     *   audit trail. It is only ever handed over inside an AES-256 encrypted
     *   .zip, under a password the admin types here and the server never
     *   stores. Lose the password and the file is unreadable; that is the
     *   trade, and the page says so.
     * - `sanitized`: a plain .sql for testing or for showing someone the data,
     *   with the people taken out (see sanitizeRow()). Products, stock, sales
     *   and forecasts are untouched, so the numbers still add up.
     *
     * Written to a temp file rather than streamed: ZipArchive needs a file,
     * and on Vercel only /tmp is writable (sys_get_temp_dir()). Both temp
     * files are removed as soon as the response is sent.
     */
    public function download(Request $request)
    {
        // The password set in Safeguard, when there is one; a password typed
        // on the backup page overrides it for that one file.
        $saved = Setting::backupPassword();

        $data = $request->validate([
            'type' => ['bail', 'required', 'string', Rule::in(['full', 'sanitized'])],
            'password' => ['bail', Rule::requiredIf(fn () => $request->input('type') === 'full' && $saved === null), 'nullable', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:128', 'confirmed'],
        ], [
            'password.required' => 'Choose a password to protect the backup, or set one in Safeguard.',
            'password.min' => 'Use at least :min characters for the backup password.',
            'password.confirmed' => 'The two backup passwords do not match.',
        ]);

        $sanitized = $data['type'] === 'sanitized';

        if (! $sanitized && ! self::canEncrypt()) {
            return back()->withErrors(['backup' => 'This server cannot encrypt a backup (its zip library has no AES support), so the full backup is unavailable here. The sanitized test copy still works.']);
        }

        $stamp = now()->format('Y-m-d_His');
        $sqlName = ($sanitized ? 'remedi-test-copy-' : 'remedi-backup-').$stamp.'.sql';
        $sqlPath = tempnam(sys_get_temp_dir(), 'remedi-sql-');

        $out = fopen($sqlPath, 'wb');
        try {
            $this->writeDump($out, $sanitized);
        } finally {
            fclose($out);
        }

        if ($sanitized) {
            AuditTrail::log('Viewed', 'Downloaded sanitized test copy of the database (no personal data)');

            return response()->download($sqlPath, $sqlName, ['Content-Type' => 'application/sql'])
                ->deleteFileAfterSend();
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'remedi-zip-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFile($sqlPath, $sqlName);
        $oneTime = ($data['password'] ?? null) !== null && $data['password'] !== '';
        $zip->setEncryptionName($sqlName, ZipArchive::EM_AES_256, $oneTime ? $data['password'] : $saved);
        $zip->close();
        @unlink($sqlPath);

        // Never the password -- the trail is readable by every admin.
        // Short on purpose: audit_trails.details is varchar(255) on MySQL, and
        // naming all the tables overflowed it (sqlite never enforces it, so
        // only a real download showed the 500). BACKUP_TABLES lists them.
        AuditTrail::log('Viewed', 'Downloaded encrypted database backup ('.count(self::BACKUP_TABLES).' tables, locked with '
            .($oneTime ? 'a one-time password' : 'the Safeguard backup password').')');

        return response()->download($zipPath, 'remedi-backup-'.$stamp.'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** Whether this PHP's libzip can write AES-256 entries. */
    public static function canEncrypt(): bool
    {
        return class_exists(ZipArchive::class)
            && method_exists(ZipArchive::class, 'isEncryptionMethodSupported')
            && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256);
    }

    /**
     * The SQL itself: schema (DROP + CREATE, exactly as the database reports
     * it, so indexes / AUTO_INCREMENT / collation come back as they were)
     * followed by chunked INSERTs, so no table is ever held in memory whole.
     *
     * 2,000-row chunks: on Vercel the database is a network hop away, and
     * measured live 500-row chunks took ~23.5 s for the whole set.
     */
    private function writeDump($out, bool $sanitized): void
    {
        $tables = self::BACKUP_TABLES;
        $pdo = DB::connection()->getPdo();

        fwrite($out, $sanitized ? "-- REMEDI sanitized test copy\n" : "-- REMEDI database backup\n");
        fwrite($out, '-- Generated '.now()->toDateTimeString().' ('.config('app.timezone').")\n");
        fwrite($out, '-- Tables: '.implode(', ', $tables)."\n");
        if ($sanitized) {
            fwrite($out, "-- People removed: account names, emails, phone numbers and passwords replaced;\n");
            fwrite($out, '-- no rows for '.implode(', ', self::SANITIZED_EMPTY_TABLES).". Not for restoring the shop.\n");
        }
        fwrite($out, "-- sales_history and inventory_receipts are intentionally excluded (imported data\n");
        fwrite($out, "-- with their own source CSV). See BackupController::BACKUP_TABLES.\n\n");
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        // One unusable hash for every sanitized account: a bcrypt of random
        // bytes nobody kept, so no account in a test copy can be signed into
        // with any password, real or guessed.
        $deadHash = $sanitized ? Hash::make(Str::random(64)) : null;

        foreach ($tables as $table) {
            fwrite($out, "-- --------------------------------------------------\n");
            fwrite($out, "-- Table: {$table}\n");
            fwrite($out, "-- --------------------------------------------------\n");
            fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($out, $this->createStatement($table).";\n\n");

            if ($sanitized && in_array($table, self::SANITIZED_EMPTY_TABLES, true)) {
                fwrite($out, "-- (rows left out of the sanitized copy)\n\n");

                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(2000, function ($rows) use ($table, $pdo, $out, $sanitized, $deadHash) {
                if ($rows->isEmpty()) {
                    return;
                }

                $rows = $rows->map(fn ($row) => (array) $row);
                if ($sanitized) {
                    $rows = $rows->map(fn ($row) => self::sanitizeRow($table, $row, $deadHash));
                }

                $columnList = '`'.implode('`, `', array_keys($rows->first())).'`';
                $valueGroups = $rows->map(fn ($row) => '('.implode(', ', array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    $row
                )).')')->implode(",\n");

                fwrite($out, "INSERT INTO `{$table}` ({$columnList}) VALUES\n{$valueGroups};\n");
            });

            fwrite($out, "\n");
        }

        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * What a sanitized copy keeps of one row. Ids, roles, active flags and
     * dates stay, so every sale still points at a cashier and the reports
     * still add up; what identifies or authenticates a person goes. Free
     * text an admin or cashier typed (a void note, a stock adjustment's
     * reason, a stock report's note) goes too -- it can name anyone.
     */
    public static function sanitizeRow(string $table, array $row, ?string $deadHash): array
    {
        if ($table === 'users') {
            $id = $row['id'];

            return array_merge($row, [
                'name' => 'User '.$id,
                'email' => 'user'.$id.'@example.invalid',
                'personal_email' => null,
                'phone' => null,
                'department' => null,
                'password' => $deadHash,
                'remember_token' => null,
                'password_reset_requested_at' => null,
                'password_otp_hash' => null,
                'password_otp_expires_at' => null,
                'password_otp_attempts' => 0,
            ]);
        }

        if ($table === 'sales' && array_key_exists('void_note', $row)) {
            $row['void_note'] = null;
        }

        if ($table === 'stock_movements' && ($row['reason'] ?? null) !== null) {
            $row['reason'] = '(removed in test copy)';
        }

        if ($table === 'stock_reports' && array_key_exists('note', $row)) {
            $row['note'] = null;
        }

        return $row;
    }

    /**
     * The table's own CREATE statement. MySQL answers SHOW CREATE TABLE;
     * the sqlite test database keeps the statement in sqlite_master, which is
     * what lets the test suite check a real dump.
     */
    private function createStatement(string $table): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        }

        $row = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");

        return $row['Create Table'] ?? (string) reset($row);
    }
}
