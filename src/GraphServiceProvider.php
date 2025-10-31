<?php

namespace Look\EloquentCypher;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class GraphServiceProvider extends ServiceProvider
{
    public function register()
    {
        $connectionResolver = function ($connection, $database, $prefix, $config) {
            // The $config array passed here doesn't include read/write split config
            // So we need to check if they exist in the original config
            // $connection parameter here is the PDO object, not the connection name
            // We can get connection name from the config
            $connectionName = $config['name'] ?? null;
            if ($connectionName) {
                $fullConfig = config("database.connections.{$connectionName}");
                if (isset($fullConfig['read']) && isset($fullConfig['write'])) {
                    $config['read'] = $fullConfig['read'];
                    $config['write'] = $fullConfig['write'];
                }
            }

            return new \Look\EloquentCypher\GraphConnection($config);
        };

        // Register 'graph' driver (v2.0)
        Connection::resolverFor('graph', $connectionResolver);

        // Register 'neo4j' driver for backward compatibility (v1.x)
        Connection::resolverFor('neo4j', $connectionResolver);

        // Register the schema builder facade binding
        $this->app->singleton('graph.schema', function ($app) {
            $connection = $app['db']->connection('graph');

            return new \Look\EloquentCypher\Schema\GraphSchemaBuilder($connection);
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\CheckCompatibilityCommand::class,
                Commands\MigrateToEdgesCommand::class,
                Commands\Schema\SchemaCommand::class,
                Commands\Schema\SchemaLabelsCommand::class,
                Commands\Schema\SchemaRelationshipsCommand::class,
                Commands\Schema\SchemaPropertiesCommand::class,
                Commands\Schema\SchemaConstraintsCommand::class,
                Commands\Schema\SchemaIndexesCommand::class,
                Commands\Schema\SchemaExportCommand::class,
            ]);
        }
    }
}
