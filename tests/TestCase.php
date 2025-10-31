<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Look\EloquentCypher\GraphServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // Set up application key for encryption
        $app['config']->set('app.key', env('APP_KEY', 'base64:RLsHpUKV17LqGDlap1GO+/0GxiB3UpkUMBdK9QaJABs='));
        $app['config']->set('app.cipher', 'AES-256-CBC');

        // Set up graph database configuration
        $app['config']->set('database.default', 'graph');
        $app['config']->set('database.connections.graph', [
            'driver' => 'graph',
            'database_type' => 'neo4j',
            'database' => '', // Neo4j doesn't use named databases in the same way
            'host' => env('NEO4J_HOST', 'localhost'),
            'port' => env('NEO4J_PORT', 7688),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password'),

            // Relationship storage configuration
            'default_relationship_storage' => env('NEO4J_RELATIONSHIP_STORAGE', 'foreign_key'), // Default to foreign_key for backward compatibility
            'auto_create_edges' => true,
            'edge_naming_convention' => 'snake_case_upper', // e.g., HAS_POSTS
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearDatabase();
    }

    protected function clearDatabase()
    {
        try {
            $connection = $this->app['db']->connection('graph');
            // Clear all data from the database
            $connection->statement('MATCH (n) DETACH DELETE n');
        } catch (\Exception $e) {
            // Database may not be available
        }
    }
}
