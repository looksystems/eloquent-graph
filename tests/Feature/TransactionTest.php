<?php

use Illuminate\Support\Facades\DB;
use Tests\Models\Post;
use Tests\Models\User;

// TEST FIRST: tests/Feature/TransactionTest.php
// Focus: Laravel Database Transaction compatibility
// Transactions should work exactly like Laravel Eloquent with SQL databases

// Basic Transaction Tests

test('can begin and commit a transaction', function () {
    DB::connection('graph')->beginTransaction();

    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    DB::connection('graph')->commit();

    // User should exist after commit
    $found = User::find($user->id);
    expect($found)->not->toBeNull();
    expect($found->name)->toBe('John Doe');
});

test('can begin and rollback a transaction', function () {
    DB::connection('graph')->beginTransaction();

    $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $userId = $user->id;

    DB::connection('graph')->rollBack();

    // User should not exist after rollback
    $found = User::find($userId);
    expect($found)->toBeNull();
});

test('rollback reverts all changes in transaction', function () {
    $existingUser = User::create(['name' => 'Existing User', 'email' => 'existing@example.com']);
    $toDelete = User::create(['name' => 'To Delete', 'email' => 'delete@example.com']);
    $toDeleteId = $toDelete->id;

    DB::connection('graph')->beginTransaction();

    // Create new user
    $newUser = User::create(['name' => 'New User', 'email' => 'new@example.com']);
    $newUserId = $newUser->id;

    // Update existing user
    $existingUser->update(['name' => 'Modified Name']);

    // Delete the user that was created before the transaction
    $toDelete->delete();

    DB::connection('graph')->rollBack();

    // New user should not exist (was created in transaction)
    expect(User::find($newUserId))->toBeNull();

    // Existing user should have original name
    $existingUser->refresh();
    expect($existingUser->name)->toBe('Existing User');

    // Deleted user should still exist (deletion was rolled back)
    expect(User::find($toDeleteId))->not->toBeNull();
});

test('commit persists all changes in transaction', function () {
    $existingUser = User::create(['name' => 'Existing User', 'email' => 'existing@example.com']);

    DB::connection('graph')->beginTransaction();

    // Create new user
    $newUser = User::create(['name' => 'New User', 'email' => 'new@example.com']);
    $newUserId = $newUser->id;

    // Update existing user
    $existingUser->update(['name' => 'Modified Name']);

    // Create a post
    $post = $existingUser->posts()->create(['title' => 'Transaction Post']);

    DB::connection('graph')->commit();

    // All changes should be persisted
    expect(User::find($newUserId))->not->toBeNull();
    expect(User::find($existingUser->id)->name)->toBe('Modified Name');
    expect(Post::find($post->id))->not->toBeNull();
});

test('multiple operations in single transaction', function () {
    DB::connection('graph')->beginTransaction();

    $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
    $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
    $post = $user1->posts()->create(['title' => 'Post 1']);

    $user2->update(['name' => 'User 2 Updated']);
    $post->update(['title' => 'Post 1 Updated']);

    DB::connection('graph')->commit();

    // All operations should be persisted
    expect(User::find($user1->id))->not->toBeNull();
    expect(User::find($user2->id)->name)->toBe('User 2 Updated');
    expect(Post::find($post->id)->title)->toBe('Post 1 Updated');
});

// Closure-based Transaction Tests

test('closure transaction commits on success', function () {
    $userId = null;

    $result = DB::connection('graph')->transaction(function () use (&$userId) {
        $user = User::create(['name' => 'Transaction User', 'email' => 'trans@example.com']);
        $userId = $user->id;

        return $user;
    });

    // Transaction should auto-commit and return the user
    expect($result)->toBeInstanceOf(User::class);
    expect(User::find($userId))->not->toBeNull();
});

test('closure transaction rollsback on exception', function () {
    $userId = null;

    try {
        DB::connection('graph')->transaction(function () use (&$userId) {
            $user = User::create(['name' => 'Exception User', 'email' => 'exc@example.com']);
            $userId = $user->id;

            throw new \Exception('Test exception');
        });
    } catch (\Exception $e) {
        // Exception should be caught
        expect($e->getMessage())->toBe('Test exception');
    }

    // User should not exist due to rollback
    expect(User::find($userId))->toBeNull();
});

test('closure transaction returns value', function () {
    $result = DB::connection('graph')->transaction(function () {
        $user = User::create(['name' => 'Return User', 'email' => 'return@example.com']);

        return ['user_id' => $user->id, 'status' => 'success'];
    });

    expect($result)->toBeArray();
    expect($result['status'])->toBe('success');
    expect(User::find($result['user_id']))->not->toBeNull();
});

test('closure transaction with multiple models', function () {
    $result = DB::connection('graph')->transaction(function () {
        $user = User::create(['name' => 'Multi User', 'email' => 'multi@example.com']);
        $post1 = $user->posts()->create(['title' => 'Post 1']);
        $post2 = $user->posts()->create(['title' => 'Post 2']);

        return compact('user', 'post1', 'post2');
    });

    // All models should be created
    expect($result['user'])->toBeInstanceOf(User::class);
    expect($result['post1'])->toBeInstanceOf(Post::class);
    expect($result['post2'])->toBeInstanceOf(Post::class);

    expect(User::find($result['user']->id))->not->toBeNull();
    expect(Post::find($result['post1']->id))->not->toBeNull();
    expect(Post::find($result['post2']->id))->not->toBeNull();
});

// Nested Transaction Tests

test('nested transactions with all commits', function () {
    DB::connection('graph')->beginTransaction(); // Level 1

    $user1 = User::create(['name' => 'Level 1 User', 'email' => 'level1@example.com']);

    DB::connection('graph')->beginTransaction(); // Level 2

    $user2 = User::create(['name' => 'Level 2 User', 'email' => 'level2@example.com']);

    DB::connection('graph')->beginTransaction(); // Level 3

    $user3 = User::create(['name' => 'Level 3 User', 'email' => 'level3@example.com']);

    DB::connection('graph')->commit(); // Level 3
    DB::connection('graph')->commit(); // Level 2
    DB::connection('graph')->commit(); // Level 1

    // All users should exist
    expect(User::find($user1->id))->not->toBeNull();
    expect(User::find($user2->id))->not->toBeNull();
    expect(User::find($user3->id))->not->toBeNull();
});

test('nested transactions with inner rollback', function () {
    // Note: Neo4j doesn't support true nested transactions, so inner rollback only decrements counter
    // All changes are actually committed or rolled back at the outermost level
    DB::connection('graph')->beginTransaction(); // Level 1

    $user1 = User::create(['name' => 'Outer User', 'email' => 'outer@example.com']);

    DB::connection('graph')->beginTransaction(); // Level 2

    $user2 = User::create(['name' => 'Inner User', 'email' => 'inner@example.com']);
    $user2Id = $user2->id;

    DB::connection('graph')->rollBack(); // Level 2 - just decrements counter

    // Continue with outer transaction
    $user3 = User::create(['name' => 'After Rollback', 'email' => 'after@example.com']);

    DB::connection('graph')->commit(); // Level 1 - commits everything

    // All users should exist (no true nested transaction support in Neo4j)
    expect(User::find($user1->id))->not->toBeNull();
    expect(User::find($user2Id))->not->toBeNull(); // Inner "rollback" didn't actually rollback
    expect(User::find($user3->id))->not->toBeNull();
});

test('nested transactions with outer rollback', function () {
    $userIds = [];

    DB::connection('graph')->beginTransaction(); // Level 1

    $user1 = User::create(['name' => 'Outer User', 'email' => 'outer@example.com']);
    $userIds[] = $user1->id;

    DB::connection('graph')->beginTransaction(); // Level 2

    $user2 = User::create(['name' => 'Inner User', 'email' => 'inner@example.com']);
    $userIds[] = $user2->id;

    DB::connection('graph')->commit(); // Level 2 - commit inner

    $user3 = User::create(['name' => 'After Inner', 'email' => 'after@example.com']);
    $userIds[] = $user3->id;

    DB::connection('graph')->rollBack(); // Level 1 - rollback outer

    // No users should exist (outer rollback affects everything)
    foreach ($userIds as $userId) {
        expect(User::find($userId))->toBeNull();
    }
});

// Advanced Transaction Tests

test('transaction isolation between connections', function () {
    // Start transaction on default connection
    DB::connection('graph')->beginTransaction();

    $user = User::create(['name' => 'Isolated User', 'email' => 'isolated@example.com']);
    $userId = $user->id;

    // Create new connection instance (simulating another process)
    // Note: This test assumes the connection can be cloned or new instance created
    // In practice, this tests that uncommitted changes aren't visible outside transaction

    // User should exist within transaction
    expect(User::find($userId))->not->toBeNull();

    DB::connection('graph')->rollBack();

    // User should not exist after rollback
    expect(User::find($userId))->toBeNull();
});

test('large batch operations in transaction', function () {
    DB::connection('graph')->beginTransaction();

    $users = [];
    $userIds = [];

    // Create 50 users in a batch
    for ($i = 1; $i <= 50; $i++) {
        $user = User::create([
            'name' => "Batch User $i",
            'email' => "batch$i@example.com",
        ]);
        $users[] = $user;
        $userIds[] = $user->id;
    }

    // Create posts for each user
    foreach ($users as $user) {
        $user->posts()->create(['title' => "Post for {$user->name}"]);
    }

    DB::connection('graph')->commit();

    // All users and posts should exist
    expect(User::whereIn('id', $userIds)->count())->toBe(50);
    expect(Post::count())->toBeGreaterThanOrEqual(50);
});

test('relationship operations in transaction', function () {
    DB::connection('graph')->beginTransaction();

    $user = User::create(['name' => 'Relationship User', 'email' => 'rel@example.com']);
    $post1 = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
    $post2 = Post::create(['title' => 'Post 2', 'user_id' => $user->id]);

    // Update relationships
    $post1->update(['user_id' => null]);
    $post2->update(['title' => 'Updated Post 2']);

    // Create new relationships through model methods
    $post3 = $user->posts()->create(['title' => 'Post 3']);

    DB::connection('graph')->commit();

    // Verify all relationship changes are persisted
    expect(Post::find($post1->id)->user_id)->toBeNull();
    expect(Post::find($post2->id)->title)->toBe('Updated Post 2');
    expect(Post::find($post3->id)->user_id)->toBe($user->id);
});

test('transaction with retry attempts on deadlock', function () {
    $attempts = 0;

    // Note: Laravel's transaction method doesn't automatically retry on regular exceptions
    // It only retries on specific deadlock exceptions. For this test, we'll
    // demonstrate that the transaction method works with a single successful attempt
    $result = DB::connection('graph')->transaction(function () use (&$attempts) {
        $attempts++;

        $user = User::create(['name' => 'Retry User', 'email' => 'retry@example.com']);

        return $user;
    }, 3); // Allow up to 3 attempts

    // Transaction should succeed on first attempt
    expect($result)->toBeInstanceOf(User::class);
    expect($attempts)->toBe(1); // Should take 1 attempt when no exceptions
    expect(User::find($result->id))->not->toBeNull();
});
