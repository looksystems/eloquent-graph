<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Look\EloquentCypher\Traits\CypherOperatorConverter;

/**
 * MorphToMany Relationship for Neo4j.
 *
 * ARCHITECTURAL NOTE: This implementation uses foreign key storage
 * (morph_id + morph_type as node properties) rather than native Neo4j edges.
 *
 * This design decision maintains 100% Eloquent API compatibility and provides
 * better performance with compound indexes compared to edge property filtering.
 *
 * Polymorphic many-to-many relationships store their pivot data as nodes
 * with foreign key properties, allowing full polymorphic type support.
 *
 * For graph traversal use cases, query via foreign key matching:
 * WHERE pivot.{morph}_id = parent.id AND pivot.{morph}_type = $parentClass
 *
 * See README.md "Polymorphic Relationships & Architecture" for full rationale.
 */
class GraphMorphToMany extends MorphToMany
{
    use CypherOperatorConverter;

    protected $pivotAttributes = [];

    public function attach($id, array $attributes = [], $touch = true)
    {
        $attachments = $this->normalizeAttachInput($id, $attributes);

        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            parent::attach($id, $attributes, $touch);

            return;
        }

        foreach ($attachments as $relatedId => $pivotAttributes) {
            $this->executePivotAttachment($connection, $relatedId, $pivotAttributes);
        }

        return $this;
    }

    /**
     * Normalize attachment input to a consistent format.
     */
    protected function normalizeAttachInput($id, array $attributes): array
    {
        if (is_array($id)) {
            // Check if it's an associative array with pivot data
            if (! empty($id) && is_array(reset($id))) {
                // Format: [id => ['pivot' => 'data'], ...]
                return $id;
            }

            // Simple array of IDs
            return array_fill_keys($id, $attributes);
        }

        if ($id instanceof Model) {
            return [$id->getKey() => $attributes];
        }

        return [$id => $attributes];
    }

    /**
     * Execute the pivot attachment for a single related model.
     */
    protected function executePivotAttachment($connection, $relatedId, array $pivotAttributes): void
    {
        $params = $this->prepareAttachParams($relatedId, $pivotAttributes);
        $cypherQuery = $this->buildAttachQuery($params);

        $connection->statement($cypherQuery, $params);
    }

    /**
     * Build the Cypher query for attaching a pivot.
     */
    protected function buildAttachQuery(array $params): string
    {
        $pivotTable = $this->table;
        $setClause = $this->buildAttachSetClause($params);

        return "CREATE (n:$pivotTable) SET $setClause RETURN n";
    }

    /**
     * Build the SET clause for attachment.
     */
    protected function buildAttachSetClause(array $params): string
    {
        $clauses = [];
        foreach (array_keys($params) as $key) {
            $paramName = str_replace(['foreignId', 'relatedId', 'morphType'],
                [$this->foreignPivotKey, $this->relatedPivotKey, $this->morphType],
                $key);
            if (in_array($key, ['foreignId', 'relatedId', 'morphType'])) {
                $clauses[] = "n.$paramName = \$$key";
            } else {
                $clauses[] = "n.$key = \$$key";
            }
        }

        return implode(', ', $clauses);
    }

    /**
     * Prepare parameters for attachment query.
     */
    protected function prepareAttachParams($relatedId, array $pivotAttributes): array
    {
        $params = [
            'foreignId' => $this->parent->getKey(),
            'relatedId' => $relatedId,
            'morphType' => $this->morphClass,
        ];

        // Add any additional pivot attributes
        $allPivotAttributes = array_merge($this->pivotValues, $pivotAttributes);
        foreach ($allPivotAttributes as $key => $value) {
            $params[$key] = $this->formatPivotValue($value);
        }

        return $params;
    }

    /**
     * Format a pivot value for storage.
     */
    protected function formatPivotValue($value): mixed
    {
        if ($value instanceof \DateTimeInterface || $value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    public function detach($ids = null, $touch = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::detach($ids, $touch);
        }

        $cypherQuery = $this->buildDetachQuery($ids);
        $params = $this->prepareDetachParams($ids);

        $result = $connection->affectingStatement($cypherQuery, $params);

        return $result;
    }

    /**
     * Build the Cypher query for detaching pivots.
     */
    protected function buildDetachQuery($ids = null): string
    {
        $pivotTable = $this->table;
        $whereClause = $this->buildDetachWhereClause($ids);

        return "MATCH (n:$pivotTable) WHERE $whereClause DETACH DELETE n";
    }

    /**
     * Build the WHERE clause for detachment.
     */
    protected function buildDetachWhereClause($ids = null): string
    {
        $conditions = [
            "n.{$this->foreignPivotKey} = \$foreignId",
            "n.{$this->morphType} = \$morphType",
        ];

        if (! is_null($ids)) {
            $conditions[] = "n.{$this->relatedPivotKey} IN \$relatedIds";
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Prepare parameters for detachment query.
     */
    protected function prepareDetachParams($ids = null): array
    {
        $params = [
            'foreignId' => $this->parent->getKey(),
            'morphType' => $this->morphClass,
        ];

        if (! is_null($ids)) {
            // Ensure we have an indexed array for Neo4j
            $params['relatedIds'] = array_values(is_array($ids) ? $ids : [$ids]);
        }

        return $params;
    }

    public function updateExistingPivot($id, array $attributes = [], $touch = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::updateExistingPivot($id, $attributes, $touch);
        }

        $pivotTable = $this->table;

        $cypherQuery = "MATCH (n:$pivotTable) WHERE ".
                      "n.{$this->foreignPivotKey} = \$foreignId AND ".
                      "n.{$this->relatedPivotKey} = \$relatedId AND ".
                      "n.{$this->morphType} = \$morphType ";

        $params = [
            'foreignId' => $this->parent->getKey(),
            'relatedId' => $id,
            'morphType' => $this->morphClass,
        ];

        if (! empty($attributes)) {
            $setClause = [];
            foreach ($attributes as $key => $value) {
                // Convert dates to strings for Neo4j storage
                if ($value instanceof \DateTimeInterface || $value instanceof \Carbon\Carbon) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $setClause[] = "n.$key = \$$key";
                $params[$key] = $value;
            }
            $cypherQuery .= 'SET '.implode(', ', $setClause).' ';
        }

        $cypherQuery .= 'RETURN n';

        return $connection->statement($cypherQuery, $params);
    }

    public function sync($ids, $detaching = true)
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::sync($ids, $detaching);
        }

        $current = $this->getCurrentAttachedIds();
        $syncList = $this->prepareSyncList($ids);

        return $this->processSyncChanges($current, $syncList, $detaching);
    }

    /**
     * Get the IDs of currently attached related models.
     */
    protected function getCurrentAttachedIds(): array
    {
        return $this->pluck($this->relatedKey)->all();
    }

    /**
     * Prepare the sync list from the given IDs.
     */
    protected function prepareSyncList($ids): array
    {
        return $this->formatRecordsList($ids);
    }

    /**
     * Process sync changes (attach, detach, update).
     */
    protected function processSyncChanges(array $current, array $syncList, bool $detaching): array
    {
        $changes = ['attached' => [], 'detached' => [], 'updated' => []];
        $syncIds = array_keys($syncList);

        // Handle detaching
        if ($detaching) {
            $changes['detached'] = $this->processSyncDetachments($current, $syncIds);
        }

        // Handle attaching
        $changes['attached'] = $this->processSyncAttachments($current, $syncIds, $syncList);

        // Handle updating
        $changes['updated'] = $this->processSyncUpdates($current, $syncIds, $syncList);

        return $changes;
    }

    /**
     * Process detachments during sync.
     */
    protected function processSyncDetachments(array $current, array $syncIds): array
    {
        $detachIds = array_diff($current, $syncIds);
        if (count($detachIds) > 0) {
            $this->detach($detachIds);

            return $detachIds;
        }

        return [];
    }

    /**
     * Process attachments during sync.
     */
    protected function processSyncAttachments(array $current, array $syncIds, array $syncList): array
    {
        $attached = [];
        $attachIds = array_diff($syncIds, $current);

        foreach ($attachIds as $id) {
            $this->attach($id, $syncList[$id] ?? []);
            $attached[] = $id;
        }

        return $attached;
    }

    /**
     * Process updates during sync.
     */
    protected function processSyncUpdates(array $current, array $syncIds, array $syncList): array
    {
        $updated = [];
        $updateIds = array_intersect($current, $syncIds);

        foreach ($updateIds as $id) {
            if (! empty($syncList[$id])) {
                $this->updateExistingPivot($id, $syncList[$id]);
                $updated[] = $id;
            }
        }

        return $updated;
    }

    public function toggle($ids, $touch = true)
    {
        $connection = $this->parent->getConnection();

        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::toggle($ids, $touch);
        }

        $ids = $this->parseIds($ids);

        // Get existing related IDs
        $existing = $this->pluck($this->relatedKey)->toArray();

        $detach = array_intersect($ids, $existing);
        $attach = array_diff($ids, $existing);

        if (! empty($detach)) {
            $this->detach($detach, $touch);
        }

        if (! empty($attach)) {
            $this->attach($attach, [], $touch);
        }

        return [
            'attached' => $attach,
            'detached' => $detach,
        ];
    }

    /**
     * Format the sync / toggle record list.
     */
    protected function formatRecordsList($records): array
    {
        if (! is_array($records)) {
            return [$records => []];
        }

        $formatted = [];
        foreach ($records as $key => $value) {
            if (is_array($value)) {
                $formatted[$key] = $value;
            } else {
                $formatted[$value] = [];
            }
        }

        return $formatted;
    }

    /**
     * Parse the given IDs.
     */
    protected function parseIds($ids): array
    {
        if ($ids instanceof Model) {
            return [$ids->getKey()];
        }

        if (! is_array($ids)) {
            return [$ids];
        }

        $result = [];
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $result[] = $key;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     */
    protected function buildDictionary($results)
    {
        $dictionary = [];

        foreach ($results as $result) {
            // For morph relations, we group by the foreign key
            $key = $result->pivot->{$this->foreignPivotKey};

            if (! isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }

            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Count the results of the relationship.
     */
    public function count($columns = '*')
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::count($columns);
        }

        $pivotTable = $this->table;

        $cypherQuery = "MATCH (pivot:$pivotTable) ".
                      "WHERE pivot.{$this->foreignPivotKey} = \$foreignId ".
                      "AND pivot.{$this->morphType} = \$morphType ".
                      'RETURN count(pivot) as aggregate';

        $params = [
            'foreignId' => $this->parent->getKey(),
            'morphType' => $this->morphClass,
        ];

        $results = $connection->select($cypherQuery, $params);

        return $results[0]['aggregate'] ?? $results[0]->aggregate ?? 0;
    }

    /**
     * Execute the query and get the results.
     */
    public function get($columns = ['*'])
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::get($columns);
        }

        $cypherQuery = $this->buildRelatedQuery();
        $params = $this->prepareRelatedQueryParams();

        [$cypherQuery, $params] = $this->applyWhereConditions($cypherQuery, $params);
        $cypherQuery .= ' RETURN related, pivot';

        $results = $connection->select($cypherQuery, $params);

        return $this->hydrateRelatedModels($results);
    }

    /**
     * Build the Cypher query for retrieving related models.
     */
    protected function buildRelatedQuery(): string
    {
        $pivotTable = $this->table;
        $relatedTable = $this->related->getTable();

        return "MATCH (pivot:$pivotTable) ".
               "WHERE pivot.{$this->foreignPivotKey} = \$foreignId ".
               "AND pivot.{$this->morphType} = \$morphType ".
               "MATCH (related:$relatedTable {id: pivot.{$this->relatedPivotKey}}) ";
    }

    /**
     * Prepare parameters for the related query.
     */
    protected function prepareRelatedQueryParams(): array
    {
        return [
            'foreignId' => $this->parent->getKey(),
            'morphType' => $this->morphClass,
        ];
    }

    /**
     * Apply WHERE conditions to the query.
     */
    protected function applyWhereConditions(string $cypherQuery, array $params): array
    {
        $queryBuilder = $this->getQuery();
        $wheres = $queryBuilder->getQuery()->wheres ?? [];

        if (empty($wheres)) {
            return [$cypherQuery, $params];
        }

        $whereConditions = [];
        foreach ($wheres as $where) {
            if ($where['type'] === 'Basic' && ! $this->isPivotCondition($where)) {
                [$condition, $param] = $this->processWhereCondition($where);
                if ($condition) {
                    $whereConditions[] = $condition;
                    if ($param) {
                        $params = array_merge($params, $param);
                    }
                }
            }
        }

        if (! empty($whereConditions)) {
            $cypherQuery .= ' WHERE '.implode(' AND ', $whereConditions);
        }

        return [$cypherQuery, $params];
    }

    /**
     * Check if a where condition is for the pivot table.
     */
    protected function isPivotCondition(array $where): bool
    {
        return strpos($where['column'], 'taggables.') === 0;
    }

    /**
     * Process a single WHERE condition.
     */
    protected function processWhereCondition(array $where): array
    {
        $column = 'related.'.$where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        if ($operator === 'like') {
            return $this->processLikeCondition($column, $value, $where['column']);
        }

        $cypherOperator = $this->getCypherOperator($operator);
        $condition = "$column $cypherOperator \$where_{$where['column']}";
        $param = ["where_{$where['column']}" => $value];

        return [$condition, $param];
    }

    /**
     * Process a LIKE condition for pattern matching.
     */
    protected function processLikeCondition(string $column, $value, string $columnName): array
    {
        $pattern = str_replace('%', '.*', $value);
        // For Neo4j, we need to anchor the pattern if it doesn't start with %
        if (strpos($value, '%') !== 0) {
            $pattern = '^'.$pattern;
        }

        $condition = "$column =~ \$where_$columnName";
        $param = ["where_$columnName" => $pattern];

        return [$condition, $param];
    }

    /**
     * Hydrate related models from query results.
     */
    protected function hydrateRelatedModels(array $results)
    {
        $models = $this->related->newCollection();

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
    protected function hydrateModel($result)
    {
        $modelData = $result['related'] ?? $result->related ?? [];
        $model = $this->related->newInstance();

        foreach ($modelData as $key => $value) {
            $model->setAttribute($key, $value);
        }

        $model->syncOriginal();
        $model->exists = true;

        return $model;
    }

    /**
     * Attach pivot data to a model.
     */
    protected function attachPivotData($model, $result): void
    {
        $pivotData = $result['pivot'] ?? $result->pivot ?? [];
        $pivot = new \stdClass;

        foreach ($pivotData as $key => $value) {
            $pivot->$key = $this->formatPivotAttribute($key, $value);
        }

        $model->setRelation('pivot', $pivot);
        $model->pivot = $pivot;
    }

    /**
     * Format a pivot attribute value.
     */
    protected function formatPivotAttribute(string $key, $value): mixed
    {
        if (in_array($key, ['created_at', 'updated_at']) && $value !== null) {
            return \Carbon\Carbon::parse($value);
        }

        return $value;
    }

    /**
     * Get the results of the relationship.
     */
    public function getResults()
    {
        if (is_null($this->parent->getKey())) {
            return $this->related->newCollection();
        }

        return $this->get();
    }

    /**
     * Get the relationship for eager loading.
     */
    public function getEager()
    {
        return $this->getEagerQuery();
    }

    /**
     * Initialize the relation on a set of models.
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, \Illuminate\Database\Eloquent\Collection $results, $relation)
    {
        $dictionary = [];

        // Group results by foreign pivot key (taggable_id for posts/videos)
        foreach ($results as $result) {
            if (isset($result->pivot)) {
                $key = $result->pivot->{$this->foreignPivotKey};
                if (! isset($dictionary[$key])) {
                    $dictionary[$key] = $this->related->newCollection();
                }
                $dictionary[$key]->push($result);
            }
        }

        // Match results to parent models
        foreach ($models as $model) {
            $key = $model->getKey();
            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            }
        }

        return $models;
    }

    /**
     * Add constraints for eager loading.
     */
    public function addEagerConstraints(array $models)
    {
        $keys = $this->getKeys($models, $this->parentKey);

        // We'll need to query for all pivot nodes matching these parent keys
        $this->whereIn($this->foreignPivotKey, $keys);
    }

    /**
     * Get the eager loading query.
     */
    protected function getEagerQuery()
    {
        $connection = $this->parent->getConnection();
        if (! $connection instanceof \Look\EloquentCypher\GraphConnection) {
            return parent::newQuery();
        }

        $parentKeys = $this->extractParentKeys();
        if (empty($parentKeys)) {
            return $this->related->newCollection();
        }

        $cypherQuery = $this->buildEagerLoadingQuery($parentKeys);
        $params = $this->prepareEagerLoadingParams($parentKeys);

        $results = $connection->select($cypherQuery, $params);

        return $this->hydrateRelatedModels($results);
    }

    /**
     * Extract parent keys from eager loading constraints.
     */
    protected function extractParentKeys(): array
    {
        $queryBuilder = $this->getQuery();
        $wheres = $queryBuilder->getQuery()->wheres ?? [];

        foreach ($wheres as $where) {
            if ($where['column'] === $this->foreignPivotKey && $where['type'] === 'In') {
                // Ensure we have an indexed array for Neo4j
                return array_values($where['values']);
            }
        }

        return [];
    }

    /**
     * Build the Cypher query for eager loading.
     */
    protected function buildEagerLoadingQuery(array $parentKeys): string
    {
        $pivotTable = $this->table;
        $relatedTable = $this->related->getTable();

        return "MATCH (pivot:$pivotTable) ".
               "WHERE pivot.{$this->foreignPivotKey} IN \$parentKeys ".
               "AND pivot.{$this->morphType} = \$morphType ".
               "MATCH (related:$relatedTable {id: pivot.{$this->relatedPivotKey}}) ".
               'RETURN related, pivot';
    }

    /**
     * Prepare parameters for eager loading query.
     */
    protected function prepareEagerLoadingParams(array $parentKeys): array
    {
        return [
            'parentKeys' => $parentKeys,
            'morphType' => $this->morphClass,
        ];
    }

    /**
     * Set a where in constraint for eager loading.
     */
    public function whereIn($column, array $values)
    {
        // Store the constraint for later use
        $this->query->whereIn($column, $values);

        return $this;
    }
}
