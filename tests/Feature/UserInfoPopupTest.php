<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User Management: clicking a row opens a read-only Personal Information
 * pop-up with the same fields as My Profile (2026-10-23). The values ride on
 * the row as JSON, so these assert that JSON -- the pop-up itself is filled in
 * the browser.
 */
class UserInfoPopupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function rowInfo(string $html, User $user): array
    {
        $this->assertSame(1, preg_match_all('/data-user-info="([^"]*)"[^>]*>\s*<td>'.preg_quote($user->staff_code, '/').'</', $html, $m));

        return json_decode(html_entity_decode($m[1][0], ENT_QUOTES), true);
    }

    public function test_each_row_carries_the_profile_fields_and_the_page_has_the_popup(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create([
            'name' => 'Maria Santos',
            'email' => 'maria@remedi.com',
            'personal_email' => 'maria@gmail.com',
            'phone' => '09171234567',
        ]);

        $html = $this->actingAs($admin)->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString('id="userInfoModal"', $html);
        $this->assertSame([
            'name' => 'Maria Santos',
            'email' => 'maria@remedi.com',
            'personal_email' => 'maria@gmail.com',
            'phone' => '09171234567',
            'role' => 'Staff',
            'code' => $staff->staff_code,
            'since' => $staff->created_at->format('F j, Y'),
            'edit' => '/users/'.$staff->id.'/edit',
        ], $this->rowInfo($html, $staff));
    }

    /** The AJAX search swaps the rows; the new rows must carry the same data. */
    public function test_rows_from_a_search_carry_it_too(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['name' => 'Juan Cruz']);

        $html = $this->actingAs($admin)->getJson('/users?search=Juan')->assertOk()->json('html');

        $this->assertSame('Juan Cruz', $this->rowInfo($html, $staff)['name']);
    }

    /** An archived account is shown read-only, with no Edit link to a form it cannot use. */
    public function test_an_archived_row_offers_no_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $staff->delete();

        $html = $this->actingAs($admin)->get('/users?archived=1')->assertOk()->getContent();

        $this->assertNull($this->rowInfo($html, $staff)['edit']);
    }
}
