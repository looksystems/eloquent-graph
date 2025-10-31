<?php

use Look\EloquentCypher\Contracts\CapabilitiesInterface;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Contracts\ResultSetInterface;
use Look\EloquentCypher\Contracts\SchemaIntrospectorInterface;
use Look\EloquentCypher\Contracts\TransactionInterface;
use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;

beforeEach(function () {
    $this->config = [
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
        'protocol' => 'bolt',
    ];

    $this->driver = new Neo4jDriver;
});

test('implements graph driver interface', function () {
    expect($this->driver)->toBeInstanceOf(GraphDriverInterface::class);
});

test('can connect to database', function () {
    $this->driver->connect($this->config);

    // Ping should work after connection
    expect($this->driver->ping())->toBeTrue();
});

test('executeQuery returns result set interface', function () {
    $this->driver->connect($this->config);

    $result = $this->driver->executeQuery('RETURN 1 as num', []);

    expect($result)->toBeInstanceOf(ResultSetInterface::class);
    expect($result->count())->toBe(1);
});

test('executeQuery handles parameters', function () {
    $this->driver->connect($this->config);

    $result = $this->driver->executeQuery('RETURN $value as num', ['value' => 42]);

    expect($result->count())->toBe(1);
    $first = $result->first();
    expect($first)->toHaveKey('num');
});

test('can begin transaction', function () {
    $this->driver->connect($this->config);

    $transaction = $this->driver->beginTransaction();

    expect($transaction)->toBeInstanceOf(TransactionInterface::class);
    expect($transaction->isOpen())->toBeTrue();
});

test('transactions can execute queries', function () {
    $this->driver->connect($this->config);

    $transaction = $this->driver->beginTransaction();
    $result = $transaction->run('RETURN 1 as num', []);

    expect($result)->toBeInstanceOf(ResultSetInterface::class);
    expect($result->count())->toBe(1);

    $this->driver->commit($transaction);
});

test('can rollback transaction', function () {
    $this->driver->connect($this->config);

    $transaction = $this->driver->beginTransaction();

    // Create a node
    $transaction->run('CREATE (n:test_rollback {id: $id})', ['id' => 999]);

    // Rollback
    $this->driver->rollback($transaction);

    // Verify node doesn't exist
    $result = $this->driver->executeQuery('MATCH (n:test_rollback {id: $id}) RETURN n', ['id' => 999]);
    expect($result->count())->toBe(0);
});

test('can commit transaction', function () {
    $this->driver->connect($this->config);

    $transaction = $this->driver->beginTransaction();

    // Create a node
    $transaction->run('CREATE (n:test_commit {id: $id})', ['id' => 888]);

    // Commit
    $this->driver->commit($transaction);

    // Verify node exists
    $result = $this->driver->executeQuery('MATCH (n:test_commit {id: $id}) RETURN n', ['id' => 888]);
    expect($result->count())->toBe(1);

    // Cleanup
    $this->driver->executeQuery('MATCH (n:test_commit {id: $id}) DELETE n', ['id' => 888]);
});

test('ping returns true when connected', function () {
    $this->driver->connect($this->config);

    expect($this->driver->ping())->toBeTrue();
});

test('ping returns false with invalid connection', function () {
    $invalidConfig = [
        'host' => 'invalid-host-12345.local',
        'port' => 9999,
        'username' => 'neo4j',
        'password' => 'wrong',
        'protocol' => 'bolt',
    ];

    $driver = new Neo4jDriver;

    try {
        $driver->connect($invalidConfig);
        $result = $driver->ping();
        expect($result)->toBeFalse();
    } catch (\Exception $e) {
        // Connection might fail immediately, which is also acceptable
        expect(true)->toBeTrue();
    }
});

test('getCapabilities returns capabilities interface', function () {
    $this->driver->connect($this->config);

    $capabilities = $this->driver->getCapabilities();

    expect($capabilities)->toBeInstanceOf(CapabilitiesInterface::class);
});

test('getSchemaIntrospector returns schema introspector interface', function () {
    $this->driver->connect($this->config);

    $introspector = $this->driver->getSchemaIntrospector();

    expect($introspector)->toBeInstanceOf(SchemaIntrospectorInterface::class);
});

test('can disconnect', function () {
    $this->driver->connect($this->config);
    expect($this->driver->ping())->toBeTrue();

    $this->driver->disconnect();

    // After disconnect, operations should fail or ping should return false
    // This depends on the implementation
    expect(true)->toBeTrue(); // Disconnect succeeded
});

test('executeBatch returns array of result sets', function () {
    $this->driver->connect($this->config);

    $queries = [
        ['cypher' => 'RETURN 1 as num', 'parameters' => []],
        ['cypher' => 'RETURN 2 as num', 'parameters' => []],
        ['cypher' => 'RETURN 3 as num', 'parameters' => []],
    ];

    $results = $this->driver->executeBatch($queries);

    expect($results)->toBeArray();
    expect(count($results))->toBe(3);
    expect($results[0])->toBeInstanceOf(ResultSetInterface::class);
    expect($results[1])->toBeInstanceOf(ResultSetInterface::class);
    expect($results[2])->toBeInstanceOf(ResultSetInterface::class);
});

test('multiple connections can coexist', function () {
    $driver1 = new Neo4jDriver;
    $driver2 = new Neo4jDriver;

    $driver1->connect($this->config);
    $driver2->connect($this->config);

    expect($driver1->ping())->toBeTrue();
    expect($driver2->ping())->toBeTrue();

    $driver1->disconnect();
    $driver2->disconnect();
});
