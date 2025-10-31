<?php

use Illuminate\Support\Facades\DB;
use Tests\Models\User;

test('can configure multiple neo4j connections', function () {
    // Configure primary connection
    config(['database.connections.neo4j_primary' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    // Configure secondary connection
    config(['database.connections.neo4j_secondary' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    // Both connections should be retrievable
    $primary = DB::connection('neo4j_primary');
    $secondary = DB::connection('neo4j_secondary');

    expect($primary)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);
    expect($secondary)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);
    expect($primary)->not->toBe($secondary);
});

test('model can use specific connection', function () {
    // Configure connections
    config(['database.connections.neo4j_primary' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    config(['database.connections.neo4j_secondary' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    // Set connection on model
    $user = new User;
    $user->setConnection('neo4j_primary');

    expect($user->getConnectionName())->toBe('neo4j_primary');

    // Create on primary
    $user->name = 'John Doe';
    $user->email = 'john@example.com';
    $user->save();

    expect($user->exists)->toBeTrue();
    expect($user->id)->not->toBeNull();

    // Switch to secondary connection
    $user2 = new User;
    $user2->setConnection('neo4j_secondary');
    $user2->name = 'Jane Doe';
    $user2->email = 'jane@example.com';
    $user2->save();

    expect($user2->exists)->toBeTrue();
    expect($user2->getConnectionName())->toBe('neo4j_secondary');
});

test('can configure read and write connections separately', function () {
    // Configure with read/write splitting
    config(['database.connections.neo4j_split' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'write' => [
            'host' => env('NEO4J_HOST', 'localhost'),
            'port' => env('NEO4J_PORT', 7688),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password'),
        ],
        'read' => [
            'host' => env('NEO4J_HOST', 'localhost'),
            'port' => env('NEO4J_PORT', 7688),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password'),
        ],
    ]]);

    $connection = DB::connection('neo4j_split');

    expect($connection)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);

    expect($connection->hasReadWriteSplit())->toBeTrue();

    // Write operations should use write connection
    $user = new User;
    $user->setConnection('neo4j_split');
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $user->save();

    expect($user->exists)->toBeTrue();

    // Read operations should use read connection
    $found = User::on('neo4j_split')->find($user->id);
    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Test User');
});

test('connection pool respects max connections limit', function () {
    config(['database.connections.neo4j_pooled' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'pool' => [
            'enabled' => true,
            'min_connections' => 2,
            'max_connections' => 5,
        ],
    ]]);

    $connection = DB::connection('neo4j_pooled');

    expect($connection)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);
    expect($connection->getPoolConfig())->toMatchArray([
        'enabled' => true,
        'min_connections' => 2,
        'max_connections' => 5,
    ]);

    // Test that multiple operations reuse connections from pool
    $users = [];
    for ($i = 0; $i < 10; $i++) {
        $user = new User;
        $user->setConnection('neo4j_pooled');
        $user->name = "User $i";
        $user->email = "user$i@example.com";
        $user->save();
        $users[] = $user;
    }

    // All users should have been created
    foreach ($users as $user) {
        expect($user->exists)->toBeTrue();
    }

    // Verify pool statistics
    $stats = $connection->getPoolStats();
    expect($stats['total_connections'])->toBeLessThanOrEqual(5);
    expect($stats['active_connections'])->toBeLessThanOrEqual(5);
});

test('can configure connection retry logic', function () {
    config(['database.connections.neo4j_retry' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'retry' => [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 100,
        ],
    ]]);

    $connection = DB::connection('neo4j_retry');

    expect($connection->getRetryConfig())->toMatchArray([
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 100,
    ]);

    // Test that operations work with retry enabled
    $user = new User;
    $user->setConnection('neo4j_retry');
    $user->name = 'Retry Test';
    $user->email = 'retry@example.com';
    $user->save();

    expect($user->exists)->toBeTrue();
});

test('can switch between connections dynamically', function () {
    // Configure multiple connections
    config(['database.connections.neo4j_a' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    config(['database.connections.neo4j_b' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    // Create user on connection A
    $user = User::on('neo4j_a')->create([
        'name' => 'Connection A User',
        'email' => 'a@example.com',
    ]);

    expect($user->getConnection()->getName())->toBe('neo4j_a');

    // Switch to connection B
    $user->setConnection('neo4j_b');
    $user->name = 'Updated on B';
    $user->save();

    expect($user->getConnection()->getName())->toBe('neo4j_b');
});

test('lazy connection initialization', function () {
    config(['database.connections.neo4j_lazy' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'lazy' => true,
    ]]);

    $connection = DB::connection('neo4j_lazy');

    expect($connection)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);
    expect($connection->isConnected())->toBeFalse();

    // Connection should be established on first use
    $user = User::on('neo4j_lazy')->create([
        'name' => 'Lazy User',
        'email' => 'lazy@example.com',
    ]);

    expect($connection->isConnected())->toBeTrue();
    expect($user->exists)->toBeTrue();
});

test('connection pool handles concurrent requests', function () {
    config(['database.connections.neo4j_concurrent' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'pool' => [
            'enabled' => true,
            'min_connections' => 2,
            'max_connections' => 10,
        ],
    ]]);

    $connection = DB::connection('neo4j_concurrent');

    // Simulate concurrent operations
    $promises = [];
    for ($i = 0; $i < 20; $i++) {
        $promises[] = function () use ($i) {
            return User::on('neo4j_concurrent')->create([
                'name' => "Concurrent User $i",
                'email' => "concurrent$i@example.com",
            ]);
        };
    }

    // Execute all operations
    $results = array_map(fn ($promise) => $promise(), $promises);

    // All operations should complete successfully
    foreach ($results as $index => $user) {
        expect($user->exists)->toBeTrue();
        expect($user->name)->toBe("Concurrent User $index");
    }

    // Check pool didn't exceed limits
    $stats = $connection->getPoolStats();
    expect($stats['peak_connections'])->toBeLessThanOrEqual(10);
});

test('read preference can be configured', function () {
    config(['database.connections.neo4j_read_pref' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'read_preference' => 'secondary_preferred',
    ]]);

    $connection = DB::connection('neo4j_read_pref');

    expect($connection->getReadPreference())->toBe('secondary_preferred');

    // Operations should still work
    $user = User::on('neo4j_read_pref')->create([
        'name' => 'Read Pref User',
        'email' => 'readpref@example.com',
    ]);

    expect($user->exists)->toBeTrue();

    // Reading should respect preference
    $found = User::on('neo4j_read_pref')->find($user->id);
    expect($found)->not->toBeNull();
});

test('connection can be purged from manager', function () {
    config(['database.connections.neo4j_purge' => [
        'driver' => 'neo4j',
        'database' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ]]);

    // Get connection
    $connection = DB::connection('neo4j_purge');
    expect($connection)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);

    // Create a user to establish connection
    $user = User::on('neo4j_purge')->create([
        'name' => 'Purge Test',
        'email' => 'purge@example.com',
    ]);

    expect($connection->isConnected())->toBeTrue();

    // Purge the connection
    DB::purge('neo4j_purge');

    // Getting connection again should create a new instance
    $newConnection = DB::connection('neo4j_purge');
    expect($newConnection)->toBeInstanceOf(\Look\EloquentCypher\GraphConnection::class);
    expect($newConnection)->not->toBe($connection);
});

// Clean up test-specific connection configurations after each test
afterEach(function () {
    // List all test-specific connection names created in this file
    $testConnections = [
        'neo4j_primary',
        'neo4j_secondary',
        'neo4j_split',
        'neo4j_pooled',
        'neo4j_concurrent',
        'neo4j_retry',
        'neo4j_lazy',
        'neo4j_read_pref',
        'neo4j_purge',
        'neo4j_a',
        'neo4j_b',
    ];

    foreach ($testConnections as $connectionName) {
        try {
            // Disconnect and purge connection if it exists
            if (method_exists(DB::class, 'connection')) {
                $conn = DB::connection($connectionName);
                if (method_exists($conn, 'disconnect')) {
                    $conn->disconnect();
                }
            }
            DB::purge($connectionName);

            // Remove from config
            if (config()->has("database.connections.{$connectionName}")) {
                config()->offsetUnset("database.connections.{$connectionName}");
            }
        } catch (\Exception $e) {
            // Connection may not exist or may already be cleaned up - ignore
        }
    }

    // Ensure default connection is active and ready
    try {
        DB::reconnect('graph');
    } catch (\Exception $e) {
        // If reconnection fails, that's OK - base test class will handle it
    }
});
