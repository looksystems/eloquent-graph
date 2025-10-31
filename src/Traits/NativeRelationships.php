<?php

namespace Look\EloquentCypher\Traits;

use Illuminate\Support\Str;

trait NativeRelationships
{
    /**
     * Determine if native Neo4j relationships should be used.
     */
    public function useNativeRelationships(): bool
    {
        return property_exists($this, 'useNativeRelationships')
            ? $this->useNativeRelationships
            : false;
    }

    /**
     * Get the relationship edge type for a given relation.
     */
    public function getRelationshipEdgeType(string $relation): string
    {
        // Check if custom edge type is defined
        if (property_exists($this, 'relationshipEdgeTypes') &&
            isset($this->relationshipEdgeTypes[$relation])) {
            return $this->relationshipEdgeTypes[$relation];
        }

        // Generate default edge type based on relation name
        return strtoupper(Str::snake($relation));
    }

    /**
     * Get the edge type for a HasOne/HasMany relationship.
     */
    public function getHasRelationshipEdgeType(string $related): string
    {
        $relatedClass = class_basename($related);

        return 'HAS_'.strtoupper(Str::snake(Str::plural($relatedClass)));
    }

    /**
     * Get the edge type for a BelongsTo relationship.
     */
    public function getBelongsToRelationshipEdgeType(string $parent): string
    {
        $parentClass = class_basename($parent);

        return 'BELONGS_TO_'.strtoupper(Str::snake($parentClass));
    }

    /**
     * Get the edge type for a MorphOne/MorphMany relationship.
     */
    public function getMorphRelationshipEdgeType(string $name): string
    {
        return 'MORPH_'.strtoupper(Str::snake($name));
    }

    /**
     * Create a native edge alongside foreign key for compatibility.
     */
    public function createNativeEdge(string $fromId, string $toId, string $edgeType, array $properties = []): void
    {
        if (! $this->useNativeRelationships()) {
            return;
        }

        $connection = $this->getConnection();
        $fromTable = $this->getTable();
        $toTable = (new static)->getTable();

        $cypherQuery = "MATCH (from:$fromTable {id: \$fromId}), (to:$toTable {id: \$toId}) "
                     ."CREATE (from)-[r:$edgeType]->(to) ";

        if (! empty($properties)) {
            $setClause = [];
            foreach ($properties as $key => $value) {
                $setClause[] = "r.$key = \$$key";
            }
            $cypherQuery .= 'SET '.implode(', ', $setClause).' ';
        }

        $cypherQuery .= 'RETURN r';

        $params = array_merge(['fromId' => $fromId, 'toId' => $toId], $properties);
        $connection->statement($cypherQuery, $params);
    }

    /**
     * Delete native edges for a relationship.
     */
    public function deleteNativeEdge(string $fromId, string $toId, string $edgeType): void
    {
        if (! $this->useNativeRelationships()) {
            return;
        }

        $connection = $this->getConnection();
        $fromTable = $this->getTable();
        $toTable = (new static)->getTable();

        $cypherQuery = "MATCH (from:$fromTable {id: \$fromId})-[r:$edgeType]->(to:$toTable {id: \$toId}) "
                     .'DELETE r';

        $params = ['fromId' => $fromId, 'toId' => $toId];
        $connection->statement($cypherQuery, $params);
    }

    /**
     * Check if should read from native edges.
     */
    public function shouldUseNativeEdges(): bool
    {
        return $this->useNativeRelationships() &&
               property_exists($this, 'preferNativeEdges') ?
               $this->preferNativeEdges : true;
    }
}
