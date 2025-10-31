<?php

namespace Tests\Feature;

use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class OrWhereHasTest extends GraphTestCase
{
    public function test_or_where_has_with_simple_relationship()
    {
        $user1 = User::create(['name' => 'Admin User', 'email' => 'admin@example.com']);
        $user2 = User::create(['name' => 'Regular User', 'email' => 'regular@example.com']);
        $user3 = User::create(['name' => 'Author User', 'email' => 'author@example.com']);

        $adminRole = Role::create(['name' => 'admin']);
        $authorRole = Role::create(['name' => 'author']);

        $user1->roles()->attach($adminRole->id);
        $user3->roles()->attach($authorRole->id);

        $user3->posts()->create(['title' => 'Blog Post']);

        // Find users who have admin role OR have posts
        $users = User::whereHas('roles', function ($query) {
            $query->where('name', 'admin');
        })->orWhereHas('posts')->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('id', $user1->id)); // Has admin role
        $this->assertTrue($users->contains('id', $user3->id)); // Has posts
        $this->assertFalse($users->contains('id', $user2->id)); // Has neither
    }

    public function test_or_where_has_with_nested_conditions()
    {
        $activeUser = User::create(['name' => 'Active User', 'email' => 'active@example.com', 'active' => true]);
        $inactiveUser = User::create(['name' => 'Inactive User', 'email' => 'inactive@example.com', 'active' => false]);
        $verifiedUser = User::create(['name' => 'Verified User', 'email' => 'verified@example.com', 'email_verified_at' => now()]);

        $activeUser->posts()->create(['title' => 'Draft Post', 'status' => 'draft']);
        $inactiveUser->posts()->create(['title' => 'Published Post', 'status' => 'published']);

        // Find active users OR users with published posts
        $users = User::where('active', true)
            ->orWhereHas('posts', function ($query) {
                $query->where('status', 'published');
            })->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('id', $activeUser->id)); // Is active
        $this->assertTrue($users->contains('id', $inactiveUser->id)); // Has published posts
        $this->assertFalse($users->contains('id', $verifiedUser->id)); // Neither condition
    }

    public function test_multiple_or_where_has_chains()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);
        $user4 = User::create(['name' => 'User 4', 'email' => 'user4@example.com']);

        $adminRole = Role::create(['name' => 'admin']);
        $editorRole = Role::create(['name' => 'editor']);

        $user1->roles()->attach($adminRole->id);
        $user2->posts()->create(['title' => 'Post']);
        $user3->profile()->create(['bio' => 'Has profile']);

        // Find users who have admin role OR have posts OR have a profile
        $users = User::whereHas('roles', function ($query) {
            $query->where('name', 'admin');
        })
            ->orWhereHas('posts')
            ->orWhereHas('profile')
            ->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->contains('id', $user1->id)); // Has admin role
        $this->assertTrue($users->contains('id', $user2->id)); // Has posts
        $this->assertTrue($users->contains('id', $user3->id)); // Has profile
        $this->assertFalse($users->contains('id', $user4->id)); // Has nothing
    }

    public function test_or_where_has_with_count_conditions()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        // User 1 has 3 posts
        $user1->posts()->create(['title' => 'Post 1']);
        $user1->posts()->create(['title' => 'Post 2']);
        $user1->posts()->create(['title' => 'Post 3']);

        // User 2 has 1 post
        $user2->posts()->create(['title' => 'Post 4']);

        // User 3 has 2 roles
        $role1 = Role::create(['name' => 'admin']);
        $role2 = Role::create(['name' => 'editor']);
        $user3->roles()->attach([$role1->id, $role2->id]);

        // Find users with 3+ posts OR 2+ roles
        $users = User::has('posts', '>=', 3)
            ->orHas('roles', '>=', 2)
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('id', $user1->id)); // Has 3 posts
        $this->assertTrue($users->contains('id', $user3->id)); // Has 2 roles
        $this->assertFalse($users->contains('id', $user2->id)); // Has only 1 post
    }

    public function test_or_where_has_combined_with_regular_where()
    {
        $activeAdmin = User::create(['name' => 'Active Admin', 'email' => 'active@example.com', 'active' => true]);
        $inactiveAdmin = User::create(['name' => 'Inactive Admin', 'email' => 'inactive@example.com', 'active' => false]);
        $activeUser = User::create(['name' => 'Active User', 'email' => 'user@example.com', 'active' => true]);

        $adminRole = Role::create(['name' => 'admin']);
        $activeAdmin->roles()->attach($adminRole->id);
        $inactiveAdmin->roles()->attach($adminRole->id);

        $activeUser->posts()->create(['title' => 'User Post', 'status' => 'published']);

        // Find active users who are either admins OR have published posts
        $users = User::where('active', true)
            ->where(function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('name', 'admin');
                })->orWhereHas('posts', function ($q) {
                    $q->where('status', 'published');
                });
            })->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('id', $activeAdmin->id)); // Active and admin
        $this->assertTrue($users->contains('id', $activeUser->id)); // Active and has published posts
        $this->assertFalse($users->contains('id', $inactiveAdmin->id)); // Admin but not active
    }

    public function test_or_where_has_with_no_results()
    {
        $user = User::create(['name' => 'User', 'email' => 'user@example.com']);

        // Search for conditions that don't exist
        $users = User::whereHas('roles', function ($query) {
            $query->where('name', 'non-existent');
        })->orWhereHas('posts', function ($query) {
            $query->where('status', 'non-existent');
        })->get();

        $this->assertCount(0, $users);
    }
}
