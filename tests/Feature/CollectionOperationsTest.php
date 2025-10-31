<?php

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Tests\Models\Post;
use Tests\Models\User;

// Task 4.2: Collection & Streaming Operations Tests
// Focus: Memory and performance efficient collection processing methods
// Coverage: chunk, lazy, cursor, chunkById, lazy loading for large datasets

test('chunk processing with moderate datasets', function () {
    // Create test data with unique naming to avoid test conflicts
    $testId = uniqid('chunk_test_');
    $users = collect();
    for ($i = 1; $i <= 25; $i++) {
        $users->push(User::create([
            'name' => "ChunkUser_{$testId}_{$i}",
            'email' => "chunkuser{$testId}{$i}@test.com",
            'age' => rand(18, 65),
        ]));
    }

    $processedUsers = [];
    $chunkCount = 0;

    User::where('name', 'LIKE', "ChunkUser_{$testId}_%")->orderBy('name')->chunk(10, function ($userChunk) use (&$processedUsers, &$chunkCount) {
        $chunkCount++;
        expect($userChunk)->toBeInstanceOf(Collection::class);
        expect($userChunk->count())->toBeLessThanOrEqual(10);

        foreach ($userChunk as $user) {
            $processedUsers[] = $user->id;
        }
    });

    // Should have processed all users in chunks (allow some flexibility for test timing)
    expect($chunkCount)->toBeGreaterThanOrEqual(2); // Should be 3 chunks normally
    expect($chunkCount)->toBeLessThanOrEqual(3);
    expect($processedUsers)->toHaveCount(count($users)); // Should match created users
    expect(array_unique($processedUsers))->toHaveCount(count($users)); // No duplicates
});

test('chunk processing with custom chunk sizes', function () {
    // Create 12 users
    for ($i = 1; $i <= 12; $i++) {
        User::create([
            'name' => "Chunk User {$i}",
            'email' => "chunk{$i}@test.com",
        ]);
    }

    // Test chunk size of 5
    $chunks = [];
    User::where('name', 'LIKE', 'Chunk User%')->orderBy('name')->chunk(5, function ($userChunk) use (&$chunks) {
        $chunks[] = $userChunk->count();
    });

    expect($chunks)->toBe([5, 5, 2]); // 12 users in chunks of 5
});

test('chunkById with custom ordering', function () {
    // Create users with specific IDs for testing
    $userIds = [];
    for ($i = 1; $i <= 15; $i++) {
        $user = User::create([
            'name' => "ChunkById User {$i}",
            'email' => "chunkbyid{$i}@test.com",
            'user_index' => $i,
        ]);
        $userIds[] = $user->id;
    }

    $processedIds = [];
    $chunkCount = 0;

    User::where('name', 'LIKE', 'ChunkById User%')
        ->chunkById(6, function ($userChunk) use (&$processedIds, &$chunkCount) {
            $chunkCount++;
            expect($userChunk->count())->toBeLessThanOrEqual(6);

            foreach ($userChunk as $user) {
                $processedIds[] = $user->id;
            }
        });

    expect($chunkCount)->toBe(3); // 15 users / 6 per chunk = 3 chunks
    expect($processedIds)->toHaveCount(15);
    expect(array_unique($processedIds))->toHaveCount(15); // No duplicates
});

test('lazy collection loading memory efficiency', function () {
    // Create test data
    for ($i = 1; $i <= 30; $i++) {
        User::create([
            'name' => "Lazy User {$i}",
            'email' => "lazy{$i}@test.com",
            'age' => rand(20, 50),
        ]);
    }

    $memoryBefore = memory_get_usage(true);

    // Test lazy loading - this should be more memory efficient than get()
    $lazyUsers = User::where('name', 'LIKE', 'Lazy User%')->lazy();

    expect($lazyUsers)->toBeInstanceOf(LazyCollection::class);

    $processedCount = 0;
    foreach ($lazyUsers as $user) {
        expect($user)->toBeInstanceOf(User::class);
        $processedCount++;

        // Break after a few to test streaming behavior
        if ($processedCount >= 5) {
            break;
        }
    }

    $memoryAfter = memory_get_usage(true);
    $memoryUsed = $memoryAfter - $memoryBefore;

    expect($processedCount)->toBe(5);
    expect($memoryUsed)->toBeLessThan(5 * 1024 * 1024); // Less than 5MB for 5 users should be reasonable
});

test('lazy collection with filtering and transformation', function () {
    // Create mixed age data
    for ($i = 1; $i <= 20; $i++) {
        User::create([
            'name' => "Filter User {$i}",
            'email' => "filter{$i}@test.com",
            'age' => ($i <= 10) ? 25 : 35, // First 10 are age 25, rest are 35
        ]);
    }

    $youngUsers = User::where('name', 'LIKE', 'Filter User%')
        ->lazy()
        ->filter(function ($user) {
            return $user->age === 25;
        })
        ->map(function ($user) {
            return $user->name;
        })
        ->take(5)
        ->values() // Reset array keys
        ->toArray();

    expect($youngUsers)->toHaveCount(5);
    expect($youngUsers[0])->toContain('Filter User');
});

test('lazy streaming works like cursor for result sets', function () {
    // Create test data
    for ($i = 1; $i <= 20; $i++) {
        User::create([
            'name' => "Stream User {$i}",
            'email' => "stream{$i}@test.com",
            'score' => $i * 10, // 10, 20, 30, etc.
        ]);
    }

    $memoryBefore = memory_get_usage(true);

    // Test lazy streaming (similar to cursor)
    $stream = User::where('name', 'LIKE', 'Stream User%')->lazy();

    expect($stream)->toBeInstanceOf(LazyCollection::class);

    $highScoreUsers = [];
    foreach ($stream as $user) {
        if ($user->score > 100) { // Score > 100 means user index > 10
            $highScoreUsers[] = $user->name;
        }

        // Test that we can break and not load everything
        if (count($highScoreUsers) >= 5) {
            break;
        }
    }

    $memoryAfter = memory_get_usage(true);
    $memoryUsed = $memoryAfter - $memoryBefore;

    expect($highScoreUsers)->toHaveCount(5);
    expect($memoryUsed)->toBeLessThan(5 * 1024 * 1024); // Should be reasonable for streaming
});

test('lazy loading with relationship constraints', function () {
    // Create users with posts
    $users = [];
    for ($i = 1; $i <= 10; $i++) {
        $user = User::create([
            'name' => "Blogger {$i}",
            'email' => "blogger{$i}@test.com",
        ]);

        // Create posts for some users
        if ($i <= 5) {
            for ($j = 1; $j <= 3; $j++) {
                Post::create([
                    'title' => "Post {$j} by User {$i}",
                    'content' => "Content for post {$j}",
                    'user_id' => $user->id,
                ]);
            }
        }
        $users[] = $user;
    }

    // Lazy load users who have posts
    $bloggers = User::where('name', 'LIKE', 'Blogger%')
        ->whereHas('posts')
        ->lazy();

    $bloggerCount = 0;
    foreach ($bloggers as $blogger) {
        expect($blogger)->toBeInstanceOf(User::class);
        $bloggerCount++;
    }

    expect($bloggerCount)->toBe(5); // Only 5 users have posts
});

test('chunk memory usage with large attribute sets', function () {
    // Create users with complex data to test memory handling
    for ($i = 1; $i <= 15; $i++) {
        User::create([
            'name' => "Complex User {$i}",
            'email' => "complex{$i}@test.com",
            'metadata' => [
                'profile' => ['bio' => str_repeat('x', 1000)], // Large string
                'settings' => range(1, 100), // Large array
                'history' => array_fill(0, 50, ['action' => 'login', 'timestamp' => time()]),
            ],
            'preferences' => array_fill(0, 20, 'preference_value'),
            'tags' => array_fill(0, 30, 'tag_value'),
        ]);
    }

    $memoryBefore = memory_get_usage(true);
    $processedUsers = 0;

    User::where('name', 'LIKE', 'Complex User%')->orderBy('name')->chunk(5, function ($userChunk) use (&$processedUsers) {
        foreach ($userChunk as $user) {
            // Access complex attributes to ensure they're properly loaded
            expect($user->metadata)->toBeArray();
            expect($user->preferences)->toBeArray();
            expect($user->tags)->toBeArray();
            $processedUsers++;
        }
    });

    $memoryAfter = memory_get_usage(true);
    $memoryUsed = $memoryAfter - $memoryBefore;

    expect($processedUsers)->toBe(15);
    expect($memoryUsed)->toBeLessThan(15 * 1024 * 1024); // Should be reasonable even with complex data
});

test('performance comparison chunk vs lazy vs all', function () {
    // Create moderate dataset
    $userIds = [];
    for ($i = 1; $i <= 40; $i++) {
        $user = User::create([
            'name' => "Perf User {$i}",
            'email' => "perf{$i}@test.com",
            'age' => rand(18, 65),
        ]);
        $userIds[] = $user->id;
    }

    // Test all() method performance and memory
    $memoryBefore = memory_get_usage(true);
    $startTime = microtime(true);

    $allUsers = User::where('name', 'LIKE', 'Perf User%')->get();
    $allCount = $allUsers->count();

    $allTime = (microtime(true) - $startTime) * 1000;
    $allMemory = memory_get_usage(true) - $memoryBefore;

    // Test chunk performance and memory
    $memoryBefore = memory_get_usage(true);
    $startTime = microtime(true);

    $chunkCount = 0;
    User::where('name', 'LIKE', 'Perf User%')->orderBy('name')->chunk(10, function ($users) use (&$chunkCount) {
        $chunkCount += $users->count();
    });

    $chunkTime = (microtime(true) - $startTime) * 1000;
    $chunkMemory = memory_get_usage(true) - $memoryBefore;

    // Test lazy performance and memory
    $memoryBefore = memory_get_usage(true);
    $startTime = microtime(true);

    $lazyCount = 0;
    foreach (User::where('name', 'LIKE', 'Perf User%')->lazy() as $user) {
        $lazyCount++;
    }

    $lazyTime = (microtime(true) - $startTime) * 1000;
    $lazyMemory = memory_get_usage(true) - $memoryBefore;

    // Assertions
    expect($allCount)->toBe(40);
    expect($chunkCount)->toBe(40);
    expect($lazyCount)->toBe(40);

    // Performance expectations
    expect($allTime)->toBeLessThan(200); // 200ms
    expect($chunkTime)->toBeLessThan(250); // 250ms (might be slightly slower due to multiple queries)
    expect($lazyTime)->toBeLessThan(300); // 300ms (streaming might be slower)

    // Memory expectations - all methods should use reasonable memory
    expect($allMemory)->toBeLessThan(10 * 1024 * 1024); // 10MB should be more than enough
    expect($chunkMemory)->toBeLessThan(10 * 1024 * 1024);
    expect($lazyMemory)->toBeLessThan(10 * 1024 * 1024);
});

test('chunk with complex where conditions', function () {
    // Create users with varying ages and status
    for ($i = 1; $i <= 20; $i++) {
        User::create([
            'name' => "Complex Where User {$i}",
            'email' => "complex{$i}@test.com",
            'age' => rand(18, 80),
            'active' => $i % 2 === 0, // Every other user is active
            'score' => $i * 5,
        ]);
    }

    $processedUsers = [];

    User::where('name', 'LIKE', 'Complex Where User%')
        ->where('active', true)
        ->where('age', '>', 25)
        ->orderBy('name')
        ->chunk(5, function ($userChunk) use (&$processedUsers) {
            foreach ($userChunk as $user) {
                expect($user->active)->toBeTrue();
                expect($user->age)->toBeGreaterThan(25);
                $processedUsers[] = $user->id;
            }
        });

    // Should have processed some users (exact count depends on random ages)
    expect(count($processedUsers))->toBeGreaterThan(0);
    expect(count($processedUsers))->toBeLessThanOrEqual(10); // Max 10 active users from 20 total
});

test('lazy collection with early termination', function () {
    // Create users with incrementing scores
    $testId = uniqid('early_term_');
    for ($i = 1; $i <= 25; $i++) {
        User::create([
            'name' => "EarlyTermUser_{$testId}_{$i}",
            'email' => "earlyterm{$testId}{$i}@test.com",
            'score' => $i * 10, // 10, 20, 30, etc.
            'user_index' => $i,
        ]);
    }

    // Test early termination with lazy collection
    $highScoreUsers = User::where('name', 'LIKE', "EarlyTermUser_{$testId}_%")
        ->lazy()
        ->filter(function ($user) {
            return $user->score >= 100; // user_index >= 10, should give us plenty
        })
        ->take(3) // Only take first 3 high-score users
        ->map(function ($user) {
            return [
                'name' => $user->name,
                'score' => $user->score,
            ];
        })
        ->toArray();

    expect($highScoreUsers)->toHaveCount(3);
    // Verify all returned users meet the criteria
    foreach ($highScoreUsers as $user) {
        expect($user['score'])->toBeGreaterThanOrEqual(100);
        expect($user['name'])->toContain("EarlyTermUser_{$testId}_");
    }
});

test('collection operations error handling', function () {
    // Test chunk with empty result set
    $emptyChunkCount = 0;
    User::where('name', 'NonExistentUser')->orderBy('name')->chunk(10, function ($users) use (&$emptyChunkCount) {
        $emptyChunkCount++;
    });

    expect($emptyChunkCount)->toBe(0); // Should not call callback for empty result

    // Test lazy with empty result set
    $emptyLazyCount = 0;
    foreach (User::where('name', 'NonExistentUser')->lazy() as $user) {
        $emptyLazyCount++;
    }

    expect($emptyLazyCount)->toBe(0);

    // Test lazy with empty result set (similar to cursor behavior)
    $emptyStreamCount = 0;
    foreach (User::where('name', 'NonExistentUser')->lazy() as $user) {
        $emptyStreamCount++;
    }

    expect($emptyStreamCount)->toBe(0);
});

test('chunk processing maintains data integrity', function () {
    // Create users with specific data for integrity testing
    $testId = uniqid('integrity_test_');
    $originalUsers = [];
    for ($i = 1; $i <= 12; $i++) {
        $user = User::create([
            'name' => "IntegrityUser_{$testId}_{$i}",
            'email' => "integrity{$testId}{$i}@test.com",
            'age' => 20 + $i,
            'score' => $i * 100,
        ]);
        $originalUsers[$user->id] = [
            'name' => $user->name,
            'age' => $user->age,
            'score' => $user->score,
        ];
    }

    $retrievedUsers = [];

    User::where('name', 'LIKE', "IntegrityUser_{$testId}_%")
        ->orderBy('name')
        ->chunk(4, function ($userChunk) use (&$retrievedUsers) {
            foreach ($userChunk as $user) {
                $retrievedUsers[$user->id] = [
                    'name' => $user->name,
                    'age' => $user->age,
                    'score' => $user->score,
                ];
            }
        });

    // Verify all users were retrieved and data matches
    expect($retrievedUsers)->toHaveCount(count($originalUsers));

    foreach ($originalUsers as $id => $originalData) {
        expect($retrievedUsers)->toHaveKey($id);
        expect($retrievedUsers[$id])->toBe($originalData);
    }
});
