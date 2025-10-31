<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\Product;
use Tests\Models\User;

test('max returns maximum value from numeric column', function () {
    User::create(['name' => 'John', 'age' => 25, 'salary' => 50000]);
    User::create(['name' => 'Jane', 'age' => 30, 'salary' => 60000]);
    User::create(['name' => 'Bob', 'age' => 35, 'salary' => 70000]);

    $maxAge = User::max('age');
    $maxSalary = User::max('salary');

    expect($maxAge)->toBe(35);
    expect($maxSalary)->toBe(70000);
});

test('max with where conditions', function () {
    User::create(['name' => 'Young1', 'age' => 20, 'salary' => 40000]);
    User::create(['name' => 'Young2', 'age' => 25, 'salary' => 50000]);
    User::create(['name' => 'Old', 'age' => 50, 'salary' => 100000]);

    $maxYoungAge = User::where('age', '<', 30)->max('age');
    $maxYoungSalary = User::where('age', '<', 30)->max('salary');

    expect($maxYoungAge)->toBe(25);
    expect($maxYoungSalary)->toBe(50000);
});

test('min returns minimum value from numeric column', function () {
    User::create(['name' => 'John', 'age' => 25, 'salary' => 50000]);
    User::create(['name' => 'Jane', 'age' => 30, 'salary' => 60000]);
    User::create(['name' => 'Bob', 'age' => 35, 'salary' => 70000]);

    $minAge = User::min('age');
    $minSalary = User::min('salary');

    expect($minAge)->toBe(25);
    expect($minSalary)->toBe(50000);
});

test('min with where conditions', function () {
    User::create(['name' => 'Young', 'age' => 20, 'salary' => 40000]);
    User::create(['name' => 'Middle', 'age' => 35, 'salary' => 65000]);
    User::create(['name' => 'Old', 'age' => 50, 'salary' => 100000]);

    $minOldAge = User::where('age', '>=', 35)->min('age');
    $minOldSalary = User::where('age', '>=', 35)->min('salary');

    expect($minOldAge)->toBe(35);
    expect($minOldSalary)->toBe(65000);
});

test('max and min work with empty results', function () {
    // No users created
    $max = User::max('age');
    $min = User::min('age');

    // Standard aggregates return 0 for empty result sets (backward compatibility)
    expect($max)->toBe(0);
    expect($min)->toBe(0);
});

test('max and min work with single record', function () {
    User::create(['name' => 'Solo', 'age' => 42, 'salary' => 75000]);

    $maxAge = User::max('age');
    $minAge = User::min('age');
    $maxSalary = User::max('salary');
    $minSalary = User::min('salary');

    expect($maxAge)->toBe(42);
    expect($minAge)->toBe(42);
    expect($maxSalary)->toBe(75000);
    expect($minSalary)->toBe(75000);
});

test('value returns single column value from first matching record', function () {
    User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 25]);
    User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'age' => 30]);

    $email = User::where('name', 'John')->value('email');
    $age = User::where('name', 'Jane')->value('age');

    expect($email)->toBe('john@example.com');
    expect($age)->toBe(30);
});

test('value returns null when no records match', function () {
    User::create(['name' => 'John', 'email' => 'john@example.com']);

    $email = User::where('name', 'NonExistent')->value('email');

    expect($email)->toBeNull();
});

test('value works with orderBy to get specific value', function () {
    User::create(['name' => 'Alice', 'age' => 25, 'salary' => 50000]);
    User::create(['name' => 'Bob', 'age' => 30, 'salary' => 60000]);
    User::create(['name' => 'Charlie', 'age' => 35, 'salary' => 70000]);

    // Get the name of the oldest user
    $oldestName = User::orderBy('age', 'desc')->value('name');

    // Get the name of the highest paid user
    $highestPaidName = User::orderBy('salary', 'desc')->value('name');

    expect($oldestName)->toBe('Charlie');
    expect($highestPaidName)->toBe('Charlie');
});

test('aggregation methods work with relationships', function () {
    $user1 = User::create(['name' => 'User1', 'age' => 25]);
    $user2 = User::create(['name' => 'User2', 'age' => 30]);

    Post::create(['title' => 'Post1', 'user_id' => $user1->id, 'views' => 100]);
    Post::create(['title' => 'Post2', 'user_id' => $user1->id, 'views' => 200]);
    Post::create(['title' => 'Post3', 'user_id' => $user2->id, 'views' => 150]);

    // Max views for user1's posts
    $user1MaxViews = Post::where('user_id', $user1->id)->max('views');

    // Min views for all posts
    $minViews = Post::min('views');

    // Average views across all posts
    $avgViews = Post::avg('views');

    expect($user1MaxViews)->toBe(200);
    expect($minViews)->toBe(100);
    expect($avgViews)->toBe(150.0);
});

test('aggregation with decimal values', function () {
    Product::create(['name' => 'Product1', 'price' => 19.99]);
    Product::create(['name' => 'Product2', 'price' => 29.50]);
    Product::create(['name' => 'Product3', 'price' => 39.99]);

    $maxPrice = Product::max('price');
    $minPrice = Product::min('price');
    $avgPrice = Product::avg('price');
    $sumPrice = Product::sum('price');

    expect($maxPrice)->toBe(39.99);
    expect($minPrice)->toBe(19.99);
    expect(round($avgPrice, 2))->toBe(29.83);
    expect(round($sumPrice, 2))->toBe(89.48);
});

test('aggregation with null values are ignored', function () {
    User::create(['name' => 'John', 'age' => 25, 'salary' => 50000]);
    User::create(['name' => 'Jane', 'age' => null, 'salary' => 60000]);
    User::create(['name' => 'Bob', 'age' => 35, 'salary' => null]);

    $maxAge = User::max('age');
    $minAge = User::min('age');
    $avgSalary = User::avg('salary');

    expect($maxAge)->toBe(35);
    expect($minAge)->toBe(25);
    expect($avgSalary)->toBe(55000.0); // Average of 50000 and 60000
});

test('aggregation methods work with scopes', function () {
    User::create(['name' => 'Active1', 'age' => 25, 'active' => true]);
    User::create(['name' => 'Active2', 'age' => 35, 'active' => true]);
    User::create(['name' => 'Inactive', 'age' => 45, 'active' => false]);

    // Assuming we have an active scope on User model
    $maxActiveAge = User::where('active', true)->max('age');
    $avgAge = User::avg('age');

    expect($maxActiveAge)->toBe(35);
    expect($avgAge)->toBe(35.0); // Average of 25, 35, 45
});
