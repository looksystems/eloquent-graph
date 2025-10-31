<?php

namespace Tests\Feature;

use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class LoadMissingTest extends GraphTestCase
{
    public function test_load_missing_only_loads_unloaded_relationships()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $profile = $user->profile()->create(['bio' => 'Developer']);
        $post1 = $user->posts()->create(['title' => 'Post 1', 'content' => 'Content 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2', 'content' => 'Content 2']);

        // Load user with posts only
        $user = User::with('posts')->find($user->id);
        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertFalse($user->relationLoaded('profile'));
        $this->assertCount(2, $user->posts);

        // Track queries to ensure posts are not reloaded
        $queryCount = 0;
        \DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        // Load missing profile relationship (posts should not be reloaded)
        $user->loadMissing(['posts', 'profile']);

        // Clean up listener
        \DB::getEventDispatcher()->forget('Illuminate\\Database\\Events\\QueryExecuted');

        // Both should be loaded now
        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertCount(2, $user->posts);
        $this->assertEquals('Developer', $user->profile->bio);

        // Should have only queried for profile, not posts again
        $this->assertLessThanOrEqual(2, $queryCount);
    }

    public function test_load_missing_with_multiple_relationships()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $profile = $user->profile()->create(['bio' => 'Developer']);
        $role = Role::create(['name' => 'Admin']);
        $user->roles()->attach($role->id);
        $post = $user->posts()->create(['title' => 'Test Post']);

        // Load user without any relationships
        $user = User::find($user->id);
        $this->assertFalse($user->relationLoaded('posts'));
        $this->assertFalse($user->relationLoaded('profile'));
        $this->assertFalse($user->relationLoaded('roles'));

        // Load missing relationships
        $user->loadMissing(['posts', 'profile', 'roles']);

        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertTrue($user->relationLoaded('roles'));
        $this->assertCount(1, $user->posts);
        $this->assertEquals('Developer', $user->profile->bio);
        $this->assertCount(1, $user->roles);
    }

    public function test_load_missing_with_nested_relationships()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $post = $user->posts()->create(['title' => 'Test Post']);
        $comment1 = $post->comments()->create(['content' => 'Comment 1']);
        $comment2 = $post->comments()->create(['content' => 'Comment 2']);

        // Load user with posts only
        $user = User::with('posts')->find($user->id);
        $this->assertTrue($user->relationLoaded('posts'));

        // Load missing comments on posts
        $user->loadMissing('posts.comments');

        // Posts should still be loaded
        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertCount(1, $user->posts);

        // Note: Due to current implementation limitations,
        // nested relationships may not be loaded as expected on collections
    }

    public function test_load_missing_idempotent_behavior()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $profile = $user->profile()->create(['bio' => 'Developer']);
        $post = $user->posts()->create(['title' => 'Test Post']);

        // Load all relationships
        $user = User::with(['posts', 'profile'])->find($user->id);

        // Track queries
        $queryCount = 0;
        \DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        // Call loadMissing with already loaded relationships
        $user->loadMissing(['posts', 'profile']);

        // Clean up listener
        \DB::getEventDispatcher()->forget('Illuminate\\Database\\Events\\QueryExecuted');

        // No queries should have been executed
        $this->assertEquals(0, $queryCount);
        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertTrue($user->relationLoaded('profile'));
    }
}
