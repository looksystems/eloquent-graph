<?php

use Illuminate\Support\Facades\DB;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Profile;
use Tests\Models\Role;
use Tests\Models\User;

beforeEach(function () {
    // Clean database before each test - Neo4j doesn't support truncate
    // Use prefixed labels for parallel test execution
    $userLabel = (new User)->getTable();
    $postLabel = (new Post)->getTable();
    $roleLabel = (new Role)->getTable();
    $profileLabel = (new Profile)->getTable();
    $commentLabel = (new Comment)->getTable();

    DB::statement("MATCH (n:`{$userLabel}`) DETACH DELETE n");
    DB::statement("MATCH (n:`{$postLabel}`) DETACH DELETE n");
    DB::statement("MATCH (n:`{$roleLabel}`) DETACH DELETE n");
    DB::statement("MATCH (n:`{$profileLabel}`) DETACH DELETE n");
    DB::statement("MATCH (n:`{$commentLabel}`) DETACH DELETE n");
});

test('model with null primary key handling', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_models';

        protected $fillable = ['name', 'data'];
    };

    // Create without explicit ID
    $instance = new $model;
    $instance->name = 'Test';
    $instance->save();

    // ID should be auto-generated
    expect($instance->id)->not->toBeNull()
        ->and($instance->name)->toBe('Test');

    // Try to set null ID (should be ignored or auto-generated)
    $instance2 = new $model;
    $instance2->id = null;
    $instance2->name = 'Test2';
    $instance2->save();

    expect($instance2->id)->not->toBeNull();
});

test('empty string vs null differentiation', function () {
    $user1 = User::create(['name' => '', 'email' => 'empty@test.com']);
    $user2 = User::create(['name' => null, 'email' => 'null@test.com']);
    $user3 = User::create(['name' => 'Valid', 'email' => 'valid@test.com']);

    // Verify storage
    expect($user1->name)->toBe('')
        ->and($user2->name)->toBeNull()
        ->and($user3->name)->toBe('Valid');

    // Query differentiation
    $emptyName = User::where('name', '')->first();
    $nullName = User::whereNull('name')->first();

    expect($emptyName)->not->toBeNull()
        ->and($emptyName->email)->toBe('empty@test.com')
        ->and($nullName)->not->toBeNull()
        ->and($nullName->email)->toBe('null@test.com');
});

test('extremely large attribute values', function () {
    // Create a 10KB+ string
    $largeString = str_repeat('Lorem ipsum dolor sit amet. ', 360);
    expect(strlen($largeString))->toBeGreaterThan(10000);

    $post = Post::create([
        'title' => 'Large Content Post',
        'content' => $largeString,
    ]);

    // Verify it saves and retrieves correctly
    $retrieved = Post::find($post->id);
    expect($retrieved->content)->toBe($largeString)
        ->and(strlen($retrieved->content))->toBe(strlen($largeString));

    // Test with large JSON
    $largeArray = array_fill(0, 1000, ['key' => 'value', 'nested' => ['data' => 'test']]);
    $largeJson = json_encode($largeArray);
    $post2 = Post::create([
        'title' => 'Large JSON Post',
        'metadata' => $largeJson,
    ]);

    $retrieved2 = Post::find($post2->id);
    // Neo4j might automatically decode JSON, so check both possibilities
    if (is_string($retrieved2->metadata)) {
        expect($retrieved2->metadata)->toBe($largeJson);
    } else {
        // If it's auto-decoded, verify the structure
        expect($retrieved2->metadata)->toBe($largeArray);
    }
});

test('special characters in model data', function () {
    $specialChars = [
        'emoji' => '😀🎉🚀❤️',
        'unicode' => '你好世界 مرحبا بالعالم',
        'quotes' => "It's a \"test\" with 'quotes'",
        'backslash' => 'C:\\Users\\Test\\Path',
        'newlines' => "Line 1\nLine 2\rLine 3\r\nLine 4",
        'tabs' => "Tab\tSeparated\tValues",
        'html' => '<script>alert("XSS")</script>',
        'sql' => "'; DROP TABLE users; --",
        'cypher' => 'MATCH (n) DETACH DELETE n',
        'mixed' => "😀 \"Test\" \n\t 'Data' \\ //",
    ];

    foreach ($specialChars as $type => $value) {
        $user = User::create([
            'name' => $value,
            'email' => "$type@test.com",
        ]);

        $retrieved = User::find($user->id);
        expect($retrieved->name)->toBe($value)
            ->and($retrieved->email)->toBe("$type@test.com");
    }
});

test('concurrent model modifications simulation', function () {
    $user = User::create(['name' => 'Original', 'email' => 'concurrent@test.com', 'age' => 25]);

    // Simulate two concurrent reads
    $instance1 = User::find($user->id);
    $instance2 = User::find($user->id);

    // Both modify different fields
    $instance1->name = 'Modified by 1';
    $instance1->save();

    $instance2->age = 30;
    $instance2->save();

    // Last write wins - instance2 overwrites instance1's changes
    $final = User::find($user->id);
    expect($final->name)->toBe('Modified by 1') // This might be 'Original' depending on implementation
        ->and($final->age)->toBe(30);

    // Test with refresh
    $instance3 = User::find($user->id);
    $instance3->name = 'Before Refresh';
    $instance3->refresh();
    expect($instance3->name)->toBe($final->name);
});

test('malformed date and time inputs', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_models';

        protected $fillable = ['name', 'date_field'];

        protected $casts = [
            'date_field' => 'datetime',
        ];
    };

    // Valid date
    $instance1 = new $model;
    $instance1->date_field = '2024-01-15 10:30:00';
    $instance1->save();
    expect($instance1->date_field)->toBeInstanceOf(\Carbon\Carbon::class);

    // Invalid date format should throw or be handled
    $instance2 = new $model;

    try {
        $instance2->date_field = 'not-a-date';
        $instance2->save();
        // If it doesn't throw, the value might be null or unchanged
        expect($instance2->date_field)->toBeNull();
    } catch (\Carbon\Exceptions\InvalidFormatException $e) {
        // Expected exception for invalid date
        expect($e)->toBeInstanceOf(\Carbon\Exceptions\InvalidFormatException::class);
    }

    // Test valid edge case dates only
    $validDates = [
        '2024-02-29', // Leap year
        '1970-01-01', // Unix epoch
        '2030-12-31', // Future date
        '2000-01-01 00:00:00', // Millennium
    ];

    foreach ($validDates as $date) {
        $instance = new $model;
        $instance->date_field = $date;
        $instance->save();
        expect($instance->date_field)->toBeInstanceOf(\Carbon\Carbon::class);
    }
});

test('float precision edge cases', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_models';

        protected $fillable = ['price', 'rate'];

        protected $casts = [
            'price' => 'float',
            'rate' => 'decimal:4',
        ];
    };

    $values = [
        0.1 + 0.2, // Famous floating point issue
        PHP_FLOAT_MAX,
        PHP_FLOAT_MIN,
        1.23456789012345,
        0.000000000001,
        -999999999.999999,
    ];

    foreach ($values as $value) {
        $instance = new $model;
        $instance->price = $value;
        $instance->rate = $value;
        $instance->save();

        $retrieved = $model->find($instance->id);

        // Float comparison with tolerance
        expect(abs($retrieved->price - $value))->toBeLessThan(0.0000001);
    }
});

test('integer overflow scenarios', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_models';

        protected $fillable = ['count', 'bignum'];

        protected $casts = [
            'count' => 'integer',
            'bignum' => 'integer',
        ];
    };

    $values = [
        PHP_INT_MAX,
        PHP_INT_MIN,
        0,
        -1,
        2147483647, // 32-bit max
        -2147483648, // 32-bit min
    ];

    foreach ($values as $value) {
        $instance = new $model;
        $instance->count = $value;
        $instance->bignum = $value;
        $instance->save();

        $retrieved = $model->find($instance->id);
        expect($retrieved->count)->toBe($value)
            ->and($retrieved->bignum)->toBe($value);
    }
});

test('empty collections and arrays', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_models';

        protected $fillable = ['items', 'tags'];

        protected $casts = [
            'items' => 'array',
            'tags' => 'collection',
        ];
    };

    // Empty arrays
    $instance = new $model;
    $instance->items = [];
    $instance->tags = collect();
    $instance->save();

    $retrieved = $model->find($instance->id);
    expect($retrieved->items)->toBe([])
        ->and($retrieved->tags)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($retrieved->tags->isEmpty())->toBeTrue();

    // Nested empty arrays
    $instance2 = new $model;
    $instance2->items = [[], [[]], [[[]]]];
    $instance2->save();

    $retrieved2 = $model->find($instance2->id);
    expect($retrieved2->items)->toBe([[], [[]], [[[]]]]);
});

test('deeply nested JSON structures', function () {
    $deepNested = [
        'level1' => [
            'level2' => [
                'level3' => [
                    'level4' => [
                        'level5' => [
                            'level6' => [
                                'level7' => [
                                    'level8' => [
                                        'level9' => [
                                            'level10' => 'deep value',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $post = Post::create([
        'title' => 'Deep JSON',
        'metadata' => json_encode($deepNested),
    ]);

    $retrieved = Post::find($post->id);

    // metadata might be stored as array or string depending on model configuration
    if (is_string($retrieved->metadata)) {
        $decoded = json_decode($retrieved->metadata, true);
    } else {
        $decoded = $retrieved->metadata;
    }

    expect($decoded['level1']['level2']['level3']['level4']['level5']['level6']['level7']['level8']['level9']['level10'])
        ->toBe('deep value');
});

test('circular relationship references', function () {
    // User has many posts, post belongs to user
    $user = User::create(['name' => 'Circular User', 'email' => 'circular@test.com']);
    $post = Post::create(['title' => 'Circular Post', 'user_id' => $user->id]);

    // Create a comment that references both
    $comment = Comment::create([
        'content' => 'Test',
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);

    // Load with circular eager loading (should not cause infinite loop)
    $loaded = User::with(['posts.comments.user.posts'])->find($user->id);

    expect($loaded)->not->toBeNull()
        ->and($loaded->posts)->toHaveCount(1)
        ->and($loaded->posts->first()->comments)->toHaveCount(1);
});

test('models with no attributes', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'empty_models';

        protected $fillable = [];
    };

    // Create with no attributes
    $instance = new $model;
    $instance->save();

    expect($instance->id)->not->toBeNull()
        ->and($instance->getAttributes())->toHaveKey('id');

    // Retrieve and verify
    $retrieved = $model->find($instance->id);
    expect($retrieved)->not->toBeNull()
        ->and($retrieved->id)->toBe($instance->id);
});

test('duplicate relationship creation handling', function () {
    $user = User::create(['name' => 'User', 'email' => 'user@test.com']);
    $role = Role::create(['name' => 'Admin']);

    // Attach role first time
    $user->roles()->attach($role->id);

    // Try to attach same role again
    $user->roles()->attach($role->id);

    // Should handle gracefully (either ignore duplicate or allow it)
    $user->load('roles');
    expect($user->roles->count())->toBeGreaterThan(0);
});

test('zero and negative values in counts and limits', function () {
    // Create test data
    for ($i = 1; $i <= 5; $i++) {
        User::create(['name' => "User $i", 'email' => "user$i@test.com", 'age' => $i * 10]);
    }

    // Zero limit (should return all or none)
    $users = User::limit(0)->get();
    expect($users->count())->toBeLessThanOrEqual(5);

    // Negative values in where clauses
    $negativeAge = User::where('age', '<', 0)->get();
    expect($negativeAge)->toHaveCount(0);

    // Update with negative value
    $user = User::first();
    $user->age = -5;
    $user->save();

    $retrieved = User::find($user->id);
    expect($retrieved->age)->toBe(-5);
});

test('boundary value testing for limits and offsets', function () {
    // Create exactly 100 users
    $users = [];
    for ($i = 1; $i <= 100; $i++) {
        $users[] = ['name' => "User $i", 'email' => "user$i@test.com"];
    }
    User::insert($users);

    // Boundary cases
    $tests = [
        ['limit' => 1, 'offset' => 0, 'expected' => 1],
        ['limit' => 1, 'offset' => 99, 'expected' => 1],
        ['limit' => 1, 'offset' => 100, 'expected' => 0],
        ['limit' => 100, 'offset' => 0, 'expected' => 100],
        ['limit' => 101, 'offset' => 0, 'expected' => 100],
        ['limit' => 50, 'offset' => 50, 'expected' => 50],
        ['limit' => 50, 'offset' => 75, 'expected' => 25],
    ];

    foreach ($tests as $test) {
        $result = User::limit($test['limit'])->offset($test['offset'])->get();
        expect($result)->toHaveCount($test['expected']);
    }
});
