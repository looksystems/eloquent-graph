<?php

namespace Tests\Unit\Native;

use Tests\Models\NativePost;
use Tests\Models\NativeUser;
use Tests\TestCase\GraphTestCase;

class EdgeCreationTest extends GraphTestCase
{
    public function test_native_models_have_use_native_relationships_property()
    {
        $user = new NativeUser;
        $this->assertTrue(property_exists($user, 'useNativeRelationships'));

        // Use reflection to check protected property value
        $reflection = new \ReflectionClass($user);
        $property = $reflection->getProperty('useNativeRelationships');
        $property->setAccessible(true);
        $this->assertTrue($property->getValue($user));

        $post = new NativePost;
        $this->assertTrue(property_exists($post, 'useNativeRelationships'));

        $reflection = new \ReflectionClass($post);
        $property = $reflection->getProperty('useNativeRelationships');
        $property->setAccessible(true);
        $this->assertTrue($property->getValue($post));
    }

    public function test_should_create_edge_returns_true_for_native_models()
    {
        $user = NativeUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Get the relationship instance
        $relation = $user->posts();

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($relation);
        $method = $reflection->getMethod('shouldCreateEdge');
        $method->setAccessible(true);

        $result = $method->invoke($relation);
        $this->assertTrue($result, 'shouldCreateEdge should return true for NativeUser posts relationship');
    }

    public function test_edge_manager_is_accessible()
    {
        $user = NativeUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $relation = $user->posts();

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($relation);
        $method = $reflection->getMethod('getEdgeManager');
        $method->setAccessible(true);

        $edgeManager = $method->invoke($relation);
        $this->assertInstanceOf(\Look\EloquentCypher\Services\EdgeManager::class, $edgeManager);
    }

    public function test_edge_type_is_generated_correctly()
    {
        $user = NativeUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $relation = $user->posts();

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($relation);
        $method = $reflection->getMethod('getEdgeType');
        $method->setAccessible(true);

        $edgeType = $method->invoke($relation);
        $this->assertEquals('HAS_NATIVE_POSTS', $edgeType, 'Edge type should be HAS_NATIVE_POSTS for NativeUser posts');
    }
}
