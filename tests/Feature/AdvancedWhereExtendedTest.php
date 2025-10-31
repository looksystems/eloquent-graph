<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\Product;
use Tests\Models\User;

test('whereColumn compares two columns', function () {
    // Create users where some have matching first_name and last_name
    User::create(['name' => 'John Doe', 'first_name' => 'John', 'last_name' => 'Smith']);
    User::create(['name' => 'Jane Jane', 'first_name' => 'Jane', 'last_name' => 'Jane']);
    User::create(['name' => 'Bob Bob', 'first_name' => 'Bob', 'last_name' => 'Bob']);
    User::create(['name' => 'Alice Brown', 'first_name' => 'Alice', 'last_name' => 'Brown']);

    // Find users where first_name equals last_name
    $matchingNames = User::whereColumn('first_name', 'last_name')->get();

    expect($matchingNames)->toHaveCount(2);
    expect($matchingNames->pluck('name')->toArray())->toContain('Jane Jane', 'Bob Bob');
});

test('whereColumn works with operators', function () {
    // Create posts with different values
    Post::create(['title' => 'Post 1', 'views' => 100, 'likes' => 50]);
    Post::create(['title' => 'Post 2', 'views' => 200, 'likes' => 250]);
    Post::create(['title' => 'Post 3', 'views' => 150, 'likes' => 150]);
    Post::create(['title' => 'Post 4', 'views' => 300, 'likes' => 100]);

    // Posts where likes > views
    $popularPosts = Post::whereColumn('likes', '>', 'views')->get();

    // Posts where likes = views
    $equalEngagement = Post::whereColumn('likes', '=', 'views')->get();

    // Posts where views >= likes
    $moreViews = Post::whereColumn('views', '>=', 'likes')->get();

    expect($popularPosts)->toHaveCount(1);
    expect($popularPosts->first()->title)->toBe('Post 2');
    expect($equalEngagement)->toHaveCount(1);
    expect($equalEngagement->first()->title)->toBe('Post 3');
    expect($moreViews)->toHaveCount(3);
});

test('whereColumn with multiple conditions', function () {
    Product::create(['name' => 'Product 1', 'cost' => 10, 'price' => 25, 'discount_price' => 20]);
    Product::create(['name' => 'Product 2', 'cost' => 15, 'price' => 30, 'discount_price' => 30]);
    Product::create(['name' => 'Product 3', 'cost' => 20, 'price' => 40, 'discount_price' => 35]);

    // Products where price equals discount_price (no discount)
    $noDiscount = Product::whereColumn('price', 'discount_price')->count();

    // Products where discount_price is less than price AND greater than cost
    $validDiscount = Product::whereColumn('discount_price', '<', 'price')
        ->whereColumn('discount_price', '>', 'cost')
        ->count();

    expect($noDiscount)->toBe(1);
    expect($validDiscount)->toBe(2);
});

test('whereJsonContains checks if JSON column contains value', function () {
    // Create users with JSON data in preferences column (as arrays, not JSON strings)
    User::create([
        'name' => 'John',
        'preferences' => ['theme' => 'dark', 'notifications' => true, 'languages' => ['en', 'es']],
    ]);

    User::create([
        'name' => 'Jane',
        'preferences' => ['theme' => 'light', 'notifications' => false, 'languages' => ['en', 'fr']],
    ]);

    User::create([
        'name' => 'Bob',
        'preferences' => ['theme' => 'dark', 'notifications' => true, 'languages' => ['de', 'fr']],
    ]);

    // Users with dark theme
    $darkThemeUsers = User::whereJsonContains('preferences->theme', 'dark')->get();

    // Users who speak English
    $englishSpeakers = User::whereJsonContains('preferences->languages', 'en')->get();

    // Users with notifications enabled
    $notificationUsers = User::whereJsonContains('preferences->notifications', true)->get();

    expect($darkThemeUsers)->toHaveCount(2);
    expect($darkThemeUsers->pluck('name')->toArray())->toContain('John', 'Bob');
    expect($englishSpeakers)->toHaveCount(2);
    expect($englishSpeakers->pluck('name')->toArray())->toContain('John', 'Jane');
    expect($notificationUsers)->toHaveCount(2);
});

test('whereJsonContains with arrays', function () {
    // Create posts with tags as JSON array
    Post::create([
        'title' => 'Laravel Post',
        'tags' => ['php', 'laravel', 'backend'],
    ]);

    Post::create([
        'title' => 'Vue Post',
        'tags' => ['javascript', 'vue', 'frontend'],
    ]);

    Post::create([
        'title' => 'Full Stack Post',
        'tags' => ['php', 'javascript', 'fullstack'],
    ]);

    // Posts tagged with PHP
    $phpPosts = Post::whereJsonContains('tags', 'php')->get();

    // Posts tagged with JavaScript
    $jsPosts = Post::whereJsonContains('tags', 'javascript')->get();

    expect($phpPosts)->toHaveCount(2);
    expect($phpPosts->pluck('title')->toArray())->toContain('Laravel Post', 'Full Stack Post');
    expect($jsPosts)->toHaveCount(2);
    expect($jsPosts->pluck('title')->toArray())->toContain('Vue Post', 'Full Stack Post');
});

test('whereJsonContains with nested objects', function () {

    User::create([
        'name' => 'Admin',
        'settings' => [
            'permissions' => ['read', 'write', 'delete'],
            'profile' => ['role' => 'admin', 'level' => 5],
        ],
    ]);

    User::create([
        'name' => 'Editor',
        'settings' => [
            'permissions' => ['read', 'write'],
            'profile' => ['role' => 'editor', 'level' => 3],
        ],
    ]);

    User::create([
        'name' => 'Viewer',
        'settings' => [
            'permissions' => ['read'],
            'profile' => ['role' => 'viewer', 'level' => 1],
        ],
    ]);

    // Users with write permission
    $canWrite = User::whereJsonContains('settings->permissions', 'write')->count();

    // Admin users
    $admins = User::whereJsonContains('settings->profile->role', 'admin')->count();

    expect($canWrite)->toBe(2);
    expect($admins)->toBe(1);
})->skip(fn () => ! hasApoc(), 'Requires APOC plugin');

test('whereJsonLength checks JSON array length', function () {
    // Create users with varying numbers of skills
    User::create([
        'name' => 'Beginner',
        'skills' => ['php'],
    ]);

    User::create([
        'name' => 'Intermediate',
        'skills' => ['php', 'javascript', 'sql'],
    ]);

    User::create([
        'name' => 'Expert',
        'skills' => ['php', 'javascript', 'python', 'go', 'rust'],
    ]);

    // Users with exactly 3 skills
    $threeSkills = User::whereJsonLength('skills', 3)->get();

    // Users with more than 3 skills
    $experts = User::whereJsonLength('skills', '>', 3)->get();

    // Users with 1 or 2 skills
    $beginners = User::whereJsonLength('skills', '<', 3)->get();

    expect($threeSkills)->toHaveCount(1);
    expect($threeSkills->first()->name)->toBe('Intermediate');
    expect($experts)->toHaveCount(1);
    expect($experts->first()->name)->toBe('Expert');
    expect($beginners)->toHaveCount(1);
})->skip(fn () => ! hasApoc(), 'Requires APOC plugin');

test('whereJsonLength with nested paths', function () {
    Post::create([
        'title' => 'Popular Post',
        'metadata' => [
            'comments' => ['c1', 'c2', 'c3', 'c4', 'c5'],
            'likes' => ['u1', 'u2', 'u3'],
        ],
    ]);

    Post::create([
        'title' => 'New Post',
        'metadata' => [
            'comments' => ['c1'],
            'likes' => [],
        ],
    ]);

    Post::create([
        'title' => 'Moderate Post',
        'metadata' => [
            'comments' => ['c1', 'c2'],
            'likes' => ['u1', 'u2', 'u3', 'u4'],
        ],
    ]);

    // Posts with more than 2 comments
    $discussedPosts = Post::whereJsonLength('metadata->comments', '>', 2)->count();

    // Posts with no likes
    $noLikes = Post::whereJsonLength('metadata->likes', 0)->count();

    // Posts with 3 or more likes
    $popular = Post::whereJsonLength('metadata->likes', '>=', 3)->count();

    expect($discussedPosts)->toBe(1);
    expect($noLikes)->toBe(1);
    expect($popular)->toBe(2);
})->skip(fn () => ! hasApoc(), 'Requires APOC plugin');

test('combining JSON where clauses', function () {
    Product::create([
        'name' => 'Premium Product',
        'features' => json_encode([
            'colors' => ['red', 'blue', 'green'],
            'sizes' => ['S', 'M', 'L', 'XL'],
            'premium' => true,
        ]),
    ]);

    Product::create([
        'name' => 'Basic Product',
        'features' => json_encode([
            'colors' => ['black', 'white'],
            'sizes' => ['M', 'L'],
            'premium' => false,
        ]),
    ]);

    Product::create([
        'name' => 'Limited Product',
        'features' => json_encode([
            'colors' => ['gold'],
            'sizes' => ['S', 'M', 'L'],
            'premium' => true,
        ]),
    ]);

    // Premium products with more than 2 colors
    $premiumColorful = Product::whereJsonContains('features->premium', true)
        ->whereJsonLength('features->colors', '>', 2)
        ->count();

    // Products that have size M and more than 2 sizes total
    $versatile = Product::whereJsonContains('features->sizes', 'M')
        ->whereJsonLength('features->sizes', '>', 2)
        ->count();

    expect($premiumColorful)->toBe(1);
    expect($versatile)->toBe(2);
})->skip(fn () => ! hasApoc(), 'Requires APOC plugin');

test('whereColumn with null values', function () {
    User::create(['name' => 'User 1', 'first_name' => 'John', 'last_name' => null]);
    User::create(['name' => 'User 2', 'first_name' => null, 'last_name' => null]);
    User::create(['name' => 'User 3', 'first_name' => 'Jane', 'last_name' => 'Jane']);

    // Both columns null should match
    $bothNull = User::whereColumn('first_name', 'last_name')
        ->whereNull('first_name')
        ->count();

    expect($bothNull)->toBe(1);
});

test('JSON queries with empty arrays and objects', function () {
    User::create(['name' => 'Empty Array', 'data' => json_encode([])]);
    User::create(['name' => 'Empty Object', 'data' => json_encode((object) [])]);
    User::create(['name' => 'With Data', 'data' => json_encode(['key' => 'value'])]);

    // Check for empty JSON
    $hasData = User::whereJsonLength('data', '>', 0)->count();

    expect($hasData)->toBe(1);
})->skip(fn () => ! hasApoc(), 'Requires APOC plugin');
