<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Look\EloquentCypher\Traits\SupportsNativeEdges;

class GraphHasManyThrough extends HasManyThrough
{
    use \Look\EloquentCypher\Relations\GraphRelationHelpers, SupportsNativeEdges;

    protected ?string $secondEdgeType = null;

    public function withSecondEdgeType(string $type): self
    {
        $this->secondEdgeType = $type;

        return $this;
    }

    protected function getSecondEdgeType(): string
    {
        if ($this->secondEdgeType) {
            return $this->secondEdgeType;
        }

        // Generate default second edge type based on related model
        $relatedTable = $this->related->getTable();
        $baseName = 'HAS_'.strtoupper($relatedTable);

        // Check if through parent has useNativeRelationships property
        if ($this->throughParent) {
            $reflection = new \ReflectionClass($this->throughParent);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);
                if ($property->getValue($this->throughParent)) {
                    $baseName = str_replace('HAS_', 'HAS_NATIVE_', $baseName);
                }
            }
        }

        return $baseName;
    }

    protected function getParentForEdge()
    {
        return $this->farParent;
    }

    /**
     * Override to generate edge type for HasManyThrough first edge.
     */
    protected function generateDefaultEdgeType(): string
    {
        $convention = config('database.connections.neo4j.edge_naming_convention', 'snake_case_upper');

        // For HasManyThrough, generate edge type based on through table name
        $throughTable = $this->throughParent->getTable();

        // Apply naming convention
        $baseName = match ($convention) {
            'snake_case_upper' => 'HAS_'.strtoupper($throughTable),
            'pascal_case' => 'Has'.str_replace(' ', '', ucwords(str_replace('_', ' ', $throughTable))),
            'camel_case' => 'has'.str_replace(' ', '', ucwords(str_replace('_', ' ', $throughTable))),
            default => 'HAS_'.strtoupper($throughTable)
        };

        // Handle native relationships edge type naming
        $parent = $this->getParentForEdge();
        if ($parent) {
            $reflection = new \ReflectionClass($parent);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);
                if ($property->getValue($parent)) {
                    $baseName = str_replace('HAS_', 'HAS_NATIVE_', $baseName);
                }
            }
        }

        return $baseName;
    }

    public function getRelationName(): ?string
    {
        // Get the relation name from the method that created this relationship
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['class']) && method_exists($trace['class'], $trace['function'])) {
                $reflection = new \ReflectionMethod($trace['class'], $trace['function']);
                if ($reflection->isPublic() && ! $reflection->isStatic()) {
                    return $trace['function'];
                }
            }
        }

        return 'comments'; // fallback
    }

    public function addConstraints()
    {
        $localValue = $this->farParent[$this->localKey];

        $this->performJoin();

        if (static::$constraints) {
            $this->query->where($this->getQualifiedFirstKeyName(), '=', $localValue);
        }
    }

    public function addEagerConstraints(array $models)
    {
        $keys = $this->getKeys($models, $this->localKey);

        // For Neo4j, we need to modify our approach to handle multiple parent keys
        $this->query->whereIn($this->throughParent->getTable().'.'.$this->firstKey, $keys);
    }

    protected function performJoin($query = null)
    {
        $query = $query ?: $this->query;

        $farKey = $this->getQualifiedFarKeyName();
        $nearKey = $this->getQualifiedFirstKeyName();

        $query->join($this->throughParent->getTable(), $nearKey, '=', $farKey);
    }

    public function getResults()
    {
        $farParentKey = $this->farParent->getAttribute($this->localKey);

        if (is_null($farParentKey)) {
            return $this->related->newCollection();
        }

        return $this->get();
    }

    protected function buildThroughQuery($query = null)
    {
        $query = $query ?: $this->query;

        // For Neo4j, we need to create a proper graph traversal query
        // MATCH (parent:users {id: $parentId})-[r1:HAS_POST]->(through:posts)-[r2:HAS_COMMENT]->(related:comments)
        // RETURN related

        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        $parentKey = $this->localKey;
        $firstKey = $this->firstKey;
        $secondKey = $this->secondKey;
        $farKey = $this->secondLocalKey;

        // Build the graph traversal Cypher query
        $cypher = "MATCH (parent:$parentTable)-[:HAS_".strtoupper($throughTable)."]->(through:$throughTable)-[:HAS_".strtoupper($relatedTable)."]->(related:$relatedTable)";

        return $query;
    }

    public function get($columns = ['*'])
    {
        // Check if we should use native edge traversal
        if ($this->shouldUseEdgeTraversal()) {
            return $this->getWithEdgeTraversal($columns);
        }

        // Check if this is for eager loading (multiple parents)
        [$isEagerLoading, $parentKeys] = $this->detectEagerLoading();

        if ($isEagerLoading) {
            return $this->getForEagerLoading($parentKeys, $columns);
        }

        // Single parent query
        $parentKey = $this->farParent->getAttribute($this->localKey);

        if (is_null($parentKey)) {
            return $this->related->newCollection();
        }

        $bindings = ['parentKey' => $parentKey];
        $cypher = $this->buildSingleParentQuery($parentKey, $bindings);

        return $this->executeAndHydrateResults($cypher, $bindings);
    }

    protected function shouldUseEdgeTraversal(): bool
    {
        // Use edge traversal if parent model has native relationships enabled
        $parent = $this->getParentForEdge();
        if ($parent) {
            $reflection = new \ReflectionClass($parent);
            if ($reflection->hasProperty('useNativeRelationships')) {
                $property = $reflection->getProperty('useNativeRelationships');
                $property->setAccessible(true);

                return $property->getValue($parent);
            }
        }

        // Fall back to config-based strategy
        $strategy = $this->getDefaultStorageStrategy();

        return in_array($strategy, ['edge', 'hybrid']) && $this->preferEdgeTraversal();
    }

    protected function getWithEdgeTraversal($columns = ['*'])
    {
        // Check if this is for eager loading (multiple parents)
        [$isEagerLoading, $parentKeys] = $this->detectEagerLoading();

        if ($isEagerLoading) {
            return $this->getForEagerLoadingWithEdges($parentKeys, $columns);
        }

        // Single parent query using edge traversal
        $parentKey = $this->farParent->getAttribute($this->localKey);

        if (is_null($parentKey)) {
            return $this->related->newCollection();
        }

        $bindings = ['parentKey' => $parentKey];
        $cypher = $this->buildEdgeTraversalQuery($parentKey, $bindings);

        return $this->executeAndHydrateResults($cypher, $bindings);
    }

    protected function buildEdgeTraversalQuery($parentKey, array &$bindings): string
    {
        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        // Get edge types
        $firstEdgeType = $this->getEdgeType();
        $secondEdgeType = $this->getSecondEdgeType();

        // Build the edge traversal Cypher query
        $cypher = "MATCH (parent:$parentTable {id: \$parentKey})-[:$firstEdgeType]->(through:$throughTable)-[:$secondEdgeType]->(related:$relatedTable)";

        // Add where clauses from the query builder
        $whereClause = $this->buildWhereClause($bindings);
        if ($whereClause) {
            $cypher .= ' WHERE '.$whereClause;
        }

        // Add ordering if specified
        $orderClause = $this->buildOrderClause();
        if ($orderClause) {
            $cypher .= ' '.$orderClause;
        }

        // Add limit if specified
        $limitClause = $this->buildLimitClause();
        if ($limitClause) {
            $cypher .= ' '.$limitClause;
        }

        $cypher .= ' RETURN related';

        return $cypher;
    }

    protected function getForEagerLoadingWithEdges(array $parentKeys, $columns = ['*'])
    {
        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        // Get edge types
        $firstEdgeType = $this->getEdgeType();
        $secondEdgeType = $this->getSecondEdgeType();

        // Build Cypher for multiple parents using edge traversal
        $cypher = "MATCH (parent:$parentTable)-[:$firstEdgeType]->(through:$throughTable)-[:$secondEdgeType]->(related:$relatedTable) ".
                  'WHERE parent.id IN $parentKeys '.
                  'RETURN related, parent.id as laravel_through_key';

        // Ensure parentKeys is passed as an indexed array (list) for Neo4j
        $bindings = ['parentKeys' => array_values($parentKeys)];
        $results = $this->query->getConnection()->select($cypher, $bindings);

        $models = [];
        foreach ($results as $result) {
            $model = $this->related->newFromBuilder($result['related']);
            $model->laravel_through_key = $result['laravel_through_key'];
            $models[] = $model;
        }

        return $this->related->newCollection($models);
    }

    protected function detectEagerLoading(): array
    {
        $wheres = $this->query->getQuery()->wheres ?? [];
        $isEagerLoading = false;
        $parentKeys = [];

        foreach ($wheres as $where) {
            if (isset($where['column']) && strpos($where['column'], $this->throughParent->getTable().'.'.$this->firstKey) !== false) {
                if ((isset($where['type']) && $where['type'] === 'In') || (isset($where['operator']) && $where['operator'] === 'in')) {
                    $isEagerLoading = true;
                    $parentKeys = isset($where['values']) ? $where['values'] : (isset($where['value']) ? $where['value'] : []);
                    $parentKeys = is_array($parentKeys) ? $parentKeys : [$parentKeys];
                    break;
                }
            }
        }

        return [$isEagerLoading, $parentKeys];
    }

    protected function buildSingleParentQuery($parentKey, array &$bindings): string
    {
        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        // Build the base Cypher query with proper graph traversal
        $cypher = "MATCH (parent:$parentTable {".$this->localKey.': $parentKey}), '.
                  "(through:$throughTable {".$this->firstKey.': parent.'.$this->localKey.'}), '.
                  "(related:$relatedTable {".$this->secondKey.': through.'.$this->secondLocalKey.'})';

        // Add where clauses from the query builder
        $whereClause = $this->buildWhereClause($bindings);
        if ($whereClause) {
            $cypher .= ' WHERE '.$whereClause;
        }

        // Add ordering if specified
        $orderClause = $this->buildOrderClause();
        if ($orderClause) {
            $cypher .= ' '.$orderClause;
        }

        // Add limit if specified
        $limitClause = $this->buildLimitClause();
        if ($limitClause) {
            $cypher .= ' '.$limitClause;
        }

        $cypher .= ' RETURN related';

        return $cypher;
    }

    protected function executeAndHydrateResults(string $cypher, array $bindings): Collection
    {
        $results = $this->query->getConnection()->select($cypher, $bindings);

        $models = [];
        foreach ($results as $result) {
            $models[] = $this->related->newFromBuilder($result['related']);
        }

        return $this->related->newCollection($models);
    }

    protected function getForEagerLoading(array $parentKeys, $columns = ['*'])
    {
        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        // Build Cypher for multiple parents
        $cypher = "MATCH (parent:$parentTable), ".
                  "(through:$throughTable {".$this->firstKey.': parent.'.$this->localKey.'}), '.
                  "(related:$relatedTable {".$this->secondKey.': through.'.$this->secondLocalKey.'}) '.
                  'WHERE parent.'.$this->localKey.' IN $parentKeys '.
                  'RETURN related, parent.'.$this->localKey.' as laravel_through_key';

        // Ensure parentKeys is passed as an indexed array (list) for Neo4j
        $bindings = ['parentKeys' => array_values($parentKeys)];
        $results = $this->query->getConnection()->select($cypher, $bindings);

        $models = [];
        foreach ($results as $result) {
            $model = $this->related->newFromBuilder($result['related']);
            $model->laravel_through_key = $result['laravel_through_key'];
            $models[] = $model;
        }

        return $this->related->newCollection($models);
    }

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return $this->query->paginate($perPage, $columns, $pageName, $page);
    }

    public function count()
    {
        $parentKey = $this->farParent->getAttribute($this->localKey);

        if (is_null($parentKey)) {
            return 0;
        }

        if ($this->shouldUseEdgeTraversal()) {
            return $this->countWithEdgeTraversal($parentKey);
        }

        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        $cypher = "MATCH (parent:$parentTable {".$this->localKey.': $parentKey}), '.
                  "(through:$throughTable {".$this->firstKey.': parent.'.$this->localKey.'}), '.
                  "(related:$relatedTable {".$this->secondKey.': through.'.$this->secondLocalKey.'}) '.
                  'RETURN count(related) as count';

        $results = $this->query->getConnection()->select($cypher, ['parentKey' => $parentKey]);

        return $results[0]['count'] ?? 0;
    }

    protected function countWithEdgeTraversal($parentKey)
    {
        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        // Get edge types
        $firstEdgeType = $this->getEdgeType();
        $secondEdgeType = $this->getSecondEdgeType();

        $cypher = "MATCH (parent:$parentTable {id: \$parentKey})-[:$firstEdgeType]->(through:$throughTable)-[:$secondEdgeType]->(related:$relatedTable) ".
                  'RETURN count(related) as count';

        $results = $this->query->getConnection()->select($cypher, ['parentKey' => $parentKey]);

        return $results[0]['count'] ?? 0;
    }

    public function first($columns = ['*'])
    {
        $parentKey = $this->farParent->getAttribute($this->localKey);

        if (is_null($parentKey)) {
            return null;
        }

        if ($this->shouldUseEdgeTraversal()) {
            return $this->firstWithEdgeTraversal($parentKey);
        }

        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        $cypher = "MATCH (parent:$parentTable {".$this->localKey.': $parentKey}), '.
                  "(through:$throughTable {".$this->firstKey.': parent.'.$this->localKey.'}), '.
                  "(related:$relatedTable {".$this->secondKey.': through.'.$this->secondLocalKey.'}) '.
                  'RETURN related LIMIT 1';

        $results = $this->query->getConnection()->select($cypher, ['parentKey' => $parentKey]);

        if (! empty($results)) {
            return $this->related->newFromBuilder($results[0]['related']);
        }

        return null;
    }

    protected function firstWithEdgeTraversal($parentKey)
    {
        $parentTable = $this->farParent->getTable();
        $throughTable = $this->throughParent->getTable();
        $relatedTable = $this->related->getTable();

        // Get edge types
        $firstEdgeType = $this->getEdgeType();
        $secondEdgeType = $this->getSecondEdgeType();

        $cypher = "MATCH (parent:$parentTable {id: \$parentKey})-[:$firstEdgeType]->(through:$throughTable)-[:$secondEdgeType]->(related:$relatedTable) ".
                  'RETURN related LIMIT 1';

        $results = $this->query->getConnection()->select($cypher, ['parentKey' => $parentKey]);

        if (! empty($results)) {
            return $this->related->newFromBuilder($results[0]['related']);
        }

        return null;
    }

    protected function prepareQueryBuilder($columns = ['*'])
    {
        $builder = $this->query->applyScopes();

        return $builder->select(
            $this->shouldSelect($columns)
        );
    }

    protected function shouldSelect($columns = ['*'])
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable().'.*'];
        }

        return array_merge($columns, [$this->getQualifiedFirstKeyName()]);
    }

    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            if (isset($dictionary[$key])) {
                $model->setRelation(
                    $relation, $this->related->newCollection($dictionary[$key])
                );
            } else {
                $model->setRelation(
                    $relation, $this->related->newCollection()
                );
            }
        }

        return $models;
    }

    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->laravel_through_key ?? $result->{$this->getFirstKeyName()};
            if (! isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }
            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    public function getQualifiedFirstKeyName()
    {
        return $this->throughParent->getTable().'.'.$this->firstKey;
    }

    public function getFirstKeyName()
    {
        return $this->firstKey;
    }

    public function getQualifiedFarKeyName()
    {
        return $this->related->getTable().'.'.$this->secondKey;
    }

    // Methods provided by Neo4jRelationHelpers trait:
    // - buildWhereClause()
    // - processWhereCondition()
    // - formatColumnName()
    // - handleLikeOperator()
    // - buildOrderClause()
    // - buildLimitClause()

    /**
     * Get the "through" parent model instance.
     * Accessor method to replace reflection usage.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getThroughParent()
    {
        return $this->throughParent;
    }

    /**
     * Get the first key (near key) on the relationship.
     * Accessor method to replace reflection usage.
     *
     * @return string
     */
    public function getFirstKey()
    {
        return $this->firstKey;
    }

    /**
     * Get the second key (far key) on the relationship.
     * Accessor method to replace reflection usage.
     *
     * @return string
     */
    public function getSecondKey()
    {
        return $this->secondKey;
    }

    /**
     * Get the local key on the relationship.
     * Accessor method to replace reflection usage.
     *
     * @return string
     */
    public function getLocalKey()
    {
        return $this->localKey;
    }

    /**
     * Get the second local key on the intermediary model.
     * Accessor method to replace reflection usage.
     *
     * @return string
     */
    public function getSecondLocalKey()
    {
        return $this->secondLocalKey;
    }
}
