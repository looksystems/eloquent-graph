<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Contracts;

interface TransactionInterface
{
    /**
     * Execute a query within the transaction
     */
    public function run(string $cypher, array $parameters = []): ResultSetInterface;

    /**
     * Commit the transaction
     */
    public function commit(): void;

    /**
     * Rollback the transaction
     */
    public function rollback(): void;

    /**
     * Check if transaction is open
     */
    public function isOpen(): bool;
}
