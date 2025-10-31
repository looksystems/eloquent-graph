<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class EagerLoadingLimitsTest extends GraphTestCase
{
    public function test_eager_loading_with_limit_applies_globally_across_all_parents()
    {
        // Create test data
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        // Create posts for each user
        for ($i = 1; $i <= 3; $i++) {
            $user1->posts()->create(['title' => "User1 Post $i", 'body' => "Body $i"]);
            $user2->posts()->create(['title' => "User2 Post $i", 'body' => "Body $i"]);
            $user3->posts()->create(['title' => "User3 Post $i", 'body' => "Body $i"]);
        }

        // Eager load with limit
        $users = User::with(['posts' => function ($query) {
            $query->limit(2);
        }])->whereIn('id', [$user1->id, $user2->id, $user3->id])->get();

        // Laravel applies limit globally - only 2 posts total across all users
        $totalPosts = $users->sum(fn ($user) => $user->posts->count());
        $this->assertEquals(2, $totalPosts, 'Limit should apply globally, not per parent');

        // At least one user should have posts
        $this->assertTrue($users->contains(fn ($user) => $user->posts->count() > 0));
    }

    public function test_eager_loading_with_offset_skips_records_correctly()
    {
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

        // Create numbered posts
        for ($i = 1; $i <= 5; $i++) {
            $user->posts()->create([
                'title' => "Post $i",
                'body' => "Body $i",
                'created_at' => now()->addMinutes($i),
            ]);
        }

        // Eager load with offset and limit
        $users = User::with(['posts' => function ($query) {
            $query->orderBy('created_at')->skip(2)->take(2);
        }])->where('id', $user->id)->get();

        $loadedUser = $users->first();
        $this->assertCount(2, $loadedUser->posts);

        // Should have posts 3 and 4 (skipped 1 and 2)
        $titles = $loadedUser->posts->pluck('title')->toArray();
        $this->assertContains('Post 3', $titles);
        $this->assertContains('Post 4', $titles);
    }

    public function test_eager_loading_with_limit_and_order_by_respects_order()
    {
        $user = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        // Create posts with different titles for ordering
        $user->posts()->create(['title' => 'Zebra', 'body' => 'Last']);
        $user->posts()->create(['title' => 'Alpha', 'body' => 'First']);
        $user->posts()->create(['title' => 'Beta', 'body' => 'Second']);
        $user->posts()->create(['title' => 'Gamma', 'body' => 'Third']);

        // Eager load with order and limit
        $users = User::with(['posts' => function ($query) {
            $query->orderBy('title', 'asc')->limit(2);
        }])->where('id', $user->id)->get();

        $loadedUser = $users->first();
        $this->assertCount(2, $loadedUser->posts);

        $titles = $loadedUser->posts->pluck('title')->toArray();
        $this->assertEquals(['Alpha', 'Beta'], $titles, 'Should get first 2 posts alphabetically');
    }

    public function test_eager_loading_limit_works_with_has_many_relationships()
    {
        $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        for ($i = 1; $i <= 5; $i++) {
            $user->posts()->create(['title' => "Post $i", 'body' => "Body $i"]);
        }

        $loadedUser = User::with(['posts' => fn ($q) => $q->limit(3)])->find($user->id);

        $this->assertCount(3, $loadedUser->posts);
    }

    public function test_eager_loading_limit_works_with_belongs_to_relationships()
    {
        // Create multiple users
        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $users[] = User::create(['name' => "User $i", 'email' => "user$i@example.com"]);
        }

        // Create posts for different users
        foreach ($users as $user) {
            Post::create(['title' => "Post for {$user->name}", 'body' => 'Test', 'user_id' => $user->id]);
        }

        // Load posts with user relationship and limit
        $posts = Post::with(['user' => function ($query) {
            $query->limit(2); // This doesn't make sense for belongsTo but test it anyway
        }])->get();

        // BelongsTo with limit should still work (though unusual)
        $this->assertGreaterThan(0, $posts->count());
        foreach ($posts as $post) {
            if ($post->user) {
                $this->assertNotNull($post->user->name);
            }
        }
    }

    public function test_multiple_eager_loads_each_respect_their_own_limits()
    {
        $user = User::create(['name' => 'Multi', 'email' => 'multi@example.com']);

        // Create posts and comments
        for ($i = 1; $i <= 5; $i++) {
            $post = $user->posts()->create(['title' => "Post $i", 'body' => "Body $i"]);
            for ($j = 1; $j <= 3; $j++) {
                $post->comments()->create(['body' => "Comment $j on Post $i"]);
            }
        }

        // Load with different limits for each relationship
        $loadedUser = User::with([
            'posts' => fn ($q) => $q->limit(2),
            'posts.comments' => fn ($q) => $q->limit(4), // Global limit on all comments
        ])->find($user->id);

        $this->assertCount(2, $loadedUser->posts, 'Should have 2 posts');

        // Total comments across all loaded posts should be limited
        $totalComments = $loadedUser->posts->sum(fn ($post) => $post->comments->count());
        $this->assertLessThanOrEqual(4, $totalComments, 'Total comments should be limited globally');
    }

    public function test_eager_loading_limit_with_empty_result_set()
    {
        $user = User::create(['name' => 'Empty', 'email' => 'empty@example.com']);
        // No posts created

        $loadedUser = User::with(['posts' => fn ($q) => $q->limit(5)])->find($user->id);

        $this->assertCount(0, $loadedUser->posts);
        $this->assertTrue($loadedUser->posts->isEmpty());
    }

    public function test_eager_loading_limit_with_zero_limit_returns_no_results()
    {
        $user = User::create(['name' => 'Zero', 'email' => 'zero@example.com']);
        $user->posts()->create(['title' => 'Post 1', 'body' => 'Body 1']);
        $user->posts()->create(['title' => 'Post 2', 'body' => 'Body 2']);

        $loadedUser = User::with(['posts' => fn ($q) => $q->limit(0)])->find($user->id);

        $this->assertCount(0, $loadedUser->posts, 'Limit 0 should return no results');
    }

    public function test_nested_eager_loading_with_limits_works_correctly()
    {
        $user = User::create(['name' => 'Nested', 'email' => 'nested@example.com']);

        // Create posts with comments
        for ($i = 1; $i <= 3; $i++) {
            $post = $user->posts()->create(['title' => "Post $i", 'body' => "Body $i"]);
            for ($j = 1; $j <= 4; $j++) {
                $post->comments()->create(['body' => "Comment $j on Post $i"]);
            }
        }

        // Nested eager loading with limits
        $loadedUser = User::with([
            'posts' => function ($query) {
                $query->limit(2); // Load only 2 posts
            },
            'posts.comments' => function ($query) {
                $query->limit(3); // Global limit on comments
            },
        ])->find($user->id);

        $this->assertCount(2, $loadedUser->posts, 'Should load 2 posts');

        // Verify comments are loaded but limited globally
        $totalComments = 0;
        foreach ($loadedUser->posts as $post) {
            $totalComments += $post->comments->count();
        }
        $this->assertLessThanOrEqual(3, $totalComments, 'Comments should be globally limited');
    }

    public function test_eager_loading_limit_with_where_has_constraint()
    {
        // Create users with different numbers of posts
        $user1 = User::create(['name' => 'Has Many', 'email' => 'many@example.com']);
        $user2 = User::create(['name' => 'Has Few', 'email' => 'few@example.com']);

        for ($i = 1; $i <= 5; $i++) {
            $user1->posts()->create(['title' => "User1 Post $i", 'body' => 'Test']);
        }
        $user2->posts()->create(['title' => 'User2 Post 1', 'body' => 'Test']);

        // Load users who have posts, with limited eager loading
        $users = User::whereHas('posts')
            ->with(['posts' => fn ($q) => $q->limit(3)])
            ->get();

        $this->assertCount(2, $users, 'Both users should be loaded');

        // Global limit means 3 posts total across all users
        $totalPosts = $users->sum(fn ($user) => $user->posts->count());
        $this->assertEquals(3, $totalPosts, 'Should have 3 posts total');
    }
}
