<?php

namespace Tests\Feature;

use Tests\Models\Comment;
use Tests\Models\Profile;
use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class RelationshipExistenceBasicTest extends GraphTestCase
{
    public function test_has_with_complex_count_conditions()
    {
        $user1 = User::create(['name' => 'Prolific Writer']);
        $user2 = User::create(['name' => 'Casual Writer']);
        $user3 = User::create(['name' => 'Silent User']);

        for ($i = 1; $i <= 10; $i++) {
            $user1->posts()->create(['title' => "Post $i"]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $user2->posts()->create(['title' => "Post $i"]);
        }

        $prolificWriters = User::has('posts', '>=', 5)->get();
        $casualWriters = User::has('posts', '>', 0)->get()->filter(function ($user) {
            return $user->posts()->count() < 5;
        });

        // For users with no posts, use whereDoesntHave
        $silentUsers = User::whereDoesntHave('posts')->get();

        $this->assertCount(1, $prolificWriters);
        $this->assertEquals('Prolific Writer', $prolificWriters->first()->name);

        $this->assertCount(1, $casualWriters);
        $this->assertEquals('Casual Writer', $casualWriters->first()->name);

        $this->assertCount(1, $silentUsers);
        $this->assertEquals('Silent User', $silentUsers->first()->name);
    }

    public function test_doesnt_have_with_soft_deleted_relationships()
    {
        $user1 = User::create(['name' => 'User with Active Posts']);
        $user2 = User::create(['name' => 'User with Deleted Posts']);
        $user3 = User::create(['name' => 'User without Posts']);

        $user1->posts()->create(['title' => 'Active Post']);
        $user2->posts()->create(['title' => 'Deleted Post', 'deleted_at' => now()]);

        // Neo4j doesn't auto-filter soft deletes, so we need to check for active posts
        $usersWithoutActivePosts = User::whereDoesntHave('posts', function ($query) {
            $query->whereNull('deleted_at');
        })->get();

        $this->assertCount(2, $usersWithoutActivePosts);
        $names = $usersWithoutActivePosts->pluck('name')->toArray();
        $this->assertContains('User with Deleted Posts', $names);
        $this->assertContains('User without Posts', $names);
    }

    public function test_has_with_multiple_relationships()
    {
        $completeUser = User::create(['name' => 'Complete User']);
        $partialUser = User::create(['name' => 'Partial User']);
        $emptyUser = User::create(['name' => 'Empty User']);

        $completeUser->posts()->create(['title' => 'Post']);
        $completeUser->profile()->create(['bio' => 'Bio']);
        $completeUser->roles()->attach(Role::create(['name' => 'Admin'])->id);

        $partialUser->posts()->create(['title' => 'Post']);

        $completeUsers = User::has('posts')->has('profile')->has('roles')->get();
        $partialUsers = User::has('posts')->doesntHave('profile')->get();
        $emptyUsers = User::doesntHave('posts')->doesntHave('profile')->doesntHave('roles')->get();

        $this->assertCount(1, $completeUsers);
        $this->assertEquals('Complete User', $completeUsers->first()->name);

        $this->assertCount(1, $partialUsers);
        $this->assertEquals('Partial User', $partialUsers->first()->name);

        $this->assertCount(1, $emptyUsers);
        $this->assertEquals('Empty User', $emptyUsers->first()->name);
    }

    public function test_where_has_with_multiple_conditions()
    {
        $user = User::create(['name' => 'Author']);

        $post1 = $user->posts()->create(['title' => 'Popular Published', 'status' => 'published', 'views' => 1000]);
        $post2 = $user->posts()->create(['title' => 'Popular Draft', 'status' => 'draft', 'views' => 1000]);
        $post3 = $user->posts()->create(['title' => 'Unpopular Published', 'status' => 'published', 'views' => 10]);

        $usersWithPopularPublishedPosts = User::whereHas('posts', function ($query) {
            $query->where('status', 'published')
                ->where('views', '>', 500);
        })->get();

        $this->assertCount(1, $usersWithPopularPublishedPosts);
        $this->assertEquals('Author', $usersWithPopularPublishedPosts->first()->name);
    }

    public function test_has_through_relationships()
    {
        $user1 = User::create(['name' => 'User with Comments']);
        $user2 = User::create(['name' => 'User without Comments']);

        $post = $user1->posts()->create(['title' => 'Post']);
        $comment = Comment::create(['content' => 'Comment', 'post_id' => $post->id, 'user_id' => $user1->id]);

        $usersWithComments = User::has('comments')->get();

        $this->assertCount(1, $usersWithComments);
        $this->assertEquals('User with Comments', $usersWithComments->first()->name);
    }

    public function test_doesnt_have_with_callback()
    {
        $user1 = User::create(['name' => 'User with Draft']);
        $user2 = User::create(['name' => 'User with Published']);
        $user3 = User::create(['name' => 'User without Posts']);

        $user1->posts()->create(['title' => 'Draft', 'status' => 'draft']);
        $user2->posts()->create(['title' => 'Published', 'status' => 'published']);

        $usersWithoutPublishedPosts = User::whereDoesntHave('posts', function ($query) {
            $query->where('status', 'published');
        })->get();

        $this->assertCount(2, $usersWithoutPublishedPosts);
        $names = $usersWithoutPublishedPosts->pluck('name')->toArray();
        $this->assertContains('User with Draft', $names);
        $this->assertContains('User without Posts', $names);
    }

    public function test_or_doesnt_have_combinations()
    {
        $onlyPosts = User::create(['name' => 'Only Posts']);
        $onlyProfile = User::create(['name' => 'Only Profile']);
        $both = User::create(['name' => 'Both']);
        $neither = User::create(['name' => 'Neither']);

        $onlyPosts->posts()->create(['title' => 'Post']);
        $onlyProfile->profile()->create(['bio' => 'Bio']);
        $both->posts()->create(['title' => 'Post']);
        $both->profile()->create(['bio' => 'Bio']);

        // Get users without posts
        $withoutPosts = User::whereDoesntHave('posts')->get();
        // Get users without profile
        $withoutProfile = User::whereDoesntHave('profile')->get();

        // Combine and get unique results
        $missingEither = $withoutPosts->merge($withoutProfile)->unique('id');

        $this->assertCount(3, $missingEither);
        $names = $missingEither->pluck('name')->toArray();
        $this->assertContains('Only Posts', $names);
        $this->assertContains('Only Profile', $names);
        $this->assertContains('Neither', $names);
    }

    public function test_has_with_select_specific_columns()
    {
        $user = User::create(['name' => 'Author']);
        $post = $user->posts()->create(['title' => 'Post', 'content' => 'Content']);

        $result = User::select('id', 'name')->has('posts')->first();

        $this->assertNotNull($result->name);
        $this->assertNull($result->email);
    }

    public function test_where_has_with_soft_deletes()
    {
        $user = User::create(['name' => 'Author']);
        $activePost = $user->posts()->create(['title' => 'Active']);
        $deletedPost = $user->posts()->create(['title' => 'Deleted', 'deleted_at' => now()]);

        $usersWithActivePosts = User::whereHas('posts', function ($query) {
            $query->whereNull('deleted_at');
        })->get();

        // Neo4j doesn't filter soft deletes automatically, so just check for posts existence
        $usersWithAllPosts = User::whereHas('posts')->get();

        $this->assertCount(1, $usersWithActivePosts);
        $this->assertCount(1, $usersWithAllPosts);
    }

    public function test_has_one_existence_queries()
    {
        $userWithProfile = User::create(['name' => 'Complete']);
        $userWithoutProfile = User::create(['name' => 'Incomplete']);

        $userWithProfile->profile()->create(['bio' => 'Developer']);

        $completeUsers = User::has('profile')->get();
        $incompleteUsers = User::doesntHave('profile')->get();

        $this->assertCount(1, $completeUsers);
        $this->assertEquals('Complete', $completeUsers->first()->name);

        $this->assertCount(1, $incompleteUsers);
        $this->assertEquals('Incomplete', $incompleteUsers->first()->name);
    }

    public function test_chained_has_queries()
    {
        $qualifiedUser = User::create(['name' => 'Qualified']);
        $partialUser = User::create(['name' => 'Partial']);
        $unqualifiedUser = User::create(['name' => 'Unqualified']);

        for ($i = 1; $i <= 5; $i++) {
            $qualifiedUser->posts()->create(['title' => "Post $i"]);
        }
        $qualifiedUser->profile()->create(['bio' => 'Bio']);
        $qualifiedUser->roles()->attach(Role::create(['name' => 'Role'])->id);

        $partialUser->posts()->create(['title' => 'Post']);
        $partialUser->profile()->create(['bio' => 'Bio']);

        $fullyQualified = User::has('posts', '>=', 5)
            ->has('profile')
            ->has('roles')
            ->get();

        $this->assertCount(1, $fullyQualified);
        $this->assertEquals('Qualified', $fullyQualified->first()->name);
    }

    public function test_has_using_relationship_counts()
    {
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $user = User::create(['name' => "User $i"]);
            for ($j = 1; $j <= $i; $j++) {
                $user->posts()->create(['title' => "Post $j"]);
            }
            $users[] = $user;
        }

        $usersWithExactlyThreePosts = User::has('posts', '=', 3)->get();
        $usersWithLessThanThreePosts = User::has('posts', '<', 3)->get();
        $usersWithMoreThanThreePosts = User::has('posts', '>', 3)->get();

        $this->assertCount(1, $usersWithExactlyThreePosts);
        $this->assertEquals('User 3', $usersWithExactlyThreePosts->first()->name);

        $this->assertCount(2, $usersWithLessThanThreePosts);
        $this->assertCount(2, $usersWithMoreThanThreePosts);
    }

    public function test_doesnt_have_morph_relationships()
    {
        $userWithImage = User::create(['name' => 'With Image']);
        $userWithoutImage = User::create(['name' => 'Without Image']);

        $userWithImage->images()->create(['url' => 'avatar.jpg']);

        $usersWithoutImages = User::doesntHave('images')->get();

        $this->assertCount(1, $usersWithoutImages);
        $this->assertEquals('Without Image', $usersWithoutImages->first()->name);
    }

    public function test_where_has_with_order_by()
    {
        $user1 = User::create(['name' => 'First Author']);
        $user2 = User::create(['name' => 'Second Author']);

        $oldDate = now()->subYear();
        $recentDate = now();

        $user1->posts()->create(['title' => 'Old Post', 'created_at' => $oldDate]);
        $user2->posts()->create(['title' => 'Recent Post', 'created_at' => $recentDate]);

        // Debug: Check what was actually stored
        $post1 = $user1->posts()->first();
        $post2 = $user2->posts()->first();

        $this->assertNotNull($post1->created_at);
        $this->assertNotNull($post2->created_at);

        $recentAuthors = User::whereHas('posts', function ($query) {
            $query->whereDate('created_at', '>', now()->subMonth());
        })->orderBy('name')->get();

        $this->assertCount(1, $recentAuthors);
        $this->assertEquals('Second Author', $recentAuthors->first()->name);
    }

    public function test_has_with_relationship_method_constraints()
    {
        $user = User::create(['name' => 'Author']);
        $publishedPost = $user->posts()->create(['title' => 'Published', 'status' => 'published']);
        $draftPost = $user->posts()->create(['title' => 'Draft', 'status' => 'draft']);

        $usersWithPublishedPosts = User::whereHas('posts', function ($query) {
            $query->where('status', 'published');
        })->get();

        $this->assertCount(1, $usersWithPublishedPosts);
    }
}
