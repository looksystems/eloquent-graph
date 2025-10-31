<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class HasManyTest extends GraphTestCase
{
    public function test_user_can_define_has_many_relationship()
    {
        $user = User::create(['name' => 'John']);
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        $posts = $user->posts;

        $this->assertCount(2, $posts);
        $postTitles = $posts->pluck('title')->toArray();
        $this->assertContains('Post 1', $postTitles);
        $this->assertContains('Post 2', $postTitles);
    }

    public function test_user_can_eager_load_has_many()
    {
        $user = User::create(['name' => 'John']);
        $user->posts()->create(['title' => 'Post 1']);

        $loaded = User::with('posts')->find($user->id);

        $this->assertTrue($loaded->relationLoaded('posts'));
        $this->assertCount(1, $loaded->posts);
    }

    public function test_has_many_returns_empty_collection_when_no_related_models()
    {
        $user = User::create(['name' => 'John']);

        $posts = $user->posts;

        $this->assertCount(0, $posts);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $posts);
    }

    public function test_user_can_query_has_many_relationship()
    {
        $user = User::create(['name' => 'John']);
        $user->posts()->create(['title' => 'Published Post', 'content' => 'published']);
        $user->posts()->create(['title' => 'Draft Post', 'content' => 'draft']);

        $publishedPosts = $user->posts()->where('content', 'published')->get();

        $this->assertCount(1, $publishedPosts);
        $this->assertEquals('Published Post', $publishedPosts->first()->title);
    }

    public function test_user_can_count_has_many_relationship()
    {
        $user = User::create(['name' => 'John']);
        $user->posts()->create(['title' => 'Post 1']);
        $user->posts()->create(['title' => 'Post 2']);
        $user->posts()->create(['title' => 'Post 3']);

        $count = $user->posts()->count();

        $this->assertEquals(3, $count);
    }

    public function test_with_count_adds_relationship_count_to_parent_model()
    {
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);

        $user1->posts()->create(['title' => 'Post 1']);
        $user1->posts()->create(['title' => 'Post 2']);
        $user2->posts()->create(['title' => 'Post 3']);

        $users = User::withCount('posts')->whereIn('id', [$user1->id, $user2->id])->get();

        $john = $users->firstWhere('name', 'John');
        $jane = $users->firstWhere('name', 'Jane');

        $this->assertEquals(2, $john->posts_count);
        $this->assertEquals(1, $jane->posts_count);
        $this->assertIsInt($john->posts_count);
    }

    public function test_has_filters_parents_that_have_at_least_n_related()
    {
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);
        $user3 = User::create(['name' => 'Bob']);

        $user1->posts()->create(['title' => 'Post 1']);
        $user1->posts()->create(['title' => 'Post 2']);
        $user2->posts()->create(['title' => 'Post 3']);
        // User3 has no posts

        // Users with at least one post
        $usersWithPosts = User::has('posts')->get();
        $this->assertCount(2, $usersWithPosts);
        $this->assertContains($user1->id, $usersWithPosts->pluck('id'));
        $this->assertContains($user2->id, $usersWithPosts->pluck('id'));

        // Users with at least 2 posts
        $usersWithTwoPosts = User::has('posts', '>=', 2)->get();
        $this->assertCount(1, $usersWithTwoPosts);
        $this->assertEquals($user1->id, $usersWithTwoPosts->first()->id);
    }

    public function test_doesnt_have_filters_parents_without_relationships()
    {
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);
        $user3 = User::create(['name' => 'Bob']);

        $user1->posts()->create(['title' => 'Post 1']);
        $user2->posts()->create(['title' => 'Post 2']);
        // User3 has no posts

        $usersWithoutPosts = User::doesntHave('posts')->get();

        $this->assertCount(1, $usersWithoutPosts);
        $this->assertEquals($user3->id, $usersWithoutPosts->first()->id);
        $this->assertEquals('Bob', $usersWithoutPosts->first()->name);
    }

    public function test_where_has_filters_parents_by_relationship_constraints()
    {
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);

        $user1->posts()->create(['title' => 'Laravel Tutorial', 'content' => 'published']);
        $user1->posts()->create(['title' => 'PHP Guide', 'content' => 'draft']);
        $user2->posts()->create(['title' => 'Neo4j Basics', 'content' => 'draft']);

        // Users with published posts
        $usersWithPublished = User::whereHas('posts', function ($query) {
            $query->where('content', 'published');
        })->get();

        $this->assertCount(1, $usersWithPublished);
        $this->assertEquals($user1->id, $usersWithPublished->first()->id);

        // Users with draft posts containing "Neo4j"
        $usersWithNeo4jDrafts = User::whereHas('posts', function ($query) {
            $query->where('content', 'draft')
                ->where('title', 'CONTAINS', 'Neo4j');
        })->get();

        $this->assertCount(1, $usersWithNeo4jDrafts);
        $this->assertEquals($user2->id, $usersWithNeo4jDrafts->first()->id);
    }

    public function test_update_through_relationship_updates_related_models()
    {
        $user = User::create(['name' => 'John']);
        $post1 = $user->posts()->create(['title' => 'Post 1', 'content' => 'original']);
        $post2 = $user->posts()->create(['title' => 'Post 2', 'content' => 'original']);

        // Update all posts for this user
        $updated = $user->posts()->update(['content' => 'updated']);

        $this->assertEquals(2, $updated);

        // Verify updates
        $freshPost1 = \Tests\Models\Post::find($post1->id);
        $freshPost2 = \Tests\Models\Post::find($post2->id);

        $this->assertEquals('updated', $freshPost1->content);
        $this->assertEquals('updated', $freshPost2->content);
        $this->assertEquals('Post 1', $freshPost1->title); // Title unchanged
    }

    public function test_delete_through_relationship_removes_related_models()
    {
        $user = User::create(['name' => 'John']);
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);
        $postIds = [$post1->id, $post2->id];

        // Delete all posts for this user
        $deleted = $user->posts()->delete();

        $this->assertEquals(2, $deleted);

        // Verify deletions
        foreach ($postIds as $postId) {
            $this->assertNull(\Tests\Models\Post::find($postId));
        }

        // User should still exist
        $this->assertNotNull(User::find($user->id));
    }

    public function test_create_through_relationship_sets_foreign_key_automatically()
    {
        $user = User::create(['name' => 'John']);

        // Create post through relationship (no need to specify user_id)
        $post = $user->posts()->create(['title' => 'Auto FK Post']);

        $this->assertNotNull($post->user_id);
        $this->assertEquals($user->id, $post->user_id);

        // Verify relationship works
        $this->assertEquals($user->id, $post->user->id);
        $this->assertCount(1, $user->fresh()->posts);
    }
}
