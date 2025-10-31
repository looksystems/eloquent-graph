<?php

namespace Look\EloquentCypher\Traits;

trait SupportsNativeEdges
{
    use ConfiguresRelationshipStorage;

    protected ?string $storageStrategy = null;

    protected ?string $edgeType = null;

    protected string $edgeDirection = 'out'; // out, in, both

    protected ?\Look\EloquentCypher\Services\EdgeManager $edgeManager = null;

    /**
     * Set the edge type for this relationship.
     */
    public function withEdgeType(string $type): self
    {
        $this->edgeType = $type;

        return $this;
    }

    /**
     * Get the edge type for this relationship.
     */
    protected function getEdgeType(): string
    {
        if ($this->edgeType) {
            return $this->edgeType;
        }

        // Check if model defines custom edge types
        $parent = $this->getParentForEdge();
        if ($parent) {
            $reflection = new \ReflectionClass($parent);
            if ($reflection->hasProperty('relationshipEdgeTypes')) {
                $property = $reflection->getProperty('relationshipEdgeTypes');
                $property->setAccessible(true);
                $edgeTypes = $property->getValue($parent);
                $relationName = $this->getRelationName();
                if (isset($edgeTypes[$relationName])) {
                    return $edgeTypes[$relationName];
                }
            }
        }

        // Generate default edge type based on naming convention
        return $this->generateDefaultEdgeType();
    }

    /**
     * Generate the default edge type name.
     */
    protected function generateDefaultEdgeType(): string
    {
        $convention = config('database.connections.neo4j.edge_naming_convention', 'snake_case_upper');

        // Get relationship method name
        $relationName = $this->getRelationName() ?? 'relationship';

        // Apply naming convention
        return match ($convention) {
            'snake_case_upper' => strtoupper(str_replace(' ', '_', preg_replace('/[A-Z]/', '_$0', $relationName))),
            'pascal_case' => str_replace(' ', '', ucwords(str_replace('_', ' ', $relationName))),
            'camel_case' => lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $relationName)))),
            default => 'HAS_'.strtoupper(str_replace(' ', '_', preg_replace('/[A-Z]/', '_$0', $relationName)))
        };
    }

    /**
     * Determine if we should create an edge for this relationship.
     */
    protected function shouldCreateEdge(): bool
    {
        // Check if model has useNativeRelationships property
        $parent = $this->getParentForEdge();
        if ($parent) {
            // Use reflection to check protected property
            $reflection = new \ReflectionClass($parent);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);
                if ($property->getValue($parent)) {
                    return true;
                }
            }
        }

        $strategy = $this->getDefaultStorageStrategy();

        return in_array($strategy, ['edge', 'hybrid']);
    }

    /**
     * Determine if we should store a foreign key for this relationship.
     */
    protected function shouldStoreForeignKey(): bool
    {
        // Check if model has useNativeRelationships property
        $parent = $this->getParentForEdge();
        if ($parent) {
            // Use reflection to check protected property
            $reflection = new \ReflectionClass($parent);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);
                if ($property->getValue($parent)) {
                    // If model explicitly uses native relationships, use hybrid mode by default
                    // This creates both foreign keys AND edges for Laravel compatibility
                    return true;
                }
            }
        }

        $strategy = $this->getDefaultStorageStrategy();

        return in_array($strategy, ['foreign_key', 'hybrid']);
    }

    /**
     * Get the edge manager instance.
     */
    protected function getEdgeManager(): \Look\EloquentCypher\Services\EdgeManager
    {
        if (! $this->edgeManager) {
            $connection = $this->getParentForEdge()->getConnection();
            $this->edgeManager = new \Look\EloquentCypher\Services\EdgeManager($connection);
        }

        return $this->edgeManager;
    }

    /**
     * Get the parent model instance for edge operations.
     */
    protected function getParentForEdge()
    {
        // This will be overridden in relationship classes
        return $this->parent ?? null;
    }

    /**
     * Get the relationship name.
     */
    public function getRelationName(): ?string
    {
        // This will be overridden in relationship classes
        return $this->relationName ?? null;
    }

    /**
     * Prefer edge traversal over foreign key lookups.
     */
    protected function preferEdgeTraversal(): bool
    {
        // In hybrid mode, prefer edges for graph traversal
        return config('database.connections.neo4j.prefer_edge_traversal', true);
    }
}
