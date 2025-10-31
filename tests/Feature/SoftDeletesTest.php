<?php

use Carbon\Carbon;
use Tests\Models\User;
use Tests\Models\UserWithSoftDeletes;

// TEST FIRST: tests/Feature/SoftDeletesTest.php
// Focus: Laravel SoftDeletes trait compatibility
// Soft deletes should work exactly like Laravel Eloquent

test('model with soft deletes can be soft deleted', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $result = $user->delete();

    expect($result)->toBeTrue();
    expect($user->deleted_at)->not->toBeNull();
    expect($user->deleted_at)->toBeInstanceOf(Carbon::class);
    expect($user->trashed())->toBeTrue();
});

test('soft deleted models are excluded from normal queries', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $user1->delete(); // Soft delete

    $users = UserWithSoftDeletes::all();

    expect($users)->toHaveCount(1);
    expect($users->first()->name)->toBe('Jane Doe');
});

test('soft deleted models can be found with withTrashed scope', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $user1->delete(); // Soft delete

    $users = UserWithSoftDeletes::withTrashed()->get();

    expect($users)->toHaveCount(2);
});

test('only soft deleted models can be retrieved with onlyTrashed scope', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $user1->delete(); // Soft delete

    $users = UserWithSoftDeletes::onlyTrashed()->get();

    expect($users)->toHaveCount(1);
    expect($users->first()->name)->toBe('John Doe');
    expect($users->first()->trashed())->toBeTrue();
});

test('soft deleted model can be restored', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $user->delete(); // Soft delete
    expect($user->trashed())->toBeTrue();

    $result = $user->restore();

    expect($result)->toBeTrue();
    expect($user->deleted_at)->toBeNull();
    expect($user->trashed())->toBeFalse();
});

test('restored model appears in normal queries again', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $user1->delete(); // Soft delete
    expect(UserWithSoftDeletes::all())->toHaveCount(1);

    $user1->restore();

    $users = UserWithSoftDeletes::all();
    expect($users)->toHaveCount(2);
});

test('model can be force deleted permanently', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $userId = $user->id;

    $result = $user->forceDelete();

    expect($result)->toBeTrue();
    expect($user->exists)->toBeFalse();

    // Should not be found in any query
    expect(UserWithSoftDeletes::find($userId))->toBeNull();
    expect(UserWithSoftDeletes::withTrashed()->find($userId))->toBeNull();
    expect(UserWithSoftDeletes::onlyTrashed()->find($userId))->toBeNull();
});

test('soft deleted model can be force deleted', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $userId = $user->id;

    $user->delete(); // Soft delete first
    expect($user->trashed())->toBeTrue();

    $result = $user->forceDelete();

    expect($result)->toBeTrue();
    expect($user->exists)->toBeFalse();

    // Should not be found in any query
    expect(UserWithSoftDeletes::withTrashed()->find($userId))->toBeNull();
});

test('trashed method returns true for soft deleted models', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    expect($user->trashed())->toBeFalse();

    $user->delete();

    expect($user->trashed())->toBeTrue();
});

test('trashed method returns false for non-deleted models', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    expect($user->trashed())->toBeFalse();
});

test('models without soft deletes trait are permanently deleted', function () {
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $userId = $user->id;

    $result = $user->delete();

    expect($result)->toBeTrue();
    expect($user->exists)->toBeFalse();
    expect(User::find($userId))->toBeNull();
});

test('whereNotNull on deleted_at finds only soft deleted models', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $user1->delete(); // Soft delete

    $trashedUsers = UserWithSoftDeletes::withTrashed()->whereNotNull('deleted_at')->get();

    expect($trashedUsers)->toHaveCount(1);
    expect($trashedUsers->first()->name)->toBe('John Doe');
});

test('whereNull on deleted_at finds only active models', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $user1->delete(); // Soft delete

    $activeUsers = UserWithSoftDeletes::withTrashed()->whereNull('deleted_at')->get();

    expect($activeUsers)->toHaveCount(1);
    expect($activeUsers->first()->name)->toBe('Jane Doe');
});

test('soft deletes can be used with where clauses', function () {
    $user1 = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = UserWithSoftDeletes::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $user3 = UserWithSoftDeletes::create(['name' => 'Bob Smith', 'email' => 'bob@example.com']);

    $user1->delete(); // Soft delete
    $user2->delete(); // Soft delete

    $trashedJohn = UserWithSoftDeletes::onlyTrashed()->where('name', 'John Doe')->first();

    expect($trashedJohn)->not->toBeNull();
    expect($trashedJohn->name)->toBe('John Doe');
    expect($trashedJohn->trashed())->toBeTrue();
});

test('soft deletes work with relationships', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    // Note: This test assumes Post model doesn't have soft deletes
    // The user should be soft deleted but posts should remain
    $post = $user->posts()->create(['title' => 'My Post']);

    $user->delete(); // Soft delete user

    // User should be soft deleted
    expect(UserWithSoftDeletes::find($user->id))->toBeNull();
    expect(UserWithSoftDeletes::withTrashed()->find($user->id))->not->toBeNull();

    // Post should still exist (assuming no cascade soft delete)
    expect($post->fresh())->not->toBeNull();
});

test('restored model updates updated_at timestamp', function () {
    $user = UserWithSoftDeletes::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $originalUpdatedAt = $user->updated_at;

    sleep(1); // Ensure timestamp difference

    $user->delete(); // Soft delete
    $user->restore();

    expect($user->updated_at->greaterThan($originalUpdatedAt))->toBeTrue();
});
