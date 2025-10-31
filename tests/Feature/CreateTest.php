<?php

use Tests\Models\User;

// TEST FIRST: tests/Feature/CreateTest.php
// Focus: Standard Eloquent create API

// TEST ISOLATION NOTE:
// Event listeners registered in these tests are automatically cleaned up by
// Neo4jTestCase::clearModelEventListeners() in tearDown(). The base test case
// ensures complete isolation between tests by:
// - Flushing all event listeners for each model
// - Unsetting event dispatchers
// - Clearing database state
// - Resetting connections
// Manual cleanup in individual tests (e.g., User::flushEventListeners()) is
// optional but demonstrates explicit cleanup intent for documentation purposes.

test('user can create model with eloquent create', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    expect($user->id)->not->toBeNull();
    expect($user->name)->toBe('John Doe');
    expect($user->email)->toBe('john@example.com');
    expect($user->exists)->toBeTrue();
});

test('user can save new model', function () {
    $user = new User;
    $user->name = 'Jane Doe';
    $user->email = 'jane@example.com';
    $result = $user->save();

    expect($result)->toBeTrue();
    expect($user->id)->not->toBeNull();
    expect($user->exists)->toBeTrue();
});

// Creation Methods (6 tests)

test('create persists model to database immediately', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    expect($user->exists)->toBeTrue();
    expect($user->id)->not->toBeNull();

    // Verify it's in the database
    $found = User::find($user->id);
    expect($found)->not->toBeNull();
    expect($found->name)->toBe('John');
});

test('make creates model instance without persisting', function () {
    $user = User::make(['name' => 'John', 'email' => 'john@example.com']);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
    expect($user->exists)->toBeFalse();
    expect($user->id)->toBeNull();

    // Verify it's NOT in the database
    expect(User::where('email', 'john@example.com')->first())->toBeNull();
});

test('forceCreate bypasses mass assignment protection', function () {
    // ForceCreate should set all attributes regardless of fillable
    $user = User::forceCreate([
        'name' => 'John',
        'email' => 'john@example.com',
        'age' => 30,
    ]);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
    expect($user->age)->toBe(30);
    expect($user->exists)->toBeTrue();
});

test('fill sets attributes without persisting', function () {
    $user = new User;
    $user->fill(['name' => 'John', 'email' => 'john@example.com']);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
    expect($user->exists)->toBeFalse();
    expect($user->id)->toBeNull();
});

test('firstOrCreate finds existing or creates new', function () {
    // Create first user
    $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);

    // Try to find or create with same email
    $user2 = User::firstOrCreate(
        ['email' => 'john@example.com'],
        ['name' => 'Different John']
    );

    expect($user2->id)->toBe($user1->id);
    expect($user2->name)->toBe('John'); // Original name, not "Different John"

    // Try with non-existent email
    $user3 = User::firstOrCreate(
        ['email' => 'jane@example.com'],
        ['name' => 'Jane']
    );

    expect($user3->id)->not->toBe($user1->id);
    expect($user3->name)->toBe('Jane');
    expect($user3->exists)->toBeTrue();
});

test('firstOrNew finds existing or returns new instance', function () {
    // Create first user
    $user1 = User::create(['name' => 'John', 'email' => 'john@example.com']);

    // Try to find or new with same email
    $user2 = User::firstOrNew(
        ['email' => 'john@example.com'],
        ['name' => 'Different John']
    );

    expect($user2->id)->toBe($user1->id);
    expect($user2->name)->toBe('John');
    expect($user2->exists)->toBeTrue();

    // Try with non-existent email
    $user3 = User::firstOrNew(
        ['email' => 'jane@example.com'],
        ['name' => 'Jane']
    );

    expect($user3->name)->toBe('Jane');
    expect($user3->exists)->toBeFalse(); // Not saved yet
    expect($user3->id)->toBeNull();
});

// Mass Assignment (4 tests)

test('fillable property controls mass assignable attributes', function () {
    // Create with fillable attributes
    $user = User::create([
        'name' => 'John',
        'email' => 'john@example.com',
        'non_fillable_field' => 'should_not_be_set', // This shouldn't work unless it's in fillable
    ]);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
});

test('fillable property allows mass assignment', function () {
    // The User model has these fields in fillable, so they should work
    $user = new User;
    $user->fill([
        'name' => 'John',
        'email' => 'john@example.com',
    ]);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
    expect($user->exists)->toBeFalse(); // Not saved yet
});

test('non-fillable attributes are ignored in create', function () {
    $user = User::create([
        'name' => 'John',
        'email' => 'john@example.com',
        'password' => 'secret-password-should-not-work', // password is not in fillable
    ]);

    expect($user->name)->toBe('John');
    expect($user->password)->toBeNull(); // Non-fillable attribute should not be set
});

test('forceCreate sets all attributes regardless of fillable', function () {
    // ForceCreate doesn't respect fillable restrictions
    $user = User::forceCreate([
        'name' => 'John',
        'email' => 'forced@example.com',
        'status' => 'active',
    ]);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('forced@example.com');
    expect($user->status)->toBe('active');
    expect($user->exists)->toBeTrue();
});

// Timestamps & Flags (3 tests)

test('wasRecentlyCreated is true after create', function () {
    $user = User::create(['name' => 'John']);

    expect($user->wasRecentlyCreated)->toBeTrue();

    // After fetching from database, it should be false
    $fetched = User::find($user->id);
    expect($fetched->wasRecentlyCreated)->toBeFalse();
});

test('created_at is set on creation', function () {
    $user = User::create(['name' => 'John']);

    expect($user->created_at)->not->toBeNull();
    expect($user->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('updated_at is set on creation', function () {
    $user = User::create(['name' => 'John']);

    expect($user->updated_at)->not->toBeNull();
    expect($user->updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($user->updated_at->timestamp)->toBe($user->created_at->timestamp);
});

// Events (2 tests)

test('creating and created events fire on create', function () {
    $eventsFired = [];

    User::creating(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'creating';
    });

    User::created(function ($user) use (&$eventsFired) {
        $eventsFired[] = 'created';
    });

    User::create(['name' => 'John']);

    expect($eventsFired)->toBe(['creating', 'created']);
});

test('returning false from creating event prevents creation', function () {
    User::creating(function ($user) {
        return false; // Cancel creation
    });

    $user = User::create(['name' => 'John']);

    expect($user->exists)->toBeFalse();
    expect($user->id)->toBeNull();

    // Verify not in database
    expect(User::where('name', 'John')->first())->toBeNull();

    // Clean up event listener
    User::flushEventListeners();
});

// Edge Cases (2 tests)

test('create with null values stores nulls correctly', function () {
    $user = User::create([
        'name' => 'John',
        'email' => null,
        'age' => null,
    ]);

    expect($user->name)->toBe('John');
    expect($user->email)->toBeNull();
    expect($user->age)->toBeNull();

    // Verify in database
    $fetched = User::find($user->id);
    expect($fetched->email)->toBeNull();
    expect($fetched->age)->toBeNull();
});

test('create with default values uses model defaults', function () {
    $user = User::create(['name' => 'John']);

    // These should get default values or null
    expect($user->name)->toBe('John');
    expect($user->exists)->toBeTrue();

    // Timestamps should be set automatically
    expect($user->created_at)->not->toBeNull();
    expect($user->updated_at)->not->toBeNull();
});
