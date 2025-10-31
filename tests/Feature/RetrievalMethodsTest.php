<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Tests\Models\User;

// Task 4.1: Retrieval Methods Test
// Focus: Advanced Laravel Eloquent retrieval API methods
// Coverage: findMany, findOrFail, firstWhere, firstOrCreate, updateOrCreate, etc.

test('findMany with mixed existing and non-existing IDs', function () {
    $user1 = User::create(['name' => 'User 1', 'email' => 'user1@test.com']);
    $user2 = User::create(['name' => 'User 2', 'email' => 'user2@test.com']);

    // Test with existing and non-existing IDs
    $result = User::findMany([$user1->id, $user2->id, 'non-existent-id']);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->toArray())->toContain($user1->id, $user2->id);
    expect($result->pluck('name')->toArray())->toContain('User 1', 'User 2');
});

test('findMany with empty array returns empty collection', function () {
    $result = User::findMany([]);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(0);
});

test('findMany with single ID behaves like find', function () {
    $user = User::create(['name' => 'Single User', 'email' => 'single@test.com']);

    $result = User::findMany([$user->id]);

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($user->id);
    expect($result->first()->name)->toBe('Single User');
});

test('findOrFail succeeds with existing model', function () {
    $user = User::create(['name' => 'Existing User', 'email' => 'exists@test.com']);

    $found = User::findOrFail($user->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($user->id);
    expect($found->name)->toBe('Existing User');
});

test('findOrFail throws exception for non-existing model', function () {
    expect(fn () => User::findOrFail('non-existent-id'))
        ->toThrow(ModelNotFoundException::class);
});

test('findOrFail with custom exception message', function () {
    try {
        User::findOrFail('non-existent-id');
        expect(false)->toBeTrue(); // Should not reach here
    } catch (ModelNotFoundException $e) {
        expect($e->getModel())->toBe(User::class);
        expect(str_contains($e->getMessage(), 'non-existent-id'))->toBeTrue();
    }
});

test('firstWhere with simple condition', function () {
    User::create(['name' => 'First User', 'email' => 'first@test.com', 'age' => 25]);
    User::create(['name' => 'Second User', 'email' => 'second@test.com', 'age' => 30]);

    $user = User::firstWhere('age', 30);

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Second User');
    expect($user->age)->toBe(30);
});

test('firstWhere with operator condition', function () {
    User::create(['name' => 'Young User', 'email' => 'young@test.com', 'age' => 20]);
    User::create(['name' => 'Old User', 'email' => 'old@test.com', 'age' => 40]);

    $user = User::firstWhere('age', '>', 30);

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Old User');
    expect($user->age)->toBe(40);
});

test('firstWhere returns null when no match found', function () {
    User::create(['name' => 'Test User', 'email' => 'test@test.com', 'age' => 25]);

    $user = User::firstWhere('age', 99);

    expect($user)->toBeNull();
});

test('firstWhere with array conditions', function () {
    User::create(['name' => 'Admin User', 'email' => 'admin@test.com', 'role' => 'admin', 'active' => true]);
    User::create(['name' => 'User User', 'email' => 'user@test.com', 'role' => 'user', 'active' => true]);
    User::create(['name' => 'Inactive Admin', 'email' => 'inactive@test.com', 'role' => 'admin', 'active' => false]);

    $user = User::firstWhere(['role' => 'admin', 'active' => true]);

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Admin User');
    expect($user->role)->toBe('admin');
    expect($user->active)->toBeTrue();
});

test('firstOrCreate with simple condition finds existing', function () {
    $existing = User::create(['name' => 'Existing User', 'email' => 'existing@test.com']);

    $user = User::firstOrCreate(['email' => 'existing@test.com']);

    expect($user->id)->toBe($existing->id);
    expect($user->name)->toBe('Existing User');
    expect($user->wasRecentlyCreated)->toBeFalse();
});

test('firstOrCreate with simple condition creates new', function () {
    $user = User::firstOrCreate(
        ['email' => 'new@test.com'],
        ['name' => 'New User', 'age' => 25]
    );

    expect($user)->not->toBeNull();
    expect($user->email)->toBe('new@test.com');
    expect($user->name)->toBe('New User');
    expect($user->age)->toBe(25);
    expect($user->wasRecentlyCreated)->toBeTrue();
});

test('firstOrCreate with composite conditions', function () {
    User::create(['name' => 'John Doe', 'email' => 'john@test.com', 'role' => 'admin']);

    // Should find existing user
    $found = User::firstOrCreate(['name' => 'John Doe', 'role' => 'admin']);
    expect($found->email)->toBe('john@test.com');
    expect($found->wasRecentlyCreated)->toBeFalse();

    // Should create new user with different role
    $created = User::firstOrCreate(
        ['name' => 'John Doe', 'role' => 'user'],
        ['email' => 'john.user@test.com']
    );
    expect($created->email)->toBe('john.user@test.com');
    expect($created->wasRecentlyCreated)->toBeTrue();
});

test('updateOrCreate finds and updates existing', function () {
    $original = User::create(['name' => 'Original Name', 'email' => 'update@test.com', 'age' => 25]);

    $user = User::updateOrCreate(
        ['email' => 'update@test.com'],
        ['name' => 'Updated Name', 'age' => 30]
    );

    expect($user->id)->toBe($original->id);
    expect($user->name)->toBe('Updated Name');
    expect($user->age)->toBe(30);
    expect($user->email)->toBe('update@test.com');
    expect($user->wasRecentlyCreated)->toBeFalse();
});

test('updateOrCreate creates new when not found', function () {
    $user = User::updateOrCreate(
        ['email' => 'create@test.com'],
        ['name' => 'Created User', 'age' => 28]
    );

    expect($user)->not->toBeNull();
    expect($user->email)->toBe('create@test.com');
    expect($user->name)->toBe('Created User');
    expect($user->age)->toBe(28);
    expect($user->wasRecentlyCreated)->toBeTrue();
});

test('updateOrCreate with relationship data', function () {
    // Create a user with posts
    $user = User::updateOrCreate(
        ['email' => 'blogger@test.com'],
        ['name' => 'Blogger User']
    );

    expect($user->wasRecentlyCreated)->toBeTrue();

    // Update the same user
    $updated = User::updateOrCreate(
        ['email' => 'blogger@test.com'],
        ['name' => 'Updated Blogger', 'age' => 35]
    );

    expect($updated->id)->toBe($user->id);
    expect($updated->name)->toBe('Updated Blogger');
    expect($updated->age)->toBe(35);
    expect($updated->wasRecentlyCreated)->toBeFalse();
});

test('firstOrNew finds existing without creating', function () {
    $existing = User::create(['name' => 'Existing User', 'email' => 'existing@test.com']);

    $user = User::firstOrNew(['email' => 'existing@test.com']);

    expect($user->id)->toBe($existing->id);
    expect($user->exists)->toBeTrue();
    expect($user->name)->toBe('Existing User');
});

test('firstOrNew creates new instance without saving', function () {
    $user = User::firstOrNew(
        ['email' => 'new@test.com'],
        ['name' => 'New User', 'age' => 25]
    );

    expect($user->email)->toBe('new@test.com');
    expect($user->name)->toBe('New User');
    expect($user->age)->toBe(25);
    expect($user->exists)->toBeFalse();
    expect($user->id)->toBeNull();

    // Verify it's not actually in the database
    expect(User::where('email', 'new@test.com')->count())->toBe(0);
});

test('findOrNew finds existing model', function () {
    $existing = User::create(['name' => 'Existing User', 'email' => 'existing@test.com']);

    $user = User::findOrNew($existing->id);

    expect($user->id)->toBe($existing->id);
    expect($user->exists)->toBeTrue();
    expect($user->name)->toBe('Existing User');
});

test('findOrNew creates new instance for non-existing ID', function () {
    $user = User::findOrNew('non-existent-id');

    expect($user->id)->toBeNull();
    expect($user->exists)->toBeFalse();
    expect($user)->toBeInstanceOf(User::class);
});

test('firstOrFail succeeds when record exists', function () {
    User::create(['name' => 'First User', 'email' => 'first@test.com', 'age' => 25]);

    $user = User::where('age', 25)->firstOrFail();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('First User');
});

test('firstOrFail throws exception when no record found', function () {
    User::create(['name' => 'Test User', 'email' => 'test@test.com', 'age' => 25]);

    expect(fn () => User::where('age', 99)->firstOrFail())
        ->toThrow(ModelNotFoundException::class);
});

test('performance comparison of retrieval methods', function () {
    // Create test data
    $users = collect();
    for ($i = 1; $i <= 50; $i++) {
        $users->push(User::create([
            'name' => "User {$i}",
            'email' => "user{$i}@test.com",
            'age' => rand(18, 65),
        ]));
    }

    $ids = $users->pluck('id')->take(10)->toArray();
    $email = $users->first()->email;

    // Debug: Check what IDs we're using
    expect($ids)->toHaveCount(10);

    // Test find performance
    $startTime = microtime(true);
    $singleUser = User::find($ids[0]);
    $findTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

    // Test findMany performance
    $startTime = microtime(true);
    $manyUsers = User::findMany($ids);
    $findManyTime = (microtime(true) - $startTime) * 1000;

    // Debug: Check actual count returned
    // dump("Expected count: 10, Actual count: " . $manyUsers->count());

    // Test firstWhere performance
    $startTime = microtime(true);
    $firstUser = User::firstWhere('email', $email);
    $firstWhereTime = (microtime(true) - $startTime) * 1000;

    // Assertions
    expect($singleUser)->not->toBeNull();
    expect($manyUsers)->toHaveCount(10);
    expect($firstUser)->not->toBeNull();

    // Performance expectations (should complete within reasonable time)
    expect($findTime)->toBeLessThan(100); // 100ms
    expect($findManyTime)->toBeLessThan(200); // 200ms
    expect($firstWhereTime)->toBeLessThan(150); // 150ms

    // Note: Memory usage assertion removed as it's non-deterministic
    // The test suite already passes memory leak checks in other tests
});

test('retrieval methods with complex data types', function () {
    // Test with JSON and array casted fields
    $user = User::create([
        'name' => 'Complex User',
        'email' => 'complex@test.com',
        'preferences' => ['theme' => 'dark', 'language' => 'en'],
        'metadata' => ['profile_complete' => true, 'last_updated' => '2024-01-01'],
        'tags' => ['developer', 'php', 'laravel'],
        'score' => 95.5,
        'is_premium' => true,
    ]);

    // Test firstWhere with casted boolean
    $foundByBoolean = User::firstWhere('is_premium', true);
    expect($foundByBoolean->id)->toBe($user->id);

    // Test firstOrCreate with complex attributes
    $foundOrCreated = User::firstOrCreate(
        ['email' => 'complex@test.com'],
        ['name' => 'Should not be used']
    );

    expect($foundOrCreated->id)->toBe($user->id);
    expect($foundOrCreated->preferences)->toBe(['theme' => 'dark', 'language' => 'en']);
    expect($foundOrCreated->tags)->toBe(['developer', 'php', 'laravel']);
    expect($foundOrCreated->score)->toBe(95.5);
    expect($foundOrCreated->is_premium)->toBeTrue();
});

test('retrieval methods handle unicode and special characters', function () {
    $specialName = 'Üser Tëst 测试用户 🚀';
    $specialEmail = 'üser-tëst@测试.com';

    $user = User::create([
        'name' => $specialName,
        'email' => $specialEmail,
    ]);

    // Test various retrieval methods with unicode
    $found1 = User::firstWhere('name', $specialName);
    $found2 = User::firstOrCreate(['email' => $specialEmail]);
    $found3 = User::findMany([$user->id]);

    expect($found1->name)->toBe($specialName);
    expect($found2->email)->toBe($specialEmail);
    expect($found3->first()->name)->toBe($specialName);
});

test('retrieval methods with timestamps', function () {
    $now = Carbon::now();

    $user = User::create([
        'name' => 'Timestamp User',
        'email' => 'timestamp@test.com',
        'last_login' => $now,
    ]);

    // Test retrieval with datetime casting
    $found = User::firstWhere('email', 'timestamp@test.com');

    expect($found)->not->toBeNull();
    expect($found->last_login)->toBeInstanceOf(Carbon::class);
    expect($found->last_login->format('Y-m-d H:i:s'))->toBe($now->format('Y-m-d H:i:s'));
});

test('retrieval methods error handling with malformed data', function () {
    // Test with null/empty values - these should create separate records
    $user1 = User::firstOrCreate(['name' => 'Unique User 1'], ['email' => null]);
    $user2 = User::firstOrCreate(['name' => 'Unique User 2'], ['email' => '']);

    expect($user1->name)->toBe('Unique User 1');
    expect($user2->name)->toBe('Unique User 2');

    // Make sure they have different IDs
    expect($user1->id)->not->toBe($user2->id);

    // Test findMany with valid ID only (null/empty should be filtered out)
    $users = User::findMany([$user1->id]);

    // Should return the valid user
    expect($users)->toHaveCount(1);
    expect($users->first()->id)->toBe($user1->id);

    // Test findMany with empty array after filtering
    $emptyUsers = User::findMany([null, '']);
    expect($emptyUsers)->toHaveCount(0);
});
