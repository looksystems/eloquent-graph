<?php

use Illuminate\Support\Facades\DB;
use Tests\Models\User;

// TEST: Query Logging functionality
// Focus: Ensure Laravel-compatible query logging works with Neo4j

beforeEach(function () {
    DB::connection('graph')->flushQueryLog();
});

test('can enable and disable query logging', function () {
    $connection = DB::connection('graph');

    // Initially, logging should be disabled
    expect($connection->logging())->toBeFalse();
    expect($connection->getQueryLog())->toBeEmpty();

    // Enable logging
    $connection->enableQueryLog();
    expect($connection->logging())->toBeTrue();

    // Disable logging
    $connection->disableQueryLog();
    expect($connection->logging())->toBeFalse();
});

test('logs select queries with bindings', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();

    // Create test data
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    // Clear log after creation
    $connection->flushQueryLog();

    // Perform a select query
    User::where('name', 'John Doe')->first();

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect($logEntry)->toHaveKeys(['query', 'bindings', 'time']);
    expect($logEntry['query'])->toContain('MATCH');
    expect($logEntry['query'])->toContain('users');
    expect($logEntry['bindings'])->toContain('John Doe');
    expect($logEntry['time'])->toBeFloat();
    expect($logEntry['time'])->toBeGreaterThan(0);
});

test('logs insert queries', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();

    User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->not->toBeEmpty();

    // Find the CREATE query
    $createQuery = collect($queryLog)->first(fn ($log) => str_contains($log['query'], 'CREATE'));
    expect($createQuery)->not->toBeNull();
    expect($createQuery['bindings'])->toContain('Jane Doe');
    expect($createQuery['bindings'])->toContain('jane@example.com');
    expect($createQuery['time'])->toBeFloat();
});

test('logs update queries', function () {
    $connection = DB::connection('graph');

    $user = User::create(['name' => 'Update Test', 'email' => 'update@example.com']);

    $connection->enableQueryLog();
    $user->update(['name' => 'Updated Name']);

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->not->toBeEmpty();

    // Find the SET query
    $updateQuery = collect($queryLog)->first(fn ($log) => str_contains($log['query'], 'SET'));
    expect($updateQuery)->not->toBeNull();
    expect($updateQuery['bindings'])->toContain('Updated Name');
    expect($updateQuery['time'])->toBeFloat();
});

test('logs delete queries', function () {
    $connection = DB::connection('graph');

    $user = User::create(['name' => 'Delete Test', 'email' => 'delete@example.com']);

    $connection->enableQueryLog();
    $user->forceDelete(); // Use forceDelete for hard delete

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->not->toBeEmpty();

    // Find the DELETE query - Neo4j uses DETACH DELETE
    $deleteQuery = collect($queryLog)->first(fn ($log) => str_contains($log['query'], 'DETACH DELETE'));
    expect($deleteQuery)->not->toBeNull();
    expect($deleteQuery['bindings'])->toContain($user->id);
    expect($deleteQuery['time'])->toBeFloat();
});

test('logs relationship queries', function () {
    $connection = DB::connection('graph');

    $user = User::create(['name' => 'Relationship Test', 'email' => 'rel@example.com']);
    $post = $user->posts()->create(['title' => 'Test Post']);

    $connection->enableQueryLog();
    $connection->flushQueryLog();

    // Query relationship
    $user->posts()->get();

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect($logEntry['query'])->toContain('posts');
    expect($logEntry['bindings'])->toContain($user->id);
    expect($logEntry['time'])->toBeFloat();
});

test('logs queries with multiple bindings', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();

    User::where('name', 'John')
        ->where('email', 'john@example.com')
        ->orWhere('name', 'Jane')
        ->get();

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->not->toBeEmpty();

    $logEntry = $queryLog[0];
    expect($logEntry['bindings'])->toContain('John');
    expect($logEntry['bindings'])->toContain('john@example.com');
    expect($logEntry['bindings'])->toContain('Jane');
});

test('flushQueryLog clears all logged queries', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();

    // Perform some queries
    User::create(['name' => 'Flush Test', 'email' => 'flush@example.com']);
    User::all();

    expect($connection->getQueryLog())->not->toBeEmpty();

    // Flush the log
    $connection->flushQueryLog();

    expect($connection->getQueryLog())->toBeEmpty();

    // New queries should still be logged
    User::first();
    expect($connection->getQueryLog())->toHaveCount(1);
});

test('does not log queries when logging is disabled', function () {
    $connection = DB::connection('graph');
    $connection->disableQueryLog();
    $connection->flushQueryLog();

    // Perform queries
    User::create(['name' => 'No Log Test', 'email' => 'nolog@example.com']);
    User::all();
    User::first();

    expect($connection->getQueryLog())->toBeEmpty();
});

test('logs aggregate queries', function () {
    $connection = DB::connection('graph');

    // Create test data
    User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
    User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

    $connection->enableQueryLog();
    $connection->flushQueryLog();

    // Perform aggregate query
    $count = User::count();

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect(strtoupper($logEntry['query']))->toContain('COUNT');
    expect($logEntry['time'])->toBeFloat();
});

test('logs queries in transactions', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    DB::connection('graph')->transaction(function () {
        User::create(['name' => 'Transaction User', 'email' => 'trans@example.com']);
        User::where('name', 'Transaction User')->first();
    });

    $queryLog = $connection->getQueryLog();
    expect(count($queryLog))->toBeGreaterThan(1);

    // Should have both CREATE and MATCH queries
    $hasCreate = collect($queryLog)->contains(fn ($log) => str_contains($log['query'], 'CREATE'));
    $hasMatch = collect($queryLog)->contains(fn ($log) => str_contains($log['query'], 'MATCH'));

    expect($hasCreate)->toBeTrue();
    expect($hasMatch)->toBeTrue();
});

test('logs raw Cypher queries', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    // Get prefixed label for users
    $usersLabel = (new \Tests\Models\User)->getTable();

    // Execute raw Cypher query with prefixed label
    $connection->select("MATCH (n:`{$usersLabel}`) RETURN n.name as name LIMIT 5");

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect($logEntry['query'])->toBe("MATCH (n:`{$usersLabel}`) RETURN n.name as name LIMIT 5");
    expect($logEntry['bindings'])->toBeEmpty();
    expect($logEntry['time'])->toBeFloat();
});

test('logs queries with execution time', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();

    // Create a larger dataset to ensure measurable time
    for ($i = 1; $i <= 10; $i++) {
        User::create(['name' => "User $i", 'email' => "user$i@example.com"]);
    }

    $connection->flushQueryLog();

    // Perform a query that should take measurable time
    User::all();

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect($logEntry['time'])->toBeFloat();
    expect($logEntry['time'])->toBeGreaterThan(0);
    // Time should be in milliseconds
    expect($logEntry['time'])->toBeLessThan(1000); // Should take less than 1 second
});

test('logQuery method adds entry to query log', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    // Manually log a query
    $connection->logQuery('MATCH (n) RETURN n', ['param' => 'value'], 5.5);

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect($logEntry['query'])->toBe('MATCH (n) RETURN n');
    expect($logEntry['bindings'])->toBe(['param' => 'value']);
    expect($logEntry['time'])->toBe(5.5);
});

test('query log works with query builder', function () {
    $connection = DB::connection('graph');
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    // Use query builder
    DB::connection('graph')->table('users')
        ->where('name', 'like', 'John%')
        ->get();

    $queryLog = $connection->getQueryLog();
    expect($queryLog)->toHaveCount(1);

    $logEntry = $queryLog[0];
    expect($logEntry['query'])->toContain('users');
    // Check if bindings contain the pattern - may be in different format
    $bindingsStr = json_encode($logEntry['bindings']);
    expect($bindingsStr)->toContain('John');
    expect($logEntry['time'])->toBeFloat();
});
