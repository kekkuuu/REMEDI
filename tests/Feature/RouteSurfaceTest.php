<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Every registered route must reach a method that exists.
 *
 * `Route::resource('products', ProductController::class)` registers all seven
 * REST actions, but this app has no product DETAIL page -- the edit screen is
 * where stock, batches and the return actions live -- so ProductController has
 * no show(). The route was registered anyway, and GET /products/{id} answered
 * 500 with a BadMethodCallException instead of 404. Nothing links there, which
 * is exactly why it survived: a stale bookmark or a typed URL was enough.
 *
 * Asserting it route by route would only cover the one we know about, so this
 * walks the whole collection instead. A resource route added tomorrow for a
 * controller missing an action fails here rather than in production.
 */
class RouteSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_route_action_exists_on_its_controller(): void
    {
        $missing = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            $action = $route->getAction('uses');

            // Closure routes have nothing to resolve.
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class)) {
                $missing[] = $route->uri().' -> '.$class.' (class not found)';

                continue;
            }

            if (! method_exists($class, $method)) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri()
                    .' -> '.class_basename($class).'::'.$method.'()';
            }
        }

        $this->assertSame([], $missing, "Routes pointing at methods that do not exist:\n".implode("\n", $missing));
    }

    public function test_a_product_id_url_is_refused_rather_than_answering_a_server_error(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        $product = Product::create([
            'name' => 'Surface Probe',
            'sku' => 'SKU-SURFACE-'.uniqid(),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);

        // 405, not 404: PUT/PATCH/DELETE /products/{product} are all still
        // registered, so the URI exists and it is the VERB that has no handler.
        // The point of the assertion is the class of the answer -- the router
        // refuses it, rather than routing it into a method that is not there
        // and returning 500 with a BadMethodCallException in the body.
        $response = $this->actingAs($admin)->get('/products/'.$product->id);
        $this->assertLessThan(500, $response->getStatusCode(), 'a missing page must never be a server error');
        $response->assertStatus(405);

        // The verbs that ARE implemented must keep working.
        $this->actingAs($admin)->get('/products/'.$product->id.'/edit')->assertOk();
        $this->actingAs($admin)->get('/products')->assertOk();
    }
}
