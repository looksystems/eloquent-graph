<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class JoinLikeOperationsTest extends GraphTestCase
{
    public function test_can_query_multiple_node_types_with_pattern_matching()
    {
        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = Post::create(['title' => 'Test Post', 'content' => 'Test content', 'user_id' => $user->id]);

        // Get prefixed labels
        $usersLabel = (new User)->getTable();
        $postsLabel = (new Post)->getTable();

        // Test pattern matching across multiple node types using foreign keys with prefixed labels
        $results = User::joinPattern("(u:`{$usersLabel}`), (p:`{$postsLabel}`)")
            ->where('p.user_id = u.id')
            ->select(['u.name as user_name', 'p.title as post_title'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John', $results[0]->user_name);
        $this->assertEquals('Test Post', $results[0]->post_title);
    }

    public function test_can_find_shortest_path_between_nodes()
    {
        // Create test nodes
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'My Post', 'user_id' => $user->id]);

        // Test that the shortest path builder is properly instantiated and works
        $builder = User::shortestPath()
            ->from($user->id)
            ->to($post->id, 'posts');

        // Verify the builder is configured correctly
        $this->assertInstanceOf(\Look\EloquentCypher\Patterns\ShortestPathBuilder::class, $builder);

        // For now, just test that the query executes without error
        $paths = $builder->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $paths);
    }

    public function test_can_query_with_variable_length_relationships()
    {
        // Create test data
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'My Post', 'user_id' => $user->id]);

        // Test that the variable path builder works
        $builder = User::variablePath()
            ->from($user->id)
            ->minHops(1)
            ->maxHops(3);

        $this->assertInstanceOf(\Look\EloquentCypher\Patterns\VariablePathBuilder::class, $builder);

        // Test that the query executes
        $connected = $builder->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $connected);
    }

    public function test_can_match_complex_graph_patterns()
    {
        // Create test scenario using foreign keys
        $user1 = User::create(['name' => 'Alice']);
        $user2 = User::create(['name' => 'Bob']);

        $post1 = Post::create(['title' => 'Alice Post', 'user_id' => $user1->id]);
        $post2 = Post::create(['title' => 'Bob Post', 'user_id' => $user2->id]);

        // Get prefixed labels
        $usersLabel = (new User)->getTable();
        $postsLabel = (new Post)->getTable();

        // Find users who have posts using graph pattern with prefixed labels
        $results = User::graphPattern()
            ->match("(u:`{$usersLabel}`), (p:`{$postsLabel}`)")
            ->where('p.user_id = u.id')
            ->return(['u.name', 'p.title'])
            ->get();

        $this->assertCount(2, $results);
        $userNames = collect($results)->map(function ($result) {
            return $result->{'u.name'};
        })->toArray();
        $this->assertContains('Alice', $userNames);
        $this->assertContains('Bob', $userNames);
    }

    public function test_can_use_cypher_where_with_patterns()
    {
        // Create users with different ages
        $youngUser = User::create(['name' => 'Young User', 'age' => 20]);
        $oldUser = User::create(['name' => 'Old User', 'age' => 40]);

        // Get prefixed label
        $usersLabel = (new User)->getTable();

        // Find users older than 30 with prefixed label
        $results = User::graphPattern()
            ->match("(u:`{$usersLabel}`)")
            ->where('u.age > 30')
            ->return(['u.name', 'u.age'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Old User', $results[0]->{'u.name'});
        $this->assertEquals(40, $results[0]->{'u.age'});
    }
}
