<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Look\EloquentCypher\Traits\SupportsNativeEdges;

class GraphHasMany extends HasMany
{
    use SupportsNativeEdges;

    protected ?string $relationName = null;

    public function __construct(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        parent::__construct($query, $parent, $foreignKey, $localKey);

        // Try to determine relation name from the call stack
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($trace as $frame) {
            if (isset($frame['function']) && isset($frame['class'])) {
                // Look for the actual method call from the model (not hasMany)
                if (is_subclass_of($frame['class'], Model::class) &&
                    ! in_array($frame['function'], ['hasMany', 'hasOne', 'belongsTo', 'belongsToMany', '__call', '__callStatic'])) {
                    $this->relationName = $frame['function'];
                    break;
                }
            }
        }
    }

    public function addConstraints()
    {
        if (static::$constraints) {
            $this->query->where($this->foreignKey, '=', $this->getParentKey());
            $this->query->whereNotNull($this->foreignKey);
        }
    }

    public function addEagerConstraints(array $models)
    {
        $keys = $this->getKeys($models, $this->localKey);
        // Ensure keys is an array for Neo4j
        if ($keys instanceof \Illuminate\Support\Collection) {
            $keys = $keys->all();
        }

        $this->query->whereIn($this->foreignKey, $keys);
    }

    public function create(array $attributes = [])
    {
        return tap($this->related->newInstance($attributes), function ($instance) {
            // Store foreign key if needed
            if ($this->shouldStoreForeignKey()) {
                $instance->setAttribute($this->getForeignKeyName(), $this->getParentKey());
            }

            $instance->save();

            // Create edge if configured
            if ($this->shouldCreateEdge()) {
                $this->getEdgeManager()->createEdge(
                    $this->parent,
                    $instance,
                    $this->getEdgeType()
                );
            }
        });
    }

    public function save(Model $model)
    {
        // Store foreign key if needed
        if ($this->shouldStoreForeignKey()) {
            $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
        }

        $result = $model->save();

        // Create edge if configured
        if ($result && $this->shouldCreateEdge()) {
            $this->getEdgeManager()->createEdge(
                $this->parent,
                $model,
                $this->getEdgeType()
            );
        }

        return $result ? $model : false;
    }

    public function getParentKey()
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Count the related models, respecting any query constraints.
     */
    public function count($columns = '*')
    {
        $bindings = ['parentKey' => $this->getParentKey()];
        $cypherQuery = $this->buildCountQuery($bindings);

        return $this->executeCountQuery($cypherQuery, $bindings);
    }

    protected function buildCountQuery(array &$bindings): string
    {
        $relatedTable = $this->related->getTable();
        $query = $this->query->getQuery();

        $cypherQuery = "MATCH (n:{$relatedTable}) WHERE n.{$this->foreignKey} = \$parentKey";

        // Add additional where clauses
        $whereClause = $this->buildAdditionalWhereClause($query->wheres ?? [], $bindings);
        if ($whereClause) {
            $cypherQuery .= $whereClause;
        }

        $cypherQuery .= ' RETURN count(n) as count';

        return $cypherQuery;
    }

    protected function buildAdditionalWhereClause(array $wheres, array &$bindings): string
    {
        $clauses = [];

        foreach ($wheres as $where) {
            $clause = $this->processWhereCondition($where, $bindings);
            if ($clause) {
                $clauses[] = $clause;
            }
        }

        return $clauses ? ' AND '.implode(' AND ', $clauses) : '';
    }

    protected function processWhereCondition(array $where, array &$bindings): ?string
    {
        if (isset($where['type']) && $where['type'] === 'Null') {
            return "n.{$where['column']} IS NULL";
        }

        if (isset($where['type']) && $where['type'] === 'NotNull') {
            return "n.{$where['column']} IS NOT NULL";
        }

        if (isset($where['type']) && $where['type'] === 'Basic') {
            $operator = $where['operator'] ?? '=';
            $value = $where['value'];

            if (is_null($value)) {
                return $operator === '='
                    ? "n.{$where['column']} IS NULL"
                    : "n.{$where['column']} IS NOT NULL";
            }

            $paramName = str_replace('.', '_', $where['column']).'_'.count($bindings);
            $bindings[$paramName] = $value;

            return "n.{$where['column']} {$operator} \${$paramName}";
        }

        return null;
    }

    protected function executeCountQuery(string $cypherQuery, array $bindings): int
    {
        $connection = $this->parent->getConnection();
        $results = $connection->select($cypherQuery, $bindings);

        return ! empty($results) ? (int) $results[0]['count'] : 0;
    }

    /**
     * Override to generate edge type for HasMany relationships.
     */
    protected function generateDefaultEdgeType(): string
    {
        $convention = config('database.connections.neo4j.edge_naming_convention', 'snake_case_upper');

        // For HasMany, generate edge type based on related table name
        $relatedTable = $this->related->getTable();

        // Apply naming convention
        $baseName = match ($convention) {
            'snake_case_upper' => 'HAS_'.strtoupper($relatedTable),
            'pascal_case' => 'Has'.str_replace(' ', '', ucwords(str_replace('_', ' ', $relatedTable))),
            'camel_case' => 'has'.str_replace(' ', '', ucwords(str_replace('_', ' ', $relatedTable))),
            default => 'HAS_'.strtoupper($relatedTable)
        };

        // Handle native relationships edge type naming
        if ($this->parent) {
            $reflection = new \ReflectionClass($this->parent);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);
                if ($property->getValue($this->parent)) {
                    $baseName = str_replace('HAS_', 'HAS_NATIVE_', $baseName);
                }
            }
        }

        return $baseName;
    }

    /**
     * Override to get the relation name.
     */
    protected function getRelationName(): ?string
    {
        return $this->relationName;
    }
}
