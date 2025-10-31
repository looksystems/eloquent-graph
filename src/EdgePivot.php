<?php

namespace Look\EloquentCypher;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Virtual pivot class that represents edge properties in Neo4j relationships.
 * This allows BelongsToMany relationships to work with native graph edges
 * while maintaining Laravel's pivot API compatibility.
 */
class EdgePivot extends Pivot
{
    /**
     * The edge ID in Neo4j (if available).
     */
    protected ?string $edgeId = null;

    /**
     * The edge type/label.
     */
    protected ?string $edgeType = null;

    /**
     * The parent node of the edge.
     */
    protected $fromNode;

    /**
     * The related node of the edge.
     */
    protected $toNode;

    /**
     * Create a virtual Pivot instance from Neo4j edge data.
     *
     * @param  array|object  $edge  The edge data from Neo4j
     * @param  \Illuminate\Database\Eloquent\Model  $parent  The parent model
     * @param  \Illuminate\Database\Eloquent\Model  $related  The related model
     * @param  string  $table  The pivot table name (for compatibility)
     * @param  bool  $exists  Whether the pivot exists
     */
    public static function fromEdge($edge, $parent, $related, string $table = 'pivot', bool $exists = true): static
    {
        // Pivot constructor expects ($parent, $attributes, $table, $exists)
        // But Illuminate\Database\Eloquent\Relations\Pivot extends Model which expects array as first param
        // So we need to create it differently
        $pivot = new static;

        // Set the pivot table name
        $pivot->setTable($table);

        // Handle both array and object formats from Neo4j
        $edgeData = is_array($edge) ? $edge : (array) $edge;

        // Set edge metadata if available
        if (isset($edgeData['id'])) {
            $pivot->edgeId = $edgeData['id'];
        }

        // Store references to parent and related models
        $pivot->fromNode = $parent;
        $pivot->toNode = $related;
        $pivot->pivotParent = $parent;

        // Fill pivot with edge properties
        if (isset($edgeData['properties'])) {
            $properties = is_array($edgeData['properties']) ? $edgeData['properties'] : (array) $edgeData['properties'];
        } else {
            // The edge data itself might be the properties
            $properties = $edgeData;
            // Remove metadata fields
            unset($properties['id'], $properties['type']);
        }

        // Fill the pivot with properties
        $pivot->forceFill($properties);

        // Handle timestamps if present
        if (isset($properties['created_at'])) {
            $pivot->created_at = is_string($properties['created_at'])
                ? Carbon::parse($properties['created_at'])
                : $properties['created_at'];
        }

        if (isset($properties['updated_at'])) {
            $pivot->updated_at = is_string($properties['updated_at'])
                ? Carbon::parse($properties['updated_at'])
                : $properties['updated_at'];
        }

        // Mark as existing
        $pivot->exists = $exists;
        $pivot->syncOriginal();

        return $pivot;
    }

    /**
     * Save the pivot model to the database.
     * This updates the edge properties directly in Neo4j.
     */
    public function save(array $options = []): bool
    {
        if (! $this->fromNode || ! $this->toNode) {
            return false;
        }

        $connection = $this->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::save($options);
        }

        // Get the dirty attributes (changed properties)
        $properties = $this->getDirty();

        // Add timestamps if needed
        if ($this->usesTimestamps()) {
            $time = $this->freshTimestamp();
            $properties['updated_at'] = $time->format('Y-m-d H:i:s');

            if (! $this->exists) {
                $properties['created_at'] = $properties['updated_at'];
            }
        }

        if (empty($properties)) {
            return true; // Nothing to update
        }

        // Build the Cypher query to update edge properties
        $fromTable = $this->fromNode->getTable();
        $toTable = $this->toNode->getTable();
        $fromId = $this->fromNode->getKey();
        $toId = $this->toNode->getKey();

        // We need to determine the edge type - this would typically come from the relationship
        // For now, we'll use a convention based on table names
        $edgeType = $this->edgeType ?? $this->generateEdgeType($fromTable, $toTable);

        $cypher = "MATCH (a:{$fromTable} {id: \$fromId})-[r:{$edgeType}]->(b:{$toTable} {id: \$toId}) ".
                  'SET r += $properties '.
                  'RETURN r';

        $result = $connection->statement($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
            'properties' => $properties,
        ]);

        if ($result) {
            $this->syncOriginal();
            $this->exists = true;
        }

        return (bool) $result;
    }

    /**
     * Delete the pivot model from the database.
     * For edges, we might want to set a deleted flag instead of removing.
     *
     * @return int|bool
     */
    public function delete()
    {
        // For soft deletes on edges, set deleted_at property
        if ($this->usesTimestamps()) {
            return $this->update(['deleted_at' => $this->freshTimestamp()]);
        }

        // Otherwise, we would delete the edge entirely
        // This requires access to the edge manager or direct Cypher query
        // For now, return false as we shouldn't delete edges through pivot
        return false;
    }

    /**
     * Update the pivot model in the database.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        $this->fill($attributes);

        return $this->save($options);
    }

    /**
     * Get the connection for the pivot.
     *
     * @return \Illuminate\Database\Connection
     */
    public function getConnection()
    {
        // Use the parent model's connection
        return $this->fromNode ? $this->fromNode->getConnection() : parent::getConnection();
    }

    /**
     * Generate a default edge type based on table names.
     */
    protected function generateEdgeType(string $fromTable, string $toTable): string
    {
        return strtoupper($fromTable).'_'.strtoupper($toTable);
    }

    /**
     * Get the edge ID.
     */
    public function getEdgeId(): ?string
    {
        return $this->edgeId;
    }

    /**
     * Set the edge type.
     *
     * @return $this
     */
    public function setEdgeType(string $type): self
    {
        $this->edgeType = $type;

        return $this;
    }
}
