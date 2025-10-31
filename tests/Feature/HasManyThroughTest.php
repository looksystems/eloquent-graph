<?php

namespace Tests\Feature;

use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class HasManyThroughTest extends GraphTestCase
{
    public function test_user_can_access_comments_through_posts()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        // Create comments for the posts
        $comment1 = $post1->comments()->create(['content' => 'Comment 1']);
        $comment2 = $post1->comments()->create(['content' => 'Comment 2']);
        $comment3 = $post2->comments()->create(['content' => 'Comment 3']);

        // Access comments through the user
        $comments = $user->comments;

        $this->assertCount(3, $comments);
        $this->assertTrue($comments->contains($comment1));
        $this->assertTrue($comments->contains($comment2));
        $this->assertTrue($comments->contains($comment3));
    }

    public function test_has_many_through_returns_empty_collection_when_no_related_models()
    {
        $user = User::create(['name' => 'John']);

        $comments = $user->comments;

        $this->assertCount(0, $comments);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $comments);
    }

    public function test_has_many_through_with_where_clause()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        // Create comments for the posts
        $post1->comments()->create(['content' => 'Great post']);
        $post1->comments()->create(['content' => 'Bad post']);
        $post2->comments()->create(['content' => 'Great comment']);

        // Filter comments through the user
        $greatComments = $user->comments()->where('content', 'like', '%Great%')->get();

        $this->assertCount(2, $greatComments);
        $this->assertTrue($greatComments->pluck('content')->contains('Great post'));
        $this->assertTrue($greatComments->pluck('content')->contains('Great comment'));
    }

    public function test_has_many_through_eager_loading()
    {
        // Create users
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);

        // Create posts
        $post1 = $user1->posts()->create(['title' => 'Post 1']);
        $post2 = $user2->posts()->create(['title' => 'Post 2']);

        // Create comments
        $post1->comments()->create(['content' => 'Comment 1']);
        $post2->comments()->create(['content' => 'Comment 2']);

        // Eager load comments through posts
        $users = User::with('comments')->get();

        $this->assertTrue($users[0]->relationLoaded('comments'));
        $this->assertTrue($users[1]->relationLoaded('comments'));
        $this->assertCount(1, $users[0]->comments);
        $this->assertCount(1, $users[1]->comments);
    }

    public function test_has_many_through_count()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        // Create comments for the posts
        $post1->comments()->create(['content' => 'Comment 1']);
        $post1->comments()->create(['content' => 'Comment 2']);
        $post2->comments()->create(['content' => 'Comment 3']);

        // Count comments through the user
        $count = $user->comments()->count();

        $this->assertEquals(3, $count);
    }

    public function test_has_many_through_first()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post = $user->posts()->create(['title' => 'Post 1']);

        // Create comment for the post
        $comment = $post->comments()->create(['content' => 'First comment']);

        // Get first comment through the user
        $firstComment = $user->comments()->first();

        $this->assertNotNull($firstComment);
        $this->assertEquals($comment->id, $firstComment->id);
        $this->assertEquals('First comment', $firstComment->content);
    }

    public function test_has_many_through_ordering()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        // Create comments with different content to test ordering
        $post1->comments()->create(['content' => 'Comment C']);
        $post2->comments()->create(['content' => 'Comment A']);
        $post1->comments()->create(['content' => 'Comment B']);

        // Order comments through the user
        $comments = $user->comments()->orderBy('content')->get();

        $this->assertCount(3, $comments);
        $this->assertEquals('Comment A', $comments[0]->content);
        $this->assertEquals('Comment B', $comments[1]->content);
        $this->assertEquals('Comment C', $comments[2]->content);
    }
}
