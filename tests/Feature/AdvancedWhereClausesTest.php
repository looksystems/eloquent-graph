<?php

use Tests\Models\User;

// Task 4.3: Advanced Where Clauses Tests
// Focus: Comprehensive where clause testing for complex query scenarios
// Coverage: whereIn, whereBetween, whereNull, whereNotNull, nested conditions, etc.

test('whereIn with small arrays', function () {
    // Create test users
    for ($i = 1; $i <= 10; $i++) {
        User::create([
            'name' => "WhereIn User {$i}",
            'email' => "wherein{$i}@test.com",
            'age' => 20 + $i,
        ]);
    }

    // Test whereIn with specific ages
    $users = User::where('name', 'LIKE', 'WhereIn User%')
        ->whereIn('age', [23, 25, 27])
        ->orderBy('age')
        ->get();

    expect($users)->toHaveCount(3);
    expect($users->pluck('age')->toArray())->toBe([23, 25, 27]);
    expect($users->pluck('name')->toArray())->toContain('WhereIn User 3', 'WhereIn User 5', 'WhereIn User 7');
});

test('whereIn with large arrays', function () {
    // Create test users
    for ($i = 1; $i <= 50; $i++) {
        User::create([
            'name' => "LargeWhereIn User {$i}",
            'email' => "largewherein{$i}@test.com",
            'age' => 20 + $i,
        ]);
    }

    // Test whereIn with large array (every 5th age)
    $targetAges = [];
    for ($i = 25; $i <= 65; $i += 5) {
        $targetAges[] = $i;
    }

    $users = User::where('name', 'LIKE', 'LargeWhereIn User%')
        ->whereIn('age', $targetAges)
        ->get();

    expect($users)->toHaveCount(9); // 25, 30, 35, 40, 45, 50, 55, 60, 65
    expect($users->pluck('age')->sort()->values()->toArray())->toBe($targetAges);
});

test('whereNotIn excludes specified values', function () {
    // Create test users
    for ($i = 1; $i <= 8; $i++) {
        User::create([
            'name' => "NotIn User {$i}",
            'email' => "notin{$i}@test.com",
            'age' => 20 + $i,
        ]);
    }

    // Test whereNotIn to exclude specific ages
    $users = User::where('name', 'LIKE', 'NotIn User%')
        ->whereNotIn('age', [22, 24, 26])
        ->get();

    expect($users)->toHaveCount(5); // Should exclude users 2, 4, 6
    $userAges = $users->pluck('age')->sort()->values()->toArray();
    expect($userAges)->toBe([21, 23, 25, 27, 28]);
});

test('whereBetween with numeric ranges', function () {
    // Create users with varying ages
    for ($i = 1; $i <= 20; $i++) {
        User::create([
            'name' => "Between User {$i}",
            'email' => "between{$i}@test.com",
            'age' => 15 + $i, // Ages 16-35
            'score' => $i * 5, // Scores 5-100
        ]);
    }

    // Test whereBetween with age range
    $users = User::where('name', 'LIKE', 'Between User%')
        ->whereBetween('age', [25, 30])
        ->get();

    expect($users)->toHaveCount(6); // Ages 25, 26, 27, 28, 29, 30
    foreach ($users as $user) {
        expect($user->age)->toBeGreaterThanOrEqual(25);
        expect($user->age)->toBeLessThanOrEqual(30);
    }

    // Test whereBetween with score range
    $highScoreUsers = User::where('name', 'LIKE', 'Between User%')
        ->whereBetween('score', [50, 75])
        ->get();

    expect($highScoreUsers)->toHaveCount(6); // Scores 50, 55, 60, 65, 70, 75
    foreach ($highScoreUsers as $user) {
        expect($user->score)->toBeGreaterThanOrEqual(50);
        expect($user->score)->toBeLessThanOrEqual(75);
    }
});

test('whereNotBetween excludes range values', function () {
    // Create users with sequential ages
    for ($i = 1; $i <= 15; $i++) {
        User::create([
            'name' => "NotBetween User {$i}",
            'email' => "notbetween{$i}@test.com",
            'age' => 20 + $i, // Ages 21-35
        ]);
    }

    // Test whereNotBetween to exclude middle range
    $users = User::where('name', 'LIKE', 'NotBetween User%')
        ->whereNotBetween('age', [26, 30])
        ->get();

    expect($users)->toHaveCount(10); // Should exclude ages 26, 27, 28, 29, 30
    foreach ($users as $user) {
        expect($user->age < 26 || $user->age > 30)->toBeTrue();
    }

    $userAges = $users->pluck('age')->sort()->values()->toArray();
    $expectedAges = [21, 22, 23, 24, 25, 31, 32, 33, 34, 35];
    expect($userAges)->toBe($expectedAges);
});

test('whereNull finds records with null values', function () {
    // Create users with some null emails
    for ($i = 1; $i <= 10; $i++) {
        User::create([
            'name' => "Null Test User {$i}",
            'email' => ($i % 3 === 0) ? null : "nulltest{$i}@test.com", // Every 3rd user has null email
            'age' => 20 + $i,
        ]);
    }

    // Test whereNull
    $usersWithNullEmail = User::where('name', 'LIKE', 'Null Test User%')
        ->whereNull('email')
        ->get();

    expect($usersWithNullEmail)->toHaveCount(3); // Users 3, 6, 9
    foreach ($usersWithNullEmail as $user) {
        expect($user->email)->toBeNull();
        expect(in_array($user->name, ['Null Test User 3', 'Null Test User 6', 'Null Test User 9']))->toBeTrue();
    }
});

test('whereNotNull finds records with non-null values', function () {
    // Create users with some null values
    for ($i = 1; $i <= 8; $i++) {
        User::create([
            'name' => "NotNull Test User {$i}",
            'email' => ($i % 4 === 0) ? null : "notnulltest{$i}@test.com", // Every 4th user has null email
            'age' => 25 + $i,
        ]);
    }

    // Test whereNotNull
    $usersWithEmail = User::where('name', 'LIKE', 'NotNull Test User%')
        ->whereNotNull('email')
        ->get();

    expect($usersWithEmail)->toHaveCount(6); // All except users 4, 8
    foreach ($usersWithEmail as $user) {
        expect($user->email)->not->toBeNull();
        expect($user->email)->toContain('@test.com');
    }
});

test('nested where conditions with AND logic', function () {
    // Create users with varying attributes
    for ($i = 1; $i <= 20; $i++) {
        User::create([
            'name' => "Nested User {$i}",
            'email' => "nested{$i}@test.com",
            'age' => 18 + ($i % 10), // Ages 18-27 cycling
            'active' => $i % 2 === 1, // Odd numbers are active
            'score' => $i * 5, // Scores 5-100
        ]);
    }

    // Test complex nested conditions
    $users = User::where('name', 'LIKE', 'Nested User%')
        ->where('active', true)
        ->where('age', '>', 20)
        ->where('score', '>=', 50)
        ->get();

    foreach ($users as $user) {
        expect($user->active)->toBeTrue();
        expect($user->age)->toBeGreaterThan(20);
        expect($user->score)->toBeGreaterThanOrEqual(50);
    }

    // Should have some results
    expect($users->count())->toBeGreaterThan(0);
});

test('simple chained where conditions', function () {
    // Create users for simple chaining testing
    for ($i = 1; $i <= 15; $i++) {
        User::create([
            'name' => "Chain User {$i}",
            'email' => "chain{$i}@test.com",
            'age' => 20 + $i, // Ages 21-35
            'score' => $i * 10, // Scores 10-150
            'level' => ($i <= 7) ? 'junior' : 'senior',
        ]);
    }

    // Test simple chained AND conditions (no closures)
    $users = User::where('name', 'LIKE', 'Chain User%')
        ->where('age', '>', 25)
        ->where('score', '>', 80)
        ->where('level', 'senior')
        ->get();

    foreach ($users as $user) {
        expect($user->age)->toBeGreaterThan(25);
        expect($user->score)->toBeGreaterThan(80);
        expect($user->level)->toBe('senior');
    }

    expect($users->count())->toBeGreaterThan(0);
});

test('complex where combinations', function () {
    // Create complex test dataset
    for ($i = 1; $i <= 25; $i++) {
        User::create([
            'name' => "Complex User {$i}",
            'email' => ($i % 5 === 0) ? null : "complex{$i}@test.com",
            'age' => 18 + ($i % 15), // Ages 18-32
            'active' => $i % 3 !== 0, // 2/3 are active
            'score' => $i * 4, // Scores 4-100
            'level' => ($i <= 10) ? 'beginner' : (($i <= 20) ? 'intermediate' : 'advanced'),
        ]);
    }

    // Test combination of different where types
    $users = User::where('name', 'LIKE', 'Complex User%')
        ->whereNotNull('email')
        ->whereIn('level', ['intermediate', 'advanced'])
        ->whereBetween('age', [22, 30])
        ->where('active', true)
        ->where('score', '>', 40)
        ->get();

    foreach ($users as $user) {
        expect($user->email)->not->toBeNull();
        expect(in_array($user->level, ['intermediate', 'advanced']))->toBeTrue();
        expect($user->age)->toBeGreaterThanOrEqual(22);
        expect($user->age)->toBeLessThanOrEqual(30);
        expect($user->active)->toBeTrue();
        expect($user->score)->toBeGreaterThan(40);
    }

    expect($users->count())->toBeGreaterThan(0);
});

test('where with special characters and unicode', function () {
    // Create users with special characters
    User::create([
        'name' => 'Üser Tëst',
        'email' => 'üser@tëst.com',
        'level' => 'spëcial',
    ]);

    User::create([
        'name' => '测试用户',
        'email' => 'test@测试.com',
        'level' => '特殊',
    ]);

    User::create([
        'name' => "User with 'quotes'",
        'email' => 'quotes@test.com',
        'level' => 'level with "quotes"',
    ]);

    // Test where with unicode
    $unicodeUser = User::where('name', 'Üser Tëst')->first();
    expect($unicodeUser)->not->toBeNull();
    expect($unicodeUser->email)->toBe('üser@tëst.com');

    // Test where with Chinese characters
    $chineseUser = User::where('name', '测试用户')->first();
    expect($chineseUser)->not->toBeNull();
    expect($chineseUser->level)->toBe('特殊');

    // Test where with quotes
    $quotesUser = User::where('name', "User with 'quotes'")->first();
    expect($quotesUser)->not->toBeNull();
    expect($quotesUser->level)->toBe('level with "quotes"');
});

test('where with multiple individual conditions', function () {
    // Create test users
    for ($i = 1; $i <= 10; $i++) {
        User::create([
            'name' => "Multi Where User {$i}",
            'email' => "multihere{$i}@test.com",
            'age' => 20 + $i,
            'active' => $i % 2 === 0,
            'level' => ($i <= 5) ? 'junior' : 'senior',
        ]);
    }

    // Test multiple individual where conditions
    $users = User::where('name', 'LIKE', 'Multi Where User%')
        ->where('active', '=', true)
        ->where('age', '>', 23)
        ->where('level', '=', 'senior')
        ->get();

    foreach ($users as $user) {
        expect($user->name)->toContain('Multi Where User');
        expect($user->active)->toBeTrue();
        expect($user->age)->toBeGreaterThan(23);
        expect($user->level)->toBe('senior');
    }

    expect($users->count())->toBeGreaterThan(0);
});

test('where with advanced LIKE patterns', function () {
    // Create users with varied naming patterns
    for ($i = 1; $i <= 10; $i++) {
        User::create([
            'name' => ($i % 2 === 0) ? "Advanced Pattern User {$i}" : "Different Name {$i}",
            'email' => "advancedpattern{$i}@test.com",
            'level' => ($i <= 5) ? "level_{$i}" : "special_{$i}",
        ]);
    }

    // Test LIKE with pattern matching
    $patternUsers = User::where('name', 'LIKE', 'Advanced Pattern%')->get();
    expect($patternUsers)->toHaveCount(5); // Only even numbered users

    // Test LIKE with level patterns
    $levelUsers = User::where('level', 'LIKE', 'level_%')->get();
    expect($levelUsers)->toHaveCount(5); // Users 1-5

    foreach ($patternUsers as $user) {
        expect($user->name)->toContain('Advanced Pattern User');
    }

    foreach ($levelUsers as $user) {
        expect($user->level)->toContain('level_');
    }
});

test('performance with large where clause combinations', function () {
    // Create a larger dataset for performance testing
    for ($i = 1; $i <= 100; $i++) {
        User::create([
            'name' => "Perf Where User {$i}",
            'email' => "perfwhere{$i}@test.com",
            'age' => 18 + ($i % 50), // Ages 18-67
            'active' => $i % 3 !== 0,
            'score' => $i * 2,
            'level' => ['junior', 'mid', 'senior'][($i - 1) % 3],
        ]);
    }

    $startTime = microtime(true);

    // Complex query with multiple where conditions
    $users = User::where('name', 'LIKE', 'Perf Where User%')
        ->where('active', true)
        ->whereIn('level', ['mid', 'senior'])
        ->whereBetween('age', [25, 55])
        ->whereNotIn('score', [10, 20, 30])
        ->whereNotNull('email')
        ->where('score', '>', 50)
        ->get();

    $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

    // Performance assertion
    expect($executionTime)->toBeLessThan(300); // Should complete within 300ms

    // Verify results make sense
    foreach ($users as $user) {
        expect($user->active)->toBeTrue();
        expect(in_array($user->level, ['mid', 'senior']))->toBeTrue();
        expect($user->age)->toBeGreaterThanOrEqual(25);
        expect($user->age)->toBeLessThanOrEqual(55);
        expect($user->score)->toBeGreaterThan(50);
        expect($user->email)->not->toBeNull();
    }

    expect($users->count())->toBeGreaterThan(0);
});

test('where clauses with casted attributes', function () {
    // Create users with different casted attributes
    for ($i = 1; $i <= 10; $i++) {
        User::create([
            'name' => "Cast User {$i}",
            'email' => "cast{$i}@test.com",
            'is_premium' => $i % 2 === 0, // Boolean cast
            'score' => ($i * 10.5), // Float cast
            'metadata' => ['level' => $i, 'active' => $i % 3 === 0], // JSON cast
            'preferences' => ['theme' => 'dark', 'notifications' => $i % 2 === 1], // Array cast
        ]);
    }

    // Test where with boolean cast
    $premiumUsers = User::where('name', 'LIKE', 'Cast User%')
        ->where('is_premium', true)
        ->get();

    expect($premiumUsers)->toHaveCount(5);
    foreach ($premiumUsers as $user) {
        expect($user->is_premium)->toBeTrue();
    }

    // Test where with float values
    $highScoreUsers = User::where('name', 'LIKE', 'Cast User%')
        ->where('score', '>', 50.0)
        ->get();

    expect($highScoreUsers->count())->toBeGreaterThan(0);
    foreach ($highScoreUsers as $user) {
        expect($user->score)->toBeGreaterThan(50.0);
    }
});

test('edge cases and error handling', function () {
    // Test whereIn with empty array
    $emptyResult = User::where('name', 'LIKE', 'NonExistent%')
        ->whereIn('age', [])
        ->get();

    expect($emptyResult)->toHaveCount(0);

    // Test whereBetween with invalid range (min > max)
    $invalidRangeResult = User::where('name', 'LIKE', 'NonExistent%')
        ->whereBetween('age', [50, 30]) // Invalid range
        ->get();

    expect($invalidRangeResult)->toHaveCount(0);

    // Test whereIn with single value
    User::create([
        'name' => 'Single Value User',
        'email' => 'single@test.com',
        'age' => 42,
    ]);

    $singleValueResult = User::whereIn('age', [42])->where('name', 'Single Value User')->first();
    expect($singleValueResult)->not->toBeNull();
    expect($singleValueResult->age)->toBe(42);
});
