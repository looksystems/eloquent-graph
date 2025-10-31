<?php

use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class WhereExistsTest extends GraphTestCase
{
    public function test_where_exists_with_subquery()
    {
        $userWithPosts = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $userWithoutPosts = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => $userWithPosts->id]);
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'user_id' => $userWithPosts->id]);

        $users = User::whereExists(function ($query) {
            $query->select('id')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id');
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_where_not_exists_with_subquery()
    {
        $userWithPosts = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $userWithoutPosts = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => $userWithPosts->id]);

        $users = User::whereNotExists(function ($query) {
            $query->select('id')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id');
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane', $users->first()->name);
    }

    public function test_where_exists_with_multiple_conditions()
    {
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Post::create(['title' => 'Published', 'content' => 'Content', 'user_id' => $user1->id, 'published' => true]);
        Post::create(['title' => 'Draft', 'content' => 'Content', 'user_id' => $user2->id, 'published' => false]);

        $users = User::whereExists(function ($query) {
            $query->select('id')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id')
                ->where('posts.published', true);
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_where_exists_with_nested_relationships()
    {
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => $user1->id]);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content', 'user_id' => $user2->id]);

        Comment::create(['content' => 'Great post!', 'post_id' => $post1->id, 'user_id' => $user2->id]);

        // Find users who have posts that have been commented on
        $users = User::whereExists(function ($query) {
            $query->select('id')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id')
                ->whereExists(function ($subQuery) {
                    $subQuery->select('id')
                        ->from('comments')
                        ->whereColumn('comments.post_id', 'posts.id');
                });
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_or_where_exists()
    {
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 30]);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'age' => 25]);
        $user3 = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 35]);

        Post::create(['title' => 'Post', 'content' => 'Content', 'user_id' => $user1->id]);
        Comment::create(['content' => 'Comment', 'post_id' => null, 'user_id' => $user2->id]);

        $users = User::where('age', '>', 32)
            ->orWhereExists(function ($query) {
                $query->select('id')
                    ->from('posts')
                    ->whereColumn('posts.user_id', 'users.id');
            })
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Bob'));
    }

    public function test_where_exists_in_query_builder()
    {
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => $user1->id]);

        $query = User::query();
        $query->whereExists(function ($q) {
            $q->select('id')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id');
        });

        $users = $query->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_where_exists_with_raw_expression()
    {
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        Post::create(['title' => 'Active Post', 'content' => 'Content', 'user_id' => $user1->id, 'status' => 'active']);
        Post::create(['title' => 'Inactive Post', 'content' => 'Content', 'user_id' => $user2->id, 'status' => 'inactive']);

        $users = User::whereExists(function ($query) {
            $query->selectRaw('1')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id')
                ->where('posts.status', 'active');
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_chained_where_exists_and_where_not_exists()
    {
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Post::create(['title' => 'Post', 'content' => 'Content', 'user_id' => $user1->id]);
        Comment::create(['content' => 'Comment', 'post_id' => null, 'user_id' => $user1->id]);
        Post::create(['title' => 'Post', 'content' => 'Content', 'user_id' => $user2->id]);

        // Users with posts but without comments
        $users = User::whereExists(function ($query) {
            $query->select('id')
                ->from('posts')
                ->whereColumn('posts.user_id', 'users.id');
        })
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('comments')
                    ->whereColumn('comments.user_id', 'users.id');
            })
            ->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane', $users->first()->name);
    }
}
