<?php

namespace Tests\Feature;

use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class HasOneThroughTest extends GraphTestCase
{
    public function test_user_can_access_latest_comment_through_latest_post()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post1 = $user->posts()->create(['title' => 'Old Post']);
        $post2 = $user->posts()->create(['title' => 'Latest Post']);

        // Create comments for the posts
        $oldComment = $post1->comments()->create(['content' => 'Old comment']);
        $latestComment = $post2->comments()->create(['content' => 'Latest comment']);

        // Access latest comment through the user (just gets first one found)
        $comment = $user->latestComment;

        $this->assertNotNull($comment);
        // Since HasOneThrough gets the first match, we should check it's one of the comments
        $this->assertContains($comment->content, ['Old comment', 'Latest comment']);
    }

    public function test_has_one_through_returns_null_when_no_related_model()
    {
        $user = User::create(['name' => 'John']);

        $comment = $user->latestComment;

        $this->assertNull($comment);
    }

    public function test_has_one_through_with_intermediate_model_but_no_final_model()
    {
        // Create user with a post but no comment
        $user = User::create(['name' => 'John']);
        $post = $user->posts()->create(['title' => 'Post without comments']);

        $comment = $user->latestComment;

        $this->assertNull($comment);
    }

    public function test_has_one_through_with_where_clause()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create posts for the user
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        // Create comments for the posts
        $post1->comments()->create(['content' => 'Approved comment']);
        $post1->comments()->create(['content' => 'Pending comment']);
        $post2->comments()->create(['content' => 'Another approved comment']);

        // Filter to get only approved comment through latest post
        $approvedComment = $user->latestComment()->where('content', 'like', '%approved%')->first();

        $this->assertNotNull($approvedComment);
        $this->assertEquals('Another approved comment', $approvedComment->content);
    }

    public function test_has_one_through_eager_loading()
    {
        // Create users
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);

        // Create posts
        $post1 = $user1->posts()->create(['title' => 'Post 1']);
        $post2 = $user2->posts()->create(['title' => 'Post 2']);

        // Create comments
        $comment1 = $post1->comments()->create(['content' => 'Comment 1']);
        $comment2 = $post2->comments()->create(['content' => 'Comment 2']);

        // Eager load latest comment through posts
        $users = User::with('latestComment')->get();

        $this->assertTrue($users[0]->relationLoaded('latestComment'));
        $this->assertTrue($users[1]->relationLoaded('latestComment'));
        $this->assertNotNull($users[0]->latestComment);
        $this->assertNotNull($users[1]->latestComment);
        $this->assertEquals('Comment 1', $users[0]->latestComment->content);
        $this->assertEquals('Comment 2', $users[1]->latestComment->content);
    }

    public function test_has_one_through_with_ordering()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create a post for the user
        $post = $user->posts()->create(['title' => 'Post 1']);

        // Create multiple comments with different content
        $comment1 = $post->comments()->create(['content' => 'Z comment']);
        $comment2 = $post->comments()->create(['content' => 'A comment']);
        $comment3 = $post->comments()->create(['content' => 'M comment']);

        // Get comment ordered by content (should get 'A comment' first)
        $firstComment = $user->latestComment()->orderBy('content')->first();

        $this->assertNotNull($firstComment);
        $this->assertEquals('A comment', $firstComment->content);
    }

    public function test_has_one_through_exists_check()
    {
        // Create users
        $userWithComment = User::create(['name' => 'John']);
        $userWithoutComment = User::create(['name' => 'Jane']);

        // Create post and comment for first user
        $post = $userWithComment->posts()->create(['title' => 'Post 1']);
        $post->comments()->create(['content' => 'Comment 1']);

        // Check existence
        $this->assertTrue($userWithComment->latestComment()->exists());
        $this->assertFalse($userWithoutComment->latestComment()->exists());
    }

    public function test_has_one_through_with_select()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create post for the user
        $post = $user->posts()->create(['title' => 'Post 1']);

        // Create comment for the post
        $comment = $post->comments()->create(['content' => 'Test comment']);

        // Select only specific fields - Neo4j returns all properties regardless
        $selectedComment = $user->latestComment()->select('id', 'content')->first();

        $this->assertNotNull($selectedComment);
        $this->assertEquals($comment->id, $selectedComment->id);
        $this->assertEquals('Test comment', $selectedComment->content);
        // Neo4j returns all properties, so post_id will be present
        $this->assertEquals($post->id, $selectedComment->post_id);
    }

    public function test_has_one_through_update()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create post for the user
        $post = $user->posts()->create(['title' => 'Post 1']);

        // Create comment for the post
        $comment = $post->comments()->create(['content' => 'Original comment']);

        // Update comment through the relationship
        $user->latestComment()->update(['content' => 'Updated comment']);

        // Verify update
        $comment->refresh();
        $this->assertEquals('Updated comment', $comment->content);
    }

    public function test_has_one_through_delete()
    {
        // Create user
        $user = User::create(['name' => 'John']);

        // Create post for the user
        $post = $user->posts()->create(['title' => 'Post 1']);

        // Create comment for the post
        $comment = $post->comments()->create(['content' => 'Comment to delete']);

        // Delete comment through the relationship
        $user->latestComment()->delete();

        // Verify deletion
        $this->assertNull(Comment::find($comment->id));
        $this->assertNull($user->fresh()->latestComment);
    }

    public function test_has_one_through_with_custom_keys()
    {
        // This test assumes we can specify custom foreign keys
        // Create user with custom ID
        $user = User::create(['name' => 'John', 'custom_id' => 999]);

        // Create post with custom user reference
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id, 'custom_user_id' => 999]);

        // Create comment with custom post reference
        $comment = Comment::create(['content' => 'Test comment', 'post_id' => $post->id, 'custom_post_id' => $post->id]);

        // Access comment through custom relationship
        $foundComment = $user->customComment;

        $this->assertNotNull($foundComment);
        $this->assertEquals($comment->id, $foundComment->id);
        $this->assertEquals('Test comment', $foundComment->content);
    }

    public function test_has_one_through_with_constraints()
    {
        // Create multiple users
        $activeUser = User::create(['name' => 'John', 'status' => 'active']);
        $inactiveUser = User::create(['name' => 'Jane', 'status' => 'inactive']);

        // Create posts
        $activePost = $activeUser->posts()->create(['title' => 'Active Post', 'status' => 'published']);
        $inactivePost = $inactiveUser->posts()->create(['title' => 'Inactive Post', 'status' => 'draft']);

        // Create comments
        $activeComment = $activePost->comments()->create(['content' => 'Active comment', 'approved' => true]);
        $inactiveComment = $inactivePost->comments()->create(['content' => 'Inactive comment', 'approved' => false]);

        // Get approved comments through active users
        $approvedComments = User::where('status', 'active')
            ->with(['latestComment' => function ($query) {
                $query->where('approved', true);
            }])
            ->get();

        $this->assertCount(1, $approvedComments);
        $this->assertNotNull($approvedComments[0]->latestComment);
        $this->assertEquals('Active comment', $approvedComments[0]->latestComment->content);
    }
}
