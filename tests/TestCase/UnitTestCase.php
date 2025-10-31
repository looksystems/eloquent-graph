<?php

namespace Tests\TestCase;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class UnitTestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            // We'll add our service provider here later
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Configure Neo4j database connection (for model configuration)
        $app['config']->set('database.connections.neo4j', [
            'driver' => 'neo4j',
            'host' => 'localhost',
            'port' => 7687,
            'username' => 'neo4j',
            'password' => 'password',
            'database' => 'neo4j',
        ]);

        // Set default database connection
        $app['config']->set('database.default', 'neo4j');
    }
}
