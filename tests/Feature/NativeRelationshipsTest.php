<?php

namespace Tests\Feature;

use Tests\Models\NativePost;
use Tests\Models\NativeUser;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class NativeRelationshipsTest extends GraphTestCase
{
    public function test_native_has_many_relationship_creates_edges()
    {
        // Create user with native relationships
        $user = NativeUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Create post through relationship
        $post = $user->posts()->create([
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // Verify foreign key is set
        $this->assertEquals($user->id, $post->user_id);

        // Verify edge exists in Neo4j
        $connection = $user->getConnection();
        $cypherQuery = 'MATCH (u:users {id: $userId})-[r:HAS_NATIVE_POSTS]->(p:posts {id: $postId}) RETURN r';
        $params = ['userId' => $user->id, 'postId' => $post->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertNotEmpty($result, 'Native edge should exist between user and post');
    }

    public function test_native_belongs_to_relationship_creates_edges()
    {
        // Create models with native relationships
        $user = NativeUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $post = NativePost::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // Associate post with user
        $post->user()->associate($user);
        $post->save();

        // Verify foreign key is set
        $this->assertEquals($user->id, $post->user_id);

        // Verify edge exists in Neo4j
        $connection = $post->getConnection();
        $cypherQuery = 'MATCH (p:posts {id: $postId})-[r:BELONGS_TO_NATIVE_USER]->(u:users {id: $userId}) RETURN r';
        $params = ['postId' => $post->id, 'userId' => $user->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertNotEmpty($result, 'Native edge should exist from post to user');
    }

    public function test_native_has_one_relationship_creates_edges()
    {
        // Create user with native relationships
        $user = NativeUser::create([
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
        ]);

        // Create profile through hasOne relationship
        $profile = $user->profile()->create([
            'bio' => 'Test Bio',
            'website' => 'https://example.com',
        ]);

        // Verify foreign key is set
        $this->assertEquals($user->id, $profile->user_id);

        // Verify edge exists
        $connection = $user->getConnection();
        $cypherQuery = 'MATCH (u:users {id: $userId})-[r:HAS_NATIVE_PROFILES]->(pr:profiles {id: $profileId}) RETURN r';
        $params = ['userId' => $user->id, 'profileId' => $profile->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertNotEmpty($result, 'Native edge should exist between user and profile');
    }

    public function test_dissociate_removes_native_edge()
    {
        // Create models with native relationships
        $user = NativeUser::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $post = NativePost::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        // Associate post with user
        $post->user()->associate($user);
        $post->save();

        // Verify edge exists
        $connection = $post->getConnection();
        $cypherQuery = 'MATCH (p:posts {id: $postId})-[r:BELONGS_TO_NATIVE_USER]->(u:users {id: $userId}) RETURN r';
        $params = ['postId' => $post->id, 'userId' => $user->id];
        $result = $connection->select($cypherQuery, $params);
        $this->assertNotEmpty($result);

        // Dissociate
        $post->user()->dissociate();
        $post->save();

        // Verify edge is removed
        $result = $connection->select($cypherQuery, $params);
        $this->assertEmpty($result, 'Native edge should be removed after dissociate');
        $this->assertNull($post->user_id);
    }

    public function test_custom_edge_types_work()
    {
        // Create user with custom edge type (defined in NativeUser)
        $user = NativeUser::create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
        ]);

        // Create post using custom relationship
        $post = $user->authored_posts()->create([
            'title' => 'Custom Edge Post',
            'content' => 'Test Content',
        ]);

        // Verify custom edge type is used
        $connection = $user->getConnection();
        $cypherQuery = 'MATCH (u:users {id: $userId})-[r:AUTHORED]->(p:posts {id: $postId}) RETURN r';
        $params = ['userId' => $user->id, 'postId' => $post->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertNotEmpty($result, 'Custom edge type AUTHORED should exist');
    }

    public function test_backward_compatibility_with_foreign_keys()
    {
        // Create models without native relationships (default)
        $user = User::create([
            'name' => 'David',
            'email' => 'david@example.com',
        ]);

        $post = $user->posts()->create([
            'title' => 'Regular Post',
            'content' => 'Test Content',
        ]);

        // Verify foreign key is set
        $this->assertEquals($user->id, $post->user_id);

        // Verify NO edge exists (backward compatibility mode)
        $connection = $user->getConnection();
        $cypherQuery = 'MATCH (u:users {id: $userId})-[r]->(p:posts {id: $postId}) RETURN r';
        $params = ['userId' => $user->id, 'postId' => $post->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertEmpty($result, 'No native edge should exist in backward compatibility mode');
    }

    public function test_hybrid_mode_with_both_edges_and_foreign_keys()
    {
        // Create native user
        $user = NativeUser::create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
        ]);

        // Create post through relationship
        $post = $user->posts()->create([
            'title' => 'Hybrid Post',
            'content' => 'Test Content',
        ]);

        // Both foreign key and edge should exist
        $this->assertEquals($user->id, $post->user_id, 'Foreign key should be set');

        // Verify edge exists
        $connection = $user->getConnection();
        $cypherQuery = 'MATCH (u:users {id: $userId})-[r:HAS_NATIVE_POSTS]->(p:posts {id: $postId}) RETURN r';
        $params = ['userId' => $user->id, 'postId' => $post->id];
        $result = $connection->select($cypherQuery, $params);

        $this->assertNotEmpty($result, 'Native edge should also exist in hybrid mode');
    }
}
