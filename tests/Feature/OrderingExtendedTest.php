<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\Models\Post;
use Tests\Models\User;

test('orderByRaw allows raw ordering expressions', function () {
    // Create users with various attributes
    User::create(['name' => 'Alice', 'age' => 25, 'score' => 100]);
    User::create(['name' => 'Bob', 'age' => 30, 'score' => 80]);
    User::create(['name' => 'Charlie', 'age' => 25, 'score' => 90]);
    User::create(['name' => 'David', 'age' => 30, 'score' => 110]);

    // Order by a calculated expression (age * score)
    $users = User::orderByRaw('u.age * u.score DESC')->get();

    // David: 30 * 110 = 3300 (first)
    // Alice: 25 * 100 = 2500 (third)
    // Bob: 30 * 80 = 2400 (fourth)
    // Charlie: 25 * 90 = 2250 (last)

    expect($users->first()->name)->toBe('David');
    expect($users->last()->name)->toBe('Charlie');
    expect($users->pluck('name')->toArray())->toBe(['David', 'Alice', 'Bob', 'Charlie']);
});

test('orderByRaw with CASE statements', function () {
    // Create posts with different statuses
    Post::create(['title' => 'Draft Post', 'status' => 'draft', 'views' => 100]);
    Post::create(['title' => 'Published Post', 'status' => 'published', 'views' => 500]);
    Post::create(['title' => 'Featured Post', 'status' => 'featured', 'views' => 200]);
    Post::create(['title' => 'Archived Post', 'status' => 'archived', 'views' => 300]);

    // Custom ordering: featured first, then published, then draft, then archived
    $posts = Post::orderByRaw("
        CASE p.status
            WHEN 'featured' THEN 1
            WHEN 'published' THEN 2
            WHEN 'draft' THEN 3
            WHEN 'archived' THEN 4
        END
    ")->get();

    expect($posts->pluck('title')->toArray())->toBe([
        'Featured Post',
        'Published Post',
        'Draft Post',
        'Archived Post',
    ]);
});

test('orderByRaw with multiple expressions', function () {
    User::create(['name' => 'User1', 'active' => true, 'score' => 100]);
    User::create(['name' => 'User2', 'active' => false, 'score' => 150]);
    User::create(['name' => 'User3', 'active' => true, 'score' => 80]);
    User::create(['name' => 'User4', 'active' => false, 'score' => 120]);

    // Order by active status (true first), then by score descending
    $users = User::orderByRaw('u.active DESC, u.score DESC')->get();

    expect($users->pluck('name')->toArray())->toBe([
        'User1',  // active=true, score=100
        'User3',  // active=true, score=80
        'User2',  // active=false, score=150
        'User4',   // active=false, score=120
    ]);
});

test('latest orders by created_at descending', function () {
    // Create users at different times
    $oldestUser = User::create(['name' => 'Oldest', 'created_at' => Carbon::now()->subDays(3)]);
    $middleUser = User::create(['name' => 'Middle', 'created_at' => Carbon::now()->subDays(1)]);
    $newestUser = User::create(['name' => 'Newest', 'created_at' => Carbon::now()]);

    // Get latest users
    $users = User::latest()->get();

    expect($users->first()->name)->toBe('Newest');
    expect($users->last()->name)->toBe('Oldest');
    expect($users->pluck('name')->toArray())->toBe(['Newest', 'Middle', 'Oldest']);
});

test('latest with custom column', function () {
    // Create posts with different updated_at times
    Post::create(['title' => 'Post 1', 'updated_at' => Carbon::now()->subHours(3)]);
    Post::create(['title' => 'Post 2', 'updated_at' => Carbon::now()->subHours(1)]);
    Post::create(['title' => 'Post 3', 'updated_at' => Carbon::now()]);

    // Get latest by updated_at
    $posts = Post::latest('updated_at')->get();

    expect($posts->first()->title)->toBe('Post 3');
    expect($posts->last()->title)->toBe('Post 1');
});

test('oldest orders by created_at ascending', function () {
    // Create users at different times
    $oldestUser = User::create(['name' => 'Oldest', 'created_at' => Carbon::now()->subDays(3)]);
    $middleUser = User::create(['name' => 'Middle', 'created_at' => Carbon::now()->subDays(1)]);
    $newestUser = User::create(['name' => 'Newest', 'created_at' => Carbon::now()]);

    // Get oldest users first
    $users = User::oldest()->get();

    expect($users->first()->name)->toBe('Oldest');
    expect($users->last()->name)->toBe('Newest');
    expect($users->pluck('name')->toArray())->toBe(['Oldest', 'Middle', 'Newest']);
});

test('oldest with custom column', function () {
    // Create posts with different published_at times
    Post::create(['title' => 'Old Post', 'published_at' => Carbon::now()->subMonths(2)]);
    Post::create(['title' => 'Recent Post', 'published_at' => Carbon::now()->subDays(5)]);
    Post::create(['title' => 'New Post', 'published_at' => Carbon::now()]);

    // Get oldest by published_at
    $posts = Post::oldest('published_at')->get();

    expect($posts->first()->title)->toBe('Old Post');
    expect($posts->last()->title)->toBe('New Post');
});

test('inRandomOrder returns records in random order', function () {
    // Create multiple users
    for ($i = 1; $i <= 10; $i++) {
        User::create(['name' => "User $i", 'score' => $i * 10]);
    }

    // Get random order multiple times
    $order1 = User::inRandomOrder()->pluck('name')->toArray();
    $order2 = User::inRandomOrder()->pluck('name')->toArray();
    $order3 = User::inRandomOrder()->pluck('name')->toArray();

    // All should have the same count
    expect($order1)->toHaveCount(10);
    expect($order2)->toHaveCount(10);
    expect($order3)->toHaveCount(10);

    // At least one order should be different (statistically very likely with 10 items)
    // Note: There's a tiny chance all three could be the same, but it's astronomically small
    $allSame = ($order1 === $order2) && ($order2 === $order3);
    expect($allSame)->toBeFalse();
});

test('inRandomOrder with limit', function () {
    // Create users
    for ($i = 1; $i <= 20; $i++) {
        User::create(['name' => "User $i"]);
    }

    // Get 5 random users
    $randomFive = User::inRandomOrder()->limit(5)->get();

    expect($randomFive)->toHaveCount(5);

    // Get different random 5
    $anotherFive = User::inRandomOrder()->limit(5)->pluck('name')->toArray();
    $firstFive = $randomFive->pluck('name')->toArray();

    // They might be different (though could theoretically be the same)
    // But all names should be valid
    foreach ($anotherFive as $name) {
        expect($name)->toMatch('/^User \d+$/');
    }
});

test('combining latest with other constraints', function () {
    // Create users with different attributes and times
    User::create(['name' => 'Active Old', 'active' => true, 'created_at' => Carbon::now()->subDays(5)]);
    User::create(['name' => 'Inactive Old', 'active' => false, 'created_at' => Carbon::now()->subDays(4)]);
    User::create(['name' => 'Active New', 'active' => true, 'created_at' => Carbon::now()->subDays(1)]);
    User::create(['name' => 'Inactive New', 'active' => false, 'created_at' => Carbon::now()]);

    // Get latest active users
    $latestActive = User::where('active', true)->latest()->get();

    expect($latestActive)->toHaveCount(2);
    expect($latestActive->first()->name)->toBe('Active New');
    expect($latestActive->last()->name)->toBe('Active Old');
});

test('combining oldest with pagination', function () {
    // Create posts
    for ($i = 1; $i <= 15; $i++) {
        Post::create([
            'title' => "Post $i",
            'created_at' => Carbon::now()->subDays(20 - $i),
        ]);
    }

    // Get oldest 5 posts
    $oldestFive = Post::oldest()->limit(5)->pluck('title')->toArray();

    expect($oldestFive)->toBe(['Post 1', 'Post 2', 'Post 3', 'Post 4', 'Post 5']);
});

test('orderByRaw with bindings', function () {
    User::create(['name' => 'Alice', 'score' => 100]);
    User::create(['name' => 'Bob', 'score' => 200]);
    User::create(['name' => 'Charlie', 'score' => 150]);

    // Order by score with a multiplier binding
    $multiplier = 2;
    $users = User::orderByRaw('u.score * ? DESC', [$multiplier])->get();

    // Order should still be Bob, Charlie, Alice (multiplier doesn't change relative order)
    expect($users->pluck('name')->toArray())->toBe(['Bob', 'Charlie', 'Alice']);
});

test('multiple ordering methods combined', function () {
    // Create posts with various attributes
    Post::create(['title' => 'Featured A', 'featured' => true, 'created_at' => Carbon::now()->subDays(2)]);
    Post::create(['title' => 'Featured B', 'featured' => true, 'created_at' => Carbon::now()]);
    Post::create(['title' => 'Regular A', 'featured' => false, 'created_at' => Carbon::now()->subDays(1)]);
    Post::create(['title' => 'Regular B', 'featured' => false, 'created_at' => Carbon::now()->subDays(3)]);

    // Featured posts first (using orderByRaw), then latest
    $posts = Post::orderByRaw('p.featured DESC')
        ->latest()
        ->pluck('title')
        ->toArray();

    expect($posts)->toBe([
        'Featured B',  // featured=true, newest
        'Featured A',  // featured=true, older
        'Regular A',   // featured=false, newer
        'Regular B',    // featured=false, oldest
    ]);
});

test('ordering with null values', function () {
    User::create(['name' => 'Has Score', 'score' => 100]);
    User::create(['name' => 'No Score', 'score' => null]);
    User::create(['name' => 'Zero Score', 'score' => 0]);

    // Order by score ascending (nulls typically come first or last depending on database)
    $users = User::orderBy('score', 'asc')->pluck('name')->toArray();

    // The exact order of nulls may vary by database, but zero should come before 100
    expect(in_array('Zero Score', $users))->toBeTrue();
    expect(in_array('Has Score', $users))->toBeTrue();
    expect(in_array('No Score', $users))->toBeTrue();

    $zeroIndex = array_search('Zero Score', $users);
    $hundredIndex = array_search('Has Score', $users);
    expect($zeroIndex)->toBeLessThan($hundredIndex);
});
