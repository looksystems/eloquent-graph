<?php

use Tests\Models\User;

// TEST FIRST: tests/Feature/UpdateTest.php
// Focus: Standard Eloquent update API

test('user can update model attributes', function () {
    $user = User::create(['name' => 'John']);

    $user->name = 'Jane';
    $result = $user->save();

    expect($result)->toBeTrue();

    $fresh = User::find($user->id);
    expect($fresh->name)->toBe('Jane');
});

test('user can mass update', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $result = $user->update(['name' => 'Jane', 'email' => 'jane@example.com']);

    expect($result)->toBeTrue();
    expect($user->name)->toBe('Jane');
    expect($user->email)->toBe('jane@example.com');
});

// Basic Updates (5 tests)

test('fill and save updates model attributes', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $user->fill(['name' => 'Jane', 'email' => 'jane@example.com']);
    $result = $user->save();

    expect($result)->toBeTrue();
    expect($user->name)->toBe('Jane');
    expect($user->email)->toBe('jane@example.com');
});

test('update method mass updates attributes', function () {
    $user = User::create(['name' => 'John', 'age' => 25]);

    $result = $user->update(['name' => 'Jane', 'age' => 30]);

    expect($result)->toBeTrue();
    expect($user->name)->toBe('Jane');
    expect($user->age)->toBe(30);
});

test('increment increases numeric column value', function () {
    $user = User::create(['name' => 'John', 'score' => 100]);

    $user->increment('score');
    expect($user->score)->toBe(101.0);

    $user->increment('score', 5);
    expect($user->score)->toBe(106.0);
});

test('decrement decreases numeric column value', function () {
    $user = User::create(['name' => 'John', 'score' => 100]);

    $user->decrement('score');
    expect($user->score)->toBe(99.0);

    $user->decrement('score', 10);
    expect($user->score)->toBe(89.0);
});

test('touch updates timestamps without other changes', function () {
    $user = User::create(['name' => 'John']);
    $originalUpdatedAt = $user->updated_at;

    sleep(1); // Ensure timestamp difference

    $result = $user->touch();

    expect($result)->toBeTrue();
    expect($user->updated_at)->toBeGreaterThan($originalUpdatedAt);
    expect($user->name)->toBe('John'); // Other attributes unchanged
});

// Dirty Tracking (4 tests)

test('isDirty returns true after attribute changes', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    expect($user->isDirty())->toBeFalse();

    $user->name = 'Jane';
    expect($user->isDirty())->toBeTrue();
    expect($user->isDirty('name'))->toBeTrue();
    expect($user->isDirty('email'))->toBeFalse();
});

test('wasChanged returns true after save', function () {
    $user = User::create(['name' => 'John']);

    $user->name = 'Jane';
    $user->save();

    expect($user->wasChanged())->toBeTrue();
    expect($user->wasChanged('name'))->toBeTrue();
    expect($user->wasChanged('email'))->toBeFalse();
});

test('getOriginal returns values before changes', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $user->name = 'Jane';
    $user->email = 'jane@example.com';

    expect($user->getOriginal('name'))->toBe('John');
    expect($user->getOriginal('email'))->toBe('john@example.com');
    expect($user->getOriginal())->toBeArray();
});

test('getDirty returns only changed attributes', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $user->name = 'Jane';

    $dirty = $user->getDirty();

    expect($dirty)->toHaveKey('name');
    expect($dirty['name'])->toBe('Jane');
    expect($dirty)->not->toHaveKey('email');
});

// Instance Management (3 tests)

test('fresh retrieves new instance from database', function () {
    $user = User::create(['name' => 'John']);

    $user->name = 'Jane'; // Change in memory only

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('John'); // Original value from DB
    expect($user->name)->toBe('Jane'); // Changed value in memory
});

test('refresh reloads current instance with latest data', function () {
    $user = User::create(['name' => 'John']);
    $id = $user->id;

    // Update via another query
    User::where('id', $id)->update(['name' => 'Jane']);

    $user->refresh();

    expect($user->name)->toBe('Jane');
});

test('fresh returns null for force deleted models', function () {
    $user = User::create(['name' => 'John']);
    $id = $user->id;

    // Force delete the model (not soft delete)
    User::find($id)->forceDelete();

    $fresh = $user->fresh();

    expect($fresh)->toBeNull();
});

// Mass Operations (2 tests)

test('query builder update with where conditions', function () {
    User::create(['name' => 'John', 'age' => 25]);
    User::create(['name' => 'Jane', 'age' => 30]);
    User::create(['name' => 'Bob', 'age' => 25]);

    $affected = User::where('age', 25)->update(['status' => 'young']);

    expect($affected)->toBe(2);

    $youngUsers = User::where('status', 'young')->get();
    expect($youngUsers)->toHaveCount(2);
});

test('query builder increment on multiple records', function () {
    User::create(['name' => 'John', 'score' => 100]);
    User::create(['name' => 'Jane', 'score' => 200]);

    $affected = User::whereIn('name', ['John', 'Jane'])->increment('score', 10);

    expect($affected)->toBe(2);
    expect(User::where('name', 'John')->first()->score)->toBe(110.0);
    expect(User::where('name', 'Jane')->first()->score)->toBe(210.0);
});

test('query builder decrement decreases values with where clause', function () {
    User::create(['name' => 'John', 'score' => 100, 'lives' => 5]);
    User::create(['name' => 'Jane', 'score' => 200, 'lives' => 3]);
    User::create(['name' => 'Bob', 'score' => 150, 'lives' => 1]);

    // Decrement lives for users with score >= 150
    $affected = User::where('score', '>=', 150)->decrement('lives', 1);

    expect($affected)->toBe(2);

    $jane = User::where('name', 'Jane')->first();
    $bob = User::where('name', 'Bob')->first();
    $john = User::where('name', 'John')->first();

    expect($jane->lives)->toBe(2); // Was 3, decremented
    expect($bob->lives)->toBe(0);  // Was 1, decremented
    expect($john->lives)->toBe(5); // Unchanged, score < 150
});

test('mass update with complex where conditions affects correct records', function () {
    User::create(['name' => 'John', 'age' => 25, 'status' => 'active', 'role' => 'user']);
    User::create(['name' => 'Jane', 'age' => 30, 'status' => 'active', 'role' => 'admin']);
    User::create(['name' => 'Bob', 'age' => 35, 'status' => 'inactive', 'role' => 'user']);
    User::create(['name' => 'Alice', 'age' => 28, 'status' => 'active', 'role' => 'user']);

    // Update all active users who are not admins and are under 30
    $affected = User::where('status', 'active')
        ->where('role', '!=', 'admin')
        ->where('age', '<', 30)
        ->update(['verified' => true, 'level' => 'premium']);

    expect($affected)->toBe(2); // John and Alice

    $john = User::where('name', 'John')->first();
    $alice = User::where('name', 'Alice')->first();
    $jane = User::where('name', 'Jane')->first();
    $bob = User::where('name', 'Bob')->first();

    expect($john->verified)->toBeTrue();
    expect($john->level)->toBe('premium');
    expect($alice->verified)->toBeTrue();
    expect($alice->level)->toBe('premium');
    expect($jane->verified)->toBeNull(); // Admin, not updated
    expect($bob->verified)->toBeNull();   // Inactive, not updated
});

test('mass update returns zero when no records match conditions', function () {
    User::create(['name' => 'John', 'age' => 25]);
    User::create(['name' => 'Jane', 'age' => 30]);

    // Try to update users with age > 100 (none exist)
    $affected = User::where('age', '>', 100)->update(['status' => 'elderly']);

    expect($affected)->toBe(0);

    // Verify no records were changed
    $users = User::whereNotNull('status')->get();
    expect($users)->toHaveCount(0);
});

// Events & Timestamps (3 tests)

test('updating and updated events fire on save', function () {
    $eventsFired = [];

    User::updating(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'updating';
    });

    User::updated(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'updated';
    });

    $user = User::create(['name' => 'John']);
    $user->name = 'Jane';
    $user->save();

    expect($eventsFired)->toBe(['updating', 'updated']);
});

test('updated_at timestamp changes on update', function () {
    $user = User::create(['name' => 'John']);
    $originalUpdatedAt = $user->updated_at;

    sleep(1); // Ensure timestamp difference

    $user->update(['name' => 'Jane']);

    expect($user->updated_at)->toBeGreaterThan($originalUpdatedAt);
});

test('created_at timestamp does not change on update', function () {
    $user = User::create(['name' => 'John']);
    $originalCreatedAt = $user->created_at;

    sleep(1);

    $user->update(['name' => 'Jane']);

    expect($user->created_at->timestamp)->toBe($originalCreatedAt->timestamp);
});
