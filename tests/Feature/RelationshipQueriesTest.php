<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class RelationshipQueriesTest extends GraphTestCase
{
    public function test_where_has_filters_models_that_have_relationships()
    {
        // Create users with and without posts
        $userWithPosts = User::create(['name' => 'Author']);
        $userWithoutPosts = User::create(['name' => 'Reader']);

        $userWithPosts->posts()->create(['title' => 'My Post']);

        $usersWithPosts = User::whereHas('posts')->get();

        $this->assertCount(1, $usersWithPosts);
        $this->assertEquals('Author', $usersWithPosts->first()->name);
    }

    public function test_where_has_with_callback_filters_by_relationship_constraints()
    {
        $user1 = User::create(['name' => 'User 1']);
        $user2 = User::create(['name' => 'User 2']);

        $user1->posts()->create(['title' => 'Published Post', 'content' => 'published']);
        $user1->posts()->create(['title' => 'Draft Post', 'content' => 'draft']);
        $user2->posts()->create(['title' => 'Another Draft', 'content' => 'draft']);

        $usersWithPublishedPosts = User::whereHas('posts', function ($query) {
            $query->where('content', 'published');
        })->get();

        $this->assertCount(1, $usersWithPublishedPosts);
        $this->assertEquals('User 1', $usersWithPublishedPosts->first()->name);
    }

    public function test_where_has_with_operator_and_count()
    {
        $user1 = User::create(['name' => 'Prolific']);
        $user2 = User::create(['name' => 'Casual']);
        $user3 = User::create(['name' => 'Silent']);

        // User 1 has 3 posts
        $user1->posts()->create(['title' => 'Post 1']);
        $user1->posts()->create(['title' => 'Post 2']);
        $user1->posts()->create(['title' => 'Post 3']);

        // User 2 has 1 post
        $user2->posts()->create(['title' => 'Single Post']);

        // User 3 has no posts

        $usersWithMultiplePosts = User::whereHas('posts', null, '>=', 2)->get();

        $this->assertCount(1, $usersWithMultiplePosts);
        $this->assertEquals('Prolific', $usersWithMultiplePosts->first()->name);
    }

    public function test_where_doesnt_have_filters_models_without_relationships()
    {
        $userWithPosts = User::create(['name' => 'Author']);
        $userWithoutPosts = User::create(['name' => 'Reader']);

        $userWithPosts->posts()->create(['title' => 'My Post']);

        $usersWithoutPosts = User::whereDoesntHave('posts')->get();

        $this->assertCount(1, $usersWithoutPosts);
        $this->assertEquals('Reader', $usersWithoutPosts->first()->name);
    }

    public function test_where_doesnt_have_with_callback_filters_by_relationship_constraints()
    {
        $user1 = User::create(['name' => 'User 1']);
        $user2 = User::create(['name' => 'User 2']);
        $user3 = User::create(['name' => 'User 3']);

        $user1->posts()->create(['title' => 'Published Post', 'content' => 'published']);
        $user2->posts()->create(['title' => 'Draft Post', 'content' => 'draft']);
        // User 3 has no posts

        $usersWithoutPublishedPosts = User::whereDoesntHave('posts', function ($query) {
            $query->where('content', 'published');
        })->get();

        $this->assertCount(2, $usersWithoutPublishedPosts);
        $userNames = $usersWithoutPublishedPosts->pluck('name')->toArray();
        $this->assertContains('User 2', $userNames);
        $this->assertContains('User 3', $userNames);
    }

    public function test_with_count_adds_relationship_counts_to_models()
    {
        $user1 = User::create(['name' => 'Prolific']);
        $user2 = User::create(['name' => 'Casual']);
        $user3 = User::create(['name' => 'Silent']);

        $user1->posts()->create(['title' => 'Post 1']);
        $user1->posts()->create(['title' => 'Post 2']);
        $user1->posts()->create(['title' => 'Post 3']);

        $user2->posts()->create(['title' => 'Single Post']);

        // User 3 has no posts

        $usersWithCounts = User::withCount('posts')->get();

        $this->assertCount(3, $usersWithCounts);

        $prolific = $usersWithCounts->where('name', 'Prolific')->first();
        $casual = $usersWithCounts->where('name', 'Casual')->first();
        $silent = $usersWithCounts->where('name', 'Silent')->first();

        $this->assertEquals(3, $prolific->posts_count);
        $this->assertEquals(1, $casual->posts_count);
        $this->assertEquals(0, $silent->posts_count);
    }

    public function test_with_count_with_constraints()
    {
        $user = User::create(['name' => 'Author']);

        $user->posts()->create(['title' => 'Published 1', 'content' => 'published']);
        $user->posts()->create(['title' => 'Published 2', 'content' => 'published']);
        $user->posts()->create(['title' => 'Draft 1', 'content' => 'draft']);

        $userWithCount = User::withCount(['posts as published_posts_count' => function ($query) {
            $query->where('content', 'published');
        }])->find($user->id);

        $this->assertEquals(2, $userWithCount->published_posts_count);
    }

    public function test_where_has_works_with_many_to_many_relationships()
    {
        $user1 = User::create(['name' => 'Admin User']);
        $user2 = User::create(['name' => 'Regular User']);
        $role = Role::create(['name' => 'Admin']);

        $user1->roles()->attach($role->id);

        $admins = User::whereHas('roles', function ($query) {
            $query->where('name', 'Admin');
        })->get();

        $this->assertCount(1, $admins);
        $this->assertEquals('Admin User', $admins->first()->name);
    }

    public function test_with_count_works_with_many_to_many_relationships()
    {
        $user1 = User::create(['name' => 'Multi Role']);
        $user2 = User::create(['name' => 'Single Role']);
        $user3 = User::create(['name' => 'No Roles']);

        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);
        $role3 = Role::create(['name' => 'Viewer']);

        $user1->roles()->attach([$role1->id, $role2->id, $role3->id]);
        $user2->roles()->attach($role1->id);

        $usersWithRoleCounts = User::withCount('roles')->get();

        $multiRole = $usersWithRoleCounts->where('name', 'Multi Role')->first();
        $singleRole = $usersWithRoleCounts->where('name', 'Single Role')->first();
        $noRoles = $usersWithRoleCounts->where('name', 'No Roles')->first();

        $this->assertEquals(3, $multiRole->roles_count);
        $this->assertEquals(1, $singleRole->roles_count);
        $this->assertEquals(0, $noRoles->roles_count);
    }
}
