<?php

use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Drivers\DriverManager;
use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;

test('can create neo4j driver with valid config', function () {
    $config = [
        'database_type' => 'neo4j',
        'host' => 'localhost',
        'port' => 7687,
        'username' => 'neo4j',
        'password' => 'password',
    ];

    $driver = DriverManager::create($config);

    expect($driver)->toBeInstanceOf(GraphDriverInterface::class);
    expect($driver)->toBeInstanceOf(Neo4jDriver::class);
});

test('throws exception for unknown driver type', function () {
    $config = [
        'database_type' => 'unknown-database',
        'host' => 'localhost',
        'port' => 7687,
        'username' => 'test',
        'password' => 'test',
    ];

    DriverManager::create($config);
})->throws(\InvalidArgumentException::class, 'Unsupported database type');

test('defaults to neo4j when database_type is missing', function () {
    $config = [
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ];

    $driver = DriverManager::create($config);

    // Should default to Neo4j driver
    expect($driver)->toBeInstanceOf(Neo4jDriver::class);
});

test('can register custom driver', function () {
    // Create a mock driver class
    $mockDriverClass = new class implements GraphDriverInterface
    {
        public function connect(array $config): void {}

        public function disconnect(): void {}

        public function executeQuery(string $cypher, array $parameters = []): \Look\EloquentCypher\Contracts\ResultSetInterface
        {
            throw new \Exception('Not implemented');
        }

        public function executeBatch(array $queries): array
        {
            return [];
        }

        public function beginTransaction(): \Look\EloquentCypher\Contracts\TransactionInterface
        {
            throw new \Exception('Not implemented');
        }

        public function commit(\Look\EloquentCypher\Contracts\TransactionInterface $transaction): void {}

        public function rollback(\Look\EloquentCypher\Contracts\TransactionInterface $transaction): void {}

        public function ping(): bool
        {
            return true;
        }

        public function getCapabilities(): \Look\EloquentCypher\Contracts\CapabilitiesInterface
        {
            throw new \Exception('Not implemented');
        }

        public function getSchemaIntrospector(): \Look\EloquentCypher\Contracts\SchemaIntrospectorInterface
        {
            throw new \Exception('Not implemented');
        }
    };

    DriverManager::register('custom-db', get_class($mockDriverClass));

    $config = [
        'database_type' => 'custom-db',
        'host' => 'localhost',
    ];

    $driver = DriverManager::create($config);

    expect($driver)->toBeInstanceOf(GraphDriverInterface::class);
});

test('registered drivers persist across calls', function () {
    // Register a custom driver
    $customClass = new class implements GraphDriverInterface
    {
        public function connect(array $config): void {}

        public function disconnect(): void {}

        public function executeQuery(string $cypher, array $parameters = []): \Look\EloquentCypher\Contracts\ResultSetInterface
        {
            throw new \Exception('Not implemented');
        }

        public function executeBatch(array $queries): array
        {
            return [];
        }

        public function beginTransaction(): \Look\EloquentCypher\Contracts\TransactionInterface
        {
            throw new \Exception('Not implemented');
        }

        public function commit(\Look\EloquentCypher\Contracts\TransactionInterface $transaction): void {}

        public function rollback(\Look\EloquentCypher\Contracts\TransactionInterface $transaction): void {}

        public function ping(): bool
        {
            return true;
        }

        public function getCapabilities(): \Look\EloquentCypher\Contracts\CapabilitiesInterface
        {
            throw new \Exception('Not implemented');
        }

        public function getSchemaIntrospector(): \Look\EloquentCypher\Contracts\SchemaIntrospectorInterface
        {
            throw new \Exception('Not implemented');
        }
    };

    $className = get_class($customClass);
    DriverManager::register('persistent-db', $className);

    // Create multiple instances
    $config = ['database_type' => 'persistent-db'];
    $driver1 = DriverManager::create($config);
    $driver2 = DriverManager::create($config);

    expect($driver1)->toBeInstanceOf(GraphDriverInterface::class);
    expect($driver2)->toBeInstanceOf(GraphDriverInterface::class);
    expect(get_class($driver1))->toBe(get_class($driver2));
});

test('neo4j is the default registered driver', function () {
    $config = [
        'database_type' => 'neo4j',
        'host' => 'localhost',
        'port' => 7687,
        'username' => 'neo4j',
        'password' => 'password',
    ];

    $driver = DriverManager::create($config);

    expect($driver)->toBeInstanceOf(Neo4jDriver::class);
});
