<?php

namespace Tests\Feature;

use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class JoinMethodsTest extends GraphTestCase
{
    public function test_can_perform_basic_inner_join()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = Post::create(['title' => 'Test Post', 'content' => 'Content', 'user_id' => $user->id]);

        // User without posts
        User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        // Post without user
        Post::create(['title' => 'Orphan Post', 'content' => 'No user', 'user_id' => 999]);

        // Perform inner join
        $results = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->select('users.name', 'posts.title')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John', $results[0]->name);
        $this->assertEquals('Test Post', $results[0]->title);
    }

    public function test_can_perform_left_join()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user1 = User::create(['name' => 'User With Posts', 'email' => 'with@example.com']);
        $user2 = User::create(['name' => 'User Without Posts', 'email' => 'without@example.com']);
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => $user1->id]);

        // Perform left join
        $results = User::query()
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->select('users.name', 'posts.title')
            ->orderBy('users.name')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('User With Posts', $results[0]->name);
        $this->assertEquals('Post 1', $results[0]->title);
        $this->assertEquals('User Without Posts', $results[1]->name);
        $this->assertNull($results[1]->title);
    }

    public function test_can_perform_right_join()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        Post::create(['title' => 'Post With User', 'content' => 'Content', 'user_id' => $user->id]);
        Post::create(['title' => 'Post Without User', 'content' => 'Content', 'user_id' => 999]);

        // Perform right join
        $results = User::query()
            ->rightJoin('posts', 'posts.user_id', '=', 'users.id')
            ->select('users.name', 'posts.title')
            ->orderBy('posts.title')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('John', $results[0]->name);
        $this->assertEquals('Post With User', $results[0]->title);
        $this->assertNull($results[1]->name);
        $this->assertEquals('Post Without User', $results[1]->title);
    }

    public function test_can_perform_cross_join()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        User::create(['name' => 'User1', 'email' => 'user1@example.com']);
        User::create(['name' => 'User2', 'email' => 'user2@example.com']);
        Post::create(['title' => 'Post1', 'content' => 'Content1', 'user_id' => 1]);
        Post::create(['title' => 'Post2', 'content' => 'Content2', 'user_id' => 2]);

        // Perform cross join
        $results = User::query()
            ->crossJoin('posts')
            ->select('users.name', 'posts.title')
            ->orderBy('users.name')
            ->orderBy('posts.title')
            ->get();

        $this->assertCount(4, $results); // 2 users × 2 posts

        // Check all combinations exist
        $combinations = $results->map(function ($item) {
            return $item->name.'-'.$item->title;
        })->toArray();

        $this->assertContains('User1-Post1', $combinations);
        $this->assertContains('User1-Post2', $combinations);
        $this->assertContains('User2-Post1', $combinations);
        $this->assertContains('User2-Post2', $combinations);
    }

    public function test_can_perform_multiple_joins()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = Post::create(['title' => 'My Post', 'content' => 'Content', 'user_id' => $user->id]);
        Comment::create(['content' => 'Great post!', 'post_id' => $post->id, 'user_id' => $user->id]);

        // Perform multiple joins
        $results = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->join('comments', 'comments.post_id', '=', 'posts.id')
            ->select('users.name', 'posts.title', 'comments.content as comment')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John', $results[0]->name);
        $this->assertEquals('My Post', $results[0]->title);
        $this->assertEquals('Great post!', $results[0]->comment);
    }

    public function test_join_with_where_conditions()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com', 'active' => true]);
        Post::create(['title' => 'Published', 'content' => 'Content', 'user_id' => $user->id, 'published' => true]);
        Post::create(['title' => 'Draft', 'content' => 'Content', 'user_id' => $user->id, 'published' => false]);

        // Join with where condition on joined table
        $results = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->where('posts.published', true)
            ->select('users.name', 'posts.title')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Published', $results[0]->title);

        // Join with where condition on main table
        $results2 = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->where('users.active', true)
            ->select('users.name', 'posts.title')
            ->get();

        $this->assertCount(2, $results2);
    }

    public function test_left_join_with_multiple_matches()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => $user->id]);
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'user_id' => $user->id]);
        Post::create(['title' => 'Post 3', 'content' => 'Content', 'user_id' => $user->id]);

        $userWithoutPosts = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        // Left join should return all posts for users with posts and null for users without
        $results = User::query()
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->select('users.name', 'posts.title')
            ->orderBy('users.name')
            ->orderBy('posts.title')
            ->get();

        $this->assertCount(4, $results); // 3 posts for John + 1 null for Jane

        $johnResults = $results->filter(fn ($r) => $r->name === 'John');
        $this->assertCount(3, $johnResults);

        $janeResults = $results->filter(fn ($r) => $r->name === 'Jane');
        $this->assertCount(1, $janeResults);
        $this->assertNull($janeResults->first()->title);
    }

    public function test_join_with_aggregation()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        Post::create(['title' => 'John Post 1', 'content' => 'Content', 'user_id' => $user1->id, 'views' => 100]);
        Post::create(['title' => 'John Post 2', 'content' => 'Content', 'user_id' => $user1->id, 'views' => 200]);
        Post::create(['title' => 'Jane Post', 'content' => 'Content', 'user_id' => $user2->id, 'views' => 150]);

        // Join with count
        $results = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.name, COUNT(*) as post_count')
            ->get();

        $this->assertCount(2, $results);

        // The issue is that selectRaw returns 'name' not 'users_name'
        // Check by property name
        $john = $results->firstWhere('name', 'John');
        $this->assertEquals(2, $john->post_count);

        $jane = $results->firstWhere('name', 'Jane');
        $this->assertEquals(1, $jane->post_count);

        // Join with sum
        $results2 = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.name, SUM(posts.views) as total_views')
            ->get();

        $john2 = $results2->firstWhere('name', 'John');
        $this->assertEquals(300, $john2->total_views);

        $jane2 = $results2->firstWhere('name', 'Jane');
        $this->assertEquals(150, $jane2->total_views);
    }

    public function test_self_join()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data with parent-child relationship
        $parent = User::create(['name' => 'Parent User', 'email' => 'parent@example.com']);
        $child1 = User::create(['name' => 'Child 1', 'email' => 'child1@example.com', 'parent_id' => $parent->id]);
        $child2 = User::create(['name' => 'Child 2', 'email' => 'child2@example.com', 'parent_id' => $parent->id]);
        User::create(['name' => 'Orphan', 'email' => 'orphan@example.com']);

        // Self join to find parent-child relationships
        $results = User::query()
            ->from('users as children')
            ->join('users as parents', 'children.parent_id', '=', 'parents.id')
            ->select('children.name as child_name', 'parents.name as parent_name')
            ->orderBy('children.name')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Child 1', $results[0]->child_name);
        $this->assertEquals('Parent User', $results[0]->parent_name);
        $this->assertEquals('Child 2', $results[1]->child_name);
        $this->assertEquals('Parent User', $results[1]->parent_name);
    }

    public function test_join_with_null_values()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        Post::create(['title' => 'Post with user', 'content' => 'Content', 'user_id' => $user->id]);
        Post::create(['title' => 'Post without user', 'content' => 'Content', 'user_id' => null]);

        // Left join should handle null foreign keys
        $results = Post::query()
            ->leftJoin('users', 'posts.user_id', '=', 'users.id')
            ->select('posts.title', 'users.name')
            ->orderBy('posts.title')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('John', $results[0]->name);
        $this->assertNull($results[1]->name);
    }

    public function test_multiple_joins_with_same_table()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $author = User::create(['name' => 'Author', 'email' => 'author@example.com']);
        $reviewer = User::create(['name' => 'Reviewer', 'email' => 'reviewer@example.com']);

        $post = Post::create([
            'title' => 'Reviewed Post',
            'content' => 'Content',
            'user_id' => $author->id,
            'reviewer_id' => $reviewer->id,
        ]);

        // Multiple joins to same table with aliases
        $results = Post::query()
            ->join('users as authors', 'posts.user_id', '=', 'authors.id')
            ->join('users as reviewers', 'posts.reviewer_id', '=', 'reviewers.id')
            ->select('posts.title', 'authors.name as author_name', 'reviewers.name as reviewer_name')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Reviewed Post', $results[0]->title);
        $this->assertEquals('Author', $results[0]->author_name);
        $this->assertEquals('Reviewer', $results[0]->reviewer_name);
    }

    public function test_join_with_closure()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com', 'active' => true]);
        Post::create(['title' => 'Recent Post', 'content' => 'Content', 'user_id' => $user->id, 'created_at' => now()]);
        Post::create(['title' => 'Old Post', 'content' => 'Content', 'user_id' => $user->id, 'created_at' => now()->subDays(10)]);

        // Join with closure for complex conditions
        $results = User::query()
            ->join('posts', function ($join) {
                $join->on('posts.user_id', '=', 'users.id')
                    ->where('posts.created_at', '>=', now()->subDays(7));
            })
            ->select('users.name', 'posts.title')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Recent Post', $results[0]->title);
    }

    public function test_join_preserves_model_attributes()
    {
        $this->markTestSkipped('SQL JOIN operations are not applicable to Neo4j. Graph databases use MATCH patterns for relationship traversal instead of SQL JOINs. See documentation for Neo4j-specific relationship querying.');

        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = Post::create(['title' => 'Test Post', 'content' => 'Content', 'user_id' => $user->id]);

        // Join should preserve model functionality
        $result = User::query()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->where('posts.id', $post->id)
            ->first();

        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals('John', $result->name);
        $this->assertEquals('john@example.com', $result->email);
    }
}
