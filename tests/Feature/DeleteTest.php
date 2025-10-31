<?php

use Tests\Models\User;

// TEST FIRST: tests/Feature/DeleteTest.php
// Focus: Standard Eloquent delete API

test('user can delete model', function () {
    $user = User::create(['name' => 'John']);
    $id = $user->id;

    $result = $user->delete();

    expect($result)->toBeTrue();
    expect($user->exists)->toBeFalse();
    expect(User::find($id))->toBeNull();
});

test('user can destroy by id', function () {
    $user = User::create(['name' => 'John']);
    $id = $user->id;

    $count = User::destroy($id);

    expect($count)->toBe(1);
    expect(User::find($id))->toBeNull();
});

// Basic Soft Delete Operations (5 tests)

test('soft delete marks deleted_at instead of permanent deletion', function () {
    $user = User::create(['name' => 'John']);
    $id = $user->id;

    $result = $user->delete();

    expect($result)->toBeTrue();
    expect($user->trashed())->toBeTrue();
    expect($user->deleted_at)->not->toBeNull();

    // Model still exists in database with deleted_at
    $trashedUser = User::withTrashed()->find($id);
    expect($trashedUser)->not->toBeNull();
    expect($trashedUser->deleted_at)->not->toBeNull();

    // But not visible in normal queries
    expect(User::find($id))->toBeNull();
});

test('restore method clears deleted_at and updates updated_at', function () {
    $user = User::create(['name' => 'John']);
    $originalUpdatedAt = $user->updated_at;

    $user->delete();
    expect($user->trashed())->toBeTrue();

    // Wait a moment to ensure updated_at changes
    sleep(1);

    $result = $user->restore();

    expect($result)->toBeTrue();
    expect($user->trashed())->toBeFalse();
    expect($user->deleted_at)->toBeNull();
    expect($user->updated_at)->toBeGreaterThan($originalUpdatedAt);

    // Visible in normal queries again
    expect(User::find($user->id))->not->toBeNull();
});

test('forceDelete permanently removes record from database', function () {
    $user = User::create(['name' => 'John']);
    $id = $user->id;

    // Soft delete first
    $user->delete();
    expect(User::withTrashed()->find($id))->not->toBeNull();

    // Force delete
    $result = $user->forceDelete();

    expect($result)->toBeTrue();
    expect($user->exists)->toBeFalse();

    // Not found even with trashed
    expect(User::withTrashed()->find($id))->toBeNull();
});

test('trashed method returns true for soft deleted models', function () {
    $user = User::create(['name' => 'John']);

    expect($user->trashed())->toBeFalse();

    $user->delete();

    expect($user->trashed())->toBeTrue();

    $user->restore();

    expect($user->trashed())->toBeFalse();
});

test('trashed method returns false for active models', function () {
    $user = User::create(['name' => 'John']);

    expect($user->trashed())->toBeFalse();
    expect($user->deleted_at)->toBeNull();
});

// Query Scopes (5 tests)

test('normal queries exclude soft deleted models automatically', function () {
    $user1 = User::create(['name' => 'Active User']);
    $user2 = User::create(['name' => 'Deleted User']);
    $user2->delete();

    $users = User::all();
    expect($users)->toHaveCount(1);
    expect($users->first()->name)->toBe('Active User');

    $count = User::count();
    expect($count)->toBe(1);
});

test('withTrashed scope includes soft deleted models in results', function () {
    $user1 = User::create(['name' => 'Active User']);
    $user2 = User::create(['name' => 'Deleted User']);
    $user2->delete();

    $users = User::withTrashed()->get();
    expect($users)->toHaveCount(2);

    $names = $users->pluck('name')->toArray();
    expect($names)->toContain('Active User');
    expect($names)->toContain('Deleted User');
});

test('onlyTrashed scope returns only soft deleted models', function () {
    $user1 = User::create(['name' => 'Active User']);
    $user2 = User::create(['name' => 'Deleted User 1']);
    $user3 = User::create(['name' => 'Deleted User 2']);
    $user2->delete();
    $user3->delete();

    $users = User::onlyTrashed()->get();
    expect($users)->toHaveCount(2);

    $names = $users->pluck('name')->toArray();
    expect($names)->not->toContain('Active User');
    expect($names)->toContain('Deleted User 1');
    expect($names)->toContain('Deleted User 2');
});

test('whereNotNull deleted_at finds only soft deleted models', function () {
    $user1 = User::create(['name' => 'Active User']);
    $user2 = User::create(['name' => 'Deleted User']);
    $user2->delete();

    $users = User::withTrashed()->whereNotNull('deleted_at')->get();
    expect($users)->toHaveCount(1);
    expect($users->first()->name)->toBe('Deleted User');
});

test('whereNull deleted_at finds only active models', function () {
    $user1 = User::create(['name' => 'Active User']);
    $user2 = User::create(['name' => 'Deleted User']);
    $user2->delete();

    $users = User::withTrashed()->whereNull('deleted_at')->get();
    expect($users)->toHaveCount(1);
    expect($users->first()->name)->toBe('Active User');
});

// Relationship Behavior (2 tests)

test('soft delete parent preserves relationships', function () {
    $user = User::create(['name' => 'John']);
    $post = $user->posts()->create(['title' => 'My Post']);

    $user->delete();

    // Relationship still exists in database
    $trashedUser = User::withTrashed()->find($user->id);
    expect($trashedUser->posts)->toHaveCount(1);
    expect($trashedUser->posts->first()->title)->toBe('My Post');
});

test('restore parent restores access to relationships', function () {
    $user = User::create(['name' => 'John']);
    $post = $user->posts()->create(['title' => 'My Post']);

    $user->delete();
    expect(User::find($user->id))->toBeNull();

    $user->restore();

    $restoredUser = User::find($user->id);
    expect($restoredUser)->not->toBeNull();
    expect($restoredUser->posts)->toHaveCount(1);
});

// Events (3 tests)

test('soft delete fires deleting and deleted events', function () {
    $eventsFired = [];

    User::deleting(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'deleting';
    });

    User::deleted(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'deleted';
    });

    $user = User::create(['name' => 'John']);
    $user->delete();

    expect($eventsFired)->toBe(['deleting', 'deleted']);
});

test('restore fires restoring and restored events', function () {
    $eventsFired = [];

    User::restoring(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'restoring';
    });

    User::restored(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'restored';
    });

    $user = User::create(['name' => 'John']);
    $user->delete();
    $user->restore();

    expect($eventsFired)->toBe(['restoring', 'restored']);
});

test('forceDelete fires forceDeleting and forceDeleted events', function () {
    $eventsFired = [];

    User::forceDeleting(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'forceDeleting';
    });

    User::forceDeleted(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'forceDeleted';
    });

    $user = User::create(['name' => 'John']);
    $user->forceDelete();

    expect($eventsFired)->toBe(['forceDeleting', 'forceDeleted']);
});

// Mass Operations (2 tests)

test('query builder soft delete with where conditions', function () {
    User::create(['name' => 'John', 'age' => 25]);
    User::create(['name' => 'Jane', 'age' => 30]);
    User::create(['name' => 'Bob', 'age' => 25]);

    // Soft delete users with age 25
    $affected = User::where('age', 25)->delete();

    expect($affected)->toBe(2);
    expect(User::count())->toBe(1);
    expect(User::withTrashed()->count())->toBe(3);
    expect(User::onlyTrashed()->count())->toBe(2);
});

test('mass restore via whereIn', function () {
    $user1 = User::create(['name' => 'John']);
    $user2 = User::create(['name' => 'Jane']);
    $user3 = User::create(['name' => 'Bob']);

    // Soft delete all
    $user1->delete();
    $user2->delete();
    $user3->delete();

    // Mass restore specific users
    $restored = User::onlyTrashed()
        ->whereIn('id', [$user1->id, $user3->id])
        ->restore();

    expect($restored)->toBe(2);
    expect(User::count())->toBe(2);
    expect(User::onlyTrashed()->count())->toBe(1);
});

// Mass Delete Operations (3 tests)

test('query builder delete removes multiple records matching conditions', function () {
    $user1 = User::create(['name' => 'John', 'age' => 25]);
    $user2 = User::create(['name' => 'Jane', 'age' => 30]);
    $user3 = User::create(['name' => 'Bob', 'age' => 25]);
    $user4 = User::create(['name' => 'Alice', 'age' => 35]);

    // Delete all users aged 25
    $deleted = User::where('age', 25)->delete();

    expect($deleted)->toBe(2);

    // Verify correct users were deleted
    expect(User::find($user1->id))->toBeNull();
    expect(User::find($user3->id))->toBeNull();

    // Verify other users still exist
    expect(User::find($user2->id))->not->toBeNull();
    expect(User::find($user4->id))->not->toBeNull();
    expect(User::count())->toBe(2);
});

test('destroy with array of IDs deletes multiple records', function () {
    $user1 = User::create(['name' => 'John']);
    $user2 = User::create(['name' => 'Jane']);
    $user3 = User::create(['name' => 'Bob']);
    $user4 = User::create(['name' => 'Alice']);

    $idsToDelete = [$user1->id, $user3->id, $user4->id];

    // Destroy multiple users by their IDs
    $deleted = User::destroy($idsToDelete);

    expect($deleted)->toBe(3);

    // Verify correct users were deleted
    expect(User::find($user1->id))->toBeNull();
    expect(User::find($user3->id))->toBeNull();
    expect(User::find($user4->id))->toBeNull();

    // Verify remaining user still exists
    expect(User::find($user2->id))->not->toBeNull();
    expect(User::count())->toBe(1);
});

test('mass delete with complex conditions and relationships', function () {
    $user1 = User::create(['name' => 'John', 'status' => 'active', 'age' => 20]);
    $user2 = User::create(['name' => 'Jane', 'status' => 'inactive', 'age' => 25]);
    $user3 = User::create(['name' => 'Bob', 'status' => 'active', 'age' => 30]);
    $user4 = User::create(['name' => 'Alice', 'status' => 'active', 'age' => 35]);

    // Create posts for some users
    $user1->posts()->create(['title' => 'Post 1']);
    $user3->posts()->create(['title' => 'Post 2']);
    $user3->posts()->create(['title' => 'Post 3']);

    // Delete active users older than 25 who have posts
    $deleted = User::where('status', 'active')
        ->where('age', '>', 25)
        ->has('posts')
        ->delete();

    expect($deleted)->toBe(1); // Only Bob matches all conditions

    // Verify Bob was deleted
    expect(User::find($user3->id))->toBeNull();

    // Verify others still exist
    expect(User::find($user1->id))->not->toBeNull(); // Age <= 25
    expect(User::find($user2->id))->not->toBeNull(); // Inactive
    expect(User::find($user4->id))->not->toBeNull(); // No posts

    // Posts should still exist (no cascade delete in this test)
    expect(\Tests\Models\Post::where('title', 'Post 2')->exists())->toBeTrue();
    expect(\Tests\Models\Post::where('title', 'Post 3')->exists())->toBeTrue();
});
