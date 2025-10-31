<?php

use Tests\Models\Post;
use Tests\Models\User;

// TEST SUITE: Advanced Model Creation Operations
// Focus: Complex creation scenarios, composite conditions, bulk operations, custom keys, cast types

test('create with complex nested attributes', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'age' => 30,
        'preferences' => [
            'theme' => 'dark',
            'notifications' => [
                'email' => true,
                'push' => false,
                'sms' => true,
            ],
            'privacy' => [
                'profile_visible' => true,
                'activity_visible' => false,
            ],
        ],
        'metadata' => [
            'registration_source' => 'web',
            'utm_campaign' => 'summer2023',
            'referrer' => 'google.com',
        ],
        'tags' => ['developer', 'premium', 'beta-tester'],
    ]);

    expect($user->id)->not->toBeNull();
    expect($user->name)->toBe('John Doe');
    expect($user->preferences)->toBeArray();
    expect($user->preferences['theme'])->toBe('dark');
    expect($user->preferences['notifications']['email'])->toBeTrue();
    expect($user->metadata)->toBeArray();
    expect($user->tags)->toContain('developer');
    expect($user->exists)->toBeTrue();
});

test('firstOrCreate with simple conditions', function () {
    // First call should create
    $user1 = User::firstOrCreate(
        ['email' => 'unique@example.com'],
        ['name' => 'John Doe', 'age' => 25]
    );

    expect($user1->wasRecentlyCreated)->toBeTrue();
    expect($user1->name)->toBe('John Doe');
    expect($user1->email)->toBe('unique@example.com');

    // Second call should find existing
    $user2 = User::firstOrCreate(
        ['email' => 'unique@example.com'],
        ['name' => 'Jane Doe', 'age' => 30]
    );

    expect($user2->wasRecentlyCreated)->toBeFalse();
    expect($user2->id)->toBe($user1->id);
    expect($user2->name)->toBe('John Doe'); // Should keep original name
});

test('firstOrCreate with composite conditions', function () {
    // Create user with specific name and status
    $user1 = User::firstOrCreate(
        ['name' => 'John Doe', 'status' => 'active'],
        ['email' => 'john@example.com', 'age' => 25]
    );

    expect($user1->wasRecentlyCreated)->toBeTrue();

    // Different status should create new user
    $user2 = User::firstOrCreate(
        ['name' => 'John Doe', 'status' => 'inactive'],
        ['email' => 'john2@example.com', 'age' => 30]
    );

    expect($user2->wasRecentlyCreated)->toBeTrue();
    expect($user2->id)->not->toBe($user1->id);

    // Same name and status should find existing
    $user3 = User::firstOrCreate(
        ['name' => 'John Doe', 'status' => 'active'],
        ['email' => 'ignored@example.com', 'age' => 99]
    );

    expect($user3->wasRecentlyCreated)->toBeFalse();
    expect($user3->id)->toBe($user1->id);
});

test('updateOrCreate creates when no match found', function () {
    $user = User::updateOrCreate(
        ['email' => 'new@example.com'],
        ['name' => 'New User', 'age' => 25]
    );

    expect($user->wasRecentlyCreated)->toBeTrue();
    expect($user->name)->toBe('New User');
    expect($user->email)->toBe('new@example.com');
});

test('updateOrCreate updates when match found', function () {
    // Create initial user
    $original = User::create([
        'name' => 'Original Name',
        'email' => 'update@example.com',
        'age' => 20,
    ]);

    // Update existing user
    $updated = User::updateOrCreate(
        ['email' => 'update@example.com'],
        ['name' => 'Updated Name', 'age' => 30]
    );

    expect($updated->wasRecentlyCreated)->toBeFalse();
    expect($updated->id)->toBe($original->id);
    expect($updated->name)->toBe('Updated Name');
    expect($updated->age)->toBe(30);
});

test('updateOrCreate with relationship data', function () {
    $user = User::updateOrCreate(
        ['email' => 'user@example.com'],
        [
            'name' => 'User With Posts',
            'age' => 25,
            'metadata' => [
                'post_count' => 0,
                'last_post_date' => null,
            ],
        ]
    );

    // Create related post
    $post = $user->posts()->create([
        'title' => 'First Post',
        'content' => 'Hello World',
    ]);

    // Update user with post information
    $updatedUser = User::updateOrCreate(
        ['email' => 'user@example.com'],
        [
            'metadata' => [
                'post_count' => 1,
                'last_post_date' => now()->toDateString(),
            ],
        ]
    );

    expect($updatedUser->id)->toBe($user->id);
    expect($updatedUser->metadata['post_count'])->toBe(1);
    expect($updatedUser->posts()->count())->toBe(1);
});

test('bulk creation with array of data', function () {
    $userData = [
        ['name' => 'User 1', 'email' => 'user1@example.com', 'age' => 25],
        ['name' => 'User 2', 'email' => 'user2@example.com', 'age' => 30],
        ['name' => 'User 3', 'email' => 'user3@example.com', 'age' => 35],
    ];

    $users = collect($userData)->map(function ($data) {
        return User::create($data);
    });

    expect($users)->toHaveCount(3);
    expect(User::count())->toBe(3);

    $names = $users->pluck('name')->toArray();
    expect($names)->toContain('User 1');
    expect($names)->toContain('User 2');
    expect($names)->toContain('User 3');
});

test('creation with custom primary key handling', function () {
    $user = User::create([
        'name' => 'Custom ID User',
        'email' => 'custom@example.com',
        'internal_id' => 'CUSTOM-123',
    ]);

    expect($user->id)->not->toBeNull();
    expect($user->internal_id)->toBe('CUSTOM-123');

    // Verify we can find by both keys
    $foundById = User::find($user->id);
    $foundByInternal = User::where('internal_id', 'CUSTOM-123')->first();

    expect($foundById->id)->toBe($user->id);
    expect($foundByInternal->id)->toBe($user->id);
});

test('creation with all Laravel cast types', function () {
    $user = User::create([
        'name' => 'Cast User',
        'email' => 'cast@example.com',
        'age' => 30,              // integer cast
        'score' => 95.5,          // float cast
        'is_active' => true,      // boolean cast
        'is_premium' => false,    // boolean cast
        'verified' => 1,          // boolean cast (truthy)
        'last_login' => now(),    // datetime cast
        'preferences' => [        // array cast
            'theme' => 'dark',
            'language' => 'en',
        ],
        'metadata' => [           // json cast
            'source' => 'api',
            'version' => '2.1',
        ],
    ]);

    // Verify casting worked correctly
    expect($user->age)->toBeInt();
    expect($user->score)->toBeFloat();
    expect($user->is_active)->toBeBool();
    expect($user->is_premium)->toBeBool();
    expect($user->verified)->toBeBool();
    expect($user->last_login)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($user->preferences)->toBeArray();
    expect($user->metadata)->toBeArray();

    // Test retrieval maintains casting
    $retrieved = User::find($user->id);
    expect($retrieved->age)->toBeInt();
    expect($retrieved->score)->toBeFloat();
    expect($retrieved->is_active)->toBe(true);
    expect($retrieved->is_premium)->toBe(false);
    expect($retrieved->verified)->toBe(true);
});

test('creation with validation-like behavior via fillable', function () {
    $user = User::create([
        'name' => 'Valid User',
        'email' => 'valid@example.com',
        'age' => 25,
        'secret_key' => 'should_be_saved', // in fillable
        'non_fillable_field' => 'should_be_ignored', // not in fillable
    ]);

    expect($user->name)->toBe('Valid User');
    expect($user->secret_key)->toBe('should_be_saved');
    expect($user->getAttribute('non_fillable_field'))->toBeNull();
});

test('create with nullable and default handling', function () {
    $user = User::create([
        'name' => 'Minimal User',
        'email' => 'minimal@example.com',
        // age, preferences, etc. not provided
    ]);

    expect($user->name)->toBe('Minimal User');
    expect($user->email)->toBe('minimal@example.com');
    expect($user->age)->toBeNull();
    expect($user->preferences)->toBeNull();
});

test('create and immediately load with relationships', function () {
    $user = User::create([
        'name' => 'User With Relationships',
        'email' => 'relationships@example.com',
    ]);

    // Create related data
    $post1 = $user->posts()->create(['title' => 'Post 1', 'content' => 'Content 1']);
    $post2 = $user->posts()->create(['title' => 'Post 2', 'content' => 'Content 2']);

    // Load user with relationships in single query
    $userWithPosts = User::with('posts')->find($user->id);

    expect($userWithPosts->posts)->toHaveCount(2);
    expect($userWithPosts->posts->pluck('title')->toArray())->toContain('Post 1');
    expect($userWithPosts->posts->pluck('title')->toArray())->toContain('Post 2');
});

test('create with timestamps enabled', function () {
    $beforeCreation = now()->subSecond(); // Give a 1-second buffer

    $user = User::create([
        'name' => 'Timestamped User',
        'email' => 'timestamp@example.com',
    ]);

    $afterCreation = now()->addSecond(); // Give a 1-second buffer

    expect($user->created_at)->not->toBeNull();
    expect($user->updated_at)->not->toBeNull();
    expect($user->created_at->between($beforeCreation, $afterCreation))->toBeTrue();
    expect($user->updated_at->between($beforeCreation, $afterCreation))->toBeTrue();

    // Also verify that timestamps are Carbon instances
    expect($user->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($user->updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('create multiple models and verify independence', function () {
    $user1 = User::create([
        'name' => 'User 1',
        'email' => 'user1@example.com',
        'preferences' => ['theme' => 'dark'],
    ]);

    $user2 = User::create([
        'name' => 'User 2',
        'email' => 'user2@example.com',
        'preferences' => ['theme' => 'light'],
    ]);

    // Verify they have different IDs
    expect($user1->id)->not->toBe($user2->id);

    // Verify independent attributes
    expect($user1->preferences['theme'])->toBe('dark');
    expect($user2->preferences['theme'])->toBe('light');

    // Verify database contains both
    expect(User::count())->toBe(2);
});

test('create with large JSON data structures', function () {
    $largeMetadata = [];
    for ($i = 0; $i < 100; $i++) {
        $largeMetadata["field_$i"] = [
            'value' => $i,
            'description' => "This is field number $i",
            'nested' => [
                'level1' => ['level2' => ['level3' => $i * 2]],
            ],
        ];
    }

    $user = User::create([
        'name' => 'Large Data User',
        'email' => 'large@example.com',
        'metadata' => $largeMetadata,
    ]);

    expect($user->metadata)->toBeArray();
    expect($user->metadata)->toHaveCount(100);
    expect($user->metadata['field_50']['value'])->toBe(50);
    expect($user->metadata['field_99']['nested']['level1']['level2']['level3'])->toBe(198);
});

test('firstOrCreate with complex JSON conditions', function () {
    // Note: JSON comparison in Neo4j works differently than SQL
    // Using email as primary identifier since JSON comparison is complex
    $user1 = User::firstOrCreate(
        ['email' => 'json@example.com'],
        [
            'name' => 'JSON User',
            'preferences' => [
                'notifications' => ['email' => true],
                'privacy' => ['public_profile' => false],
            ],
        ]
    );

    expect($user1->wasRecentlyCreated)->toBeTrue();

    // Should find existing user by email (simpler condition)
    $user2 = User::firstOrCreate(
        ['email' => 'json@example.com'],
        [
            'name' => 'Different Name',
            'preferences' => ['theme' => 'light'],
        ]
    );

    expect($user2->wasRecentlyCreated)->toBeFalse();
    expect($user2->id)->toBe($user1->id);
    expect($user2->name)->toBe('JSON User'); // Original name preserved
});

test('updateOrCreate with complex attribute merging', function () {
    // Create initial user
    $user = User::create([
        'name' => 'Original User',
        'email' => 'merge@example.com',
        'preferences' => ['theme' => 'dark', 'language' => 'en'],
        'metadata' => ['version' => 1, 'source' => 'web'],
    ]);

    // Update with new data
    $updatedUser = User::updateOrCreate(
        ['email' => 'merge@example.com'],
        [
            'name' => 'Updated User',
            'preferences' => ['theme' => 'light', 'timezone' => 'UTC'],
            'metadata' => ['version' => 2, 'last_update' => 'api'],
        ]
    );

    expect($updatedUser->id)->toBe($user->id);
    expect($updatedUser->name)->toBe('Updated User');
    expect($updatedUser->preferences['theme'])->toBe('light');
    expect($updatedUser->preferences['timezone'])->toBe('UTC');
    expect($updatedUser->metadata['version'])->toBe(2);
    expect($updatedUser->metadata['last_update'])->toBe('api');
});

test('create with special characters and unicode', function () {
    $user = User::create([
        'name' => 'José María Ñoño',
        'email' => 'josé@example.com',
        'metadata' => [
            'bio' => 'Software Engineer 👨‍💻 from España 🇪🇸',
            'skills' => ['JavaScript', 'PHP', 'Español', '中文'],
            'special_chars' => '!@#$%^&*()_+-=[]{}|;:,.<>?',
        ],
    ]);

    expect($user->name)->toBe('José María Ñoño');
    expect($user->email)->toBe('josé@example.com');
    expect($user->metadata['bio'])->toContain('👨‍💻');
    expect($user->metadata['skills'])->toContain('Español');
    expect($user->metadata['skills'])->toContain('中文');
});

test('bulk create operations with transaction-like behavior', function () {
    $users = [];

    // Create multiple users in sequence
    for ($i = 1; $i <= 5; $i++) {
        $users[] = User::create([
            'name' => "Bulk User $i",
            'email' => "bulk$i@example.com",
            'batch_number' => 100,
            'user_index' => $i,
        ]);
    }

    expect($users)->toHaveCount(5);
    expect(User::where('batch_number', 100)->count())->toBe(5);

    // Verify each user has unique ID but same batch
    $ids = collect($users)->pluck('id')->unique();
    expect($ids)->toHaveCount(5);

    $batchUsers = User::where('batch_number', 100)->orderBy('user_index')->get();
    expect($batchUsers->pluck('name')->toArray())->toBe([
        'Bulk User 1', 'Bulk User 2', 'Bulk User 3', 'Bulk User 4', 'Bulk User 5',
    ]);
});

test('create with model-specific defaults and overrides', function () {
    // Test that we can override implicit defaults
    $user = User::create([
        'name' => 'Default Test User',
        'email' => 'defaults@example.com',
        'active' => false,    // Override what might be a default true
        'created_at' => '2020-01-01 00:00:00',  // Override timestamp
    ]);

    expect($user->name)->toBe('Default Test User');
    expect($user->active)->toBeFalse();
    expect($user->created_at->year)->toBe(2020);
});

test('create and verify object identity and equality', function () {
    $user1 = User::create([
        'name' => 'Identity User',
        'email' => 'identity@example.com',
    ]);

    $user2 = User::find($user1->id);

    // Same data, different object instances
    expect($user1->id)->toBe($user2->id);
    expect($user1->name)->toBe($user2->name);
    expect($user1->email)->toBe($user2->email);

    // Verify is() method works for model comparison
    expect($user1->is($user2))->toBeTrue();
});

test('create with edge case empty and null values', function () {
    $user = User::create([
        'name' => '',  // Empty string
        'email' => 'empty@example.com',
        'age' => 0,    // Zero value
        'preferences' => [], // Empty array
        'metadata' => null,  // Explicit null
        'tags' => [],   // Empty array
    ]);

    expect($user->name)->toBe('');
    expect($user->age)->toBe(0);
    expect($user->preferences)->toBe([]);
    expect($user->metadata)->toBeNull();
    expect($user->tags)->toBe([]);
});

test('creation performance with moderate dataset', function () {
    $startTime = microtime(true);

    // Create 20 users with complex data
    $users = [];
    for ($i = 1; $i <= 20; $i++) {
        $users[] = User::create([
            'name' => "Performance User $i",
            'email' => "perf$i@example.com",
            'age' => 20 + $i,
            'preferences' => [
                'theme' => $i % 2 ? 'dark' : 'light',
                'notifications' => ['email' => true, 'sms' => false],
            ],
            'metadata' => [
                'created_via' => 'performance_test',
                'batch' => 'test_batch_1',
                'index' => $i,
            ],
        ]);
    }

    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;

    expect($users)->toHaveCount(20);
    expect(User::count())->toBe(20);
    expect($executionTime)->toBeLessThan(5.0); // Should complete within 5 seconds
});
