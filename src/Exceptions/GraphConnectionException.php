<?php

namespace Look\EloquentCypher\Exceptions;

/**
 * Exception thrown when there are connection issues with Neo4j
 */
class GraphConnectionException extends \Look\EloquentCypher\Exceptions\GraphException
{
    /**
     * Create a new connection exception for authentication failures
     *
     * @return static
     */
    public static function authenticationFailed(string $host, int $port): self
    {
        $exception = new self(
            "Failed to authenticate with Neo4j at {$host}:{$port}. Please check your credentials."
        );

        $exception->setMigrationHint(
            'Neo4j requires authentication. Default credentials are usually neo4j/neo4j. '.
            'You can set credentials in your .env file: NEO4J_USERNAME and NEO4J_PASSWORD'
        );

        return $exception;
    }

    /**
     * Create a new connection exception for unreachable server
     *
     * @return static
     */
    public static function serverUnreachable(string $host, int $port): self
    {
        $exception = new self(
            "Cannot connect to Neo4j server at {$host}:{$port}. Please ensure the server is running."
        );

        $exception->setMigrationHint(
            "Make sure Neo4j is running. You can start it with 'neo4j start' or check Docker: ".
            "'docker run -p 7687:7687 -p 7474:7474 neo4j'"
        );

        return $exception;
    }

    /**
     * Create a new connection exception for database not found
     *
     * @return static
     */
    public static function databaseNotFound(string $database): self
    {
        $exception = new self(
            "Database '{$database}' does not exist on the Neo4j server."
        );

        $exception->setMigrationHint(
            'Neo4j 4.0+ supports multiple databases. Create the database using: '.
            "CREATE DATABASE {$database} or use the default 'neo4j' database."
        );

        return $exception;
    }

    /**
     * Create a new connection exception for connection pool exhausted
     *
     * @return static
     */
    public static function connectionPoolExhausted(int $maxConnections): self
    {
        $exception = new self(
            "Connection pool exhausted. Maximum of {$maxConnections} connections reached."
        );

        $exception->setMigrationHint(
            'Consider increasing the connection pool size in your configuration, '.
            'or ensure connections are being properly closed after use.'
        );

        return $exception;
    }

    /**
     * Create a new connection exception for missing credentials
     *
     * @return static
     */
    public static function missingCredentials(string $credentialType, string $connectionType = 'default'): self
    {
        $connectionLabel = $connectionType === 'default' ? '' : " for {$connectionType} connection";

        $exception = new self(
            "Missing required credential '{$credentialType}'{$connectionLabel}. Database credentials must be explicitly configured."
        );

        $exception->setMigrationHint(
            "Neo4j requires authentication. Please set the '{$credentialType}' in your database configuration. ".
            "You can set credentials in your .env file:\n".
            "- For default connection: NEO4J_USERNAME and NEO4J_PASSWORD\n".
            "- For read/write split: Configure 'read' and 'write' arrays with username and password"
        );

        return $exception;
    }
}
