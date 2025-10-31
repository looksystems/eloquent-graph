<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class CursorTest extends GraphTestCase
{
    public function test_cursor_returns_lazy_collection()
    {
        User::create(['name' => 'User 1', 'email' => 'user1@test.com']);
        User::create(['name' => 'User 2', 'email' => 'user2@test.com']);
        User::create(['name' => 'User 3', 'email' => 'user3@test.com']);

        $cursor = User::cursor();

        $this->assertInstanceOf(\Illuminate\Support\LazyCollection::class, $cursor);
        $this->assertCount(3, $cursor);
    }

    public function test_cursor_processes_records_one_at_a_time()
    {
        // Create test users
        for ($i = 1; $i <= 10; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@test.com"]);
        }

        $processedCount = 0;
        foreach (User::cursor() as $user) {
            $processedCount++;
            $this->assertInstanceOf(User::class, $user);
            $this->assertStringStartsWith('User', $user->name);
        }

        $this->assertEquals(10, $processedCount);
    }

    public function test_cursor_with_where_conditions()
    {
        for ($i = 1; $i <= 10; $i++) {
            User::create([
                'name' => "User $i",
                'email' => "user$i@test.com",
                'active' => $i % 2 === 0, // Even numbers are active
            ]);
        }

        $activeUsers = [];
        foreach (User::where('active', true)->cursor() as $user) {
            $activeUsers[] = $user->name;
        }

        $this->assertCount(5, $activeUsers);
        foreach ($activeUsers as $name) {
            $number = (int) str_replace('User ', '', $name);
            $this->assertEquals(0, $number % 2); // Should all be even
        }
    }

    public function test_cursor_with_order_by()
    {
        User::create(['name' => 'Charlie', 'email' => 'charlie@test.com']);
        User::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com']);

        $names = [];
        foreach (User::orderBy('name')->cursor() as $user) {
            $names[] = $user->name;
        }

        $this->assertEquals(['Alice', 'Bob', 'Charlie'], $names);
    }

    public function test_cursor_with_early_termination()
    {
        for ($i = 1; $i <= 100; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@test.com"]);
        }

        $collected = [];
        foreach (User::cursor() as $user) {
            $collected[] = $user->name;
            if (count($collected) >= 5) {
                break; // Early termination
            }
        }

        $this->assertCount(5, $collected);
    }

    public function test_cursor_with_empty_results()
    {
        // No users created
        $count = 0;
        foreach (User::where('name', 'NonExistent')->cursor() as $user) {
            $count++;
        }

        $this->assertEquals(0, $count);
    }

    public function test_cursor_with_relationships()
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com']);
        for ($i = 1; $i <= 5; $i++) {
            Post::create([
                'title' => "Post $i",
                'content' => "Content $i",
                'user_id' => $user->id,
            ]);
        }

        $posts = [];
        foreach ($user->posts()->cursor() as $post) {
            $posts[] = $post->title;
        }

        $this->assertCount(5, $posts);
        $this->assertContains('Post 1', $posts);
        $this->assertContains('Post 5', $posts);
    }

    public function test_cursor_can_be_used_with_collection_methods()
    {
        for ($i = 1; $i <= 10; $i++) {
            User::create([
                'name' => "User $i",
                'email' => "user$i@test.com",
                'score' => $i * 10,
            ]);
        }

        // Using collection methods on cursor
        $highScoreUsers = User::cursor()
            ->filter(fn ($user) => $user->score > 50)
            ->map(fn ($user) => $user->name)
            ->toArray();

        $this->assertCount(5, $highScoreUsers);
        $this->assertContains('User 6', $highScoreUsers);
        $this->assertContains('User 10', $highScoreUsers);
    }

    public function test_cursor_maintains_model_attributes()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'bio' => 'This is a test bio',
            'score' => 100,
        ]);

        // Verify the user was created with the bio
        $createdUser = User::find($user->id);
        $this->assertEquals('This is a test bio', $createdUser->bio, 'Bio should be saved');

        foreach (User::cursor() as $fetchedUser) {
            $this->assertEquals('Test User', $fetchedUser->name);
            $this->assertEquals('test@example.com', $fetchedUser->email);
            $this->assertEquals('This is a test bio', $fetchedUser->bio);
            $this->assertEquals(100, $fetchedUser->score);
            $this->assertEquals($user->id, $fetchedUser->id);
        }
    }

    public function test_cursor_with_limit()
    {
        for ($i = 1; $i <= 20; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@test.com"]);
        }

        $users = [];
        foreach (User::limit(5)->cursor() as $user) {
            $users[] = $user->name;
        }

        $this->assertCount(5, $users);
    }

    public function test_cursor_vs_lazy_with_chunk_size()
    {
        for ($i = 1; $i <= 10; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@test.com"]);
        }

        // cursor() should process one at a time (chunk size = 1)
        $cursorCount = 0;
        foreach (User::cursor() as $user) {
            $cursorCount++;
        }

        // lazy() with chunk size 3
        $lazyCount = 0;
        foreach (User::lazy(3) as $user) {
            $lazyCount++;
        }

        $this->assertEquals(10, $cursorCount);
        $this->assertEquals(10, $lazyCount);
    }
}
