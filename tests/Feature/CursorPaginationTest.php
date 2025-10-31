<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class CursorPaginationTest extends GraphTestCase
{
    public function test_for_page_after_id_with_numeric_id()
    {
        // Create users with specific IDs to test ordering
        for ($i = 1; $i <= 10; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@example.com"]);
        }

        $allUsers = User::orderBy('id')->get();
        $firstUserId = $allUsers->first()->id;
        $fifthUserId = $allUsers->skip(4)->first()->id;

        // Get page after the 5th user
        $users = User::forPageAfterId($fifthUserId, 3)->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->every(fn ($user) => $user->id > $fifthUserId));

        // Verify ordering
        $ids = $users->pluck('id')->toArray();
        $sortedIds = $ids;
        sort($sortedIds);
        $this->assertEquals($sortedIds, $ids);
    }

    public function test_for_page_before_id_with_numeric_id()
    {
        // Create users with specific IDs
        for ($i = 1; $i <= 10; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@example.com"]);
        }

        $allUsers = User::orderBy('id')->get();
        $sixthUserId = $allUsers->skip(5)->first()->id;

        // Get page before the 6th user
        $users = User::forPageBeforeId($sixthUserId, 3)->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->every(fn ($user) => $user->id < $sixthUserId));

        // Verify reverse ordering
        $ids = $users->pluck('id')->toArray();
        $sortedIds = $ids;
        sort($sortedIds);
        $this->assertEquals(array_reverse($sortedIds), $ids);
    }

    public function test_for_page_after_id_with_custom_column()
    {
        $user1 = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user2 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $user3 = User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $user4 = User::create(['name' => 'David', 'email' => 'david@example.com']);
        $user5 = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

        // Get users after 'Charlie' alphabetically by name
        $users = User::forPageAfterId('Charlie', 2, 'name')->get();

        $this->assertCount(2, $users);
        $names = $users->pluck('name')->toArray();
        $this->assertEquals(['David', 'Eve'], $names);
    }

    public function test_for_page_before_id_with_custom_column()
    {
        $user1 = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user2 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $user3 = User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $user4 = User::create(['name' => 'David', 'email' => 'david@example.com']);
        $user5 = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

        // Get users before 'David' alphabetically by name
        $users = User::forPageBeforeId('David', 2, 'name')->get();

        $this->assertCount(2, $users);
        $names = $users->pluck('name')->toArray();
        $this->assertEquals(['Charlie', 'Bob'], $names); // Reversed order
    }

    public function test_for_page_after_id_with_date_column()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com', 'created_at' => now()->subDays(5)]);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com', 'created_at' => now()->subDays(4)]);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com', 'created_at' => now()->subDays(3)]);
        $user4 = User::create(['name' => 'User 4', 'email' => 'user4@example.com', 'created_at' => now()->subDays(2)]);
        $user5 = User::create(['name' => 'User 5', 'email' => 'user5@example.com', 'created_at' => now()->subDays(1)]);

        // Get users created after user3's creation date using timestamp string
        $users = User::forPageAfterId($user3->created_at->format('Y-m-d H:i:s'), 2, 'created_at')->get();

        $this->assertCount(2, $users);
        $names = $users->pluck('name')->toArray();
        $this->assertEquals(['User 4', 'User 5'], $names);
    }

    public function test_for_page_after_id_with_zero_results()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $lastUser = User::orderBy('id', 'desc')->first();

        // Try to get page after the last user
        $users = User::forPageAfterId($lastUser->id, 10)->get();

        $this->assertCount(0, $users);
    }

    public function test_for_page_before_id_with_zero_results()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $firstUser = User::orderBy('id')->first();

        // Try to get page before the first user
        $users = User::forPageBeforeId($firstUser->id, 10)->get();

        $this->assertCount(0, $users);
    }

    public function test_for_page_after_id_with_limit()
    {
        for ($i = 1; $i <= 20; $i++) {
            User::create(['name' => "User $i", 'email' => "user$i@example.com"]);
        }

        $allUsers = User::orderBy('id')->get();
        $tenthUserId = $allUsers->skip(9)->first()->id;

        // Request 5 users after the 10th user
        $users = User::forPageAfterId($tenthUserId, 5)->get();

        $this->assertCount(5, $users);
        $this->assertTrue($users->every(fn ($user) => $user->id > $tenthUserId));
    }

    public function test_for_page_methods_preserve_query_scope()
    {
        $activeUser1 = User::create(['name' => 'Active 1', 'email' => 'active1@example.com', 'active' => true]);
        $inactiveUser = User::create(['name' => 'Inactive', 'email' => 'inactive@example.com', 'active' => false]);
        $activeUser2 = User::create(['name' => 'Active 2', 'email' => 'active2@example.com', 'active' => true]);
        $activeUser3 = User::create(['name' => 'Active 3', 'email' => 'active3@example.com', 'active' => true]);

        // Get active users after the first active user
        $users = User::where('active', true)
            ->forPageAfterId($activeUser1->id, 2)
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('id', $activeUser2->id));
        $this->assertTrue($users->contains('id', $activeUser3->id));
        $this->assertFalse($users->contains('id', $inactiveUser->id));
    }

    public function test_for_page_after_id_handles_low_starting_id()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $user3 = User::create(['name' => 'User 3', 'email' => 'user3@example.com']);

        // Use a very low ID that is definitely smaller than any generated ID
        $users = User::forPageAfterId('0', 2)->get();

        // Should get first 2 users based on ordering
        $this->assertCount(2, $users);

        // Verify they are the first two users by ID order
        $allUsers = User::orderBy('id')->take(2)->get();
        $this->assertEquals($allUsers->pluck('id')->toArray(), $users->pluck('id')->toArray());
    }
}
