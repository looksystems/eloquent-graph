<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Look\EloquentCypher\Handlers\PivotHandler;
use Look\EloquentCypher\Traits\CypherOperatorConverter;
use Look\EloquentCypher\Traits\SupportsNativeEdges;

class GraphBelongsToMany extends BelongsToMany
{
    use CypherOperatorConverter;
    use SupportsNativeEdges;

    protected $pivotAttributes = [];

    protected $eagerParentModels = [];

    protected ?PivotHandler $pivotHandler = null;

    public function __construct($query, Model $parent, $table, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null, $relationName = null)
    {
        parent::__construct($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    public function attach($id, array $attributes = [], $touch = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            parent::attach($id, $attributes, $touch);

            return;
        }

        // Check if we should create edges for native relationships
        if ($this->shouldCreateEdge()) {
            return $this->attachWithEdges($id, $attributes, $touch);
        }

        // Original foreign key based attachment
        $attachments = $this->normalizeAttachInput($id, $attributes);

        foreach ($attachments as $relatedId => $pivotAttributes) {
            $this->executeSingleAttachment($relatedId, $pivotAttributes);
        }

        return $this;
    }

    /**
     * Attach using native graph edges.
     */
    protected function attachWithEdges($id, array $attributes = [], $touch = true)
    {
        $attachments = $this->normalizeAttachInput($id, $attributes);
        $edgeManager = $this->getEdgeManager();
        $edgeType = $this->getEdgeType();

        foreach ($attachments as $relatedId => $pivotAttributes) {
            // Merge with default pivot values
            $allPivotAttributes = array_merge($this->pivotValues, $pivotAttributes);

            // Add timestamps if needed (check if withTimestamps was called)
            if (isset($this->pivotCreatedAt) && $this->pivotCreatedAt) {
                $allPivotAttributes['created_at'] = now()->format('Y-m-d H:i:s');
            }
            if (isset($this->pivotUpdatedAt) && $this->pivotUpdatedAt) {
                $allPivotAttributes['updated_at'] = now()->format('Y-m-d H:i:s');
            }

            // Create edge with pivot data as properties
            $result = $edgeManager->createEdge(
                $this->parent,
                $relatedId,
                $edgeType,
                $allPivotAttributes,
                $this->parent->getTable(),
                $this->related->getTable()
            );

            // Note: For BelongsToMany in Neo4j, the edge IS the relationship
            // No separate foreign key storage needed as relationships are edges
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return $this;
    }

    /**
     * Normalize the attachment input to a consistent format.
     */
    protected function normalizeAttachInput($id, array $attributes): array
    {
        if (is_array($id)) {
            // Check if it's an associative array with pivot data
            if (! empty($id) && is_array(reset($id))) {
                // Format: [id => ['pivot' => 'data'], ...]
                return $id;
            } else {
                // Simple array of IDs
                return array_fill_keys($id, $attributes);
            }
        } elseif ($id instanceof Model) {
            return [$id->getKey() => $attributes];
        } else {
            return [$id => $attributes];
        }
    }

    /**
     * Execute attachment for a single related model.
     */
    protected function executeSingleAttachment(mixed $relatedId, array $pivotAttributes): void
    {
        $connection = $this->parent->getConnection();

        $cypherQuery = $this->buildAttachmentQuery();
        $params = $this->buildAttachmentParams($relatedId, $pivotAttributes);

        if (! empty($pivotAttributes) || ! empty($this->pivotValues)) {
            $setClause = $this->buildPivotSetClause($pivotAttributes, $params);
            $cypherQuery .= $setClause;
        }

        $cypherQuery .= 'RETURN r';
        $connection->statement($cypherQuery, $params);
    }

    /**
     * Build the base attachment Cypher query.
     */
    protected function buildAttachmentQuery(): string
    {
        return "MATCH (a:{$this->parent->getTable()} {id: \$parentId}), ".
               "(b:{$this->related->getTable()} {id: \$relatedId}) ".
               "CREATE (a)-[r:{$this->getRelationshipType()}]->(b) ";
    }

    /**
     * Build parameters for attachment query.
     */
    protected function buildAttachmentParams(mixed $relatedId, array $pivotAttributes): array
    {
        return [
            'parentId' => $this->parent->getKey(),
            'relatedId' => $relatedId,
        ];
    }

    /**
     * Build SET clause for pivot attributes.
     */
    protected function buildPivotSetClause(array $pivotAttributes, array &$params): string
    {
        $allPivotAttributes = array_merge($this->pivotValues, $pivotAttributes);

        if (empty($allPivotAttributes)) {
            return '';
        }

        $setClause = [];
        foreach ($allPivotAttributes as $key => $value) {
            $value = $this->formatPivotValue($value);
            $setClause[] = "r.$key = \$$key";
            $params[$key] = $value;
        }

        return 'SET '.implode(', ', $setClause).' ';
    }

    /**
     * Format pivot value for Neo4j storage.
     */
    protected function formatPivotValue($value): mixed
    {
        return $this->getPivotHandler()->formatPivotValue($value);
    }

    public function detach($ids = null, $touch = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::detach($ids, $touch);
        }

        // Check if we should use edges
        if ($this->shouldCreateEdge()) {
            return $this->detachWithEdges($ids, $touch);
        }

        if (is_null($ids)) {
            $cypherQuery = "MATCH (a:{$this->parent->getTable()} {id: \$parentId})".
                          "-[r:{$this->getRelationshipType()}]->".
                          "(b:{$this->related->getTable()}) ".
                          'DELETE r';

            $params = ['parentId' => $this->parent->getKey()];
        } else {
            $ids = is_array($ids) ? $ids : [$ids];
            $cypherQuery = "MATCH (a:{$this->parent->getTable()} {id: \$parentId})".
                          "-[r:{$this->getRelationshipType()}]->".
                          "(b:{$this->related->getTable()}) ".
                          'WHERE b.id IN $relatedIds '.
                          'DELETE r';

            $params = [
                'parentId' => $this->parent->getKey(),
                'relatedIds' => $ids,
            ];
        }

        $connection->statement($cypherQuery, $params);

        return count($ids ?? []);
    }

    /**
     * Detach using native edges.
     */
    protected function detachWithEdges($ids = null, $touch = true)
    {
        $edgeManager = $this->getEdgeManager();
        $edgeType = $this->getEdgeType();
        $detached = 0;

        if (is_null($ids)) {
            // Delete all edges from parent
            $detached = $edgeManager->deleteAllEdgesFromNode(
                $this->parent->getKey(),
                $this->parent->getTable(),
                $edgeType,
                'out'
            );
        } else {
            $ids = is_array($ids) ? $ids : [$ids];
            foreach ($ids as $id) {
                $deleted = $edgeManager->deleteEdge(
                    $this->parent->getKey(),
                    $id,
                    $edgeType,
                    $this->parent->getTable(),
                    $this->related->getTable()
                );
                if ($deleted) {
                    $detached++;
                }
            }
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return is_null($ids) ? $detached : count($ids);
    }

    public function updateExistingPivot($id, array $attributes = [], $touch = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::updateExistingPivot($id, $attributes, $touch);
        }

        // Check if we should use edges
        if ($this->shouldCreateEdge()) {
            return $this->updateExistingPivotWithEdges($id, $attributes, $touch);
        }

        $cypherQuery = "MATCH (a:{$this->parent->getTable()} {id: \$parentId})".
                      "-[r:{$this->getRelationshipType()}]->".
                      "(b:{$this->related->getTable()} {id: \$relatedId}) ";

        $params = [
            'parentId' => $this->parent->getKey(),
            'relatedId' => $id,
        ];

        if (! empty($attributes)) {
            $setClause = [];
            foreach ($attributes as $key => $value) {
                // Convert dates to strings for Neo4j storage
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                } elseif ($value instanceof \Carbon\Carbon) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $setClause[] = "r.$key = \$$key";
                $params[$key] = $value;
            }
            $cypherQuery .= 'SET '.implode(', ', $setClause).' ';
        }

        $cypherQuery .= 'RETURN r';

        return $connection->statement($cypherQuery, $params);
    }

    /**
     * Update existing pivot using edges.
     */
    protected function updateExistingPivotWithEdges($id, array $attributes = [], $touch = true)
    {
        $edgeManager = $this->getEdgeManager();
        $edgeType = $this->getEdgeType();

        // Add updated_at if using timestamps
        if (isset($this->pivotUpdatedAt) && $this->pivotUpdatedAt) {
            $attributes['updated_at'] = now()->format('Y-m-d H:i:s');
        }

        // Update edge properties using composite ID
        $edgeId = [
            'from' => $this->parent->getKey(),
            'to' => $id,
            'type' => $edgeType,
        ];
        $result = $edgeManager->updateEdgeProperties($edgeId, $attributes);

        if ($touch) {
            $this->touchIfTouching();
        }

        return ! empty($result);
    }

    public function sync($ids, $detaching = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::sync($ids, $detaching);
        }

        // Get existing related IDs first
        $existing = $this->get()->pluck($this->related->getKeyName())->toArray();

        // Normalize the input
        $records = [];
        if (is_array($ids)) {
            foreach ($ids as $key => $value) {
                if (is_array($value)) {
                    $records[$key] = $value;
                } else {
                    $records[$value] = [];
                }
            }
        } else {
            $records = [$ids => []];
        }

        $toAttach = array_diff(array_keys($records), $existing);
        $toDetach = $detaching ? array_diff($existing, array_keys($records)) : [];

        // Detach records
        if (! empty($toDetach)) {
            $this->detach(array_values($toDetach));
        }

        // Attach new records
        foreach ($toAttach as $id) {
            $this->attach($id, $records[$id] ?? []);
        }

        return [
            'attached' => array_values($toAttach),
            'detached' => array_values($toDetach),
            'updated' => [],
        ];
    }

    public function toggle($ids, $touch = true)
    {
        $connection = $this->parent->getConnection();

        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::toggle($ids, $touch);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        // Get existing related IDs using our Neo4j implementation
        $existingModels = $this->get();
        $existing = $existingModels->pluck($this->related->getKeyName())->toArray();

        $detach = array_intersect($ids, $existing);
        $attach = array_diff($ids, $existing);

        if (! empty($detach)) {
            $this->detach($detach);
        }

        if (! empty($attach)) {
            $this->attach($attach);
        }

        return [
            'attached' => $attach,
            'detached' => $detach,
        ];
    }

    /**
     * Get the sum of a column on the pivot table.
     *
     * @param  string  $column
     * @return mixed
     */
    public function sum($column)
    {
        if (strpos($column, 'pivot.') === 0) {
            $pivotColumn = substr($column, 6); // Remove 'pivot.' prefix

            return $this->getPivotAggregate('sum', $pivotColumn);
        }

        return $this->query->sum($column);
    }

    /**
     * Get the average of a column on the pivot table.
     *
     * @param  string  $column
     * @return mixed
     */
    public function avg($column)
    {
        if (strpos($column, 'pivot.') === 0) {
            $pivotColumn = substr($column, 6); // Remove 'pivot.' prefix

            return $this->getPivotAggregate('avg', $pivotColumn);
        }

        return $this->query->avg($column);
    }

    /**
     * Get the minimum value of a column on the pivot table.
     *
     * @param  string  $column
     * @return mixed
     */
    public function min($column)
    {
        if (strpos($column, 'pivot.') === 0) {
            $pivotColumn = substr($column, 6); // Remove 'pivot.' prefix

            return $this->getPivotAggregate('min', $pivotColumn);
        }

        return $this->query->min($column);
    }

    /**
     * Get the maximum value of a column on the pivot table.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max($column)
    {
        if (strpos($column, 'pivot.') === 0) {
            $pivotColumn = substr($column, 6); // Remove 'pivot.' prefix

            return $this->getPivotAggregate('max', $pivotColumn);
        }

        return $this->query->max($column);
    }

    /**
     * Get a pivot table aggregate value.
     *
     * @param  string  $function
     * @param  string  $column
     * @return mixed
     */
    protected function getPivotAggregate($function, $column)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return 0;
        }

        $parentKey = $this->parent->getKey();
        if (! $parentKey) {
            return 0;
        }

        $cypher = $this->buildPivotAggregateQuery($function, $column);
        $params = $this->buildPivotAggregateParams($parentKey);

        $result = $connection->select($cypher, $params);

        return $this->extractAggregateValue($result);
    }

    /**
     * Build the Cypher query for pivot aggregate.
     */
    protected function buildPivotAggregateQuery(string $function, string $column): string
    {
        $cypher = $this->buildPivotMatchClause();
        $cypher .= $this->buildPivotAggregateWhereClause();
        $cypher .= "RETURN {$function}(rel.{$column}) as aggregate";

        return $cypher;
    }

    /**
     * Build the MATCH clause for pivot aggregate.
     */
    protected function buildPivotMatchClause(): string
    {
        return "MATCH (parent:{$this->parent->getTable()} {id: \$parentId})-".
               "[rel:{$this->getRelationshipType()}]->".
               "(related:{$this->related->getTable()}) ";
    }

    /**
     * Build WHERE clause for pivot aggregate.
     */
    protected function buildPivotAggregateWhereClause(): string
    {
        if (empty($this->pivotWheres)) {
            return '';
        }

        $whereConditions = [];
        foreach ($this->pivotWheres as $where) {
            $condition = $this->buildSinglePivotWhereCondition($where);
            if ($condition) {
                $whereConditions[] = $condition;
            }
        }

        return ! empty($whereConditions)
            ? 'WHERE '.implode(' AND ', $whereConditions).' '
            : '';
    }

    /**
     * Build a single pivot WHERE condition for aggregate.
     */
    protected function buildSinglePivotWhereCondition(array $where): string
    {
        $pivotColumn = 'rel.'.$where['column'];
        $operator = $where['operator'] ?? '=';

        if ($operator === 'is' || $operator === 'is not') {
            return $operator === 'is'
                ? $pivotColumn.' IS NULL'
                : $pivotColumn.' IS NOT NULL';
        }

        if (isset($where['values'])) {
            $paramName = $this->generatePivotParamName($where['column']);

            return $pivotColumn.' IN $'.$paramName;
        }

        $paramName = $this->generatePivotParamName($where['column']);
        $cypherOperator = $this->getCypherOperator($operator);

        return $pivotColumn.' '.$cypherOperator.' $'.$paramName;
    }

    /**
     * Generate parameter name for pivot column.
     */
    protected function generatePivotParamName(string $column): string
    {
        return $this->getPivotHandler()->generatePivotParamName($column);
    }

    /**
     * Build parameters for pivot aggregate query.
     */
    protected function buildPivotAggregateParams(mixed $parentKey): array
    {
        $params = ['parentId' => $parentKey];

        foreach ($this->pivotWheres as $where) {
            $operator = $where['operator'] ?? '=';

            if ($operator !== 'is' && $operator !== 'is not') {
                $paramName = $this->generatePivotParamName($where['column']);
                $params[$paramName] = $where['values'] ?? $where['value'] ?? null;
            }
        }

        return $params;
    }

    /**
     * Extract aggregate value from query result.
     */
    protected function extractAggregateValue(array $result): mixed
    {
        if (! empty($result) && isset($result[0])) {
            // Handle both array and object formats
            if (is_array($result[0])) {
                return $result[0]['aggregate'] ?? 0;
            } else {
                return $result[0]->aggregate ?? 0;
            }
        }

        return 0;
    }

    public function get($columns = ['*'])
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::get($columns);
        }

        // Make sure we have the parent key
        if (! $this->parent->getKey()) {
            return new Collection;
        }

        // Build and execute the query
        $queryData = $this->buildRelationshipQuery();
        $results = $connection->select($queryData['query'], $queryData['params']);

        // Reset pagination for next query
        $this->pageLimit = null;
        $this->pageOffset = null;

        // Transform results into models
        return $this->hydrateModelsFromResults($results);
    }

    /**
     * Build the complete Cypher query for the relationship.
     */
    protected function buildRelationshipQuery(): array
    {
        $params = ['parentId' => $this->parent->getKey()];

        // Start with MATCH clause
        $cypherQuery = $this->buildMatchClause();

        // Add WHERE conditions from pivot
        $whereResult = $this->buildPivotWhereClause($params);
        if ($whereResult['clause']) {
            $cypherQuery .= $whereResult['clause'];
            $params = $whereResult['params'];
        }

        // Add RETURN clause
        $cypherQuery .= 'RETURN b, r';

        // Add ORDER BY clause
        $cypherQuery .= $this->buildOrderByClause();

        // Add pagination
        $cypherQuery .= $this->buildPaginationClause();

        return ['query' => $cypherQuery, 'params' => $params];
    }

    /**
     * Build the MATCH clause for the relationship query.
     */
    protected function buildMatchClause(): string
    {
        return "MATCH (a:{$this->parent->getTable()} {id: \$parentId})".
               "-[r:{$this->getRelationshipType()}]->".
               "(b:{$this->related->getTable()}) ";
    }

    /**
     * Build WHERE clause from pivot conditions.
     */
    protected function buildPivotWhereClause(array &$params): array
    {
        if (empty($this->pivotWheres)) {
            return ['clause' => '', 'params' => $params];
        }

        $whereConditions = [];
        foreach ($this->pivotWheres as $where) {
            $condition = $this->buildSinglePivotCondition($where, $params);
            if ($condition) {
                $whereConditions[] = $condition;
            }
        }

        $clause = ! empty($whereConditions)
            ? 'WHERE '.implode(' AND ', $whereConditions).' '
            : '';

        return ['clause' => $clause, 'params' => $params];
    }

    /**
     * Build a single pivot WHERE condition.
     */
    protected function buildSinglePivotCondition(array $where, array &$params): string
    {
        $column = 'r.'.$where['column'];
        $operator = $where['operator'] ?? '=';

        if ($operator === 'is' || $operator === 'is not') {
            return $this->buildNullCondition($column, $operator);
        }

        if ($operator === 'in' || $operator === 'not in') {
            return $this->buildInCondition($column, $operator, $where, $params);
        }

        return $this->buildBasicCondition($column, $operator, $where, $params);
    }

    /**
     * Build NULL/NOT NULL condition.
     */
    protected function buildNullCondition(string $column, string $operator): string
    {
        return $operator === 'is'
            ? $column.' IS NULL'
            : $column.' IS NOT NULL';
    }

    /**
     * Build IN/NOT IN condition.
     */
    protected function buildInCondition(string $column, string $operator, array $where, array &$params): string
    {
        $paramName = 'pivot_'.str_replace('.', '_', $where['column']);
        $params[$paramName] = $where['values'];

        return $column.' '.strtoupper($operator).' $'.$paramName;
    }

    /**
     * Build basic comparison condition.
     */
    protected function buildBasicCondition(string $column, string $operator, array $where, array &$params): string
    {
        $paramName = 'pivot_'.str_replace('.', '_', $where['column']);
        $cypherOperator = $this->getCypherOperator($operator);
        $params[$paramName] = $where['value'];

        return $column.' '.$cypherOperator.' $'.$paramName;
    }

    /**
     * Build ORDER BY clause from order specifications.
     */
    protected function buildOrderByClause(): string
    {
        $orderClauses = [];

        // Apply regular order by (on related model)
        if (! empty($this->orderByClauses)) {
            foreach ($this->orderByClauses as $order) {
                $orderClauses[] = 'b.'.$order['column'].' '.strtoupper($order['direction']);
            }
        }

        // Apply pivot order by
        if (! empty($this->pivotOrderBy)) {
            foreach ($this->pivotOrderBy as $order) {
                $orderClauses[] = 'r.'.$order['column'].' '.strtoupper($order['direction']);
            }
        }

        return ! empty($orderClauses)
            ? ' ORDER BY '.implode(', ', $orderClauses)
            : '';
    }

    /**
     * Build pagination clause (SKIP/LIMIT).
     */
    protected function buildPaginationClause(): string
    {
        $clause = '';

        if ($this->pageOffset !== null) {
            $clause .= ' SKIP '.(int) $this->pageOffset;
        }
        if ($this->pageLimit !== null) {
            $clause .= ' LIMIT '.(int) $this->pageLimit;
        }

        return $clause;
    }

    /**
     * Hydrate models from query results.
     */
    protected function hydrateModelsFromResults(array $results): Collection
    {
        $models = new Collection;

        foreach ($results as $result) {
            $model = $this->hydrateModel($result);
            $this->attachPivotData($model, $result);
            $models->push($model);
        }

        return $models;
    }

    /**
     * Hydrate a single model from result data.
     */
    protected function hydrateModel(array $result): Model
    {
        $model = $this->related->newInstance();
        $model->setRawAttributes($result['b']);
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }

    /**
     * Attach pivot data to the model.
     */
    protected function attachPivotData(Model $model, array $result): void
    {
        if (empty($result['r'])) {
            return;
        }

        $pivotData = is_array($result['r']) ? $result['r'] : [];
        $pivotData = $this->mergePivotDefaults($pivotData);

        // Create appropriate pivot object based on storage strategy
        if ($this->shouldCreateEdge()) {
            $pivot = \Look\EloquentCypher\EdgePivot::fromEdge($pivotData, $this->parent, $model, $this->table, true);
            $pivot->setEdgeType($this->getEdgeType());
        } else {
            $pivot = $this->createPivotObject($pivotData);
        }

        $accessorName = $this->accessor ?: 'pivot';
        $model->setRelation($accessorName, $pivot);
        $model->$accessorName = $pivot;
    }

    /**
     * Merge default pivot values with actual data.
     */
    protected function mergePivotDefaults(array $pivotData): array
    {
        foreach ($this->pivotValues as $key => $value) {
            if (! isset($pivotData[$key])) {
                $pivotData[$key] = $value;
            }
        }

        return $pivotData;
    }

    /**
     * Create a pivot object from data array.
     */
    protected function createPivotObject(array $pivotData): \stdClass
    {
        $pivot = new \stdClass;
        foreach ($pivotData as $key => $value) {
            $pivot->$key = $value;
        }

        return $pivot;
    }

    public function addEagerConstraints(array $models)
    {
        // For Neo4j, we'll handle eager loading differently
        // Store the parent models for later use
        $this->eagerParentModels = $models;
    }

    public function getEager()
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::getEager();
        }

        $parentIds = $this->extractEagerParentIds();
        if (empty($parentIds)) {
            return new Collection;
        }

        $results = $this->executeEagerQuery($parentIds);
        $dictionary = $this->buildEagerDictionary($results);
        $this->assignEagerResultsToParents($dictionary);

        return new Collection;
    }

    /**
     * Extract parent IDs from eager parent models.
     */
    protected function extractEagerParentIds(): array
    {
        $parentIds = [];
        foreach ($this->eagerParentModels as $model) {
            $parentIds[] = $model->getKey();
        }

        return $parentIds;
    }

    /**
     * Execute eager loading query.
     */
    protected function executeEagerQuery(array $parentIds): array
    {
        $connection = $this->parent->getConnection();

        $cypherQuery = $this->buildEagerLoadingQuery();
        $params = ['parentIds' => $parentIds];

        return $connection->select($cypherQuery, $params);
    }

    /**
     * Build the Cypher query for eager loading.
     */
    protected function buildEagerLoadingQuery(): string
    {
        return "MATCH (a:{$this->parent->getTable()})".
               "-[r:{$this->getRelationshipType()}]->".
               "(b:{$this->related->getTable()}) ".
               'WHERE a.id IN $parentIds '.
               'RETURN a.id as parent_id, b, r';
    }

    /**
     * Build dictionary of parent_id => related models.
     */
    protected function buildEagerDictionary(array $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $model = $this->hydrateEagerModel($result);
            $dictionary[$result['parent_id']][] = $model;
        }

        return $dictionary;
    }

    /**
     * Hydrate a single eager loaded model.
     */
    protected function hydrateEagerModel(array $result): Model
    {
        $model = $this->related->newInstance();
        $model->setRawAttributes($result['b']);
        $model->exists = true;
        $model->syncOriginal();

        $this->attachPivotData($model, $result);

        return $model;
    }

    /**
     * Assign eager loaded results to parent models.
     */
    protected function assignEagerResultsToParents(array $dictionary): void
    {
        foreach ($this->eagerParentModels as $parentModel) {
            $key = $parentModel->getKey();
            $relatedModels = $dictionary[$key] ?? [];

            $parentModel->setRelation(
                $this->relationName,
                $this->related->newCollection($relatedModels)
            );
        }
    }

    public function match(array $models, Collection $results, $relation)
    {
        // Neo4j handles this differently in getEager()
        return $models;
    }

    protected function buildDictionary(Collection $results)
    {
        // Not used for Neo4j
        return [];
    }

    /**
     * Get the pivot handler instance.
     */
    protected function getPivotHandler(): PivotHandler
    {
        if (! $this->pivotHandler) {
            $this->pivotHandler = new PivotHandler;
        }

        return $this->pivotHandler;
    }

    public function getRelationshipType()
    {
        $parentTable = $this->parent->getTable();
        $relatedTable = $this->related->getTable();

        return strtoupper($parentTable).'_'.strtoupper($relatedTable);
    }

    public function getResults()
    {
        return $this->get();
    }

    /**
     * Get a generator for the given query.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function cursor()
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::cursor();
        }

        // For BelongsToMany relationships, we need to use lazy() instead
        // since we have custom query building that doesn't work with standard cursor
        return $this->lazy(1);
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @param  int  $chunkSize
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazy($chunkSize = 1000)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::lazy($chunkSize);
        }

        return \Illuminate\Support\LazyCollection::make(function () use ($chunkSize) {
            $offset = 0;

            while (true) {
                // Set pagination for this chunk
                $this->pageLimit = $chunkSize;
                $this->pageOffset = $offset;

                // Get the chunk
                $chunk = $this->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $item) {
                    yield $item;
                }

                // If we got less than chunkSize, we're done
                if ($chunk->count() < $chunkSize) {
                    break;
                }

                $offset += $chunkSize;
            }
        });
    }

    public function count()
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::count();
        }

        $cypherQuery = "MATCH (a:{$this->parent->getTable()} {id: \$parentId})".
                      "-[r:{$this->getRelationshipType()}]->".
                      "(b:{$this->related->getTable()}) ".
                      'RETURN COUNT(b) as count';

        $params = ['parentId' => $this->parent->getKey()];
        $results = $connection->select($cypherQuery, $params);

        return ! empty($results) ? $results[0]['count'] : 0;
    }

    /**
     * Get all of the IDs from the related models.
     * Override to use our Neo4j implementation.
     */
    public function allRelatedIds()
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::allRelatedIds();
        }

        return $this->get()->pluck($this->related->getKeyName());
    }

    /**
     * Override pluck to use our Neo4j implementation
     */
    public function pluck($column, $key = null)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::pluck($column, $key);
        }

        return $this->get()->pluck($column, $key);
    }

    /**
     * Set a where clause for a pivot table column.
     */
    public function wherePivot($column, $operator = null, $value = null, $boolean = 'and')
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::wherePivot($column, $operator, $value, $boolean);
        }

        // If only two arguments are passed, the second is the value
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Add the where condition to be used in the Cypher query
        $this->pivotWheres[] = compact('column', 'operator', 'value', 'boolean');

        // For now, we'll apply the filter in the get() method
        return $this;
    }

    /**
     * Set a "where in" clause for a pivot table column.
     */
    public function wherePivotIn($column, $values, $boolean = 'and', $not = false)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::wherePivotIn($column, $values, $boolean, $not);
        }

        $operator = $not ? 'not in' : 'in';
        $this->pivotWheres[] = compact('column', 'operator', 'values', 'boolean');

        return $this;
    }

    /**
     * Set a where clause for a pivot table column to be null.
     */
    public function wherePivotNull($column, $boolean = 'and', $not = false)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::wherePivotNull($column, $boolean, $not);
        }

        $operator = $not ? 'is not' : 'is';
        $this->pivotWheres[] = ['column' => $column, 'operator' => $operator, 'value' => null, 'boolean' => $boolean];

        return $this;
    }

    /**
     * Set an "order by" clause for a pivot table column.
     */
    public function orderByPivot($column, $direction = 'asc')
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::orderByPivot($column, $direction);
        }

        $this->pivotOrderBy[] = compact('column', 'direction');

        return $this;
    }

    /**
     * Specify that the pivot table has pivot values.
     */
    public function withPivotValue($column, $value = null)
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->withPivotValue($key, $val);
            }

            return $this;
        }

        $this->pivotValues[$column] = $value;

        return $this;
    }

    /**
     * Alias to set the accessor name for the pivot.
     */
    public function as($accessor)
    {
        $this->accessor = $accessor;

        return $this;
    }

    /**
     * Add an "order by" clause to the query.
     */
    public function orderBy($column, $direction = 'asc')
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::orderBy($column, $direction);
        }

        $this->orderByClauses[] = compact('column', 'direction');

        return $this;
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk($count, callable $callback)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::chunk($count, $callback);
        }

        $page = 1;

        do {
            // Build and execute query with limit and offset
            $results = $this->forPage($page, $count)->get();

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            // Call the callback with the results
            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * Set the limit and offset for a given page.
     */
    public function forPage($page, $perPage = 15)
    {
        $this->pageLimit = $perPage;
        $this->pageOffset = ($page - 1) * $perPage;

        return $this;
    }

    protected $pivotWheres = [];

    protected $pivotOrderBy = [];

    protected $pivotValues = [];

    protected $accessor = 'pivot';

    protected $orderByClauses = [];

    protected $pageLimit = null;

    protected $pageOffset = null;

    /**
     * Get the pivot where conditions.
     * Accessor method to replace reflection usage.
     *
     * @return array
     */
    public function getPivotWheres()
    {
        return $this->pivotWheres;
    }

    /**
     * Get the parent model for edge operations.
     *
     * @return Model
     */
    protected function getParentForEdge()
    {
        return $this->parent;
    }

    /**
     * Get the relationship name for edge operations.
     */
    public function getRelationName(): ?string
    {
        return $this->relationName;
    }

    /**
     * Generate the default edge type for BelongsToMany.
     * Overrides the trait method to use the existing convention.
     */
    protected function generateDefaultEdgeType(): string
    {
        // Use the existing getRelationshipType method for consistency
        return $this->getRelationshipType();
    }
}
