<?php

namespace Look\EloquentCypher\Traits;

trait ConfiguresRelationshipStorage
{
    /**
     * Get the default storage strategy for relationships.
     *
     * Priority order:
     * 1. Relationship-specific setting ($this->storageStrategy)
     * 2. Model-level default ($this->defaultRelationshipStorage)
     * 3. Global config (database.connections.neo4j.default_relationship_storage)
     * 4. Default to 'foreign_key' for backward compatibility
     */
    protected function getDefaultStorageStrategy(): string
    {
        // Priority 1: Relationship-specific setting
        if (isset($this->storageStrategy)) {
            return $this->storageStrategy;
        }

        // Priority 2: Model-level default
        if (isset($this->defaultRelationshipStorage)) {
            return $this->defaultRelationshipStorage;
        }

        // Priority 3: Global config (defaults to 'foreign_key' for backward compatibility)
        return config('database.connections.neo4j.default_relationship_storage') ?? 'foreign_key';
    }

    /**
     * Configure this relationship to use foreign keys only.
     */
    public function useForeignKeys(): self
    {
        $this->storageStrategy = 'foreign_key';

        return $this;
    }

    /**
     * Configure this relationship to use native edges only.
     */
    public function useNativeEdges(): self
    {
        $this->storageStrategy = 'edge';

        return $this;
    }

    /**
     * Configure this relationship to use both foreign keys and edges.
     */
    public function useHybridStorage(): self
    {
        $this->storageStrategy = 'hybrid';

        return $this;
    }
}
