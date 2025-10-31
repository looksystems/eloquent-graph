<?php

namespace Tests\Feature;

use Tests\Models\Comment;
use Tests\Models\NativeComment;
use Tests\Models\NativePost;
use Tests\Models\NativeUser;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class NativeHasManyThroughTest extends GraphTestCase
{
    public function test_native_has_many_through_uses_edge_traversal()
    {
        // Create models with native relationships enabled
        $user = NativeUser::create(['name' => 'John']);
        $post = NativePost::create(['title' => 'Test Post']);
        $comment = NativeComment::create(['content' => 'Test Comment']);

        // Establish relationships using native edges
        $user->posts()->save($post);
        $post->comments()->save($comment);

        // Access comments through the user using native edge traversal
        $comments = $user->comments;

        // Verify the relationship works
        $this->assertCount(1, $comments);
        $this->assertEquals($comment->id, $comments->first()->id);
        $this->assertEquals('Test Comment', $comments->first()->content);

        // Verify that native edge traversal was used by checking the generated Cypher
        // The query should use edge patterns like (user)-[:HAS_POSTS]->(post)-[:HAS_COMMENTS]->(comment)
        $connection = $user->getConnection();

        // Check that edges were created with NATIVE prefix (based on Neo4jHasMany implementation)
        $userPostEdge = $connection->select(
            'MATCH (u:users)-[r:HAS_NATIVE_POSTS]->(p:posts) WHERE u.id = $userId AND p.id = $postId RETURN r',
            ['userId' => $user->id, 'postId' => $post->id]
        );
        $this->assertNotEmpty($userPostEdge);

        $postCommentEdge = $connection->select(
            'MATCH (p:posts)-[r:HAS_NATIVE_COMMENTS]->(c:comments) WHERE p.id = $postId AND c.id = $commentId RETURN r',
            ['postId' => $post->id, 'commentId' => $comment->id]
        );
        $this->assertNotEmpty($postCommentEdge);
    }

    public function test_native_has_many_through_with_where_clause_uses_edge_traversal()
    {
        $user = NativeUser::create(['name' => 'John']);

        $post1 = NativePost::create(['title' => 'Post 1']);
        $post2 = NativePost::create(['title' => 'Post 2']);

        $user->posts()->saveMany([$post1, $post2]);

        $comment1 = NativeComment::create(['content' => 'Great comment']);
        $comment2 = NativeComment::create(['content' => 'Bad comment']);
        $comment3 = NativeComment::create(['content' => 'Great feedback']);

        $post1->comments()->saveMany([$comment1, $comment2]);
        $post2->comments()->save($comment3);

        // Filter using edge traversal
        $greatComments = $user->comments()->where('content', 'like', '%Great%')->get();

        $this->assertCount(2, $greatComments);
        $this->assertTrue($greatComments->pluck('content')->contains('Great comment'));
        $this->assertTrue($greatComments->pluck('content')->contains('Great feedback'));
    }

    public function test_native_has_many_through_count_uses_edge_traversal()
    {
        $user = NativeUser::create(['name' => 'John']);

        $post1 = NativePost::create(['title' => 'Post 1']);
        $post2 = NativePost::create(['title' => 'Post 2']);

        $user->posts()->saveMany([$post1, $post2]);

        $post1->comments()->create(['content' => 'Comment 1']);
        $post1->comments()->create(['content' => 'Comment 2']);
        $post2->comments()->create(['content' => 'Comment 3']);

        $count = $user->comments()->count();

        $this->assertEquals(3, $count);
    }

    public function test_native_has_many_through_eager_loading_uses_edge_traversal()
    {
        $user1 = NativeUser::create(['name' => 'John']);
        $user2 = NativeUser::create(['name' => 'Jane']);

        $post1 = NativePost::create(['title' => 'Post 1']);
        $post2 = NativePost::create(['title' => 'Post 2']);

        $user1->posts()->save($post1);
        $user2->posts()->save($post2);

        $comment1 = NativeComment::create(['content' => 'Comment 1']);
        $comment2 = NativeComment::create(['content' => 'Comment 2']);

        $post1->comments()->save($comment1);
        $post2->comments()->save($comment2);

        // Eager load using edge traversal
        $users = NativeUser::with('comments')->get();

        $this->assertTrue($users[0]->relationLoaded('comments'));
        $this->assertTrue($users[1]->relationLoaded('comments'));
        $this->assertCount(1, $users[0]->comments);
        $this->assertCount(1, $users[1]->comments);
    }

    public function test_native_has_many_through_first_uses_edge_traversal()
    {
        $user = NativeUser::create(['name' => 'John']);
        $post = NativePost::create(['title' => 'Post 1']);

        $user->posts()->save($post);

        $comment = NativeComment::create(['content' => 'First comment']);
        $post->comments()->save($comment);

        $firstComment = $user->comments()->first();

        $this->assertNotNull($firstComment);
        $this->assertEquals($comment->id, $firstComment->id);
        $this->assertEquals('First comment', $firstComment->content);
    }

    public function test_native_has_many_through_ordering_uses_edge_traversal()
    {
        $user = NativeUser::create(['name' => 'John']);

        $post1 = NativePost::create(['title' => 'Post 1']);
        $post2 = NativePost::create(['title' => 'Post 2']);

        $user->posts()->saveMany([$post1, $post2]);

        $commentC = NativeComment::create(['content' => 'Comment C']);
        $commentA = NativeComment::create(['content' => 'Comment A']);
        $commentB = NativeComment::create(['content' => 'Comment B']);

        $post1->comments()->save($commentC);
        $post2->comments()->save($commentA);
        $post1->comments()->save($commentB);

        $comments = $user->comments()->orderBy('content')->get();

        $this->assertCount(3, $comments);
        $this->assertEquals('Comment A', $comments[0]->content);
        $this->assertEquals('Comment B', $comments[1]->content);
        $this->assertEquals('Comment C', $comments[2]->content);
    }

    public function test_backward_compatibility_with_foreign_key_has_many_through()
    {
        // Test that models without $useNativeRelationships still work
        $user = User::create(['name' => 'John']);
        $post = Post::create(['title' => 'Test Post']);
        $comment = Comment::create(['content' => 'Test Comment']);

        // These should use foreign key relationships
        $user->posts()->save($post);
        $post->comments()->save($comment);

        // Access should still work through foreign keys
        $comments = $user->comments;

        $this->assertCount(1, $comments);
        $this->assertEquals($comment->id, $comments->first()->id);

        // Should have foreign keys set
        $this->assertEquals($user->id, $post->user_id);
        $this->assertEquals($post->id, $comment->post_id);
    }

    public function test_custom_edge_types_for_has_many_through()
    {
        $user = NativeUser::create(['name' => 'John']);
        $post = NativePost::create(['title' => 'Test Post']);
        $comment = NativeComment::create(['content' => 'Test Comment']);

        // Create relationships with custom edge types
        $user->posts()->withEdgeType('AUTHORED')->save($post);
        $post->comments()->withEdgeType('RECEIVED')->save($comment);

        $comments = $user->customComments; // Should be a new relationship method

        $this->assertCount(1, $comments);
        $this->assertEquals($comment->id, $comments->first()->id);

        // Verify custom edge types were used
        $connection = $user->getConnection();

        $authoredEdge = $connection->select(
            'MATCH (u:users)-[r:AUTHORED]->(p:posts) WHERE u.id = $userId RETURN r',
            ['userId' => $user->id]
        );
        $this->assertNotEmpty($authoredEdge);

        $receivedEdge = $connection->select(
            'MATCH (p:posts)-[r:RECEIVED]->(c:comments) WHERE p.id = $postId RETURN r',
            ['postId' => $post->id]
        );
        $this->assertNotEmpty($receivedEdge);
    }
}
