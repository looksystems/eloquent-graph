<?php

namespace Tests\Unit\Native;

use Tests\Models\NativeUser;
use Tests\TestCase\GraphTestCase;

class CustomEdgeTypeTest extends GraphTestCase
{
    public function test_authored_posts_uses_custom_edge_type()
    {
        $user = NativeUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Get the relationship instance
        $relation = $user->authored_posts();

        // Use reflection to check relationship name
        $reflection = new \ReflectionClass($relation);
        if ($reflection->hasProperty('relationName')) {
            $property = $reflection->getProperty('relationName');
            $property->setAccessible(true);
            $relationName = $property->getValue($relation);
            $this->assertEquals('authored_posts', $relationName, 'Relationship name should be authored_posts');
        }

        // Check edge type
        $method = $reflection->getMethod('getEdgeType');
        $method->setAccessible(true);
        $edgeType = $method->invoke($relation);

        $this->assertEquals('AUTHORED', $edgeType, 'Edge type should be AUTHORED for authored_posts relationship');

        // Create post
        $post = $user->authored_posts()->create([
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // Verify edge exists
        $connection = $user->getConnection();
        $cypherQuery = 'MATCH (u:users {id: $userId})-[r:AUTHORED]->(p:posts {id: $postId}) RETURN r';
        $params = ['userId' => $user->id, 'postId' => $post->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertNotEmpty($result, 'AUTHORED edge should exist between user and post');

        // Also check what edge types exist
        if (empty($result)) {
            $cypherQuery = 'MATCH (u:users {id: $userId})-[r]->(p:posts {id: $postId}) RETURN type(r) as type';
            $result = $connection->select($cypherQuery, $params);
            if (! empty($result)) {
                $types = array_map(fn ($r) => $r['type'], $result);
                $this->fail('Expected AUTHORED edge but found: '.implode(', ', $types));
            }
        }
    }
}
