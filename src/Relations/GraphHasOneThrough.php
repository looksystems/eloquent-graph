<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class GraphHasOneThrough extends HasOneThrough
{
    use \Look\EloquentCypher\Relations\GraphRelationHelpers;

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
            return null;
        }

        return $this->first();
    }

    public function get($columns = ['*'])
    {
        // For HasOneThrough, get() should return the first result
        return $this->first($columns);
    }

    public function first($columns = ['*'])
    {
        // Check if this is for eager loading (multiple parents)
        [$isEagerLoading, $parentKeys] = $this->detectEagerLoading();

        if ($isEagerLoading) {
            // For eager loading, we need to return a collection to be matched later
            return $this->getForEagerLoading($parentKeys, $columns);
        }

        // Single parent query
        $parentKey = $this->farParent->getAttribute($this->localKey);

        if (is_null($parentKey)) {
            return null;
        }

        $bindings = ['parentKey' => $parentKey];
        $cypher = $this->buildSingleParentQuery($parentKey, $bindings);

        return $this->executeSingleResult($cypher, $bindings);
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

        // Always limit to 1 for HasOneThrough
        $cypher .= ' RETURN related LIMIT 1';

        return $cypher;
    }

    protected function executeSingleResult(string $cypher, array $bindings)
    {
        $results = $this->query->getConnection()->select($cypher, $bindings);

        if (! empty($results)) {
            return $this->related->newFromBuilder($results[0]['related']);
        }

        return null;
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
                  'WHERE parent.'.$this->localKey.' IN $parentKeys ';

        // Add where clauses from the query builder
        $bindings = ['parentKeys' => array_values($parentKeys)];
        $whereClause = $this->buildWhereClause($bindings);
        if ($whereClause) {
            $cypher .= ' AND '.$whereClause;
        }

        // Add ordering if specified
        $orderClause = $this->buildOrderClause();
        if ($orderClause) {
            $cypher .= ' '.$orderClause;
        }

        $cypher .= ' RETURN related, parent.'.$this->localKey.' as laravel_through_key';

        $results = $this->query->getConnection()->select($cypher, $bindings);

        $models = [];
        foreach ($results as $result) {
            $model = $this->related->newFromBuilder($result['related']);
            $model->laravel_through_key = $result['laravel_through_key'];
            $models[] = $model;
        }

        return $this->related->newCollection($models);
    }

    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            if (isset($dictionary[$key])) {
                // For HasOneThrough, we only want the first result
                $model->setRelation(
                    $relation, $dictionary[$key][0] ?? null
                );
            } else {
                $model->setRelation($relation, null);
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
            // For HasOneThrough, we only keep the first result for each key
            if (empty($dictionary[$key])) {
                $dictionary[$key][] = $result;
            }
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
    // Note: buildLimitClause() is not used in HasOneThrough as it always limits to 1

    public function count()
    {
        // For HasOneThrough, count returns 0 or 1
        return $this->first() ? 1 : 0;
    }

    public function exists()
    {
        return $this->first() !== null;
    }

    public function doesntExist()
    {
        return $this->first() === null;
    }

    public function update(array $attributes)
    {
        $model = $this->first();

        if ($model) {
            $model->update($attributes);

            return 1;
        }

        return 0;
    }

    public function delete()
    {
        $model = $this->first();

        if ($model) {
            return $model->delete();
        }

        return 0;
    }

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
