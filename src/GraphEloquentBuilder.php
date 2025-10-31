<?php

namespace Look\EloquentCypher;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Look\EloquentCypher\Handlers\PivotHandler;
use Look\EloquentCypher\Helpers\ExistsClauseBuilder;
use Look\EloquentCypher\Patterns\GraphPatternBuilder;
use Look\EloquentCypher\Patterns\ShortestPathBuilder;
use Look\EloquentCypher\Patterns\VariablePathBuilder;
use Look\EloquentCypher\Registry\RelationHandlerRegistry;
use Look\EloquentCypher\Traits\CypherOperatorConverter;

class GraphEloquentBuilder extends Builder
{
    use CypherOperatorConverter;

    protected $withCountRelations = [];

    /**
     * The methods that should be returned from query builder.
     */
    protected $passthru = [
        'aggregate', 'average', 'avg', 'count', 'dd', 'ddrawsql',
        'doesntexist', 'doesntexistor', 'dump', 'dumprawsql',
        'exists', 'existsor', 'explain', 'getbindings', 'getconnection',
        'getcountforpagination', 'getgrammar', 'getrawbindings',
        'implode', 'insert', 'insertgetid', 'insertorignore',
        'insertusing', 'insertorignoreusing', 'max', 'min',
        'numericaggregate', 'raw', 'rawvalue', 'sum', 'tosql', 'torawsql',
        // Neo4j-specific aggregate functions
        'percentileDisc', 'percentileCont', 'stdev', 'stdevp', 'collect',
    ];

    /**
     * Get the discrete percentile value of a column.
     */
    public function percentileDisc(string $column, float $percentile): ?float
    {
        return $this->toBase()->percentileDisc($column, $percentile);
    }

    /**
     * Get the interpolated percentile value of a column.
     */
    public function percentileCont(string $column, float $percentile): ?float
    {
        return $this->toBase()->percentileCont($column, $percentile);
    }

    /**
     * Get the sample standard deviation of a column.
     */
    public function stdev(string $column): ?float
    {
        return $this->toBase()->stdev($column);
    }

    /**
     * Get the population standard deviation of a column.
     */
    public function stdevp(string $column): ?float
    {
        return $this->toBase()->stdevp($column);
    }

    /**
     * Collect all values of a column into an array.
     */
    public function collect(string $column): array
    {
        return $this->toBase()->collect($column);
    }

    /**
     * Paginate the given query using a cursor paginator.
     */
    public function cursorPaginate($perPage = null, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        $perPage = $perPage ?: $this->model->getPerPage();

        // Forward to the query builder's implementation
        return $this->toBase()->cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Find a model by its primary key.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function find($id, $columns = ['*'])
    {
        return $this->where($this->model->getKeyName(), '=', $id)->first($columns);
    }

    /**
     * Find multiple models by their primary keys.
     *
     * @param  iterable  $ids
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findMany($ids, $columns = ['*'])
    {
        // Convert to array and filter out null/empty values
        $ids = collect($ids)->filter(function ($id) {
            return $id !== null && $id !== '';
        })->toArray();

        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->whereIn($this->model->getKeyName(), $ids)->get($columns);
    }

    public function getModels($columns = ['*'])
    {
        [$orderByCountField, $orderDirection] = $this->extractOrderByCount();

        $results = $this->query->get($columns);
        $models = $this->createModelsFromResults($results);

        // Add withCount data if needed
        if (! empty($this->withCountRelations)) {
            $this->addWithCountToModels($models);
        }

        // If we need to order by count field, do it now
        if ($orderByCountField) {
            $models = $this->sortModelsByCount($models, $orderByCountField, $orderDirection);
        }

        return $models;
    }

    /**
     * Extract order by count configuration from query orders.
     */
    protected function extractOrderByCount(): array
    {
        $orderByCountField = null;
        $orderDirection = 'asc';

        if (! empty($this->query->orders) && ! empty($this->withCountRelations)) {
            foreach ($this->query->orders as $key => $order) {
                foreach ($this->withCountRelations as $relationData) {
                    if ($relationData['alias'] === $order['column']) {
                        $orderByCountField = $order['column'];
                        $orderDirection = strtolower($order['direction']);
                        // Remove this order from the query since we'll handle it after
                        unset($this->query->orders[$key]);
                        $this->query->orders = array_values($this->query->orders);
                        break 2;
                    }
                }
            }
        }

        return [$orderByCountField, $orderDirection];
    }

    /**
     * Create models from query results.
     */
    protected function createModelsFromResults($results): array
    {
        $models = [];
        foreach ($results as $result) {
            // Check if this is a join query
            if (! empty($this->query->neo4jJoins)) {
                $model = $this->createModelFromJoinResult($result);
            } else {
                $model = $this->model->newFromBuilder((array) $result);
            }
            // Note: newFromBuilder already fires the 'retrieved' event
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Create a model from a join query result.
     */
    protected function createModelFromJoinResult($result)
    {
        $modelAttributes = $this->extractJoinAttributes($result);

        return $this->model->newFromBuilder($modelAttributes);
    }

    /**
     * Extract model attributes from join result.
     */
    protected function extractJoinAttributes($result): array
    {
        $resultArray = (array) $result;
        $modelAttributes = [];
        $modelTable = $this->model->getTable();

        foreach ($resultArray as $key => $value) {
            // If the key doesn't have a table prefix or starts with the model's table name,
            // include it in the model attributes
            if (! str_contains($key, '_') || str_starts_with($key, $modelTable.'_')) {
                // Remove table prefix if present
                $cleanKey = str_replace($modelTable.'_', '', $key);
                $modelAttributes[$cleanKey] = $value;
            } elseif (! str_contains($key, 'j1_') && ! str_contains($key, 'j2_') && ! str_contains($key, 'j3_')) {
                // Also include attributes without join prefixes
                $modelAttributes[$key] = $value;
            }
        }

        return $modelAttributes;
    }

    /**
     * Sort models by count field.
     */
    protected function sortModelsByCount(array $models, string $orderByCountField, string $orderDirection): array
    {
        usort($models, function ($a, $b) use ($orderByCountField, $orderDirection) {
            $aVal = $a->{$orderByCountField} ?? 0;
            $bVal = $b->{$orderByCountField} ?? 0;

            if ($orderDirection === 'desc') {
                return $bVal <=> $aVal;
            }

            return $aVal <=> $bVal;
        });

        return $models;
    }

    /**
     * Add a relationship count / exists condition to the query.
     */
    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     */
    public function whereDoesntHave($relation, ?Closure $callback = null)
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with OR clause.
     */
    public function orWhereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query.
     */
    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null)
    {
        if (is_string($relation)) {
            if (strpos($relation, '.') !== false) {
                return $this->hasNested($relation, $operator, $count, $boolean, $callback);
            }

            $relation = $this->getRelationWithoutConstraints($relation);
        }

        $handlerMethod = RelationHandlerRegistry::getHasHandler($relation);

        if ($handlerMethod) {
            return $this->$handlerMethod($relation, $operator, $count, $boolean, $callback);
        }

        throw \Look\EloquentCypher\Exceptions\GraphQueryException::invalidRelationType(get_class($relation), 'unknown');
    }

    /**
     * Add a relationship count / exists condition to the query.
     */
    public function doesntHave($relation, $boolean = 'and', ?Closure $callback = null)
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add subselect queries to count the relations.
     */
    public function withCount($relations)
    {
        if (empty($relations)) {
            return $this;
        }

        $relations = is_array($relations) ? $relations : func_get_args();

        return $this->withAggregate($relations, '*', 'count');
    }

    /**
     * Add subselect queries to aggregate a relationship.
     *
     * @param  mixed  $relations
     * @param  string  $column
     * @param  string  $function
     * @return $this
     */
    public function withAggregate($relations, $column, $function = null)
    {
        if (empty($relations)) {
            return $this;
        }

        $this->initializeWithCountRelations();
        $relations = $this->normalizeAggregateRelations($relations, $column, func_num_args());

        foreach ($this->parseWithRelations($relations) as $name => $constraints) {
            [$relation, $alias] = $this->extractRelationAndAlias($name, $function, $column);

            $this->withCountRelations[] = [
                'relation' => $relation,
                'alias' => $alias,
                'constraints' => $constraints,
                'column' => $column,
                'function' => $function,
            ];
        }

        return $this;
    }

    /**
     * Initialize the withCountRelations array if not set.
     */
    protected function initializeWithCountRelations(): void
    {
        if (! isset($this->withCountRelations)) {
            $this->withCountRelations = [];
        }
    }

    /**
     * Normalize the relations parameter for aggregate operations.
     */
    protected function normalizeAggregateRelations($relations, $column, int $argCount): array
    {
        // If relations is not an array and we have 3 args, it's from withAggregate
        if (! is_array($relations) && $argCount === 3) {
            return [$relations];
        }

        if (! is_array($relations)) {
            // Otherwise it's from the variadic withCount
            $relations = func_get_args();
            // In this case column and function are not set properly
            if (count($relations) > 1 && ! is_string($column)) {
                return array_slice($relations, 0, -2);
            }
        }

        return $relations;
    }

    /**
     * Extract relation name and alias from the given name.
     */
    protected function extractRelationAndAlias(string $name, ?string $function, $column): array
    {
        $segments = explode(' as ', $name);
        $relation = $segments[0];

        // Use custom alias if provided
        if (isset($segments[1])) {
            return [$relation, $segments[1]];
        }

        // Generate default alias
        $alias = $this->generateAggregateAlias($relation, $function, $column);

        return [$relation, $alias];
    }

    /**
     * Generate a default alias for an aggregate relation.
     */
    protected function generateAggregateAlias(string $relation, ?string $function, $column): string
    {
        $snakeRelation = \Illuminate\Support\Str::snake($relation);

        if ($function === 'count') {
            return $snakeRelation.'_count';
        }

        return $snakeRelation.'_'.$function.'_'.$column;
    }

    /**
     * Get the relation instance for the given relation name.
     */
    protected function getRelationWithoutConstraints($relation)
    {
        return $this->model->{$relation}();
    }

    /**
     * Add a has condition specifically for HasMany relationships.
     */
    protected function addNeo4jHasWhere($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        $baseCondition = $this->buildHasExistsCondition($relation, $callback);
        $condition = $this->buildCountCondition($baseCondition, $relation, $operator, $count, $callback);

        $this->query->whereRaw($condition, [], $boolean);

        return $this;
    }

    /**
     * Build the EXISTS condition for HasMany relationships.
     */
    protected function buildHasExistsCondition($relation, $callback)
    {
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $parentKey = $relation->getLocalKeyName();

        $additionalConditions = [];
        if ($callback) {
            $whereClause = $this->applyCallbackConstraints($relation, $callback, 'related');
            if ($whereClause) {
                $additionalConditions[] = $whereClause;
            }
        }

        return ExistsClauseBuilder::buildRelationExists(
            'n',
            'related',
            $relatedTable,
            $foreignKey,
            $parentKey,
            $additionalConditions
        );
    }

    /**
     * Apply callback constraints to a relation query.
     */
    protected function applyCallbackConstraints($relation, ?Closure $callback, string $alias): ?string
    {
        if (! $callback) {
            return null;
        }

        $relatedQuery = $relation->getQuery();
        $callback($relatedQuery);

        return $this->buildWhereClauseFromQuery($relatedQuery, $alias);
    }

    /**
     * Build the final count condition based on operator and count.
     */
    protected function buildCountCondition($baseCondition, $relation, $operator, $count, $callback)
    {
        // Simple existence check
        if ($operator === '>=' && $count == 1) {
            return $baseCondition;
        }

        // NOT EXISTS check
        if ($operator === '<' && $count === 1) {
            return 'NOT '.$baseCondition;
        }

        // Count-based condition
        return $this->buildCountSubquery($relation, $operator, $count, $callback);
    }

    /**
     * Build a count subquery for complex count conditions.
     */
    protected function buildCountSubquery($relation, $operator, $count, $callback)
    {
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $parentKey = $relation->getLocalKeyName();

        $subquery = "MATCH (related:$relatedTable) WHERE related.$foreignKey = n.$parentKey";

        if ($callback) {
            $whereClause = $this->applyCallbackConstraints($relation, $callback, 'related');
            if ($whereClause) {
                $subquery .= " AND $whereClause";
            }
        }

        $subquery .= " WITH count(related) as rel_count WHERE rel_count $operator $count RETURN rel_count";

        return "EXISTS { $subquery }";
    }

    /**
     * Add a has condition specifically for BelongsToMany relationships.
     */
    protected function addNeo4jHasWhereForBelongsToMany($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        $pivotHandler = new PivotHandler;
        $pivotInfo = $pivotHandler->extractPivotTableInfo($relation);
        $whereClauses = $pivotHandler->buildPivotConstraints($this, $relation, $callback, $pivotInfo['relatedAlias'], $pivotInfo['relationAlias']);

        $matchPattern = "MATCH ({$pivotInfo['parentAlias']})-[{$pivotInfo['relationAlias']}:{$pivotInfo['relationshipType']}]->({$pivotInfo['relatedAlias']}:{$pivotInfo['relatedTable']})";

        $condition = $pivotHandler->buildPivotExistsCondition($matchPattern, $whereClauses, $operator, $count, $pivotInfo);

        $this->query->whereRaw($condition, [], $boolean);

        return $this;
    }

    /**
     * Add a has condition specifically for BelongsTo relationships.
     */
    protected function addNeo4jHasWhereForBelongsTo($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        $config = $this->extractBelongsToConfig($relation);
        $additionalConditions = $this->buildRelationCallbackConstraints($relation, $callback, $config['relatedAlias']);

        $condition = ExistsClauseBuilder::buildRelationExists(
            $config['parentAlias'],
            $config['relatedAlias'],
            $config['relatedTable'],
            $config['ownerKey'],
            $config['foreignKey'],
            $additionalConditions
        );

        $condition = $this->applyCountOperatorToCondition($condition, $operator, $count, $config);
        $this->query->whereRaw($condition, [], $boolean);

        return $this;
    }

    /**
     * Extract configuration for BelongsTo relation.
     */
    protected function extractBelongsToConfig($relation): array
    {
        return [
            'parentAlias' => 'n',
            'relatedAlias' => 'related',
            'relatedTable' => $relation->getRelated()->getTable(),
            'foreignKey' => $relation->getForeignKeyName(),
            'ownerKey' => $relation->getOwnerKeyName(),
        ];
    }

    /**
     * Build callback constraints for a relation.
     */
    protected function buildRelationCallbackConstraints($relation, ?Closure $callback, string $alias): array
    {
        if (! $callback) {
            return [];
        }

        $relatedQuery = $relation->getQuery();
        $callback($relatedQuery);
        $whereClause = $this->buildWhereClauseFromQuery($relatedQuery, $alias);

        return $whereClause ? [$whereClause] : [];
    }

    /**
     * Apply count operator to an EXISTS condition.
     */
    protected function applyCountOperatorToCondition(string $condition, string $operator, int $count, array $config): string
    {
        if ($operator === '<' && $count == 1) {
            return 'NOT '.$condition;
        }

        if ($operator !== '>=' || $count != 1) {
            // For count-based operations, wrap in SIZE
            $relatedAlias = $config['relatedAlias'];
            $relatedTable = $config['relatedTable'];
            $ownerKey = $config['ownerKey'];
            $parentAlias = $config['parentAlias'];
            $foreignKey = $config['foreignKey'];

            return "SIZE([($relatedAlias:$relatedTable) WHERE $relatedAlias.$ownerKey = $parentAlias.$foreignKey | 1]) $operator $count";
        }

        return $condition;
    }

    /**
     * Add a has condition specifically for HasOne relationships.
     */
    protected function addNeo4jHasWhereForHasOne($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        // HasOne works similar to HasMany but typically with count = 1
        return $this->addNeo4jHasWhere($relation, $operator, $count, $boolean, $callback);
    }

    /**
     * Add a has condition specifically for HasManyThrough relationships.
     */
    protected function addNeo4jHasWhereForHasManyThrough($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        $tables = $this->extractHasManyThroughTables($relation);
        $keys = $this->extractHasManyThroughKeys($relation);

        $baseCondition = $this->buildHasManyThroughExistsClause($tables, $keys, $callback, $relation);
        $condition = $this->buildHasManyThroughCondition($baseCondition, $operator, $count, $tables, $keys, $callback, $relation);

        $this->query->whereRaw($condition, [], $boolean);

        return $this;
    }

    /**
     * Extract table names for HasManyThrough relationship.
     */
    protected function extractHasManyThroughTables($relation): array
    {
        return [
            'parent' => $this->model->getTable(),
            'through' => $relation->getThroughParent()->getTable(),
            'related' => $relation->getRelated()->getTable(),
        ];
    }

    /**
     * Extract keys for HasManyThrough relationship.
     */
    protected function extractHasManyThroughKeys($relation): array
    {
        return [
            'firstKey' => $relation->getFirstKey(),
            'secondKey' => $relation->getSecondKey(),
            'localKey' => $relation->getLocalKey(),
            'secondLocalKey' => $relation->getSecondLocalKey(),
        ];
    }

    /**
     * Build the EXISTS clause for HasManyThrough relationship.
     */
    protected function buildHasManyThroughExistsClause(array $tables, array $keys, ?Closure $callback, $relation): string
    {
        $baseCondition = 'EXISTS { '.
            "MATCH (parent:{$tables['parent']}) WHERE parent.{$keys['localKey']} = n.{$keys['localKey']} ".
            "MATCH (through:{$tables['through']}) WHERE through.{$keys['firstKey']} = parent.{$keys['localKey']} ".
            "MATCH (related:{$tables['related']}) WHERE related.{$keys['secondKey']} = through.{$keys['secondLocalKey']}";

        if ($callback) {
            $whereClause = $this->applyCallbackConstraints($relation, $callback, 'related');
            if ($whereClause) {
                $baseCondition .= " WHERE $whereClause";
            }
        }

        return $baseCondition.' }';
    }

    /**
     * Build the complete condition for HasManyThrough relationship.
     */
    protected function buildHasManyThroughCondition(string $baseCondition, string $operator, int $count, array $tables, array $keys, ?Closure $callback, $relation): string
    {
        if ($operator === '>=' && $count == 1) {
            return $baseCondition;
        }

        if ($operator === '<' && $count === 1) {
            return 'NOT '.$baseCondition;
        }

        // For count-based conditions, use EXISTS subquery with COUNT()
        return $this->buildHasManyThroughCountCondition($tables, $keys, $operator, $count, $callback, $relation);
    }

    /**
     * Build count-based condition for HasManyThrough relationship.
     */
    protected function buildHasManyThroughCountCondition(array $tables, array $keys, string $operator, int $count, ?Closure $callback, $relation): string
    {
        $subquery = "MATCH (parent:{$tables['parent']}) WHERE parent.{$keys['localKey']} = n.{$keys['localKey']} ".
            "MATCH (through:{$tables['through']}) WHERE through.{$keys['firstKey']} = parent.{$keys['localKey']} ".
            "MATCH (related:{$tables['related']}) WHERE related.{$keys['secondKey']} = through.{$keys['secondLocalKey']}";

        if ($callback) {
            $whereClause = $this->applyCallbackConstraints($relation, $callback, 'related');
            if ($whereClause) {
                $subquery .= " WHERE $whereClause";
            }
        }

        $subquery .= " WITH count(related) as rel_count WHERE rel_count $operator $count RETURN rel_count";

        return "EXISTS { $subquery }";
    }

    /**
     * Add withCount for HasMany relationships.
     */
    protected function addWithCountForHasMany($relation, $alias, ?Closure $constraints = null)
    {
        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $parentKey = $relation->getLocalKeyName();

        // Use SIZE with pattern comprehension for counting
        $countCypher = "SIZE([(n)-[*0..1]->(:$relatedTable) WHERE true | 1])";

        // For now, let's use a simpler approach that works
        // We'll add the count logic in the query processing later
        $this->query->selectRaw("0 as $alias");

        return $this;
    }

    /**
     * Add withCount for BelongsToMany relationships.
     */
    protected function addWithCountForBelongsToMany($relation, $alias, ?Closure $constraints = null)
    {
        // BelongsToMany uses graph relationships, so we need different logic
        // For now, delegate to the post-processing approach
        return $this;
    }

    /**
     * Add withCount for HasOne relationships.
     */
    protected function addWithCountForHasOne($relation, $alias, ?Closure $constraints = null)
    {
        return $this->addWithCountForHasMany($relation, $alias, $constraints);
    }

    /**
     * Add a has condition specifically for MorphOne relationships.
     */
    protected function addNeo4jHasWhereForMorphOne($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        return $this->addNeo4jHasWhereForMorph($relation, $operator, $count, $boolean, $callback, 'one');
    }

    /**
     * Add a has condition specifically for MorphMany relationships.
     */
    protected function addNeo4jHasWhereForMorphMany($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        return $this->addNeo4jHasWhereForMorph($relation, $operator, $count, $boolean, $callback, 'many');
    }

    /**
     * Add a has condition for Morph relationships (shared logic for MorphOne and MorphMany).
     */
    protected function addNeo4jHasWhereForMorph($relation, $operator, $count, $boolean, ?Closure $callback, string $morphType)
    {
        $morphInfo = $this->getMorphIdentifiers($relation);
        $additionalConditions = $this->buildMorphConditions($relation, $callback, $morphInfo['relatedAlias']);

        $condition = $this->buildMorphExistsClause(
            $morphInfo,
            $operator,
            $count,
            $additionalConditions,
            $morphType,
            $callback
        );

        $this->query->whereRaw($condition, [], $boolean);

        return $this;
    }

    /**
     * Extract morph type, foreign key, and class info from a morph relation.
     */
    protected function getMorphIdentifiers($relation): array
    {
        return [
            'parentAlias' => 'n',
            'relatedAlias' => 'related',
            'relatedTable' => $relation->getRelated()->getTable(),
            'morphType' => $relation->getMorphType(),
            'foreignKey' => $relation->getForeignKeyName(),
            'parentKey' => $relation->getLocalKeyName(),
            'morphClass' => get_class($this->model),
        ];
    }

    /**
     * Build morph-specific WHERE clauses from callback.
     */
    protected function buildMorphConditions($relation, ?Closure $callback, string $relatedAlias): array
    {
        if (! $callback) {
            return [];
        }

        $additionalConditions = [];
        $relatedQuery = $relation->getQuery();
        $callback($relatedQuery);
        $whereClause = $this->buildWhereClauseFromQuery($relatedQuery, $relatedAlias);

        if ($whereClause) {
            $additionalConditions[] = $whereClause;
        }

        return $additionalConditions;
    }

    /**
     * Build complete EXISTS clause for morph relationships.
     */
    protected function buildMorphExistsClause(array $morphInfo, string $operator, int $count, array $additionalConditions, string $morphRelationType, ?Closure $callback): string
    {
        $baseCondition = ExistsClauseBuilder::buildMorphExists(
            $morphInfo['parentAlias'],
            $morphInfo['relatedAlias'],
            $morphInfo['relatedTable'],
            $morphInfo['foreignKey'],
            $morphInfo['parentKey'],
            $morphInfo['morphType'],
            $morphInfo['morphClass'],
            $additionalConditions
        );

        // Handle different operators
        if ($morphRelationType === 'one' && $operator === '<' && $count == 1) {
            return 'NOT '.$baseCondition;
        } elseif ($morphRelationType === 'one' && $operator !== '>=' || ($morphRelationType === 'one' && $count != 1)) {
            // For count-based operations on MorphOne, wrap in SIZE
            return "SIZE([({$morphInfo['relatedAlias']}:{$morphInfo['relatedTable']}) WHERE {$morphInfo['relatedAlias']}.{$morphInfo['foreignKey']} = {$morphInfo['parentAlias']}.{$morphInfo['parentKey']} AND {$morphInfo['relatedAlias']}.{$morphInfo['morphType']} = '{$morphInfo['morphClass']}' | 1]) $operator $count";
        }

        // Handle MorphMany operators and counts
        if ($operator === '>=' && $count == 1) {
            return $baseCondition;
        } elseif ($operator === '<' && $count === 1) {
            return 'NOT '.$baseCondition;
        } else {
            // Count-based condition for MorphMany
            return $this->buildMorphCountCondition($morphInfo, $operator, $count, $callback);
        }
    }

    /**
     * Build count-based condition for morph relationships.
     */
    protected function buildMorphCountCondition(array $morphInfo, string $operator, int $count, ?Closure $callback): string
    {
        $subquery = "MATCH ({$morphInfo['relatedAlias']}:{$morphInfo['relatedTable']}) WHERE {$morphInfo['relatedAlias']}.{$morphInfo['foreignKey']} = {$morphInfo['parentAlias']}.{$morphInfo['parentKey']} AND {$morphInfo['relatedAlias']}.{$morphInfo['morphType']} = '{$morphInfo['morphClass']}'";

        if ($callback) {
            $additionalConditions = $this->buildMorphConditions(null, $callback, $morphInfo['relatedAlias']);
            if (! empty($additionalConditions)) {
                $subquery .= ' AND '.implode(' AND ', $additionalConditions);
            }
        }

        $subquery .= " WITH count({$morphInfo['relatedAlias']}) as rel_count WHERE rel_count $operator $count RETURN rel_count";

        return "EXISTS { $subquery }";
    }

    /**
     * Add a has condition specifically for MorphTo relationships.
     */
    protected function addNeo4jHasWhereForMorphTo($relation, $operator, $count, $boolean, ?Closure $callback = null)
    {
        $config = $this->extractMorphToConfig($relation);
        $conditions = $this->buildMorphToExistenceConditions($config, $relation, $callback);
        $condition = $this->buildMorphToCondition($config, $conditions, $operator, $count);

        $this->query->whereRaw($condition, [], $boolean);

        return $this;
    }

    /**
     * Extract configuration for MorphTo relation.
     */
    protected function extractMorphToConfig($relation): array
    {
        return [
            'parentAlias' => 'n',
            'morphType' => $relation->getMorphType(),
            'foreignKey' => $relation->getForeignKeyName(),
        ];
    }

    /**
     * Build existence conditions for MorphTo relation.
     */
    protected function buildMorphToExistenceConditions(array $config, $relation, ?Closure $callback): array
    {
        $conditions = [
            "{$config['parentAlias']}.{$config['foreignKey']} IS NOT NULL",
            "{$config['parentAlias']}.{$config['morphType']} IS NOT NULL",
        ];

        if ($callback) {
            $constraints = $this->buildRelationCallbackConstraints($relation, $callback, $config['parentAlias']);
            $conditions = array_merge($conditions, $constraints);
        }

        return $conditions;
    }

    /**
     * Build the complete condition for MorphTo relation.
     */
    protected function buildMorphToCondition(array $config, array $conditions, string $operator, int $count): string
    {
        $matchPattern = "MATCH ({$config['parentAlias']})";
        $condition = ExistsClauseBuilder::buildBasicExists($matchPattern, $conditions);

        // Handle different operators
        if ($operator === '<' && $count == 1) {
            return 'NOT '.$condition;
        }

        if ($operator === '=' && $count == 0) {
            // For count = 0, check if morphTo fields are null
            return "({$config['parentAlias']}.{$config['foreignKey']} IS NULL OR {$config['parentAlias']}.{$config['morphType']} IS NULL)";
        }

        return $condition;
    }

    /**
     * Add withCount for MorphOne relationships.
     */
    protected function addWithCountForMorphOne($relation, $alias, ?Closure $constraints = null)
    {
        // MorphOne is similar to HasOne but with morph constraints
        return $this->addWithCountForMorphMany($relation, $alias, $constraints);
    }

    /**
     * Add withCount for MorphMany relationships.
     */
    protected function addWithCountForMorphMany($relation, $alias, ?Closure $constraints = null)
    {
        // For now, we'll use a placeholder that will be processed later
        // The actual counting will be done in post-processing
        $this->query->selectRaw("0 as $alias");

        return $this;
    }

    /**
     * Add withCount for MorphToMany relationships.
     */
    protected function addWithCountForMorphToMany($relation, $alias, ?Closure $constraints = null)
    {
        // MorphToMany uses a pivot table/node, so we need to count pivot nodes
        // For now, we'll use a placeholder that will be processed later
        $this->query->selectRaw("0 as $alias");

        return $this;
    }

    /**
     * Add withCount for MorphTo relationships.
     */
    protected function addWithCountForMorphTo($relation, $alias, ?Closure $constraints = null)
    {
        // MorphTo counts are always 0 or 1
        $this->query->selectRaw("0 as $alias");

        return $this;
    }

    /**
     * Add withCount for BelongsTo relationships.
     */
    protected function addWithCountForBelongsTo($relation, $alias, ?Closure $constraints = null)
    {
        $parentTable = $this->model->getTable();
        $relatedTable = $relation->getRelated()->getTable();
        $relationshipType = strtoupper($relatedTable).'_'.strtoupper($parentTable);

        $countCypher = "SIZE([(related:$relatedTable)-[:$relationshipType]->(n) | 1])";
        $this->query->selectRaw("$countCypher as $alias");

        return $this;
    }

    /**
     * Parse the nested relations in a relation.
     */
    protected function parseWithRelations(array $relations)
    {
        $results = [];

        foreach ($relations as $name => $constraints) {
            // If numeric key, then constraints is the name
            if (is_numeric($name)) {
                $name = $constraints;
                $constraints = function () {
                    // Empty closure for relationships without constraints
                };
            }

            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Build pivot where clauses from a BelongsToMany relation.
     */
    public function buildPivotWhereClausesFromRelation($relation, $relationAlias)
    {
        $pivotWheres = $this->extractPivotWheres($relation);
        if (empty($pivotWheres)) {
            return null;
        }

        $clauses = [];
        foreach ($pivotWheres as $where) {
            $clause = $this->buildSinglePivotWhereClause($where, $relationAlias);
            if ($clause) {
                $clauses[] = $clause;
            }
        }

        return empty($clauses) ? null : implode(' AND ', $clauses);
    }

    /**
     * Extract pivot where conditions from relation.
     */
    protected function extractPivotWheres($relation): array
    {
        try {
            if (method_exists($relation, 'getPivotWheres')) {
                return $relation->getPivotWheres() ?: [];
            }
        } catch (\ReflectionException $e) {
            // If we can't access the property, return empty array
        }

        return [];
    }

    /**
     * Build a single pivot where clause.
     */
    protected function buildSinglePivotWhereClause(array $where, string $relationAlias): ?string
    {
        $column = $relationAlias.'.'.$where['column'];
        $operator = $this->getCypherOperator($where['operator'] ?? '=');
        $value = $where['value'] ?? null;

        if ($this->isNullOperator($operator)) {
            return $this->buildNullPivotClause($column, $operator);
        }

        if (isset($where['values'])) {
            return $this->buildInPivotClause($column, $where['values']);
        }

        return $this->buildComparisonPivotClause($column, $operator, $value);
    }

    /**
     * Check if operator is a null comparison.
     */
    protected function isNullOperator(string $operator): bool
    {
        return $operator === 'is' || $operator === 'is not';
    }

    /**
     * Build a null comparison pivot clause.
     */
    protected function buildNullPivotClause(string $column, string $operator): string
    {
        return $operator === 'is' ? "$column IS NULL" : "$column IS NOT NULL";
    }

    /**
     * Build an IN pivot clause.
     */
    protected function buildInPivotClause(string $column, array $values): string
    {
        $valuesList = implode(', ', array_map(function ($v) {
            return is_string($v) ? "'$v'" : $v;
        }, $values));

        return "$column IN [$valuesList]";
    }

    /**
     * Build a comparison pivot clause.
     */
    protected function buildComparisonPivotClause(string $column, string $operator, $value): string
    {
        $formattedValue = $this->formatPivotValue($value);

        return "$column $operator $formattedValue";
    }

    /**
     * Format a value for pivot comparison.
     */
    protected function formatPivotValue($value): string
    {
        if (is_string($value)) {
            return "'$value'";
        }

        if ($value instanceof \DateTimeInterface) {
            return "'".$value->format('Y-m-d H:i:s')."'";
        }

        return (string) $value;
    }

    /**
     * Build where clause from a query builder instance.
     */
    public function buildWhereClauseFromQuery($query, $alias)
    {
        $wheres = $query->getQuery()->wheres ?? [];
        $clauses = [];

        foreach ($wheres as $where) {
            $clause = $this->buildSingleWhereClause($where, $alias);
            if ($clause) {
                $clauses[] = $clause;
            }
        }

        return implode(' AND ', $clauses);
    }

    /**
     * Build a single where clause from a where array.
     */
    protected function buildSingleWhereClause(array $where, string $alias): ?string
    {
        if (isset($where['type'])) {
            return $this->buildTypedWhereClause($where, $alias);
        }

        if (isset($where['column'], $where['operator'], $where['value'])) {
            return $this->buildUntypedWhereClause($where, $alias);
        }

        return null;
    }

    /**
     * Build a typed where clause.
     */
    protected function buildTypedWhereClause(array $where, string $alias): ?string
    {
        $column = $where['column'] ?? null;
        $operator = $where['operator'] ?? '=';

        switch ($where['type']) {
            case 'Basic':
                return $this->buildBasicWhereClause($where, $alias, $column, $operator);
            case 'Date':
                return $this->buildDateWhereClause($where, $alias, $column, $operator);
            case 'In':
            case 'InRaw':
                return $this->buildInWhereClause($where, $alias, $column);
            case 'NotIn':
            case 'NotInRaw':
                return $this->buildNotInWhereClause($where, $alias, $column);
            case 'Null':
                return "$alias.$column IS NULL";
            case 'NotNull':
                return "$alias.$column IS NOT NULL";
            default:
                return null;
        }
    }

    /**
     * Build a basic where clause.
     */
    protected function buildBasicWhereClause(array $where, string $alias, ?string $column, string $operator): ?string
    {
        if (! isset($where['value'])) {
            return null;
        }

        $value = is_string($where['value']) ? "'{$where['value']}'" : $where['value'];

        return "$alias.$column $operator $value";
    }

    /**
     * Build an IN where clause.
     */
    protected function buildInWhereClause(array $where, string $alias, ?string $column): ?string
    {
        if (! $column) {
            return null;
        }

        $values = $where['values'] ?? [];

        // Handle empty array - should return false condition
        if (empty($values)) {
            return '1 = 0';
        }

        // Format values for Cypher IN clause
        $formattedValues = array_map(function ($value) {
            return is_string($value) ? "'{$value}'" : $value;
        }, $values);

        $valueList = '['.implode(', ', $formattedValues).']';

        return "$alias.$column IN $valueList";
    }

    /**
     * Build a NOT IN where clause.
     */
    protected function buildNotInWhereClause(array $where, string $alias, ?string $column): ?string
    {
        if (! $column) {
            return null;
        }

        $values = $where['values'] ?? [];

        // Handle empty array - no constraint needed
        if (empty($values)) {
            return null;
        }

        // Format values for Cypher NOT IN clause
        $formattedValues = array_map(function ($value) {
            return is_string($value) ? "'{$value}'" : $value;
        }, $values);

        $valueList = '['.implode(', ', $formattedValues).']';

        return "NOT $alias.$column IN $valueList";
    }

    /**
     * Build a date where clause.
     */
    protected function buildDateWhereClause(array $where, string $alias, ?string $column, string $operator): ?string
    {
        if (! isset($where['value'])) {
            return null;
        }

        $dateValue = $this->formatDateValue($where['value']);
        $operator = $this->getCypherOperator($operator);

        // For date comparisons in Neo4j with datetime strings
        // We use substring to extract just the date part (first 10 chars: YYYY-MM-DD)
        return "substring($alias.$column, 0, 10) $operator '$dateValue'";
    }

    /**
     * Format a date value for comparison.
     */
    protected function formatDateValue($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            // Extract just the date part if it contains time
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
                return $matches[0];
            }

            try {
                return (new \DateTime($value))->format('Y-m-d');
            } catch (\Exception $e) {
                return $value; // Use as-is if parsing fails
            }
        }

        return (string) $value;
    }

    /**
     * Build an untyped where clause.
     */
    protected function buildUntypedWhereClause(array $where, string $alias): string
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = is_string($where['value']) ? "'{$where['value']}'" : $where['value'];

        return "$alias.$column $operator $value";
    }

    /**
     * Add withCount data to models by querying for each model.
     */
    protected function addWithCountToModels($models)
    {
        foreach ($models as $model) {
            foreach ($this->withCountRelations as $relationData) {
                $this->processRelationCount($model, $relationData);
            }
        }
    }

    /**
     * Process single relation count for a model.
     */
    protected function processRelationCount($model, array $relationData): void
    {
        $relationName = $relationData['relation'];
        $alias = $relationData['alias'];
        $constraints = $relationData['constraints'];
        $column = $relationData['column'] ?? '*';
        $function = $relationData['function'] ?? 'count';

        // Check if this is a nested relationship (contains a dot)
        if (strpos($relationName, '.') !== false) {
            $value = $this->countNestedRelation($model, $relationName, $constraints, $column, $function);
        } else {
            $value = $this->processSimpleRelationCount($model, $relationName, $constraints, $column, $function);
        }

        $model->setAttribute($alias, $value);
    }

    /**
     * Process count for a simple (non-nested) relation.
     */
    protected function processSimpleRelationCount($model, string $relationName, $constraints, string $column, string $function)
    {
        $relationInstance = $model->{$relationName}();

        // Apply constraints if they exist
        $this->applyCountConstraints($relationInstance, $constraints);

        // Perform the aggregate operation based on function type
        return $this->getAggregateValue($relationInstance, $function, $column);
    }

    /**
     * Apply callback constraints to a relation instance.
     */
    protected function applyCountConstraints($relationInstance, $constraints): void
    {
        if ($constraints && is_callable($constraints)) {
            $constraints($relationInstance);
        }
    }

    /**
     * Get aggregate value for a relation.
     */
    protected function getAggregateValue($relationInstance, string $function, string $column)
    {
        if ($function === 'count') {
            // For count operations, use the registry to get the appropriate count method
            return RelationHandlerRegistry::getCountValue($relationInstance);
        }

        if (in_array($function, ['sum', 'avg', 'min', 'max', 'stdev', 'stdevp', 'collect'])) {
            // For aggregates, get the query and apply the aggregate
            $query = $relationInstance->getQuery()->getQuery();

            return $query->{$function}($column) ?? 0;
        }

        return 0;
    }

    /**
     * Count nested relationships using dot notation.
     */
    protected function countNestedRelation($model, $relationPath, $constraints, $column, $function)
    {
        $segments = $this->validateNestedRelationPath($relationPath);
        if (! $segments) {
            return 0;
        }

        $relations = $this->getNestedRelationInstances($model, $segments);
        if (! $relations) {
            return 0;
        }

        return $this->executeNestedRelationCount($model, $relations);
    }

    /**
     * Validate and parse the nested relation path.
     */
    protected function validateNestedRelationPath($relationPath)
    {
        $segments = explode('.', $relationPath);

        // For now, we only support two-level nesting
        if (count($segments) !== 2) {
            return null;
        }

        return $segments;
    }

    /**
     * Get the relation instances for the nested path.
     */
    protected function getNestedRelationInstances($model, $segments)
    {
        $firstRelation = $segments[0];
        $secondRelation = $segments[1];

        $firstRelationInstance = $model->{$firstRelation}();
        if (! $firstRelationInstance) {
            return null;
        }

        $firstRelatedModel = $firstRelationInstance->getRelated()->newInstance();
        $secondRelationInstance = $firstRelatedModel->{$secondRelation}();

        return [
            'first' => $firstRelationInstance,
            'second' => $secondRelationInstance,
            'firstTable' => $firstRelationInstance->getRelated()->getTable(),
            'secondTable' => $secondRelationInstance->getRelated()->getTable(),
        ];
    }

    /**
     * Execute the count query for nested relations.
     */
    protected function executeNestedRelationCount($model, $relations)
    {
        $firstRelation = $relations['first'];
        $secondRelation = $relations['second'];

        // Only handle HasMany->HasMany for now
        if (! (RelationHandlerRegistry::supportsThroughQuery($firstRelation) &&
               RelationHandlerRegistry::supportsThroughQuery($secondRelation))) {
            return 0;
        }

        $cypher = $this->buildNestedCountCypher($model, $relations);
        $params = ['modelId' => $model->getKey()];

        return $this->extractCountFromResults(
            $model->getConnection()->select($cypher, $params)
        );
    }

    /**
     * Build the Cypher query for nested count.
     */
    protected function buildNestedCountCypher($model, $relations)
    {
        $modelTable = $model->getTable();
        $firstTable = $relations['firstTable'];
        $secondTable = $relations['secondTable'];

        $firstForeignKey = $relations['first']->getForeignKeyName();
        $firstLocalKey = $relations['first']->getLocalKeyName();
        $secondForeignKey = $relations['second']->getForeignKeyName();
        $secondLocalKey = $relations['second']->getLocalKeyName();

        return "MATCH (m:$modelTable {id: \$modelId}) ".
               "MATCH (f:$firstTable) WHERE f.$firstForeignKey = m.$firstLocalKey ".
               "MATCH (s:$secondTable) WHERE s.$secondForeignKey = f.$secondLocalKey ".
               'RETURN COUNT(s) as count';
    }

    /**
     * Extract count value from query results.
     */
    protected function extractCountFromResults($results)
    {
        if (empty($results) || ! isset($results[0])) {
            return 0;
        }

        $result = $results[0];
        if (is_array($result)) {
            return $result['count'] ?? 0;
        }

        return $result->count ?? 0;
    }

    /**
     * Add a raw where clause to the query.
     */
    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $this->query->whereRaw($sql, $bindings, $boolean);

        return $this;
    }

    /**
     * Add a raw select statement to the query.
     */
    public function selectRaw($expressions, $bindings = [])
    {
        if (is_array($expressions)) {
            foreach ($expressions as $expression) {
                $this->query->selectRaw($expression, $bindings);
            }
        } else {
            $this->query->selectRaw($expressions, $bindings);
        }

        return $this;
    }

    /**
     * Execute a custom graph pattern query.
     */
    public function joinPattern($pattern, $select = null)
    {
        // This returns a new GraphPatternBuilder for complex pattern queries
        return new GraphPatternBuilder($this->query->connection, $pattern, $select);
    }

    /**
     * Find shortest path between nodes.
     */
    public function shortestPath()
    {
        return new ShortestPathBuilder($this->query->connection);
    }

    /**
     * Query with variable length relationships.
     */
    public function variablePath()
    {
        return new VariablePathBuilder($this->query->connection);
    }

    /**
     * Complex graph pattern matching.
     */
    public function graphPattern()
    {
        return new GraphPatternBuilder($this->query->connection);
    }

    /**
     * Get a generator for the given query.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function cursor()
    {
        return $this->applyScopes()->query->cursor()->map(function ($record) {
            // Convert to array if it's an object
            $attributes = is_array($record) ? $record : (array) $record;

            return $this->model->newFromBuilder($attributes);
        });
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @param  int  $chunkSize
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazy($chunkSize = 1000)
    {
        return $this->lazyById($chunkSize);
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @param  int  $chunkSize
     * @param  string|null  $column
     * @param  string|null  $alias
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyById($chunkSize = 1000, $column = null, $alias = null)
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, ordered by the given column.
     *
     * @param  int  $chunkSize
     * @param  string|null  $column
     * @param  string|null  $alias
     * @param  bool  $descending
     * @return \Illuminate\Support\LazyCollection
     */
    protected function orderedLazyById($chunkSize = 1000, $column = null, $alias = null, $descending = false)
    {
        $column = $column ?? $this->defaultKeyName();
        $alias = $alias ?? $column;

        return LazyCollection::make(function () use ($chunkSize, $column, $alias, $descending) {
            yield from $this->lazyByIdGenerator($chunkSize, $column, $alias, $descending);
        });
    }

    /**
     * Generator for lazy loading by ID.
     */
    protected function lazyByIdGenerator(int $chunkSize, string $column, string $alias, bool $descending)
    {
        $state = $this->initializeLazyByIdState();

        while (true) {
            $this->checkIterationLimit($state['iterations']++, $state['maxIterations']);

            $results = $this->fetchLazyByIdPage($state['lastId'], $chunkSize, $column, $descending);

            if ($results->isEmpty()) {
                break;
            }

            foreach ($results as $result) {
                yield $result;
            }

            $newLastId = $this->extractLastIdFromResults($results, $alias, $column);
            if ($newLastId === null) {
                break;
            }

            if (! $this->updateLazyByIdState($state, $newLastId, $results->count(), $chunkSize)) {
                break;
            }
        }
    }

    /**
     * Initialize state for lazy by ID iteration.
     */
    protected function initializeLazyByIdState(): array
    {
        return [
            'lastId' => null,
            'iterations' => 0,
            'maxIterations' => 10000,
            'stuckCount' => 0,
            'maxStuckCount' => 3,
        ];
    }

    /**
     * Check if iteration limit has been exceeded.
     */
    protected function checkIterationLimit(int $iterations, int $maxIterations): void
    {
        if ($iterations > $maxIterations) {
            throw new \RuntimeException('Max iterations exceeded in lazyById - possible infinite loop');
        }
    }

    /**
     * Fetch a page of results for lazy by ID.
     */
    protected function fetchLazyByIdPage($lastId, int $chunkSize, string $column, bool $descending)
    {
        $clone = clone $this;

        if ($descending) {
            return $clone->forPageBeforeId($lastId, $chunkSize, $column)->get();
        }

        return $clone->forPageAfterId($lastId, $chunkSize, $column)->get();
    }

    /**
     * Extract the last ID from results.
     */
    protected function extractLastIdFromResults($results, string $alias, string $column): mixed
    {
        $lastResult = $results->last();

        if (! $lastResult) {
            return null;
        }

        // Try alias first, then column name
        if (isset($lastResult->{$alias})) {
            return $lastResult->{$alias};
        }

        if (isset($lastResult->{$column})) {
            return $lastResult->{$column};
        }

        return null;
    }

    /**
     * Update lazy by ID state with new last ID.
     */
    protected function updateLazyByIdState(array &$state, $newLastId, int $resultCount, int $chunkSize): bool
    {
        $previousLastId = $state['lastId'];

        if ($previousLastId !== null && $previousLastId === $newLastId) {
            $state['stuckCount']++;

            if ($state['stuckCount'] >= $state['maxStuckCount']) {
                // Check if we've processed all results
                if ($resultCount < $chunkSize) {
                    // This is likely the last chunk
                    return false;
                }
                throw new \RuntimeException("lazyById stuck on same ID '{$newLastId}' for {$state['stuckCount']} iterations - possible data issue");
            }
        } else {
            // Reset stuck counter if ID progressed
            $state['stuckCount'] = 0;
        }

        $state['lastId'] = $newLastId;

        return true;
    }

    /**
     * Get the default key name of the model.
     *
     * @return string
     */
    protected function defaultKeyName()
    {
        return $this->getModel()->getKeyName();
    }

    /**
     * Constrain the query to the next "page" of results after a given ID.
     *
     * @param  mixed  $lastId
     * @param  int  $perPage
     * @param  string  $column
     * @param  bool  $before
     * @return $this
     */
    public function forPageAfterId($lastId = 0, $perPage = 15, $column = 'id', $before = false)
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if (! $before) {
            $this->orderBy($column);
            if ($lastId !== null) {
                $this->where($column, '>', $lastId);
            }
        } else {
            $this->orderByDesc($column);
            if ($lastId !== null) {
                $this->where($column, '<', $lastId);
            }
        }

        return $this->limit($perPage);
    }

    /**
     * Constrain the query to the previous "page" of results before a given ID.
     *
     * @param  mixed  $lastId
     * @param  int  $perPage
     * @param  string  $column
     * @return $this
     */
    public function forPageBeforeId($lastId = 0, $perPage = 15, $column = 'id')
    {
        return $this->forPageAfterId($lastId, $perPage, $column, true);
    }

    /**
     * Force delete models (bypassing soft deletes).
     *
     * @return mixed
     */
    public function forceDelete()
    {
        // Remove soft delete scope if it exists
        if (method_exists($this, 'withTrashed')) {
            $this->withTrashed();
        }

        // Use the query builder's delete directly
        return $this->toBase()->delete();
    }

    /**
     * Get an array with all orders with a given column removed.
     *
     * @param  string  $column
     * @return array
     */
    protected function removeExistingOrdersFor($column)
    {
        return collect($this->query->orders)
            ->reject(function ($order) use ($column) {
                return isset($order['column']) && $order['column'] === $column;
            })->values()->all();
    }

    /**
     * Chunk the results of a query by comparing IDs.
     */
    public function chunkById($count, callable $callback, $column = null, $alias = null)
    {
        $column = $column ?? $this->model->getKeyName();
        $alias = $alias ?? $column;

        return $this->query->chunkById($count, function ($results) use ($callback) {
            // Convert the results to Eloquent models
            $models = $this->getModel()->hydrate($results->all());

            return $callback($models, $this);
        }, $column, $alias);
    }

    /**
     * Delete records from the database.
     *
     * @return mixed
     */
    public function delete()
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->toBase()->delete();
    }

    /**
     * Update records in the database.
     *
     * @return int
     */
    public function update(array $values)
    {
        return $this->toBase()->update($values);
    }
}
