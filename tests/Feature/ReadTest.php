<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Tests\Models\User;

// TEST FIRST: tests/Feature/ReadTest.php
// Focus: Standard Eloquent retrieval API

test('user can find model by id', function () {
    $created = User::create(['name' => 'John']);
    $found = User::find($created->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
    expect($found->name)->toBe('John');
});

test('user gets null for non existent model', function () {
    $user = User::find(99999);
    expect($user)->toBeNull();
});

test('user can get all models', function () {
    User::create(['name' => 'User 1']);
    User::create(['name' => 'User 2']);

    $users = User::all();

    expect($users)->toHaveCount(2);
    expect($users)->toBeInstanceOf(Collection::class);
});

// Exception Tests (3 tests)

test('findOrFail throws ModelNotFoundException when record not found', function () {
    expect(function () {
        User::findOrFail(99999);
    })->toThrow(ModelNotFoundException::class);
});

test('firstOrFail throws exception on empty result set', function () {
    expect(function () {
        User::where('name', 'NonExistent')->firstOrFail();
    })->toThrow(ModelNotFoundException::class);
});

test('findOrNew returns new instance when not found without exception', function () {
    $user = User::findOrNew(99999);

    expect($user)->toBeInstanceOf(User::class);
    expect($user->exists)->toBeFalse();
    expect($user->id)->toBeNull();

    // Can set attributes on the new instance
    $user->name = 'New User';
    expect($user->name)->toBe('New User');
});

// Basic Retrieval (8 tests)

test('findMany retrieves multiple records by array of IDs', function () {
    $user1 = User::create(['name' => 'User 1']);
    $user2 = User::create(['name' => 'User 2']);
    $user3 = User::create(['name' => 'User 3']);

    $users = User::findMany([$user1->id, $user3->id]);

    expect($users)->toHaveCount(2);
    expect($users->pluck('name')->toArray())->toEqualCanonicalizing(['User 1', 'User 3']);
});

test('first returns first record matching query', function () {
    User::create(['name' => 'Alice', 'age' => 30]);
    User::create(['name' => 'Bob', 'age' => 25]);
    User::create(['name' => 'Charlie', 'age' => 35]);

    $user = User::orderBy('age')->first();

    expect($user->name)->toBe('Bob');
    expect($user->age)->toBe(25);
});

test('firstWhere retrieves first record matching conditions', function () {
    User::create(['name' => 'Alice', 'status' => 'inactive']);
    User::create(['name' => 'Bob', 'status' => 'active']);
    User::create(['name' => 'Charlie', 'status' => 'active']);

    $user = User::firstWhere('status', 'active');

    expect($user->name)->toBe('Bob');
});

test('get returns collection of all matching records', function () {
    User::create(['name' => 'Alice', 'age' => 30]);
    User::create(['name' => 'Bob', 'age' => 25]);
    User::create(['name' => 'Charlie', 'age' => 30]);

    $users = User::where('age', 30)->get();

    expect($users)->toHaveCount(2);
    expect($users->pluck('name')->toArray())->toContain('Alice', 'Charlie');
});

test('pluck single column returns array of values', function () {
    User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $names = User::pluck('name');

    expect($names)->toBeInstanceOf(Collection::class);
    expect($names->toArray())->toBe(['Alice', 'Bob']);
});

test('pluck with key-value pairs returns associative array', function () {
    $user1 = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $user2 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $emailsByName = User::pluck('email', 'name');

    expect($emailsByName->toArray())->toBe([
        'Alice' => 'alice@example.com',
        'Bob' => 'bob@example.com',
    ]);
});

test('value retrieves single column value from first record', function () {
    User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $email = User::where('name', 'Alice')->value('email');

    expect($email)->toBe('alice@example.com');
});

test('value returns null when no record found', function () {
    $value = User::where('name', 'NonExistent')->value('email');

    expect($value)->toBeNull();
});

// Pagination (3 tests)

test('paginate returns LengthAwarePaginator with metadata', function () {
    // Create 15 users
    for ($i = 1; $i <= 15; $i++) {
        User::create(['name' => "User $i"]);
    }

    $paginated = User::paginate(10);

    expect($paginated)->toHaveCount(10);
    expect($paginated->total())->toBe(15);
    expect($paginated->perPage())->toBe(10);
    expect($paginated->currentPage())->toBe(1);
    expect($paginated->lastPage())->toBe(2);
});

test('simplePaginate returns Paginator without total count', function () {
    // Create 15 users
    for ($i = 1; $i <= 15; $i++) {
        User::create(['name' => "User $i"]);
    }

    $paginated = User::simplePaginate(10);

    expect($paginated)->toHaveCount(10);
    expect($paginated->perPage())->toBe(10);
    expect($paginated->currentPage())->toBe(1);
    expect($paginated->hasMorePages())->toBeTrue();
});

test('cursorPaginate returns CursorPaginator for efficient pagination', function () {
    // Create 5 users
    for ($i = 1; $i <= 5; $i++) {
        User::create(['name' => "User $i"]);
    }

    $paginated = User::cursorPaginate(3);

    expect($paginated)->toHaveCount(3);
    expect($paginated->perPage())->toBe(3);
    expect($paginated->hasMorePages())->toBeTrue();
});

// Chunking & Cursors (4 tests)

test('chunk processes records in batches', function () {
    // Create 5 users
    for ($i = 1; $i <= 5; $i++) {
        User::create(['name' => "User $i"]);
    }

    $chunks = [];
    User::chunk(2, function ($users) use (&$chunks) {
        $chunks[] = $users->count();
    });

    expect($chunks)->toBe([2, 2, 1]);
});

test('chunkById safely processes with ID ordering', function () {
    // Create 5 users
    for ($i = 1; $i <= 5; $i++) {
        User::create(['name' => "User $i"]);
    }

    $processedIds = [];
    User::chunkById(2, function ($users) use (&$processedIds) {
        foreach ($users as $user) {
            $processedIds[] = $user->id;
        }
    });

    expect(count($processedIds))->toBe(5);
    expect($processedIds)->toBe(array_unique($processedIds)); // No duplicates
});

test('cursor returns LazyCollection for memory efficiency', function () {
    // Create 3 users
    for ($i = 1; $i <= 3; $i++) {
        User::create(['name' => "User $i"]);
    }

    $cursor = User::cursor();

    expect($cursor)->toBeInstanceOf(\Illuminate\Support\LazyCollection::class);

    $names = [];
    foreach ($cursor as $user) {
        $names[] = $user->name;
    }

    expect($names)->toBe(['User 1', 'User 2', 'User 3']);
});

test('lazy returns LazyCollection with custom chunk size', function () {
    // Create 3 users
    for ($i = 1; $i <= 3; $i++) {
        User::create(['name' => "User $i"]);
    }

    $lazy = User::lazy(2);

    expect($lazy)->toBeInstanceOf(\Illuminate\Support\LazyCollection::class);
    expect($lazy->count())->toBe(3);
});

// Query Helpers (5 tests)

test('exists returns true when records match query', function () {
    User::create(['name' => 'Alice', 'age' => 30]);

    expect(User::where('name', 'Alice')->exists())->toBeTrue();
    expect(User::where('name', 'Bob')->exists())->toBeFalse();
});

test('doesntExist returns true when no records match', function () {
    User::create(['name' => 'Alice']);

    expect(User::where('name', 'Bob')->doesntExist())->toBeTrue();
    expect(User::where('name', 'Alice')->doesntExist())->toBeFalse();
});

test('count returns integer count of matching records', function () {
    User::create(['name' => 'Alice', 'age' => 30]);
    User::create(['name' => 'Bob', 'age' => 25]);
    User::create(['name' => 'Charlie', 'age' => 30]);

    expect(User::count())->toBe(3);
    expect(User::where('age', 30)->count())->toBe(2);
});

test('min max avg sum aggregate functions work correctly', function () {
    User::create(['name' => 'Alice', 'age' => 30, 'salary' => 50000]);
    User::create(['name' => 'Bob', 'age' => 25, 'salary' => 60000]);
    User::create(['name' => 'Charlie', 'age' => 35, 'salary' => 70000]);

    expect(User::min('age'))->toBe(25);
    expect(User::max('age'))->toBe(35);
    expect(User::avg('age'))->toBe(30.0);
    expect(User::sum('salary'))->toBe(180000);
});

test('select with specific columns limits returned data', function () {
    User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

    $user = User::select('name', 'age')->first();

    expect($user->name)->toBe('Alice');
    expect($user->age)->toBe(30);
    expect($user->email)->toBeNull(); // Not selected
});
