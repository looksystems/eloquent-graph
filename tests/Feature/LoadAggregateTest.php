<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class LoadAggregateTest extends GraphTestCase
{
    public function test_load_sum_aggregates_numeric_values()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Create posts with different view counts
        $user1->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user1->posts()->create(['title' => 'Post 2', 'views' => 200]);
        $user1->posts()->create(['title' => 'Post 3', 'views' => 300]);

        $user2->posts()->create(['title' => 'Post 4', 'views' => 50]);
        $user2->posts()->create(['title' => 'Post 5', 'views' => 150]);

        // Get users without aggregates
        $users = User::whereIn('id', [$user1->id, $user2->id])->get();

        // Load sum aggregate
        $users->loadSum('posts', 'views');

        $loadedUser1 = $users->find($user1->id);
        $loadedUser2 = $users->find($user2->id);

        $this->assertEquals(600, $loadedUser1->posts_sum_views);
        $this->assertEquals(200, $loadedUser2->posts_sum_views);
    }

    public function test_load_avg_calculates_average_values()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        $user->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user->posts()->create(['title' => 'Post 2', 'views' => 200]);
        $user->posts()->create(['title' => 'Post 3', 'views' => 300]);

        $user->loadAvg('posts', 'views');

        $this->assertEquals(200, $user->posts_avg_views);
    }

    public function test_load_min_finds_minimum_value()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        $user->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user->posts()->create(['title' => 'Post 2', 'views' => 50]);
        $user->posts()->create(['title' => 'Post 3', 'views' => 300]);

        $user->loadMin('posts', 'views');

        $this->assertEquals(50, $user->posts_min_views);
    }

    public function test_load_max_finds_maximum_value()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        $user->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user->posts()->create(['title' => 'Post 2', 'views' => 500]);
        $user->posts()->create(['title' => 'Post 3', 'views' => 300]);

        $user->loadMax('posts', 'views');

        $this->assertEquals(500, $user->posts_max_views);
    }

    public function test_load_multiple_aggregates_simultaneously()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Create posts with different view counts
        $user1->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user1->posts()->create(['title' => 'Post 2', 'views' => 200]);
        $user1->posts()->create(['title' => 'Post 3', 'views' => 300]);

        $user2->posts()->create(['title' => 'Post 4', 'views' => 50]);
        $user2->posts()->create(['title' => 'Post 5', 'views' => 150]);

        // Get users without aggregates
        $users = User::whereIn('id', [$user1->id, $user2->id])->get();

        // Load various aggregates
        $users->loadSum('posts', 'views');
        $users->loadAvg('posts', 'views');
        $users->loadMin('posts', 'views');
        $users->loadMax('posts', 'views');

        $loadedUser1 = $users->find($user1->id);
        $loadedUser2 = $users->find($user2->id);

        // Check User 1 aggregates
        $this->assertEquals(600, $loadedUser1->posts_sum_views);
        $this->assertEquals(200, $loadedUser1->posts_avg_views);
        $this->assertEquals(100, $loadedUser1->posts_min_views);
        $this->assertEquals(300, $loadedUser1->posts_max_views);

        // Check User 2 aggregates
        $this->assertEquals(200, $loadedUser2->posts_sum_views);
        $this->assertEquals(100, $loadedUser2->posts_avg_views);
        $this->assertEquals(50, $loadedUser2->posts_min_views);
        $this->assertEquals(150, $loadedUser2->posts_max_views);
    }

    public function test_load_aggregate_with_custom_function()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $user->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user->posts()->create(['title' => 'Post 2', 'views' => 200]);

        // Load with custom aggregate function
        $user->loadAggregate('posts', 'views', 'sum');
        $this->assertEquals(300, $user->posts_sum_views);

        // Load count aggregate
        $user->loadAggregate('posts', '*', 'count');
        $this->assertEquals(2, $user->posts_count);
    }

    public function test_load_aggregate_with_no_related_records()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        // Load aggregates when user has no posts
        $user->loadSum('posts', 'views');
        $user->loadAvg('posts', 'views');
        $user->loadMin('posts', 'views');
        $user->loadMax('posts', 'views');

        $this->assertEquals(0, $user->posts_sum_views);
        // Neo4j returns 0 for aggregates on empty sets, not null
        $this->assertEquals(0, $user->posts_avg_views);
        $this->assertEquals(0, $user->posts_min_views);
        $this->assertEquals(0, $user->posts_max_views);
    }

    public function test_load_count_aggregate()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $user->posts()->create(['title' => 'Post 1']);
        $user->posts()->create(['title' => 'Post 2']);
        $user->posts()->create(['title' => 'Post 3']);

        $user->loadCount('posts');

        $this->assertEquals(3, $user->posts_count);
    }

    public function test_load_aggregate_on_collection()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        $user1->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user2->posts()->create(['title' => 'Post 2', 'views' => 200]);
        $user2->posts()->create(['title' => 'Post 3', 'views' => 300]);

        // Get all users
        $users = User::all();

        // Load count on collection
        $users->loadCount('posts');

        $this->assertEquals(1, $users->find($user1->id)->posts_count);
        $this->assertEquals(2, $users->find($user2->id)->posts_count);
        $this->assertEquals(0, $users->find($user3->id)->posts_count);
    }
}
