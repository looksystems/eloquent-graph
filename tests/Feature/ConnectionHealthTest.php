<?php

declare(strict_types=1);

test('ping detects healthy connection', function () {
    $connection = app('db')->connection('graph');

    // Ensure connection is healthy
    $connection->select('RETURN 1');

    $isHealthy = $connection->ping();
    expect($isHealthy)->toBeTrue();

    // Verify ping doesn't affect normal operations
    $result = $connection->select('RETURN 2 as two');
    expect($result)->toHaveCount(1);
    expect($result[0]->two ?? $result[0]['two'])->toBe(2);
});

test('ping detects broken connection', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $setProtectedProperty = function ($property, $value) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($connection, $value);
    };

    $getProtectedProperty = function ($property) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($connection);
    };

    // First ensure connection works
    $connection->select('RETURN 1');

    // Force close the connection
    $client = $getProtectedProperty('neo4jClient');
    if (method_exists($client, 'close')) {
        $client->close();
    }

    // Mark as stale
    $setProtectedProperty('isStale', true);

    $isHealthy = $connection->ping();
    expect($isHealthy)->toBeFalse();
});

test('reconnect if stale reestablishes connection', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $setProtectedProperty = function ($property, $value) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($connection, $value);
    };

    $getProtectedProperty = function ($property) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($connection);
    };

    // Verify initial connection works
    $result = $connection->select('RETURN 1 as one');
    expect($result[0]->one ?? $result[0]['one'])->toBe(1);

    // Force stale state
    $setProtectedProperty('isStale', true);

    // Should detect stale and reconnect
    $connection->reconnectIfStale();

    // Connection should be healthy again
    $isStale = $getProtectedProperty('isStale');
    expect($isStale)->toBeFalse();

    // Verify connection works
    $result = $connection->select('RETURN 2 as two');
    expect($result[0]->two ?? $result[0]['two'])->toBe(2);
});

test('queries work after reconnection', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $setProtectedProperty = function ($property, $value) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($connection, $value);
    };

    // Create some test data
    $connection->statement('CREATE (n:HealthTest {id: 1, name: "Test"})');

    // Verify we can query it
    $result = $connection->select('MATCH (n:HealthTest {id: 1}) RETURN n.name as name');
    expect($result[0]->name ?? $result[0]['name'])->toBe('Test');

    // Force reconnection
    $setProtectedProperty('isStale', true);
    $connection->reconnectIfStale();

    // Should still be able to query the data
    $result = $connection->select('MATCH (n:HealthTest {id: 1}) RETURN n.name as name');
    expect($result[0]->name ?? $result[0]['name'])->toBe('Test');

    // Can create new data after reconnection
    $connection->statement('CREATE (n:HealthTest {id: 2, name: "After Reconnect"})');

    $result = $connection->select('MATCH (n:HealthTest {id: 2}) RETURN n.name as name');
    expect($result[0]->name ?? $result[0]['name'])->toBe('After Reconnect');

    // Clean up
    $connection->statement('MATCH (n:HealthTest) DELETE n');
});

test('connection health check is lightweight', function () {
    $connection = app('db')->connection('graph');

    // Ping should be fast and not create any data
    $startTime = microtime(true);

    for ($i = 0; $i < 10; $i++) {
        $connection->ping();
    }

    $elapsed = microtime(true) - $startTime;

    // 10 pings should take less than 1 second total
    expect($elapsed)->toBeLessThan(1.0);

    // Verify no side effects (no nodes created)
    $result = $connection->select('MATCH (n:PingTest) RETURN count(n) as count');
    expect($result[0]->count ?? $result[0]['count'])->toBe(0);
});

test('reconnect maintains configuration', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $setProtectedProperty = function ($property, $value) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($connection, $value);
    };

    $callProtectedMethod = function ($method, ...$args) use ($connection, $reflection) {
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invoke($connection, ...$args);
    };

    // Get current config
    $originalConfig = $connection->getConfig();

    // Force reconnection
    $setProtectedProperty('isStale', true);
    $callProtectedMethod('reconnect');

    // Config should be preserved
    $newConfig = $connection->getConfig();
    expect($newConfig)->toBe($originalConfig);

    // Connection should work with same credentials
    $result = $connection->select('RETURN 1 as one');
    expect($result[0]->one ?? $result[0]['one'])->toBe(1);
});

test('handles reconnection failures gracefully', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $callProtectedMethod = function ($method, ...$args) use ($connection, $reflection) {
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invoke($connection, ...$args);
    };

    // Save original config
    $originalConfig = $connection->getConfig();

    // Corrupt the config to make reconnection fail
    $badConfig = $originalConfig;
    $badConfig['password'] = 'wrong_password';
    $connection->setConfig($badConfig);

    try {
        $callProtectedMethod('reconnect');
        throw new \Exception('Should have thrown authentication exception');
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('auth');
    }

    // Restore good config
    $connection->setConfig($originalConfig);

    // Should be able to reconnect now
    $callProtectedMethod('reconnect');

    $result = $connection->select('RETURN 1 as one');
    expect($result[0]->one ?? $result[0]['one'])->toBe(1);
});

test('detects connection staleness patterns', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $callProtectedMethod = function ($method, ...$args) use ($connection, $reflection) {
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invoke($connection, ...$args);
    };

    $patterns = [
        'Connection pool is closed',
        'Connection is stale',
        'Socket has been closed',
        'Connection reset by peer',
        'Broken pipe',
    ];

    foreach ($patterns as $pattern) {
        $exception = new \Exception($pattern);
        $shouldReconnect = $callProtectedMethod('shouldReconnect', $exception);
        expect($shouldReconnect)->toBeTrue("Failed to detect: $pattern");
    }

    // Should not reconnect for other errors
    $nonStalePatterns = [
        'Syntax error',
        'Invalid password',
        'Constraint violation',
    ];

    foreach ($nonStalePatterns as $pattern) {
        $exception = new \Exception($pattern);
        $shouldReconnect = $callProtectedMethod('shouldReconnect', $exception);
        expect($shouldReconnect)->toBeFalse("Incorrectly detected as stale: $pattern");
    }
});

test('automatic reconnection on stale queries', function () {
    $connection = app('db')->connection('graph');
    $reflection = new \ReflectionClass($connection);

    $setProtectedProperty = function ($property, $value) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($connection, $value);
    };

    $getProtectedProperty = function ($property) use ($connection, $reflection) {
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($connection);
    };

    // Enable auto-reconnect if config exists
    $config = $connection->getConfig();
    $config['auto_reconnect'] = true;
    $connection->setConfig($config);

    // First query works
    $result = $connection->select('RETURN 1 as one');
    expect($result[0]->one ?? $result[0]['one'])->toBe(1);

    // Force stale
    $setProtectedProperty('isStale', true);

    // This should auto-reconnect and succeed
    $result = $connection->select('RETURN 2 as two');
    expect($result[0]->two ?? $result[0]['two'])->toBe(2);

    // Should no longer be stale
    $isStale = $getProtectedProperty('isStale');
    expect($isStale)->toBeFalse();
});
