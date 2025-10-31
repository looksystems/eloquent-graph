<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Contracts;

interface SchemaIntrospectorInterface
{
    /**
     * Get all node labels
     */
    public function getLabels(): array;

    /**
     * Get all relationship types
     */
    public function getRelationshipTypes(): array;

    /**
     * Get all property keys
     */
    public function getPropertyKeys(): array;

    /**
     * Get all constraints
     */
    public function getConstraints(?string $type = null): array;

    /**
     * Get all indexes
     */
    public function getIndexes(?string $type = null): array;

    /**
     * Get complete schema introspection
     */
    public function introspect(): array;
}
