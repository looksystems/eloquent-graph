<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Models\Product;
use Tests\Scopes\ActiveScope;
use Tests\Scopes\TenantScope;
use Tests\TestCase;

class ModelScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test products
        $this->seedProducts();
    }

    protected function tearDown(): void
    {
        // Clean up all test data
        // Use prefixed label for parallel test execution
        $productLabel = (new Product)->getTable();
        DB::connection('graph')->cypher(
            "MATCH (n:`{$productLabel}`) DETACH DELETE n"
        );

        parent::tearDown();
    }

    protected function seedProducts()
    {
        // Create diverse product data for testing
        $products = [
            ['name' => 'Laptop Pro', 'price' => 1299.99, 'category' => 'Electronics', 'is_active' => true, 'stock_quantity' => 50, 'rating' => 4.5, 'tenant_id' => 1],
            ['name' => 'Wireless Mouse', 'price' => 29.99, 'category' => 'Electronics', 'is_active' => true, 'stock_quantity' => 200, 'rating' => 4.2, 'tenant_id' => 1],
            ['name' => 'USB Cable', 'price' => 9.99, 'category' => 'Electronics', 'is_active' => false, 'stock_quantity' => 0, 'rating' => 3.8, 'tenant_id' => 1],
            ['name' => 'Gaming Chair', 'price' => 399.99, 'category' => 'Furniture', 'is_active' => true, 'stock_quantity' => 15, 'rating' => 4.7, 'tenant_id' => 1],
            ['name' => 'Standing Desk', 'price' => 599.99, 'category' => 'Furniture', 'is_active' => true, 'stock_quantity' => 8, 'rating' => 4.6, 'tenant_id' => 2],
            ['name' => 'Office Chair', 'price' => 199.99, 'category' => 'Furniture', 'is_active' => false, 'stock_quantity' => 0, 'rating' => 3.5, 'tenant_id' => 2],
            ['name' => 'Mechanical Keyboard', 'price' => 149.99, 'category' => 'Electronics', 'is_active' => true, 'stock_quantity' => 75, 'rating' => 4.8, 'tenant_id' => 2],
            ['name' => 'Monitor 4K', 'price' => 449.99, 'category' => 'Electronics', 'is_active' => true, 'stock_quantity' => 30, 'rating' => 4.4, 'tenant_id' => 1],
            ['name' => 'Desk Lamp', 'price' => 39.99, 'category' => 'Furniture', 'is_active' => true, 'stock_quantity' => 100, 'rating' => 4.1, 'tenant_id' => 1],
            ['name' => 'Webcam HD', 'price' => 79.99, 'category' => 'Electronics', 'is_active' => true, 'stock_quantity' => 5, 'rating' => 3.9, 'tenant_id' => 2],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }

    // ===========================
    // Local Scope Tests
    // ===========================

    public function test_basic_local_scope_active()
    {
        $activeProducts = Product::active()->get();

        $this->assertCount(8, $activeProducts);
        foreach ($activeProducts as $product) {
            $this->assertTrue($product->is_active);
        }
    }

    public function test_local_scope_with_parameters_price_range()
    {
        $affordableProducts = Product::priceRange(20, 100)->get();

        // We have 3 products in the 20-100 range: Wireless Mouse (29.99), Desk Lamp (39.99), Webcam HD (79.99)
        $this->assertCount(3, $affordableProducts);
        foreach ($affordableProducts as $product) {
            $this->assertGreaterThanOrEqual(20, $product->price);
            $this->assertLessThanOrEqual(100, $product->price);
        }
    }

    public function test_chaining_multiple_local_scopes()
    {
        $products = Product::active()
            ->expensive(100)
            ->category('Electronics')
            ->get();

        $this->assertCount(3, $products);
        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
            $this->assertGreaterThan(100, $product->price);
            $this->assertEquals('Electronics', $product->category);
        }
    }

    public function test_scope_with_complex_logic_popular()
    {
        $popularProducts = Product::popular()->get();

        foreach ($popularProducts as $product) {
            $this->assertGreaterThanOrEqual(4.0, $product->rating);
            $this->assertGreaterThan(10, $product->stock_quantity);
            $this->assertTrue($product->is_active);
        }
    }

    public function test_scope_with_ordering_and_limiting_featured()
    {
        $featuredProducts = Product::featured(3)->get();

        $this->assertLessThanOrEqual(3, $featuredProducts->count());

        // Check ordering
        $previousRating = 5.0;
        foreach ($featuredProducts as $product) {
            $this->assertGreaterThanOrEqual(4.5, $product->rating);
            $this->assertLessThanOrEqual($previousRating, $product->rating);
            $previousRating = $product->rating;
        }
    }

    public function test_scope_in_stock_with_minimum_parameter()
    {
        $wellStockedProducts = Product::inStock(50)->get();

        // We have 4 products with stock >= 50: Laptop Pro (50), Wireless Mouse (200), Mechanical Keyboard (75), Desk Lamp (100)
        $this->assertCount(4, $wellStockedProducts);
        foreach ($wellStockedProducts as $product) {
            $this->assertGreaterThanOrEqual(50, $product->stock_quantity);
        }
    }

    public function test_scope_filter_with_multiple_optional_parameters()
    {
        $filters = [
            'category' => 'Electronics',
            'min_price' => 50,
            'max_price' => 500,
            'in_stock' => true,
        ];

        $filteredProducts = Product::filter($filters)->get();

        foreach ($filteredProducts as $product) {
            $this->assertEquals('Electronics', $product->category);
            $this->assertGreaterThanOrEqual(50, $product->price);
            $this->assertLessThanOrEqual(500, $product->price);
            $this->assertGreaterThan(0, $product->stock_quantity);
        }
    }

    public function test_scope_combining_with_regular_where_clauses()
    {
        $products = Product::active()
            ->where('rating', '>', 4.3)
            ->expensive(200)
            ->get();

        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
            $this->assertGreaterThan(4.3, $product->rating);
            $this->assertGreaterThan(200, $product->price);
        }
    }

    public function test_scope_for_tenant()
    {
        $tenant1Products = Product::forTenant(1)->get();
        $tenant2Products = Product::forTenant(2)->get();

        // Tenant 1: Laptop Pro, Wireless Mouse, USB Cable, Gaming Chair, Monitor 4K, Desk Lamp (6 products)
        // Tenant 2: Standing Desk, Office Chair, Mechanical Keyboard, Webcam HD (4 products)
        $this->assertCount(6, $tenant1Products);
        $this->assertCount(4, $tenant2Products);

        foreach ($tenant1Products as $product) {
            $this->assertEquals(1, $product->tenant_id);
        }
    }

    public function test_scope_returns_query_builder_for_further_chaining()
    {
        $query = Product::active();

        $this->assertInstanceOf(\Look\EloquentCypher\GraphEloquentBuilder::class, $query);

        // Can continue chaining
        $products = $query->where('price', '<', 100)->get();

        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
            $this->assertLessThan(100, $product->price);
        }
    }

    // ===========================
    // Global Scope Tests
    // ===========================

    public function test_global_scope_automatic_application()
    {
        // Test global scope by adding it to the Product model directly
        // (anonymous classes have table naming issues with Neo4j labels)
        Product::addGlobalScope('active_test', new ActiveScope);

        $products = Product::all();

        // Should only get active products (8 out of 10)
        $this->assertCount(8, $products);
        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
        }

        // Clean up
        Product::removeGlobalScope('active_test');

        // Verify cleanup worked
        $allProducts = Product::all();
        $this->assertCount(10, $allProducts);
    }

    public function test_adding_global_scope_at_runtime()
    {
        Product::addGlobalScope('active', new ActiveScope);

        $products = Product::all();

        $this->assertCount(8, $products);
        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
        }

        // Clean up global scope
        Product::removeGlobalScope('active');
    }

    public function test_removing_global_scope_with_without_global_scope()
    {
        Product::addGlobalScope('active', new ActiveScope);

        $withScope = Product::count();
        $withoutScope = Product::withoutGlobalScope('active')->count();

        $this->assertEquals(8, $withScope);
        $this->assertEquals(10, $withoutScope);

        // Clean up
        Product::removeGlobalScope('active');
    }

    public function test_removing_all_global_scopes()
    {
        Product::addGlobalScope('active', new ActiveScope);
        Product::addGlobalScope('tenant', new TenantScope(1));

        $withScopes = Product::count();
        $withoutScopes = Product::withoutGlobalScopes()->count();

        $this->assertLessThan(10, $withScopes);
        $this->assertEquals(10, $withoutScopes);

        // Clean up
        Product::removeGlobalScope('active');
        Product::removeGlobalScope('tenant');
    }

    public function test_multiple_global_scopes_interaction()
    {
        Product::addGlobalScope('active', new ActiveScope);
        Product::addGlobalScope('tenant', new TenantScope(1));

        $products = Product::all();

        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
            $this->assertEquals(1, $product->tenant_id);
        }

        // Clean up
        Product::removeGlobalScope('active');
        Product::removeGlobalScope('tenant');
    }

    public function test_global_scope_with_local_scope_combination()
    {
        Product::addGlobalScope('active', new ActiveScope);

        $expensiveProducts = Product::expensive(200)->get();

        foreach ($expensiveProducts as $product) {
            $this->assertTrue($product->is_active); // Global scope applied
            $this->assertGreaterThan(200, $product->price); // Local scope applied
        }

        // Clean up
        Product::removeGlobalScope('active');
    }

    public function test_checking_if_model_has_global_scope()
    {
        Product::addGlobalScope('active', new ActiveScope);

        $this->assertTrue(Product::hasGlobalScope('active'));
        $this->assertFalse(Product::hasGlobalScope('nonexistent'));

        // Clean up
        Product::removeGlobalScope('active');
    }

    public function test_global_scope_with_closure()
    {
        Product::addGlobalScope('highRated', function ($builder) {
            $builder->where('rating', '>=', 4.0);
        });

        $products = Product::all();

        foreach ($products as $product) {
            $this->assertGreaterThanOrEqual(4.0, $product->rating);
        }

        // Clean up
        Product::removeGlobalScope('highRated');
    }

    public function test_global_scope_application_order()
    {
        // Apply scopes in specific order
        Product::addGlobalScope('z_active', new ActiveScope);
        Product::addGlobalScope('a_tenant', new TenantScope(1));

        $products = Product::all();

        // Both scopes should be applied regardless of order
        foreach ($products as $product) {
            $this->assertTrue($product->is_active);
            $this->assertEquals(1, $product->tenant_id);
        }

        // Clean up
        Product::removeGlobalScope('z_active');
        Product::removeGlobalScope('a_tenant');
    }

    public function test_global_scope_with_aggregate_functions()
    {
        Product::addGlobalScope('active', new ActiveScope);

        $count = Product::count();
        $avgPrice = Product::avg('price');
        $maxRating = Product::max('rating');

        $this->assertEquals(8, $count); // Only active products
        $this->assertNotNull($avgPrice);
        $this->assertNotNull($maxRating);

        // Clean up
        Product::removeGlobalScope('active');
    }
}
