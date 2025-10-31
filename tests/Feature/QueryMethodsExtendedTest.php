<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\User;

test('doesntExist returns true when no records match', function () {
    // No users exist yet
    $doesntExist = User::where('name', 'NonExistent')->doesntExist();

    expect($doesntExist)->toBeTrue();

    // Create a user
    User::create(['name' => 'John']);

    // Check non-existent user
    $doesntExist = User::where('name', 'Jane')->doesntExist();
    expect($doesntExist)->toBeTrue();

    // Check existing user
    $doesntExist = User::where('name', 'John')->doesntExist();
    expect($doesntExist)->toBeFalse();
});

test('doesntExist with multiple conditions', function () {
    User::create(['name' => 'Alice', 'age' => 25, 'active' => true]);
    User::create(['name' => 'Bob', 'age' => 30, 'active' => false]);

    // Check for non-existent combination
    $doesntExist = User::where('name', 'Alice')
        ->where('age', 30)
        ->doesntExist();

    expect($doesntExist)->toBeTrue();

    // Check for existing combination
    $doesntExist = User::where('name', 'Alice')
        ->where('age', 25)
        ->doesntExist();

    expect($doesntExist)->toBeFalse();
});

test('doesntExist with relationships', function () {
    $user = User::create(['name' => 'Author']);
    Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

    // User with posts exists
    $hasNoPosts = User::whereHas('posts')->doesntExist();
    expect($hasNoPosts)->toBeFalse();

    // User without posts
    $userWithoutPosts = User::create(['name' => 'No Posts']);
    $hasNoPosts = User::where('id', $userWithoutPosts->id)
        ->whereDoesntHave('posts')
        ->doesntExist();

    expect($hasNoPosts)->toBeFalse(); // The user exists, even without posts
});

test('lazyById returns lazy collection ordered by id', function () {
    // Create users with known IDs
    for ($i = 1; $i <= 50; $i++) {
        User::create(['name' => "User $i", 'score' => $i * 10]);
    }

    $count = 0;
    $names = [];

    // Process users lazily by ID
    User::lazyById(10)->each(function ($user) use (&$count, &$names) {
        $count++;
        $names[] = $user->name;

        // Stop after processing 15 users
        if ($count >= 15) {
            return false;
        }
    });

    expect($count)->toBe(15);
    expect($names)->toHaveCount(15);
    expect($names[0])->toBe('User 1'); // Should start from lowest ID
});

test('lazyById with chunk size', function () {
    // Create users
    for ($i = 1; $i <= 25; $i++) {
        User::create(['name' => "User $i"]);
    }

    $chunks = [];
    $chunkCount = 0;

    // Track chunk sizes
    User::lazyById(5)->chunk(5)->each(function ($chunk) use (&$chunks, &$chunkCount) {
        $chunkCount++;
        $chunks[] = $chunk->count();

        if ($chunkCount >= 3) {
            return false; // Stop after 3 chunks
        }
    });

    expect($chunkCount)->toBe(3);
    expect($chunks)->toBe([5, 5, 5]);
});

test('lazyById with where conditions', function () {
    // Create users with different scores
    for ($i = 1; $i <= 30; $i++) {
        User::create(['name' => "User $i", 'score' => $i * 10, 'active' => $i % 2 === 0]);
    }

    $activeUsers = [];

    // Get only active users lazily by ID
    User::where('active', true)->lazyById(5)->each(function ($user) use (&$activeUsers) {
        $activeUsers[] = $user->name;

        if (count($activeUsers) >= 5) {
            return false;
        }
    });

    expect($activeUsers)->toHaveCount(5);
    // Active users are those with even numbers
    expect($activeUsers)->toBe(['User 2', 'User 4', 'User 6', 'User 8', 'User 10']);
});

test('lazyById maintains order even with custom column', function () {
    // Create users with custom IDs
    User::create(['name' => 'Third', 'custom_id' => 30]);
    User::create(['name' => 'First', 'custom_id' => 10]);
    User::create(['name' => 'Second', 'custom_id' => 20]);

    $names = [];

    User::lazyById(10, 'custom_id')->each(function ($user) use (&$names) {
        $names[] = $user->name;
    });

    expect($names)->toBe(['First', 'Second', 'Third']);
});

test('forPage returns specific page of results', function () {
    // Create posts
    for ($i = 1; $i <= 25; $i++) {
        Post::create(['title' => "Post $i", 'views' => $i * 10]);
    }

    // Get page 1 (items 1-10) - Order by views for numeric ordering
    $page1 = Post::orderBy('views')->forPage(1, 10)->get();

    expect($page1)->toHaveCount(10);
    expect($page1->first()->title)->toBe('Post 1');
    expect($page1->last()->title)->toBe('Post 10');

    // Get page 2 (items 11-20)
    $page2 = Post::orderBy('views')->forPage(2, 10)->get();

    expect($page2)->toHaveCount(10);
    expect($page2->first()->title)->toBe('Post 11');
    expect($page2->last()->title)->toBe('Post 20');

    // Get page 3 (items 21-25)
    $page3 = Post::orderBy('views')->forPage(3, 10)->get();

    expect($page3)->toHaveCount(5);
    expect($page3->first()->title)->toBe('Post 21');
    expect($page3->last()->title)->toBe('Post 25');
});

test('forPage with custom page size', function () {
    // Create users
    for ($i = 1; $i <= 17; $i++) {
        User::create(['name' => "User $i"]);
    }

    // Page size of 5
    $page1 = User::orderBy('name')->forPage(1, 5)->pluck('name')->toArray();
    $page2 = User::orderBy('name')->forPage(2, 5)->pluck('name')->toArray();
    $page3 = User::orderBy('name')->forPage(3, 5)->pluck('name')->toArray();
    $page4 = User::orderBy('name')->forPage(4, 5)->pluck('name')->toArray();

    expect($page1)->toHaveCount(5);
    expect($page2)->toHaveCount(5);
    expect($page3)->toHaveCount(5);
    expect($page4)->toHaveCount(2); // Only 2 items left
});

test('forPage with where conditions', function () {
    // Create posts with different statuses
    for ($i = 1; $i <= 20; $i++) {
        Post::create([
            'title' => "Post $i",
            'status' => $i % 2 === 0 ? 'published' : 'draft',
        ]);
    }

    // Get page 1 of published posts (5 per page)
    $publishedPage1 = Post::where('status', 'published')
        ->orderBy('title')
        ->forPage(1, 5)
        ->pluck('title')
        ->toArray();

    // Should get posts 2, 4, 6, 8, 10
    expect($publishedPage1)->toHaveCount(5);
    expect($publishedPage1[0])->toBe('Post 10'); // Alphabetically "Post 10" comes before "Post 2"
});

test('forPage returns empty collection for out of range page', function () {
    // Create 10 users
    for ($i = 1; $i <= 10; $i++) {
        User::create(['name' => "User $i"]);
    }

    // Page 5 with 5 items per page (would need 21-25 items)
    $outOfRange = User::forPage(5, 5)->get();

    expect($outOfRange)->toBeEmpty();
});

test('forPage with page 0 or negative returns empty', function () {
    User::create(['name' => 'Test User']);

    $page0 = User::forPage(0, 10)->get();
    $pageNegative = User::forPage(-1, 10)->get();

    expect($page0)->toBeEmpty();
    expect($pageNegative)->toBeEmpty();
});

test('combining doesntExist with complex queries', function () {
    $user1 = User::create(['name' => 'User1', 'age' => 25]);
    $user2 = User::create(['name' => 'User2', 'age' => 35]);

    Post::create(['title' => 'Young Post', 'user_id' => $user1->id]);

    // Check if users over 30 without posts don't exist
    $noOldUsersWithoutPosts = User::where('age', '>', 30)
        ->whereDoesntHave('posts')
        ->doesntExist();

    expect($noOldUsersWithoutPosts)->toBeFalse(); // user2 exists and has no posts
});

test('lazyById memory efficiency', function () {
    // Create a reasonable number of users
    for ($i = 1; $i <= 100; $i++) {
        User::create([
            'name' => "User $i",
            'email' => "user$i@example.com",
            'score' => $i * 10,
        ]);
    }

    $processedCount = 0;

    // Process lazily - should not load all 100 into memory at once
    User::lazyById(10)->each(function ($user) use (&$processedCount) {
        $processedCount++;

        // Simulate some processing
        expect($user->name)->toStartWith('User');

        // Stop after 25 to demonstrate early termination
        if ($processedCount >= 25) {
            return false;
        }
    });

    expect($processedCount)->toBe(25);
});

test('forPage preserves query builder state', function () {
    // Create posts
    for ($i = 1; $i <= 15; $i++) {
        Post::create([
            'title' => "Post $i",
            'views' => $i * 10,
            'status' => $i <= 10 ? 'published' : 'draft',
        ]);
    }

    $query = Post::where('status', 'published')->orderBy('views', 'desc');

    // Get different pages from the same query
    $page1 = $query->forPage(1, 3)->pluck('title')->toArray();
    $page2 = $query->forPage(2, 3)->pluck('title')->toArray();

    // Should get published posts in descending order of views
    expect($page1)->toBe(['Post 10', 'Post 9', 'Post 8']);
    expect($page2)->toBe(['Post 7', 'Post 6', 'Post 5']);
});

test('doesntExist is opposite of exists', function () {
    User::create(['name' => 'John', 'age' => 30]);

    $exists = User::where('name', 'John')->exists();
    $doesntExist = User::where('name', 'John')->doesntExist();

    expect($exists)->toBeTrue();
    expect($doesntExist)->toBeFalse();

    $exists = User::where('name', 'Jane')->exists();
    $doesntExist = User::where('name', 'Jane')->doesntExist();

    expect($exists)->toBeFalse();
    expect($doesntExist)->toBeTrue();
});
