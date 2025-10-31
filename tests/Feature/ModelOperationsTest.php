<?php

namespace Tests\Feature;

use Tests\Models\Post;
use Tests\Models\Product;
use Tests\Models\User;

test('increment increases numeric column value', function () {
    $user = User::create(['name' => 'John', 'score' => 100, 'level' => 5]);

    // Increment score by default amount (1)
    $user->increment('score');

    expect($user->fresh()->score)->toBe(101.0);

    // Increment score by specific amount
    $user->increment('score', 5);

    expect($user->fresh()->score)->toBe(106.0);
});

test('increment with multiple columns', function () {
    $user = User::create(['name' => 'Jane', 'score' => 50, 'level' => 2, 'points' => 10]);

    // Increment multiple columns
    $user->increment('score', 10, ['level' => 3, 'points' => 15]);

    $fresh = $user->fresh();
    expect($fresh->score)->toBe(60.0);
    expect($fresh->level)->toBe(3);
    expect($fresh->points)->toBe(15);
});

test('decrement decreases numeric column value', function () {
    $user = User::create(['name' => 'Bob', 'score' => 100, 'lives' => 3]);

    // Decrement lives by default amount (1)
    $user->decrement('lives');

    expect($user->fresh()->lives)->toBe(2);

    // Decrement score by specific amount
    $user->decrement('score', 25);

    expect($user->fresh()->score)->toBe(75.0);
});

test('decrement with multiple columns', function () {
    $user = User::create(['name' => 'Alice', 'health' => 100, 'mana' => 50, 'stamina' => 75]);

    // Decrement health and update other columns
    $user->decrement('health', 30, ['mana' => 20, 'stamina' => 50]);

    $fresh = $user->fresh();
    expect($fresh->health)->toBe(70);
    expect($fresh->mana)->toBe(20);
    expect($fresh->stamina)->toBe(50);
});

test('increment and decrement on query builder', function () {
    User::create(['name' => 'User1', 'score' => 100]);
    User::create(['name' => 'User2', 'score' => 200]);
    User::create(['name' => 'User3', 'score' => 150]);

    // Increment all scores by 50
    User::query()->increment('score', 50);

    $scores = User::pluck('score')->sort()->values()->toArray();
    expect($scores)->toBe([150.0, 200.0, 250.0]);

    // Decrement scores where score > 200
    User::where('score', '>', 200)->decrement('score', 25);

    $user2Score = User::where('name', 'User2')->value('score');
    $user3Score = User::where('name', 'User3')->value('score');

    expect($user2Score)->toBe(225.0);  // Was 250, now 225
    expect($user3Score)->toBe(200.0);  // Was 200, no change (not > 200)
});

test('increment with negative value acts like decrement', function () {
    $user = User::create(['name' => 'Test', 'score' => 100]);

    $user->increment('score', -20);

    expect($user->fresh()->score)->toBe(80.0);
});

test('decrement with negative value acts like increment', function () {
    $user = User::create(['name' => 'Test', 'score' => 100]);

    $user->decrement('score', -15);

    expect($user->fresh()->score)->toBe(115.0);
});

test('is method checks if two models are the same', function () {
    $user1 = User::create(['name' => 'John']);
    $user2 = User::create(['name' => 'Jane']);

    // Same model instance
    $sameUser = User::find($user1->id);

    expect($user1->is($sameUser))->toBeTrue();
    expect($user1->is($user2))->toBeFalse();
});

test('is method with null', function () {
    $user = User::create(['name' => 'John']);

    expect($user->is(null))->toBeFalse();
});

test('is method checks table and primary key', function () {
    $user = User::create(['name' => 'John']);
    $post = Post::create(['title' => 'Test Post', 'user_id' => $user->id]);

    // Different models even if they might have the same ID
    expect($user->is($post))->toBeFalse();

    // Same user fetched differently
    $userFromQuery = User::where('name', 'John')->first();
    expect($user->is($userFromQuery))->toBeTrue();
});

test('isNot method checks if two models are different', function () {
    $user1 = User::create(['name' => 'Alice']);
    $user2 = User::create(['name' => 'Bob']);

    $sameUser = User::find($user1->id);

    expect($user1->isNot($user2))->toBeTrue();
    expect($user1->isNot($sameUser))->toBeFalse();
});

test('isNot method with null', function () {
    $user = User::create(['name' => 'Charlie']);

    expect($user->isNot(null))->toBeTrue();
});

test('is and isNot work with unsaved models', function () {
    $saved = User::create(['name' => 'Saved']);
    $unsaved = new User(['name' => 'Unsaved']);

    expect($saved->is($unsaved))->toBeFalse();
    expect($saved->isNot($unsaved))->toBeTrue();

    // Two unsaved models are not the same
    $unsaved2 = new User(['name' => 'Unsaved2']);
    expect($unsaved->is($unsaved2))->toBeFalse();

    // Same unsaved instance
    expect($unsaved->is($unsaved))->toBeTrue();
});

test('increment and decrement return the model', function () {
    $user = User::create(['name' => 'Test', 'score' => 100]);

    $result1 = $user->increment('score');
    $result2 = $user->decrement('score');

    expect($result1)->toBeInstanceOf(User::class);
    expect($result2)->toBeInstanceOf(User::class);
    expect($result1->id)->toBe($user->id);
});

test('increment and decrement with float values', function () {
    $product = Product::create(['name' => 'Item', 'price' => 99.99]);

    $product->increment('price', 10.50);
    expect($product->fresh()->price)->toBe(110.49);

    $product->decrement('price', 5.25);
    expect($product->fresh()->price)->toBe(105.24);
});

test('bulk increment and decrement operations', function () {
    // Create multiple users
    for ($i = 1; $i <= 5; $i++) {
        User::create(['name' => "User$i", 'score' => $i * 100, 'level' => $i]);
    }

    // Increment all levels
    User::query()->increment('level');

    $levels = User::orderBy('name')->pluck('level')->toArray();
    expect($levels)->toBe([2, 3, 4, 5, 6]);

    // Decrement scores for high-level users
    User::where('level', '>', 3)->decrement('score', 50);

    $highLevelScores = User::where('level', '>', 3)->orderBy('name')->pluck('score')->toArray();
    expect($highLevelScores)->toBe([250.0, 350.0, 450.0]); // Original: 300, 400, 500
});

test('is and isNot with relationships', function () {
    $user = User::create(['name' => 'Author']);
    $post = Post::create(['title' => 'My Post', 'user_id' => $user->id]);

    $author = $post->user;
    $originalUser = User::find($user->id);

    expect($author->is($originalUser))->toBeTrue();
    expect($author->is($user))->toBeTrue();
});

test('increment and decrement handle null values', function () {
    $user = User::create(['name' => 'Test', 'score' => null]);

    // Incrementing null should treat it as 0
    $user->increment('score', 5);
    expect($user->fresh()->score)->toBe(5.0);

    // Reset to null
    $user->update(['score' => null]);

    // Decrementing null should treat it as 0
    $user->decrement('score', 3);
    expect($user->fresh()->score)->toBe(-3.0);
});

test('model comparison with soft deleted models', function () {
    // Assuming soft deletes are available
    $user = User::create(['name' => 'SoftDeleted']);
    $userId = $user->id;

    $user->delete();

    // Retrieve with trashed
    $trashedUser = User::withTrashed()->find($userId);

    expect($user->is($trashedUser))->toBeTrue();
});
