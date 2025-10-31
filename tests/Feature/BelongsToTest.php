<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class BelongsToTest extends GraphTestCase
{
    public function test_post_can_belong_to_user()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'My Post', 'user_id' => $user->id]);

        $owner = $post->user;

        $this->assertNotNull($owner);
        $this->assertEquals($user->id, $owner->id);
        $this->assertEquals('John', $owner->name);
    }

    public function test_belongs_to_returns_null_when_no_parent()
    {
        $post = Post::create(['title' => 'Orphan Post']);

        $owner = $post->user;

        $this->assertNull($owner);
    }

    public function test_can_eager_load_belongs_to()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'My Post', 'user_id' => $user->id]);

        $loaded = Post::with('user')->find($post->id);

        $this->assertTrue($loaded->relationLoaded('user'));
        $this->assertNotNull($loaded->user);
        $this->assertEquals('John', $loaded->user->name);
    }

    public function test_can_associate_parent()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'My Post']);

        $post->user()->associate($user);
        $post->save();

        $fresh = Post::find($post->id);
        $this->assertEquals($user->id, $fresh->user_id);
        $this->assertEquals($user->id, $fresh->user->id);
    }

    public function test_can_dissociate_parent()
    {
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'My Post', 'user_id' => $user->id]);

        $post->user()->dissociate();
        $post->save();

        $fresh = Post::find($post->id);
        $this->assertNull($fresh->user_id);
        $this->assertNull($fresh->user);
    }

    public function test_can_query_belongs_to_relationship()
    {
        $user1 = User::create(['name' => 'John', 'age' => 25]);
        $user2 = User::create(['name' => 'Jane', 'age' => 30]);
        $post = Post::create(['title' => 'My Post', 'user_id' => $user1->id]);

        $youngOwner = $post->user()->where('age', '<', 28)->first();
        $oldOwner = $post->user()->where('age', '>', 28)->first();

        $this->assertNotNull($youngOwner);
        $this->assertEquals('John', $youngOwner->name);
        $this->assertNull($oldOwner);
    }

    public function test_with_count_counts_parent_records()
    {
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);

        $post1 = Post::create(['title' => 'Post with User', 'user_id' => $user1->id]);
        $post2 = Post::create(['title' => 'Orphan Post']);

        $posts = Post::withCount('user')->whereIn('id', [$post1->id, $post2->id])->get();

        $withUser = $posts->firstWhere('id', $post1->id);
        $withoutUser = $posts->firstWhere('id', $post2->id);

        // BelongsTo withCount should be 1 if parent exists, 0 if not
        $this->assertEquals(1, $withUser->user_count);
        $this->assertEquals(0, $withoutUser->user_count);
        $this->assertIsInt($withUser->user_count);
    }

    public function test_has_filters_models_with_parent()
    {
        $user = User::create(['name' => 'John']);

        $post1 = Post::create(['title' => 'Post with User', 'user_id' => $user->id]);
        $post2 = Post::create(['title' => 'Orphan Post 1']);
        $post3 = Post::create(['title' => 'Orphan Post 2']);

        // Posts that have a user (owner)
        $postsWithUser = Post::has('user')->get();

        $this->assertCount(1, $postsWithUser);
        $this->assertEquals($post1->id, $postsWithUser->first()->id);
    }

    public function test_where_has_filters_by_parent_constraints()
    {
        $youngUser = User::create(['name' => 'Young John', 'age' => 20]);
        $oldUser = User::create(['name' => 'Old Jane', 'age' => 50]);

        $post1 = Post::create(['title' => 'Young User Post', 'user_id' => $youngUser->id]);
        $post2 = Post::create(['title' => 'Old User Post', 'user_id' => $oldUser->id]);
        $post3 = Post::create(['title' => 'No User Post']);

        // Posts owned by users younger than 30
        $youngUserPosts = Post::whereHas('user', function ($query) {
            $query->where('age', '<', 30);
        })->get();

        $this->assertCount(1, $youngUserPosts);
        $this->assertEquals($post1->id, $youngUserPosts->first()->id);

        // Posts owned by users named Jane
        $janePosts = Post::whereHas('user', function ($query) {
            $query->where('name', 'CONTAINS', 'Jane');
        })->get();

        $this->assertCount(1, $janePosts);
        $this->assertEquals($post2->id, $janePosts->first()->id);
    }

    public function test_update_or_create_finds_or_creates_parent()
    {
        // Test finding existing parent
        $existingUser = User::create(['name' => 'Existing User', 'email' => 'existing@example.com']);
        $post1 = Post::create(['title' => 'Post 1']);

        // Associate with existing user by finding or creating
        $user1 = User::firstOrCreate(
            ['email' => 'existing@example.com'],
            ['name' => 'Should Not Create']
        );

        $post1->user()->associate($user1);
        $post1->save();

        $this->assertEquals($existingUser->id, $post1->fresh()->user_id);
        $this->assertEquals('Existing User', $post1->fresh()->user->name);

        // Test creating new parent
        $post2 = Post::create(['title' => 'Post 2']);

        $user2 = User::firstOrCreate(
            ['email' => 'new@example.com'],
            ['name' => 'New User']
        );

        $post2->user()->associate($user2);
        $post2->save();

        $this->assertNotNull($post2->fresh()->user_id);
        $this->assertEquals('New User', $post2->fresh()->user->name);
        $this->assertEquals('new@example.com', $post2->fresh()->user->email);
    }

    public function test_save_associates_parent_and_updates_foreign_key()
    {
        $post = Post::create(['title' => 'Post without User']);
        $this->assertNull($post->user_id);

        // Create and associate user
        $user = User::create(['name' => 'New Parent']);
        $post->user()->associate($user);

        // Foreign key should be set immediately after associate()
        $this->assertEquals($user->id, $post->user_id);

        // Save to persist the change
        $post->save();

        // Verify persistence
        $freshPost = Post::find($post->id);
        $this->assertEquals($user->id, $freshPost->user_id);
        $this->assertEquals('New Parent', $freshPost->user->name);

        // Test changing parent
        $newUser = User::create(['name' => 'Different Parent']);
        $freshPost->user()->associate($newUser);
        $freshPost->save();

        $finalPost = Post::find($post->id);
        $this->assertEquals($newUser->id, $finalPost->user_id);
        $this->assertEquals('Different Parent', $finalPost->user->name);
    }
}
