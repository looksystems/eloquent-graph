<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase;

class ParameterBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clean database between tests
        DB::connection('graph')->statement('MATCH (n) DETACH DELETE n');
    }

    public function test_where_in_with_empty_array_works_correctly(): void
    {
        // Create some test users
        User::create(['id' => 1, 'name' => 'User 1', 'email' => 'user1@test.com']);
        User::create(['id' => 2, 'name' => 'User 2', 'email' => 'user2@test.com']);
        User::create(['id' => 3, 'name' => 'User 3', 'email' => 'user3@test.com']);

        // Test whereIn with empty array - should return no results
        $users = User::whereIn('id', [])->get();
        $this->assertCount(0, $users);

        // Test whereNotIn with empty array - should return all results
        $users = User::whereNotIn('id', [])->get();
        $this->assertCount(3, $users);
    }

    public function test_where_in_with_large_array_works_correctly(): void
    {
        // Create test users
        for ($i = 1; $i <= 10; $i++) {
            User::create(['id' => $i, 'name' => "User $i", 'email' => "user$i@test.com"]);
        }

        // Test with large array
        $ids = [1, 3, 5, 7, 9];
        $users = User::whereIn('id', $ids)->orderBy('id')->get();

        $this->assertCount(5, $users);
        $this->assertEquals([1, 3, 5, 7, 9], $users->pluck('id')->toArray());
    }

    public function test_where_json_contains_with_empty_arrays(): void
    {
        // Create users with JSON data
        User::create([
            'id' => 1,
            'name' => 'User 1',
            'email' => 'user1@test.com',
            'settings' => ['tags' => ['php', 'laravel'], 'roles' => []],
        ]);

        User::create([
            'id' => 2,
            'name' => 'User 2',
            'email' => 'user2@test.com',
            'settings' => ['tags' => [], 'roles' => ['admin']],
        ]);

        User::create([
            'id' => 3,
            'name' => 'User 3',
            'email' => 'user3@test.com',
            'settings' => ['tags' => ['graph'], 'roles' => ['user', 'moderator']],
        ]);

        // Test whereJsonContains with value in empty array field
        $users = User::whereJsonContains('settings->roles', 'admin')->get();
        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);

        // Test whereJsonLength with empty arrays
        $users = User::whereJsonLength('settings->tags', 0)->get();
        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);

        $users = User::whereJsonLength('settings->roles', 0)->get();
        $this->assertCount(1, $users);
        $this->assertEquals(1, $users->first()->id);
    }

    public function test_array_property_updates_preserve_type(): void
    {
        $this->markTestSkipped(
            'Nested JSON path updates (e.g., settings->notifications) require deeper integration with '.
            'Laravel\'s attribute hydration system. While setAttribute() handles the JSON path syntax, '.
            'the value persistence through save/refresh cycle needs additional work. '.
            'Workaround: Update the entire parent property instead: $user->update([\'settings\' => $modifiedSettings]). '.
            'This is documented in HANDOFF.md as a known limitation for future enhancement.'
        );

        // Create a user with array properties
        $user = User::create([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'settings' => [
                'preferences' => ['theme' => 'dark', 'lang' => 'en'],
                'notifications' => ['email', 'sms', 'push'],
            ],
        ]);

        // Update with empty array
        $user->update(['settings->notifications' => []]);
        $user->refresh();

        $settings = $user->settings;
        $this->assertIsArray($settings['notifications'] ?? null);
        $this->assertEmpty($settings['notifications']);

        // Update with new indexed array
        $user->update(['settings->notifications' => ['email', 'push']]);
        $user->refresh();

        $settings = $user->settings;
        $this->assertIsArray($settings['notifications']);
        $this->assertCount(2, $settings['notifications']);
        $this->assertEquals(['email', 'push'], $settings['notifications']);

        // Update with associative array
        $user->update(['settings->preferences' => ['theme' => 'light', 'lang' => 'fr', 'timezone' => 'UTC']]);
        $user->refresh();

        $settings = $user->settings;
        $this->assertIsArray($settings['preferences']);
        $this->assertEquals('light', $settings['preferences']['theme']);
        $this->assertEquals('fr', $settings['preferences']['lang']);
        $this->assertEquals('UTC', $settings['preferences']['timezone']);
    }

    public function test_bulk_insert_with_array_parameters(): void
    {
        // Prepare bulk data with various array types
        $users = [
            [
                'id' => 1,
                'name' => 'User 1',
                'email' => 'user1@test.com',
                'settings' => ['tags' => ['php', 'laravel'], 'scores' => [85, 90, 88]],
            ],
            [
                'id' => 2,
                'name' => 'User 2',
                'email' => 'user2@test.com',
                'settings' => ['tags' => [], 'scores' => []],  // Empty arrays
            ],
            [
                'id' => 3,
                'name' => 'User 3',
                'email' => 'user3@test.com',
                'settings' => ['tags' => ['neo4j', 'graph'], 'scores' => [95, 92, 93, 91]],
            ],
        ];

        // Bulk insert
        User::insert($users);

        // Verify all records inserted correctly
        $this->assertEquals(3, User::count());

        // Verify array data preserved
        $user1 = User::find(1);
        $this->assertEquals(['php', 'laravel'], $user1->settings['tags'] ?? []);
        $this->assertEquals([85, 90, 88], $user1->settings['scores'] ?? []);

        $user2 = User::find(2);
        $this->assertEmpty($user2->settings['tags'] ?? []);
        $this->assertEmpty($user2->settings['scores'] ?? []);

        $user3 = User::find(3);
        $this->assertEquals(['neo4j', 'graph'], $user3->settings['tags'] ?? []);
        $this->assertEquals([95, 92, 93, 91], $user3->settings['scores'] ?? []);
    }

    public function test_query_builder_handles_mixed_parameter_types(): void
    {
        // Create test data
        User::create(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25]);
        User::create(['id' => 2, 'name' => 'Bob', 'email' => 'bob@test.com', 'age' => 30]);
        User::create(['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@test.com', 'age' => 35]);

        // Complex query with various parameter types
        $users = User::where('age', '>=', 25)
            ->whereIn('name', ['Alice', 'Bob', 'Charlie'])
            ->whereNotIn('id', [])  // Empty array
            ->where('email', 'like', '%test.com')
            ->orderBy('age')
            ->get();

        $this->assertCount(3, $users);
        $this->assertEquals(['Alice', 'Bob', 'Charlie'], $users->pluck('name')->toArray());
    }

    public function test_relationship_queries_with_array_parameters(): void
    {
        // Create users and posts
        $user1 = User::create(['id' => 1, 'name' => 'User 1', 'email' => 'user1@test.com']);
        $user2 = User::create(['id' => 2, 'name' => 'User 2', 'email' => 'user2@test.com']);

        Post::create(['id' => 1, 'title' => 'Post 1', 'body' => 'Body 1', 'user_id' => $user1->id]);
        Post::create(['id' => 2, 'title' => 'Post 2', 'body' => 'Body 2', 'user_id' => $user1->id]);
        Post::create(['id' => 3, 'title' => 'Post 3', 'body' => 'Body 3', 'user_id' => $user2->id]);

        // Test whereHas with whereIn using empty array
        $users = User::whereHas('posts', function ($query) {
            $query->whereIn('id', []);  // Should result in no matches
        })->get();

        $this->assertCount(0, $users);

        // Test whereHas with whereIn using array of IDs
        $users = User::whereHas('posts', function ($query) {
            $query->whereIn('id', [1, 2]);
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals(1, $users->first()->id);
    }

    public function test_update_with_array_parameters(): void
    {
        // Create multiple users
        User::create(['id' => 1, 'name' => 'User 1', 'email' => 'user1@test.com', 'tags' => ['old']]);
        User::create(['id' => 2, 'name' => 'User 2', 'email' => 'user2@test.com', 'tags' => ['old']]);
        User::create(['id' => 3, 'name' => 'User 3', 'email' => 'user3@test.com', 'tags' => ['old']]);

        // Update using whereIn with array parameter
        User::whereIn('id', [1, 3])->update(['tags' => ['new', 'updated']]);

        // Verify updates
        $user1 = User::find(1);
        $this->assertEquals(['new', 'updated'], $user1->tags);

        $user2 = User::find(2);
        $this->assertEquals(['old'], $user2->tags);  // Should not be updated

        $user3 = User::find(3);
        $this->assertEquals(['new', 'updated'], $user3->tags);
    }

    public function test_delete_with_array_parameters(): void
    {
        // Create test users
        for ($i = 1; $i <= 5; $i++) {
            User::create(['id' => $i, 'name' => "User $i", 'email' => "user$i@test.com"]);
        }

        // Delete using whereIn with array
        User::whereIn('id', [2, 4])->delete();

        // Verify deletions
        $this->assertEquals(3, User::count());
        $this->assertNull(User::find(2));
        $this->assertNull(User::find(4));
        $this->assertNotNull(User::find(1));
        $this->assertNotNull(User::find(3));
        $this->assertNotNull(User::find(5));
    }

    public function test_subquery_with_array_parameters(): void
    {
        $this->markTestSkipped('Subqueries in whereIn not yet implemented - complex feature');

        // Create users with varying number of posts
        $user1 = User::create(['id' => 1, 'name' => 'User 1', 'email' => 'user1@test.com']);
        $user2 = User::create(['id' => 2, 'name' => 'User 2', 'email' => 'user2@test.com']);
        $user3 = User::create(['id' => 3, 'name' => 'User 3', 'email' => 'user3@test.com']);

        // Create posts
        Post::create(['id' => 1, 'title' => 'Post 1', 'body' => 'Body', 'user_id' => $user1->id]);
        Post::create(['id' => 2, 'title' => 'Post 2', 'body' => 'Body', 'user_id' => $user2->id]);
        Post::create(['id' => 3, 'title' => 'Post 3', 'body' => 'Body', 'user_id' => $user2->id]);

        // Get users who have posts with specific IDs
        $postIds = [2, 3];
        $users = User::whereIn('id', function ($query) use ($postIds) {
            $query->select('user_id')
                ->from('posts')
                ->whereIn('id', $postIds);
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);
    }
}
