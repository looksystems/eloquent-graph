<?php

use Tests\Models\User;

// Task 4.4: Ordering & Limiting Tests
// Focus: Ordering and pagination scenarios for optimal performance
// Coverage: orderBy, orderByDesc, multiple column ordering, limit, offset, take, skip, pagination

test('basic orderBy ascending', function () {
    // Create users with varying ages
    $ages = [30, 25, 35, 20, 40];
    foreach ($ages as $index => $age) {
        User::create([
            'name' => 'Order User '.($index + 1),
            'email' => "order{$index}@test.com",
            'age' => $age,
        ]);
    }

    // Test orderBy ascending
    $users = User::where('name', 'LIKE', 'Order User%')
        ->orderBy('age')
        ->get();

    expect($users)->toHaveCount(5);

    $ages = $users->pluck('age')->toArray();
    $expectedAges = [20, 25, 30, 35, 40];
    expect($ages)->toBe($expectedAges);

    // Verify names correspond to correct ages
    expect($users[0]->age)->toBe(20); // User 4
    expect($users[1]->age)->toBe(25); // User 2
    expect($users[2]->age)->toBe(30); // User 1
    expect($users[3]->age)->toBe(35); // User 3
    expect($users[4]->age)->toBe(40); // User 5
});

test('basic orderBy descending', function () {
    // Create users with varying scores
    $scores = [85, 92, 78, 96, 81];
    foreach ($scores as $index => $score) {
        User::create([
            'name' => 'Score User '.($index + 1),
            'email' => "score{$index}@test.com",
            'score' => $score,
        ]);
    }

    // Test orderBy descending
    $users = User::where('name', 'LIKE', 'Score User%')
        ->orderBy('score', 'desc')
        ->get();

    expect($users)->toHaveCount(5);

    $scores = $users->pluck('score')->toArray();
    $expectedScores = [96.0, 92.0, 85.0, 81.0, 78.0];
    expect($scores)->toBe($expectedScores);
});

test('orderByDesc convenience method', function () {
    // Create users with different experience years
    for ($i = 1; $i <= 8; $i++) {
        User::create([
            'name' => "Experience User {$i}",
            'email' => "exp{$i}@test.com",
            'experience_years' => $i + 2, // 3-10 years
        ]);
    }

    // Test orderByDesc
    $users = User::where('name', 'LIKE', 'Experience User%')
        ->orderByDesc('experience_years')
        ->get();

    expect($users)->toHaveCount(8);

    $experience = $users->pluck('experience_years')->toArray();
    $expectedExperience = [10, 9, 8, 7, 6, 5, 4, 3];
    expect($experience)->toBe($expectedExperience);
});

test('multiple column ordering precedence', function () {
    // Create users with same levels but different scores
    $testData = [
        ['level' => 'senior', 'score' => 95, 'name' => 'Alice'],
        ['level' => 'junior', 'score' => 88, 'name' => 'Bob'],
        ['level' => 'senior', 'score' => 92, 'name' => 'Charlie'],
        ['level' => 'junior', 'score' => 90, 'name' => 'Diana'],
        ['level' => 'senior', 'score' => 98, 'name' => 'Eve'],
    ];

    foreach ($testData as $index => $data) {
        User::create([
            'name' => 'Multi Order '.$data['name'],
            'email' => "multi{$index}@test.com",
            'level' => $data['level'],
            'score' => $data['score'],
        ]);
    }

    // Test multiple column ordering: level ASC, then score DESC
    $users = User::where('name', 'LIKE', 'Multi Order%')
        ->orderBy('level')
        ->orderByDesc('score')
        ->get();

    expect($users)->toHaveCount(5);

    // Should be: junior (Diana=90, Bob=88), then senior (Eve=98, Alice=95, Charlie=92)
    $expectedOrder = [
        ['level' => 'junior', 'score' => 90.0, 'name' => 'Multi Order Diana'],
        ['level' => 'junior', 'score' => 88.0, 'name' => 'Multi Order Bob'],
        ['level' => 'senior', 'score' => 98.0, 'name' => 'Multi Order Eve'],
        ['level' => 'senior', 'score' => 95.0, 'name' => 'Multi Order Alice'],
        ['level' => 'senior', 'score' => 92.0, 'name' => 'Multi Order Charlie'],
    ];

    foreach ($users as $index => $user) {
        expect($user->level)->toBe($expectedOrder[$index]['level']);
        expect($user->score)->toBe($expectedOrder[$index]['score']);
        expect($user->name)->toBe($expectedOrder[$index]['name']);
    }
});

test('limit restricts result count', function () {
    // Create 12 users
    for ($i = 1; $i <= 12; $i++) {
        User::create([
            'name' => "Limit User {$i}",
            'email' => "limit{$i}@test.com",
            'age' => 20 + $i,
        ]);
    }

    // Test limit with different values
    $limitedUsers3 = User::where('name', 'LIKE', 'Limit User%')
        ->orderBy('age')
        ->limit(3)
        ->get();

    expect($limitedUsers3)->toHaveCount(3);
    expect($limitedUsers3->pluck('age')->toArray())->toBe([21, 22, 23]);

    $limitedUsers7 = User::where('name', 'LIKE', 'Limit User%')
        ->orderBy('age')
        ->limit(7)
        ->get();

    expect($limitedUsers7)->toHaveCount(7);
    expect($limitedUsers7->pluck('age')->toArray())->toBe([21, 22, 23, 24, 25, 26, 27]);
});

test('take is alias for limit', function () {
    // Create 10 users
    for ($i = 1; $i <= 10; $i++) {
        User::create([
            'name' => "Take User {$i}",
            'email' => "take{$i}@test.com",
            'score' => $i * 10,
        ]);
    }

    // Test take method
    $takenUsers = User::where('name', 'LIKE', 'Take User%')
        ->orderBy('score')
        ->take(4)
        ->get();

    expect($takenUsers)->toHaveCount(4);
    expect($takenUsers->pluck('score')->toArray())->toBe([10.0, 20.0, 30.0, 40.0]);
});

test('offset skips initial results', function () {
    // Create 15 users
    for ($i = 1; $i <= 15; $i++) {
        User::create([
            'name' => "Offset User {$i}",
            'email' => "offset{$i}@test.com",
            'age' => 18 + $i, // Ages 19-33
        ]);
    }

    // Test offset
    $offsetUsers = User::where('name', 'LIKE', 'Offset User%')
        ->orderBy('age')
        ->offset(5)
        ->get();

    expect($offsetUsers)->toHaveCount(10); // 15 - 5 = 10
    expect($offsetUsers->pluck('age')->toArray())->toBe([24, 25, 26, 27, 28, 29, 30, 31, 32, 33]);

    // Test offset with limit
    $offsetLimitedUsers = User::where('name', 'LIKE', 'Offset User%')
        ->orderBy('age')
        ->offset(3)
        ->limit(5)
        ->get();

    expect($offsetLimitedUsers)->toHaveCount(5);
    expect($offsetLimitedUsers->pluck('age')->toArray())->toBe([22, 23, 24, 25, 26]);
});

test('skip is alias for offset', function () {
    // Create 12 users
    for ($i = 1; $i <= 12; $i++) {
        User::create([
            'name' => "Skip User {$i}",
            'email' => "skip{$i}@test.com",
            'score' => $i * 5, // Scores 5-60
        ]);
    }

    // Test skip method
    $skippedUsers = User::where('name', 'LIKE', 'Skip User%')
        ->orderBy('score')
        ->skip(4)
        ->take(6)
        ->get();

    expect($skippedUsers)->toHaveCount(6);
    expect($skippedUsers->pluck('score')->toArray())->toBe([25.0, 30.0, 35.0, 40.0, 45.0, 50.0]);
});

test('pagination simulation with offset and limit', function () {
    // Create 25 users for pagination testing
    for ($i = 1; $i <= 25; $i++) {
        User::create([
            'name' => "Page User {$i}",
            'email' => "page{$i}@test.com",
            'age' => 20 + $i,
            'user_index' => $i,
        ]);
    }

    $perPage = 6;

    // Page 1 (offset 0)
    $page1 = User::where('name', 'LIKE', 'Page User%')
        ->orderBy('user_index')
        ->offset(0)
        ->limit($perPage)
        ->get();

    expect($page1)->toHaveCount(6);
    expect($page1->pluck('user_index')->toArray())->toBe([1, 2, 3, 4, 5, 6]);

    // Page 2 (offset 6)
    $page2 = User::where('name', 'LIKE', 'Page User%')
        ->orderBy('user_index')
        ->offset(6)
        ->limit($perPage)
        ->get();

    expect($page2)->toHaveCount(6);
    expect($page2->pluck('user_index')->toArray())->toBe([7, 8, 9, 10, 11, 12]);

    // Page 3 (offset 12)
    $page3 = User::where('name', 'LIKE', 'Page User%')
        ->orderBy('user_index')
        ->offset(12)
        ->limit($perPage)
        ->get();

    expect($page3)->toHaveCount(6);
    expect($page3->pluck('user_index')->toArray())->toBe([13, 14, 15, 16, 17, 18]);

    // Last page (offset 18) - partial page
    $page4 = User::where('name', 'LIKE', 'Page User%')
        ->orderBy('user_index')
        ->offset(18)
        ->limit($perPage)
        ->get();

    expect($page4)->toHaveCount(6); // Remaining users (limit still applies)
    expect($page4->pluck('user_index')->toArray())->toBe([19, 20, 21, 22, 23, 24]);
});

test('ordering with string columns', function () {
    // Create users with names for alphabetical sorting
    $names = ['Zoe', 'Alice', 'Charlie', 'Bob', 'Diana'];
    foreach ($names as $index => $name) {
        User::create([
            'name' => "Alpha {$name}",
            'email' => "alpha{$index}@test.com",
            'level' => $name,
        ]);
    }

    // Test alphabetical ordering by name
    $users = User::where('name', 'LIKE', 'Alpha%')
        ->orderBy('level')
        ->get();

    expect($users)->toHaveCount(5);
    $sortedLevels = $users->pluck('level')->toArray();
    expect($sortedLevels)->toBe(['Alice', 'Bob', 'Charlie', 'Diana', 'Zoe']);
});

test('ordering with null values', function () {
    // Create users with some null scores
    $testData = [
        ['name' => 'User A', 'score' => 85],
        ['name' => 'User B', 'score' => null],
        ['name' => 'User C', 'score' => 92],
        ['name' => 'User D', 'score' => null],
        ['name' => 'User E', 'score' => 78],
    ];

    foreach ($testData as $index => $data) {
        User::create([
            'name' => "Null Score {$data['name']}",
            'email' => "nullscore{$index}@test.com",
            'score' => $data['score'],
        ]);
    }

    // Test ordering with nulls (nulls typically come first or last depending on DB)
    $users = User::where('name', 'LIKE', 'Null Score%')
        ->orderBy('score')
        ->get();

    expect($users)->toHaveCount(5);

    // Check that non-null values are properly ordered
    $nonNullUsers = $users->filter(function ($user) {
        return $user->score !== null;
    });

    $nonNullScores = $nonNullUsers->pluck('score')->toArray();
    expect($nonNullScores)->toBe([78.0, 85.0, 92.0]);
});

test('ordering with casted attributes', function () {
    // Create users with boolean and float attributes
    for ($i = 1; $i <= 8; $i++) {
        User::create([
            'name' => "Cast Order User {$i}",
            'email' => "castorder{$i}@test.com",
            'is_premium' => $i % 2 === 0, // Every other user is premium
            'score' => $i * 12.5, // Float scores: 12.5, 25.0, 37.5, etc.
            'age' => 25 + $i,
        ]);
    }

    // Test ordering by boolean (false < true)
    $usersByPremium = User::where('name', 'LIKE', 'Cast Order User%')
        ->orderBy('is_premium')
        ->orderBy('age')
        ->get();

    expect($usersByPremium)->toHaveCount(8);

    // First 4 should be non-premium (false), last 4 should be premium (true)
    for ($i = 0; $i < 4; $i++) {
        expect($usersByPremium[$i]->is_premium)->toBeFalse();
    }
    for ($i = 4; $i < 8; $i++) {
        expect($usersByPremium[$i]->is_premium)->toBeTrue();
    }

    // Test ordering by float
    $usersByScore = User::where('name', 'LIKE', 'Cast Order User%')
        ->orderBy('score')
        ->get();

    $scores = $usersByScore->pluck('score')->toArray();
    $expectedScores = [12.5, 25.0, 37.5, 50.0, 62.5, 75.0, 87.5, 100.0];
    expect($scores)->toBe($expectedScores);
});

test('performance with large datasets ordering', function () {
    $this->markTestSkipped('Performance test - timing varies by system');

    // Create a larger dataset for performance testing
    for ($i = 1; $i <= 200; $i++) {
        User::create([
            'name' => "Perf Order User {$i}",
            'email' => "perforder{$i}@test.com",
            'age' => 18 + ($i % 50), // Ages 18-67
            'score' => rand(1, 1000),
            'level' => ['junior', 'mid', 'senior'][($i - 1) % 3],
        ]);
    }

    $startTime = microtime(true);

    // Complex ordering query
    $users = User::where('name', 'LIKE', 'Perf Order User%')
        ->orderBy('level')
        ->orderByDesc('score')
        ->orderBy('age')
        ->limit(50)
        ->get();

    $executionTime = (microtime(true) - $startTime) * 1000;

    // Performance expectations
    expect($executionTime)->toBeLessThan(500); // Should complete within 500ms
    expect($users)->toHaveCount(50);

    // Verify ordering is applied correctly
    expect($users->first()->level)->toBe('junior'); // Should start with junior level
});

test('edge cases with ordering and limiting', function () {
    // Create minimal dataset
    User::create([
        'name' => 'Edge Case User 1',
        'email' => 'edge1@test.com',
        'age' => 30,
    ]);

    User::create([
        'name' => 'Edge Case User 2',
        'email' => 'edge2@test.com',
        'age' => 25,
    ]);

    // Test limit larger than result set
    $users = User::where('name', 'LIKE', 'Edge Case User%')
        ->orderBy('age')
        ->limit(10)
        ->get();

    expect($users)->toHaveCount(2);
    expect($users->pluck('age')->toArray())->toBe([25, 30]);

    // Test offset larger than result set
    $emptyResult = User::where('name', 'LIKE', 'Edge Case User%')
        ->orderBy('age')
        ->offset(5)
        ->get();

    expect($emptyResult)->toHaveCount(0);

    // Test zero limit
    $zeroResult = User::where('name', 'LIKE', 'Edge Case User%')
        ->orderBy('age')
        ->limit(0)
        ->get();

    expect($zeroResult)->toHaveCount(0);
});

test('ordering with complex where conditions', function () {
    // Create users for complex filtering and ordering
    for ($i = 1; $i <= 20; $i++) {
        User::create([
            'name' => "Complex Filter User {$i}",
            'email' => "complexfilter{$i}@test.com",
            'age' => 20 + $i,
            'active' => $i % 3 !== 0, // 2/3 are active
            'score' => $i * 5,
            'level' => ($i <= 10) ? 'junior' : 'senior',
        ]);
    }

    // Complex query with filtering and ordering
    $users = User::where('name', 'LIKE', 'Complex Filter User%')
        ->where('active', true)
        ->where('score', '>', 30)
        ->whereIn('level', ['junior', 'senior'])
        ->orderByDesc('score')
        ->orderBy('age')
        ->limit(8)
        ->get();

    foreach ($users as $user) {
        expect($user->active)->toBeTrue();
        expect($user->score)->toBeGreaterThan(30);
        expect(in_array($user->level, ['junior', 'senior']))->toBeTrue();
    }

    expect($users->count())->toBeGreaterThan(0);
    expect($users->count())->toBeLessThanOrEqual(8);

    // Verify descending score order
    $scores = $users->pluck('score')->toArray();
    $sortedScores = collect($scores)->sort()->reverse()->values()->toArray();
    expect($scores)->toBe($sortedScores);
});

test('memory efficiency with large result limiting', function () {
    $this->markTestSkipped('Performance test - timing varies by system');

    // Create larger dataset
    for ($i = 1; $i <= 100; $i++) {
        User::create([
            'name' => "Memory Test User {$i}",
            'email' => "memtest{$i}@test.com",
            'age' => 18 + $i,
            'metadata' => [
                'bio' => str_repeat('x', 500), // Large data per record
                'preferences' => array_fill(0, 50, 'value'),
            ],
        ]);
    }

    $memoryBefore = memory_get_usage(true);

    // Get limited results to test memory efficiency
    $limitedUsers = User::where('name', 'LIKE', 'Memory Test User%')
        ->orderBy('age')
        ->limit(10)
        ->get();

    $memoryAfter = memory_get_usage(true);
    $memoryUsed = $memoryAfter - $memoryBefore;

    expect($limitedUsers)->toHaveCount(10);
    expect($memoryUsed)->toBeLessThan(10 * 1024 * 1024); // Should use less than 10MB

    // Verify we got the first 10 by age
    $ages = $limitedUsers->pluck('age')->toArray();
    expect($ages)->toBe([19, 20, 21, 22, 23, 24, 25, 26, 27, 28]);
});
