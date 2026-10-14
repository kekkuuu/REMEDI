<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * The database backup (BackupController): a full backup only ever leaves as
 * an AES-256 zip under a password the admin chooses, and the sanitized test
 * copy carries the shop's numbers without its people. The dump is real SQL
 * written from this test database (the controller reads sqlite_master here,
 * SHOW CREATE TABLE on MySQL), so these read the actual file.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    /** An admin, a cashier with personal details, a sale, an audit row, a setting. */
    private function shop(): User
    {
        $admin = User::factory()->admin()->create(['email' => 'boss@remedi.com']);
        $cashier = User::factory()->create([
            'name' => 'Maria Cashier',
            'email' => 'maria@remedi.com',
            'personal_email' => 'maria.private@gmail.com',
            'phone' => '09454553998',
        ]);

        $category = Category::firstOrCreate(['name' => 'General Merchandise']);
        Product::create([
            'name' => 'Backup Probe Tissue', 'sku' => 'SKU-BACKUP-1', 'category_id' => $category->id,
            'unit' => 'PCS', 'selling_price' => '12.50', 'reorder_level' => 5,
        ]);

        DB::table('sales')->insert([
            'transaction_no' => 'TXN-BACKUP-1', 'user_id' => $cashier->id,
            'total_amount' => 12.50, 'amount_paid' => 20, 'change_due' => 7.50,
            'payment_voided' => true, 'payment_method' => 'cash',
            'void_reason' => 'other', 'void_note' => 'Maria rang it twice for Mr Santos',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        AuditTrail::create([
            'user_id' => $admin->id, 'username' => $admin->name, 'role' => 'admin',
            'action' => 'Created', 'details' => 'Added user: Maria Cashier', 'ip_address' => '203.0.113.9',
        ]);
        Setting::create(['key' => 'probe_secret', 'value' => 'gcash-account-payload']);

        return $admin;
    }

    private function body(TestResponse $response): string
    {
        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    public function test_the_backup_page_explains_handling_and_offers_both_kinds(): void
    {
        $this->actingAs(User::factory()->admin()->create())->withSession($this->confirmed())
            ->get('/admin/backup')
            ->assertOk()
            ->assertSee('A full backup holds personal and security data.')
            ->assertSee('Do not email it, send it in a chat')
            ->assertSee('Sanitized test copy')
            ->assertSee('name="type" value="sanitized"', false);
    }

    public function test_staff_cannot_download_a_backup(): void
    {
        $this->actingAs(User::factory()->create())->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'sanitized'])
            ->assertForbidden();
    }

    public function test_the_download_asks_for_the_login_password_first(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/backup', ['type' => 'sanitized'])
            ->assertRedirect(route('password.confirm'));
    }

    public function test_the_sanitized_copy_keeps_the_numbers_and_drops_the_people(): void
    {
        $admin = $this->shop();
        $cashier = User::where('email', 'maria@remedi.com')->first();

        $response = $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'sanitized'])
            ->assertOk();

        $this->assertStringContainsString('remedi-test-copy-', $response->headers->get('content-disposition'));
        $sql = $this->body($response);

        // The shop's numbers are still there.
        $this->assertStringContainsString('SKU-BACKUP-1', $sql);
        $this->assertStringContainsString('TXN-BACKUP-1', $sql);
        $this->assertStringContainsString('user'.$cashier->id.'@example.invalid', $sql);

        // The people are not.
        foreach (['maria@remedi.com', 'boss@remedi.com', 'maria.private@gmail.com', '09454553998',
            'Maria Cashier', 'Mr Santos', '203.0.113.9', 'gcash-account-payload', $cashier->password] as $secret) {
            $this->assertStringNotContainsString($secret, $sql, "Leaked into the test copy: {$secret}");
        }

        // Structure kept, rows dropped, for the tables that are about people.
        $this->assertStringContainsString('DROP TABLE IF EXISTS `audit_trails`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `audit_trails`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `settings`', $sql);
    }

    public function test_no_sanitized_account_can_be_signed_into(): void
    {
        $row = BackupController::sanitizeRow('users', [
            'id' => 7, 'name' => 'Real Name', 'email' => 'real@remedi.com', 'password' => 'real-hash',
            'phone' => '0917', 'personal_email' => 'x@y.z', 'password_otp_hash' => 'h',
        ], 'dead-hash');

        $this->assertSame('User 7', $row['name']);
        $this->assertSame('user7@example.invalid', $row['email']);
        $this->assertSame('dead-hash', $row['password']);
        $this->assertNull($row['phone']);
        $this->assertNull($row['personal_email']);
        $this->assertNull($row['password_otp_hash']);
    }

    public function test_a_full_backup_needs_a_password(): void
    {
        $admin = $this->shop();

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full'])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD.'x'])
            ->assertSessionHasErrors('password');
    }

    public function test_a_full_backup_is_an_encrypted_zip_that_only_its_password_opens(): void
    {
        if (! BackupController::canEncrypt()) {
            $this->markTestSkipped('This PHP build has no AES zip support.');
        }

        $admin = $this->shop();

        $response = $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk();

        $this->assertSame('application/zip', $response->headers->get('content-type'));
        $path = $response->baseResponse->getFile()->getPathname();

        // Nothing readable in the file itself.
        $this->assertStringNotContainsString('maria@remedi.com', file_get_contents($path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex(0)['encryption_method']);

        // Wrong password: nothing comes out.
        $zip->setPassword('not-the-password');
        $this->assertFalse($zip->getFromIndex(0));

        // Right password: the whole, unsanitized backup.
        $zip->setPassword(self::PASSWORD);
        $sql = $zip->getFromIndex(0);
        $zip->close();

        $this->assertStringContainsString('maria@remedi.com', $sql);
        $this->assertStringContainsString('INSERT INTO `audit_trails`', $sql);
        $this->assertStringContainsString('INSERT INTO `settings`', $sql);
    }

    public function test_the_password_never_reaches_the_audit_trail(): void
    {
        if (! BackupController::canEncrypt()) {
            $this->markTestSkipped('This PHP build has no AES zip support.');
        }

        $admin = $this->shop();
        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk();

        $this->assertTrue(AuditTrail::where('details', 'like', 'Downloaded encrypted database backup%')->exists());
        $this->assertFalse(AuditTrail::where('details', 'like', '%'.self::PASSWORD.'%')->exists());
    }

    // ── The backup password kept in Safeguard (2026-10-15) ───────────────

    private function openWith(TestResponse $response, string $password): string|false
    {
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $zip->setPassword($password);
        $sql = $zip->getFromIndex(0);
        $zip->close();

        return $sql;
    }

    public function test_an_admin_sets_the_backup_password_in_safeguard_and_it_is_stored_encrypted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', ['backup_password' => self::PASSWORD, 'backup_password_confirmation' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        $raw = Setting::where('key', Setting::BACKUP_PASSWORD_KEY)->value('value');
        $this->assertNotSame(self::PASSWORD, $raw);
        $this->assertStringNotContainsString(self::PASSWORD, $raw);
        $this->assertSame(self::PASSWORD, Setting::backupPassword());

        $this->assertTrue(AuditTrail::where('details', 'Set the backup password')->exists());
        $this->assertFalse(AuditTrail::where('details', 'like', '%'.self::PASSWORD.'%')->exists());

        // Changing it is logged as a change, and the new one replaces the old.
        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', ['backup_password' => 'another-long-password', 'backup_password_confirmation' => 'another-long-password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('another-long-password', Setting::backupPassword());
        $this->assertTrue(AuditTrail::where('details', 'Changed the backup password')->exists());
    }

    public function test_a_weak_or_mismatched_backup_password_is_not_saved(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', ['backup_password' => 'short', 'backup_password_confirmation' => 'short'])
            ->assertSessionHasErrors('backup_password');
        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', ['backup_password' => self::PASSWORD, 'backup_password_confirmation' => self::PASSWORD.'x'])
            ->assertSessionHasErrors('backup_password');

        $this->assertFalse(Setting::backupPasswordIsSet());
    }

    public function test_only_a_confirmed_admin_can_set_the_backup_password(): void
    {
        $body = ['backup_password' => self::PASSWORD, 'backup_password_confirmation' => self::PASSWORD];

        $this->actingAs(User::factory()->create())->withSession($this->confirmed())
            ->put('/safeguard/backup-password', $body)->assertForbidden();

        // A fresh session: the confirmation stamp above must not carry over.
        $this->flushSession();
        $this->actingAs(User::factory()->admin()->create())
            ->put('/safeguard/backup-password', $body)->assertRedirect(route('password.confirm'));

        $this->assertFalse(Setting::backupPasswordIsSet());
    }

    public function test_a_full_backup_uses_the_saved_password_without_asking(): void
    {
        if (! BackupController::canEncrypt()) {
            $this->markTestSkipped('This PHP build has no AES zip support.');
        }

        $admin = $this->shop();
        Setting::setBackupPassword(self::PASSWORD);

        $this->actingAs($admin)->withSession($this->confirmed())->get('/admin/backup')
            ->assertSee('backup password set in Safeguard');

        $response = $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full'])
            ->assertOk();

        $this->assertStringContainsString('maria@remedi.com', $this->openWith($response, self::PASSWORD));
        $this->assertTrue(AuditTrail::where('details', 'like', '%Safeguard backup password%')->exists());

        // audit_trails.details is varchar(255) on MySQL; sqlite does not
        // enforce it, so check the length here or only production finds out.
        foreach (AuditTrail::pluck('details') as $details) {
            $this->assertLessThanOrEqual(255, strlen($details), $details);
        }
    }

    public function test_a_one_time_password_overrides_the_saved_one_for_that_file(): void
    {
        if (! BackupController::canEncrypt()) {
            $this->markTestSkipped('This PHP build has no AES zip support.');
        }

        $admin = $this->shop();
        Setting::setBackupPassword(self::PASSWORD);

        $response = $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full', 'password' => 'just-for-this-file', 'password_confirmation' => 'just-for-this-file'])
            ->assertOk();

        $this->assertFalse($this->openWith($response, self::PASSWORD));
        $this->assertStringContainsString('maria@remedi.com', $this->openWith($response, 'just-for-this-file'));
    }

    public function test_removing_the_saved_password_makes_the_backup_ask_again(): void
    {
        $admin = $this->shop();
        Setting::setBackupPassword(self::PASSWORD);

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', ['remove' => 1])
            ->assertSessionHasNoErrors();
        $this->assertFalse(Setting::backupPasswordIsSet());

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full'])
            ->assertSessionHasErrors('password');
    }

    /** Saved under another APP_KEY: unreadable here, so treated as not saved. */
    public function test_a_password_saved_under_another_app_key_is_not_used(): void
    {
        $admin = $this->shop();
        Setting::put(Setting::BACKUP_PASSWORD_KEY, 'eyJpdiI6Im5vdC1yZWFsIn0=');

        $this->assertTrue(Setting::backupPasswordIsSet());
        $this->assertNull(Setting::backupPassword());

        $this->actingAs($admin)->withSession($this->confirmed())->get('/admin/backup')
            ->assertSee('cannot be read on this server');
        $this->actingAs($admin)->withSession($this->confirmed())
            ->post('/admin/backup', ['type' => 'full'])
            ->assertSessionHasErrors('password');
    }
}
