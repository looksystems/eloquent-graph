<?php

namespace Look\EloquentCypher\Exceptions;

/**
 * Exception thrown when there are issues with Cypher queries
 */
class GraphQueryException extends \Look\EloquentCypher\Exceptions\GraphException
{
    /**
     * Create a syntax error exception
     *
     * @return static
     */
    public static function syntaxError(string $message, string $cypher, array $parameters = []): self
    {
        $exception = new self("Cypher syntax error: {$message}");

        $exception->setCypher($cypher)
            ->setParameters($parameters)
            ->setMigrationHint(
                "Common SQL to Cypher differences:\n".
                "• SELECT → MATCH/RETURN\n".
                "• JOIN → MATCH patterns\n".
                "• WHERE → WHERE (similar but different syntax)\n".
                "• INSERT → CREATE\n".
                "• UPDATE → SET\n".
                '• DELETE → DELETE/DETACH DELETE'
            );

        return $exception;
    }

    /**
     * Create a property not found exception
     *
     * @return static
     */
    public static function propertyNotFound(string $property, string $label): self
    {
        $exception = new self(
            "Property '{$property}' not found on node with label '{$label}'"
        );

        $exception->setMigrationHint(
            "Neo4j is schema-less, so properties don't need to be pre-defined. ".
            'Check if the property name is correct, or if the node has been properly created with all properties.'
        );

        return $exception;
    }

    /**
     * Create a label not found exception
     *
     * @return static
     */
    public static function labelNotFound(string $label): self
    {
        $exception = new self(
            "No nodes found with label '{$label}'"
        );

        $exception->setMigrationHint(
            'In Neo4j, labels are like table names in SQL. '.
            "Ensure you've created nodes with this label: CREATE (n:{$label} {'{'}...properties{'}'})"
        );

        return $exception;
    }

    /**
     * Create a relationship type not found exception
     *
     * @return static
     */
    public static function relationshipTypeNotFound(string $type): self
    {
        $exception = new self(
            "No relationships found with type '{$type}'"
        );

        $exception->setMigrationHint(
            'Relationship types in Neo4j are like foreign key constraints in SQL. '.
            "Create relationships: MATCH (a), (b) CREATE (a)-[:{$type}]->(b)"
        );

        return $exception;
    }

    /**
     * Create a query timeout exception
     *
     * @return static
     */
    public static function queryTimeout(int $timeout, string $cypher): self
    {
        $exception = new self(
            "Query execution exceeded the timeout of {$timeout} seconds"
        );

        $exception->setCypher($cypher)
            ->setMigrationHint(
                "Consider:\n".
                "• Adding indexes: CREATE INDEX ON :{label}(property)\n".
                "• Limiting results: Add LIMIT clause\n".
                "• Optimizing patterns: Use more specific match patterns\n".
                '• Using query hints: Use index hints with USING INDEX'
            );

        return $exception;
    }

    /**
     * Create an invalid parameter exception
     *
     * @param  mixed  $value
     * @return static
     */
    public static function invalidParameter(string $parameter, $value, string $expectedType): self
    {
        $actualType = gettype($value);
        $exception = new self(
            "Invalid parameter '{$parameter}': Expected {$expectedType}, got {$actualType}"
        );

        $exception->setMigrationHint(
            'Neo4j supports these types: string, integer, float, boolean, array, null. '.
            'Complex objects need to be serialized to JSON strings or broken down into properties.'
        );

        return $exception;
    }

    /**
     * Create an invalid cast type exception
     *
     * @return static
     */
    public static function invalidCastType(string $type, string $attribute): self
    {
        $exception = new self(
            "Invalid cast type '{$type}' for attribute '{$attribute}'"
        );

        $exception->setMigrationHint(
            'Supported cast types: integer, float, decimal, string, boolean, object, array, json, '.
            'collection, date, datetime, timestamp, hashed, encrypted, encrypted:array, encrypted:collection, '.
            'encrypted:json, encrypted:object'
        );

        return $exception;
    }

    /**
     * Create an invalid relation type exception
     *
     * @return static
     */
    public static function invalidRelationType(string $relationType, string $relationName): self
    {
        $exception = new self(
            "Invalid relation type '{$relationType}' for relation '{$relationName}'. Must be a Neo4j relation instance."
        );

        $exception->setMigrationHint(
            'Neo4j supports: hasOne, hasMany, belongsTo, belongsToMany, hasManyThrough. '.
            'Polymorphic relationships require special handling in graph databases.'
        );

        return $exception;
    }

    /**
     * Create a missing order by exception for chunking
     *
     * @return static
     */
    public static function missingOrderByForChunk(): self
    {
        $exception = new self(
            'You must specify an orderBy clause when using chunk operations'
        );

        $exception->setMigrationHint(
            'Chunking requires consistent ordering to paginate correctly. '.
            "Add an orderBy clause: ->orderBy('id')->chunk(100, function(\$records) { ... })"
        );

        return $exception;
    }

    /**
     * Create an invalid aggregate function exception
     *
     * @return static
     */
    public static function invalidAggregateFunction(string $function): self
    {
        $exception = new self(
            "Invalid aggregate function '{$function}'"
        );

        $exception->setMigrationHint(
            'Neo4j supports: count(), avg(), sum(), min(), max(), collect(). '.
            'Use these in Cypher: RETURN count(n), avg(n.property), sum(n.property), etc.'
        );

        return $exception;
    }

    /**
     * Create an unsupported operation exception
     *
     * @return static
     */
    public static function unsupportedOperation(string $operation, string $reason): self
    {
        $exception = new self(
            "Operation '{$operation}' is not supported: {$reason}"
        );

        $exception->setMigrationHint(
            'Graph databases work differently from relational databases. '.
            'Consider alternative approaches using graph patterns and traversals.'
        );

        return $exception;
    }

    public function getQuery(): string
    {
        return $this->getCypher() ?? '';
    }

    public function getHint(): string
    {
        return $this->getMigrationHint() ?? '';
    }

    public function getConnectionName(): string
    {
        return 'neo4j';
    }
}
