<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Look\EloquentCypher\Traits\SupportsNativeEdges;

class GraphBelongsTo extends BelongsTo
{
    use SupportsNativeEdges;

    public function __construct(Builder $query, Model $child, $foreignKey, $ownerKey, $relationName)
    {
        parent::__construct($query, $child, $foreignKey, $ownerKey, $relationName);
    }

    public function addConstraints()
    {
        if (static::$constraints) {
            $table = $this->related->getTable();

            $this->query->where($this->ownerKey, '=', $this->child->{$this->foreignKey});
        }
    }

    public function addEagerConstraints(array $models)
    {
        $key = $this->ownerKey;

        $whereIn = $this->whereInMethod($this->related, $key);

        $this->query->{$whereIn}($key, $this->getEagerModelKeys($models));
    }

    protected function getEagerModelKeys(array $models)
    {
        $keys = [];

        foreach ($models as $model) {
            if (! is_null($value = $model->{$this->foreignKey})) {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    public function associate($model)
    {
        $ownerKey = $model instanceof Model ? $model->getAttribute($this->ownerKey) : $model;

        // Store foreign key if needed
        if ($this->shouldStoreForeignKey()) {
            $this->child->setAttribute($this->foreignKey, $ownerKey);
        }

        // Create edge immediately if model IDs are available
        if ($this->shouldCreateEdge() && $model instanceof Model && $this->child->exists && $model->exists) {
            $this->getEdgeManager()->createEdge(
                $this->child,
                $model,
                $this->getEdgeType()
            );
        }

        if ($model instanceof Model) {
            $this->child->setRelation($this->relationName, $model);
        } else {
            $this->child->unsetRelation($this->relationName);
        }

        return $this->child;
    }

    public function dissociate()
    {
        // Get the current parent before dissociating
        $currentParent = $this->getResults();

        // Remove foreign key if it's stored
        if ($this->shouldStoreForeignKey()) {
            $this->child->setAttribute($this->foreignKey, null);
        }

        // Remove edge if configured
        if ($this->shouldCreateEdge() && $currentParent) {
            $this->getEdgeManager()->deleteEdge(
                $this->child,
                $currentParent,
                $this->getEdgeType()
            );
        }

        return $this->child->setRelation($this->relationName, null);
    }

    public function getResults()
    {
        if (is_null($this->child->{$this->foreignKey})) {
            return null;
        }

        return $this->query->first();
    }

    /**
     * Get the parent model for edge operations.
     */
    protected function getParentForEdge()
    {
        return $this->child;
    }

    /**
     * Override to generate edge type for BelongsTo relationships.
     */
    protected function generateDefaultEdgeType(): string
    {
        $convention = config('database.connections.neo4j.edge_naming_convention', 'snake_case_upper');

        // For BelongsTo, use relation name
        $relationName = $this->relationName;

        // Apply naming convention
        $baseName = match ($convention) {
            'snake_case_upper' => 'BELONGS_TO_'.strtoupper($relationName),
            'pascal_case' => 'BelongsTo'.str_replace(' ', '', ucwords(str_replace('_', ' ', $relationName))),
            'camel_case' => 'belongsTo'.str_replace(' ', '', ucwords(str_replace('_', ' ', $relationName))),
            default => 'BELONGS_TO_'.strtoupper($relationName)
        };

        // Handle native relationships edge type naming
        if ($this->child) {
            $reflection = new \ReflectionClass($this->child);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);
                if ($property->getValue($this->child)) {
                    $baseName = str_replace('BELONGS_TO_', 'BELONGS_TO_NATIVE_', $baseName);
                }
            }
        }

        return $baseName;
    }

    /**
     * Override to get the relation name.
     */
    public function getRelationName(): ?string
    {
        return $this->relationName;
    }
}
