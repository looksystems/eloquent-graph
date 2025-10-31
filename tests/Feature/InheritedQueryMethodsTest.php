<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class InheritedQueryMethodsTest extends GraphTestCase
{
    public function test_pluck_single_column(): void
    {
        // Create test data
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 30]);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35]);

        // Test plucking single column
        $names = User::pluck('name');

        $this->assertCount(3, $names);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Charlie', $names);
    }

    public function test_pluck_with_key_value_pairs(): void
    {
        // Create test data
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        // Test plucking with key-value pairs
        $emailsByName = User::pluck('email', 'name');

        $this->assertEquals('alice@example.com', $emailsByName['Alice']);
        $this->assertEquals('bob@example.com', $emailsByName['Bob']);
    }

    public function test_pluck_with_where_condition(): void
    {
        // Create test data
        User::create(['name' => 'Alice', 'age' => 25]);
        User::create(['name' => 'Bob', 'age' => 30]);
        User::create(['name' => 'Charlie', 'age' => 35]);
        User::create(['name' => 'David', 'age' => 40]);

        // Test plucking with where condition
        $names = User::where('age', '>', 30)->pluck('name');

        $this->assertCount(2, $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('David', $names);
    }

    public function test_pluck_from_relationship(): void
    {
        // Create test data
        $user = User::create(['name' => 'Alice']);
        $user->posts()->create(['title' => 'First Post', 'content' => 'Content 1']);
        $user->posts()->create(['title' => 'Second Post', 'content' => 'Content 2']);
        $user->posts()->create(['title' => 'Third Post', 'content' => 'Content 3']);

        // Test plucking from relationship
        $titles = $user->posts()->pluck('title');

        $this->assertCount(3, $titles);
        $this->assertContains('First Post', $titles);
        $this->assertContains('Second Post', $titles);
        $this->assertContains('Third Post', $titles);
    }

    public function test_pluck_with_order_by(): void
    {
        // Create test data
        User::create(['name' => 'Charlie', 'age' => 35]);
        User::create(['name' => 'Alice', 'age' => 25]);
        User::create(['name' => 'Bob', 'age' => 30]);

        // Test plucking with order
        $names = User::orderBy('name')->pluck('name');

        $this->assertEquals(['Alice', 'Bob', 'Charlie'], $names->toArray());
    }

    public function test_model_to_array(): void
    {
        // Create test data
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 25,
        ]);

        // Test toArray() method
        $array = $user->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Alice', $array['name']);
        $this->assertEquals('alice@example.com', $array['email']);
        $this->assertEquals(25, $array['age']);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
    }

    public function test_model_to_json(): void
    {
        // Create test data
        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'age' => 30,
        ]);

        // Test toJson() method
        $json = $user->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('Bob', $decoded['name']);
        $this->assertEquals('bob@example.com', $decoded['email']);
        $this->assertEquals(30, $decoded['age']);
        $this->assertArrayHasKey('id', $decoded);
    }

    public function test_collection_to_array(): void
    {
        // Create test data
        User::create(['name' => 'Alice', 'age' => 25]);
        User::create(['name' => 'Bob', 'age' => 30]);

        // Test collection toArray()
        $users = User::orderBy('name')->get();
        $array = $users->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertEquals('Alice', $array[0]['name']);
        $this->assertEquals('Bob', $array[1]['name']);
    }

    public function test_collection_to_json(): void
    {
        // Create test data
        User::create(['name' => 'Charlie', 'age' => 35]);
        User::create(['name' => 'David', 'age' => 40]);

        // Test collection toJson()
        $users = User::orderBy('name')->get();
        $json = $users->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertCount(2, $decoded);
        $this->assertEquals('Charlie', $decoded[0]['name']);
        $this->assertEquals('David', $decoded[1]['name']);
    }

    public function test_to_array_with_relationships(): void
    {
        // Create test data
        $user = User::create(['name' => 'Alice']);
        $user->posts()->create(['title' => 'First Post', 'content' => 'Content']);
        $user->posts()->create(['title' => 'Second Post', 'content' => 'More content']);

        // Load relationships and convert to array
        $user->load('posts');
        $array = $user->toArray();

        $this->assertArrayHasKey('posts', $array);
        $this->assertCount(2, $array['posts']);
        $this->assertEquals('First Post', $array['posts'][0]['title']);
        $this->assertEquals('Second Post', $array['posts'][1]['title']);
    }

    public function test_to_json_with_custom_encoding_options(): void
    {
        // Create test data with special characters
        $user = User::create([
            'name' => 'Alice & Bob',
            'email' => 'alice+bob@example.com',
        ]);

        // Test toJson() with custom encoding options
        $json = $user->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertJson($json);
        $this->assertStringContainsString('Alice & Bob', $json);
        $this->assertStringContainsString('alice+bob@example.com', $json);
    }

    public function test_fresh_retrieves_current_instance_from_database(): void
    {
        // Create test data
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'age' => 25,
        ]);

        // Modify the model in memory
        $user->name = 'Modified Name';
        $user->age = 99;

        // Get fresh instance from database
        $freshUser = $user->fresh();

        // Fresh instance should have original database values
        $this->assertEquals('Alice', $freshUser->name);
        $this->assertEquals(25, $freshUser->age);

        // Original instance should still have modified values
        $this->assertEquals('Modified Name', $user->name);
        $this->assertEquals(99, $user->age);
    }

    public function test_fresh_with_relationships(): void
    {
        // Create test data
        $user = User::create(['name' => 'Alice']);
        $post1 = $user->posts()->create(['title' => 'Post 1', 'content' => 'Content 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2', 'content' => 'Content 2']);

        // Get fresh instance with relationships
        $freshUser = $user->fresh(['posts']);

        $this->assertCount(2, $freshUser->posts);
        $this->assertEquals('Post 1', $freshUser->posts[0]->title);
        $this->assertEquals('Post 2', $freshUser->posts[1]->title);
    }

    public function test_refresh_updates_current_instance(): void
    {
        // Create test data
        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'age' => 30,
        ]);

        // Modify the model in memory
        $user->name = 'Modified Name';
        $user->age = 99;

        // Update the database record separately
        User::where('id', $user->id)->update([
            'name' => 'Database Updated',
            'age' => 35,
        ]);

        // Refresh the current instance
        $user->refresh();

        // Current instance should now have database values
        $this->assertEquals('Database Updated', $user->name);
        $this->assertEquals(35, $user->age);
    }

    public function test_refresh_with_relationships(): void
    {
        // Create test data
        $user = User::create(['name' => 'Charlie']);
        $user->posts()->create(['title' => 'Original Post', 'content' => 'Content']);

        // Load posts
        $user->load('posts');
        $this->assertCount(1, $user->posts);

        // Add another post directly to database
        Post::create([
            'user_id' => $user->id,
            'title' => 'New Post',
            'content' => 'New Content',
        ]);

        // Refresh with relationships
        $user->refresh('posts');

        $this->assertCount(2, $user->posts);

        // Check both posts exist without assuming order
        $titles = $user->posts->pluck('title')->toArray();
        $this->assertContains('Original Post', $titles);
        $this->assertContains('New Post', $titles);
    }

    public function test_fresh_returns_null_for_deleted_model(): void
    {
        // Create and then delete a user
        $user = User::create(['name' => 'Temporary User']);
        $userId = $user->id;

        // Force delete from database (not soft delete)
        User::where('id', $userId)->forceDelete();

        // Fresh should return null for deleted model
        $freshUser = $user->fresh();

        $this->assertNull($freshUser);
    }

    public function test_refresh_throws_exception_for_deleted_model(): void
    {
        // Create and then delete a user
        $user = User::create(['name' => 'Temporary User']);
        $userId = $user->id;

        // Force delete from database (not soft delete)
        User::where('id', $userId)->forceDelete();

        // Refresh should throw ModelNotFoundException for deleted model
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $user->refresh();
    }
}
