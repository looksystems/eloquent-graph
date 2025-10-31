<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Contracts;

interface GraphDriverInterface
{
    /**
     * Connect to the database
     */
    public function connect(array $config): void;

    /**
     * Disconnect from the database
     */
    public function disconnect(): void;

    /**
     * Execute a Cypher query
     */
    public function executeQuery(string $cypher, array $parameters = []): ResultSetInterface;

    /**
     * Execute multiple queries in a batch
     */
    public function executeBatch(array $queries): array;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): TransactionInterface;

    /**
     * Commit a transaction
     */
    public function commit(TransactionInterface $transaction): void;

    /**
     * Rollback a transaction
     */
    public function rollback(TransactionInterface $transaction): void;

    /**
     * Check connection health
     */
    public function ping(): bool;

    /**
     * Get database capabilities
     */
    public function getCapabilities(): CapabilitiesInterface;

    /**
     * Get schema introspector
     */
    public function getSchemaIntrospector(): SchemaIntrospectorInterface;
}
