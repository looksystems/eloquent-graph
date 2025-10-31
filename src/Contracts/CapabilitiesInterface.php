<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Contracts;

interface CapabilitiesInterface
{
    /**
     * Check if database supports JSON operations
     */
    public function supportsJsonOperations(): bool;

    /**
     * Check if database supports schema introspection
     */
    public function supportsSchemaIntrospection(): bool;

    /**
     * Check if database supports transactions
     */
    public function supportsTransactions(): bool;

    /**
     * Check if database supports batch execution
     */
    public function supportsBatchExecution(): bool;

    /**
     * Get database version
     */
    public function getVersion(): string;

    /**
     * Get database type (neo4j, memgraph, age, etc.)
     */
    public function getDatabaseType(): string;
}
