<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A search box receives whatever the query string says, and `?search[]=x`
 * arrives as an ARRAY. likeTerm() is typed ?string, so any site that passed
 * the raw value straight through died with an uncaught TypeError -- a 500
 * page for a signed-in user, reachable by editing the URL or through the
 * AJAX live search. SaleController and UserController validated first; the
 * others did not.
 */
class SearchInputTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: bool}> [url, adminOnly] */
    public static function searchSites(): array
    {
        return [
            'inventory' => ['/inventory', false],
            'pos barcode lookup' => ['/pos/lookup', false],
            'pos' => ['/pos', false],
            'sales' => ['/sales', false],
            'suggest products' => ['/suggest/products', false],
            'suggest sales' => ['/suggest/sales', false],
            'products' => ['/products', true],
            'users' => ['/users', true],
            'audit' => ['/audit', true],
            'suggest users' => ['/suggest/users', true],
            'suggest audit' => ['/suggest/audit', true],
        ];
    }

    /** @dataProvider searchSites */
    public function test_an_array_search_term_never_crashes_the_page(string $url, bool $adminOnly): void
    {
        $user = $adminOnly ? User::factory()->admin()->create() : User::factory()->create();

        foreach (['search', 'q', 'sku'] as $param) {
            $response = $this->actingAs($user)->get($url.'?'.$param.'[]=x');

            $this->assertLessThan(500, $response->getStatusCode(), "{$url}?{$param}[]=x answered {$response->getStatusCode()}");
        }
    }

    /**
     * Every OTHER filter parameter the list and report pages read, sent as an
     * array. Same failure shape as the search box: a value the page assumed
     * was a string.
     */
    public function test_no_filter_parameter_crashes_a_page_when_sent_as_an_array(): void
    {
        $admin = User::factory()->admin()->create();
        $pages = ['/inventory', '/pos', '/sales', '/products', '/users', '/audit', '/notifications', '/barcodes', '/barcodes/products', '/stock-reports'];
        // /forecast is left out: its queries are MySQL-only, so this suite's sqlite
        // cannot render it at all (checked against MySQL separately).
        $params = ['filter', 'category_id', 'category', 'days', 'status', 'role', 'sort', 'dir', 'start_date',
            'end_date', 'month', 'period', 'product', 'cashier', 'action', 'user_id', 'date_from', 'date_to',
            'archived', 'page', 'tab', 'kind', 'expiry_from', 'expiry_to'];

        $failures = [];
        foreach ($pages as $page) {
            foreach ($params as $param) {
                $status = $this->actingAs($admin)->get($page.'?'.$param.'[]=x')->getStatusCode();
                if ($status >= 500) {
                    $failures[] = "{$page}?{$param}[]=x -> {$status}";
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
