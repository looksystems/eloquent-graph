<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Drivers;

use InvalidArgumentException;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;

class DriverManager
{
    protected static array $drivers = [
        'neo4j' => Neo4jDriver::class,
        // Future: 'memgraph' => MemgraphDriver::class,
        // Future: 'age' => AgeDriver::class,
    ];

    /**
     * Create a driver instance based on configuration
     */
    public static function create(array $config): GraphDriverInterface
    {
        $databaseType = $config['database_type'] ?? 'neo4j';

        if (! isset(static::$drivers[$databaseType])) {
            throw new InvalidArgumentException(
                "Unsupported database type: {$databaseType}. ".
                'Supported types: '.implode(', ', array_keys(static::$drivers))
            );
        }

        $driverClass = static::$drivers[$databaseType];
        $driver = new $driverClass;
        $driver->connect($config);

        return $driver;
    }

    /**
     * Register a custom driver
     */
    public static function register(string $name, string $driverClass): void
    {
        if (! is_subclass_of($driverClass, GraphDriverInterface::class)) {
            throw new InvalidArgumentException(
                'Driver must implement GraphDriverInterface'
            );
        }

        static::$drivers[$name] = $driverClass;
    }

    /**
     * Get list of supported database types
     */
    public static function supportedTypes(): array
    {
        return array_keys(static::$drivers);
    }
}
