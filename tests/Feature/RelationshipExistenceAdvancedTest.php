<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class RelationshipExistenceAdvancedTest extends GraphTestCase
{
    public function test_where_has_with_nested_relationship_constraints()
    {
        $author = User::create(['name' => 'Author']);
        $commenter1 = User::create(['name' => 'Active Commenter']);
        $commenter2 = User::create(['name' => 'Inactive Commenter']);

        $popularPost = $author->posts()->create(['title' => 'Popular Post']);
        $unpopularPost = $author->posts()->create(['title' => 'Unpopular Post']);

        $popularPost->comments()->create(['content' => 'Great!', 'user_id' => $commenter1->id, 'approved' => true]);
        $popularPost->comments()->create(['content' => 'Nice!', 'user_id' => $commenter1->id, 'approved' => true]);
        $unpopularPost->comments()->create(['content' => 'Spam', 'user_id' => $commenter2->id, 'approved' => false]);

        $usersWithApprovedComments = User::whereHas('posts.comments', function ($query) {
            $query->where('approved', true);
        })->get();

        $this->assertCount(1, $usersWithApprovedComments);
        $this->assertEquals('Author', $usersWithApprovedComments->first()->name);
    }

    public function test_or_where_has_combinations()
    {
        $user1 = User::create(['name' => 'Admin with Posts']);
        $user2 = User::create(['name' => 'Editor without Posts']);
        $user3 = User::create(['name' => 'Regular with Posts']);

        $adminRole = Role::create(['name' => 'Admin']);
        $editorRole = Role::create(['name' => 'Editor']);

        $user1->roles()->attach($adminRole->id);
        $user2->roles()->attach($editorRole->id);

        $user1->posts()->create(['title' => 'Admin Post']);
        $user3->posts()->create(['title' => 'Regular Post']);

        // Use orWhereHas to get users with posts OR users who are admins
        $combined = User::whereHas('posts')
            ->orWhereHas('roles', function ($query) {
                $query->where('name', 'Admin');
            })->get();

        $this->assertCount(2, $combined);
        $names = $combined->pluck('name')->toArray();
        $this->assertContains('Admin with Posts', $names);
        $this->assertContains('Regular with Posts', $names);
    }

    public function test_performance_of_existence_queries()
    {
        for ($i = 1; $i <= 50; $i++) {
            $user = User::create(['name' => "User $i"]);
            if ($i % 2 == 0) {
                for ($j = 1; $j <= 3; $j++) {
                    $user->posts()->create(['title' => "Post $j for User $i"]);
                }
            }
        }

        $startTime = microtime(true);
        $usersWithPosts = User::has('posts')->get();
        $hasTime = microtime(true) - $startTime;

        $startTime = microtime(true);
        $usersWithPostsWhere = User::whereHas('posts')->get();
        $whereHasTime = microtime(true) - $startTime;

        $this->assertCount(25, $usersWithPosts);
        $this->assertCount(25, $usersWithPostsWhere);

        $this->assertLessThan(1, $hasTime);
        $this->assertLessThan(1, $whereHasTime);
    }

    public function test_has_with_pivot_constraints()
    {

        $user1 = User::create(['name' => 'Recent Admin']);
        $user2 = User::create(['name' => 'Old Admin']);
        $user3 = User::create(['name' => 'Non Admin']);

        $adminRole = Role::create(['name' => 'Admin']);
        $userRole = Role::create(['name' => 'User']);

        $user1->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user2->roles()->attach($adminRole->id, ['assigned_at' => now()->subYear()]);
        $user3->roles()->attach($userRole->id);

        $recentAdmins = User::whereHas('roles', function ($query) {
            $query->where('name', 'Admin')
                ->wherePivot('assigned_at', '>', now()->subMonth());
        })->get();

        $this->assertCount(1, $recentAdmins);
        $this->assertEquals('Recent Admin', $recentAdmins->first()->name);
    }

    public function test_morph_relationship_existence()
    {
        $userWithImages = User::create(['name' => 'User with Images']);
        $userWithoutImages = User::create(['name' => 'User without Images']);
        $postWithImages = Post::create(['title' => 'Post with Images', 'user_id' => $userWithImages->id]);

        $userWithImages->images()->create(['url' => 'user-image.jpg']);
        $postWithImages->images()->create(['url' => 'post-image.jpg']);

        $usersWithImages = User::has('images')->get();
        $postsWithImages = Post::has('images')->get();

        $this->assertCount(1, $usersWithImages);
        $this->assertEquals('User with Images', $usersWithImages->first()->name);

        $this->assertCount(1, $postsWithImages);
        $this->assertEquals('Post with Images', $postsWithImages->first()->title);
    }

    public function test_nested_has_queries()
    {
        $author = User::create(['name' => 'Author']);
        $commenter = User::create(['name' => 'Commenter']);
        $silent = User::create(['name' => 'Silent']);

        $post = $author->posts()->create(['title' => 'Post']);
        $comment = $post->comments()->create(['content' => 'Comment', 'user_id' => $commenter->id]);

        $authorsWithCommentedPosts = User::whereHas('posts', function ($query) {
            $query->has('comments');
        })->get();

        $this->assertCount(1, $authorsWithCommentedPosts);
        $this->assertEquals('Author', $authorsWithCommentedPosts->first()->name);
    }

    public function test_has_with_aggregated_conditions()
    {
        $popularAuthor = User::create(['name' => 'Popular Author']);
        $unpopularAuthor = User::create(['name' => 'Unpopular Author']);

        for ($i = 1; $i <= 5; $i++) {
            $post = $popularAuthor->posts()->create(['title' => "Popular Post $i", 'views' => 1000 * $i]);
        }

        $unpopularAuthor->posts()->create(['title' => 'Unpopular Post', 'views' => 100]);

        $authorsWithHighViewPosts = User::whereHas('posts', function ($query) {
            $query->where('views', '>', 2000);
        })->get();

        $this->assertCount(1, $authorsWithHighViewPosts);
        $this->assertEquals('Popular Author', $authorsWithHighViewPosts->first()->name);
    }

    public function test_complex_nested_existence_queries()
    {
        $author1 = User::create(['name' => 'Popular Author']);
        $author2 = User::create(['name' => 'Unpopular Author']);
        $commenter = User::create(['name' => 'Active Commenter']);

        $popularPost = $author1->posts()->create(['title' => 'Popular', 'views' => 1000]);
        $unpopularPost = $author2->posts()->create(['title' => 'Unpopular', 'views' => 10]);

        $popularPost->comments()->create(['content' => 'Great!', 'user_id' => $commenter->id, 'approved' => true]);
        $popularPost->comments()->create(['content' => 'Nice!', 'user_id' => $commenter->id, 'approved' => true]);

        $authorsWithPopularCommentedPosts = User::whereHas('posts', function ($query) {
            $query->where('views', '>', 500)
                ->whereHas('comments', function ($q) {
                    $q->where('approved', true);
                });
        })->get();

        $this->assertCount(1, $authorsWithPopularCommentedPosts);
        $this->assertEquals('Popular Author', $authorsWithPopularCommentedPosts->first()->name);
    }

    public function test_where_has_with_json_conditions()
    {
        $user = User::create(['name' => 'Author']);

        $post1 = $user->posts()->create([
            'title' => 'Tech Post',
            'metadata' => ['tags' => ['tech', 'programming']],
        ]);

        $post2 = $user->posts()->create([
            'title' => 'Lifestyle Post',
            'metadata' => ['tags' => ['lifestyle', 'health']],
        ]);

        $techAuthors = User::whereHas('posts', function ($query) {
            $query->whereJsonContains('metadata->tags', 'tech');
        })->get();

        $this->assertCount(1, $techAuthors);
        $this->assertEquals('Author', $techAuthors->first()->name);
    }
}
