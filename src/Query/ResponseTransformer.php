<?php

namespace Look\EloquentCypher\Query;

use Illuminate\Support\Collection;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use Laudis\Neo4j\Types\Relationship;
use Look\EloquentCypher\Contracts\ResultSetInterface;

/**
 * Handles transformation of graph database responses into standardized formats.
 * Centralizes all response processing logic to ensure consistency.
 */
class ResponseTransformer
{
    /**
     * Transform a result set from the driver into a Laravel Collection.
     *
     * @param  ResultSetInterface|Collection|iterable  $results
     */
    public function transformResultSet($results): Collection
    {
        if ($results instanceof Collection) {
            return $results;
        }

        // Convert ResultSetInterface to array first
        if ($results instanceof ResultSetInterface) {
            $results = $results->toArray();
        }

        $data = [];
        foreach ($results as $record) {
            $data[] = $this->transformRecord($record);
        }

        return collect($data);
    }

    /**
     * Transform a single record from Neo4j.
     *
     * @param  mixed  $record
     */
    public function transformRecord($record): array
    {
        $row = [];

        foreach ($record as $key => $value) {
            $row[$key] = $this->transformValue($value, $key);
        }

        return $row;
    }

    /**
     * Transform a single value from Neo4j.
     * Handles nodes, relationships, maps, lists, and scalar values.
     *
     * @param  mixed  $value
     * @param  string|null  $key  The key name can give context about the data
     * @return mixed
     */
    public function transformValue($value, ?string $key = null)
    {
        // Handle Neo4j Node objects
        if ($value instanceof Node) {
            return $this->transformNode($value);
        }

        // Handle Neo4j Relationship objects
        if ($value instanceof Relationship) {
            return $this->transformRelationship($value, $key);
        }

        // Handle Cypher Map (like Neo4j properties)
        if ($value instanceof CypherMap) {
            return $this->transformCypherMap($value);
        }

        // Handle Cypher List
        if ($value instanceof CypherList) {
            return $this->transformCypherList($value);
        }

        // Handle objects with toArray method
        if (is_object($value) && method_exists($value, 'toArray')) {
            // Special handling for relationship properties (pivot data)
            if ($key === 'r') {
                $data = $value->toArray();
                // If it has properties, extract them directly
                if (isset($data['properties'])) {
                    if (is_object($data['properties']) && method_exists($data['properties'], 'toArray')) {
                        return $data['properties']->toArray();
                    }

                    return (array) $data['properties'];
                }

                return $data;
            }

            return $this->transformObjectWithToArray($value);
        }

        // Handle numeric values - cast floats to int when they represent whole numbers
        if (is_float($value) && $value == floor($value)) {
            return (int) $value;
        }

        // Return scalar values as-is
        return $value;
    }

    /**
     * Transform a Neo4j Node into an array.
     */
    public function transformNode(Node $node): array
    {
        $properties = $node->getProperties();

        if ($properties instanceof CypherMap) {
            return $properties->toArray();
        }

        return (array) $properties;
    }

    /**
     * Transform a Neo4j Relationship into an array.
     * For pivot data (r key), we return properties directly.
     */
    public function transformRelationship(Relationship $relationship, ?string $key = null): array
    {
        $properties = $relationship->getProperties();

        // For 'r' key (pivot data), return properties directly
        if ($key === 'r') {
            if ($properties instanceof CypherMap) {
                return $properties->toArray();
            }

            return (array) $properties;
        }

        // Otherwise return full relationship data
        $data = [
            'type' => $relationship->getType(),
            'startNodeId' => $relationship->getStartNodeId(),
            'endNodeId' => $relationship->getEndNodeId(),
        ];

        if ($properties instanceof CypherMap) {
            $data['properties'] = $properties->toArray();
        } else {
            $data['properties'] = (array) $properties;
        }

        return $data;
    }

    /**
     * Transform a CypherMap into an array.
     */
    public function transformCypherMap(CypherMap $map): array
    {
        return $map->toArray();
    }

    /**
     * Transform a CypherList into an array.
     */
    public function transformCypherList(CypherList $list): array
    {
        $result = [];

        foreach ($list as $item) {
            $result[] = $this->transformValue($item);
        }

        return $result;
    }

    /**
     * Transform an object with a toArray method.
     *
     * @param  object  $object
     * @return mixed
     */
    protected function transformObjectWithToArray($object)
    {
        $data = $object->toArray();

        // Handle nested properties structure
        if (isset($data['properties']) && is_object($data['properties'])) {
            if (method_exists($data['properties'], 'toArray')) {
                return $data['properties']->toArray();
            }

            return (array) $data['properties'];
        }

        return $data;
    }

    /**
     * Handle mixed format responses (array or object).
     * This ensures consistent access patterns regardless of Neo4j response format.
     *
     * @param  mixed  $data
     * @return mixed
     */
    public function handleMixedFormat($data)
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_object($data)) {
            // Convert to array for consistent access
            if (method_exists($data, 'toArray')) {
                return $data->toArray();
            }

            return (array) $data;
        }

        return $data;
    }

    /**
     * Transform a pivot result (for many-to-many relationships).
     */
    public function transformPivotResult(array $result, array $pivotColumns): array
    {
        $pivotData = [];

        foreach ($pivotColumns as $column) {
            $key = "pivot_{$column}";
            if (isset($result[$key])) {
                $pivotData[$column] = $result[$key];
                unset($result[$key]);
            }
        }

        if (! empty($pivotData)) {
            $result['pivot'] = (object) $pivotData;
        }

        return $result;
    }

    /**
     * Transform an aggregation result.
     *
     * @param  mixed  $result
     * @return mixed
     */
    public function transformAggregateResult($result, string $function)
    {
        if (empty($result)) {
            // Return appropriate default for aggregation functions
            return in_array($function, ['sum', 'count']) ? 0 : null;
        }

        // Extract the aggregate value
        if (is_array($result) && count($result) === 1) {
            $firstRow = reset($result);
            if (is_array($firstRow) && count($firstRow) === 1) {
                return reset($firstRow);
            }
        }

        return $result;
    }

    /**
     * Transform a count result.
     *
     * @param  mixed  $result
     */
    public function transformCountResult($result): int
    {
        if (is_numeric($result)) {
            return (int) $result;
        }

        if (is_array($result) && ! empty($result)) {
            $firstRow = reset($result);
            if (is_array($firstRow) && isset($firstRow['count'])) {
                return (int) $firstRow['count'];
            }
        }

        return 0;
    }

    /**
     * Transform results for JSON serialization.
     *
     * @param  mixed  $results
     */
    public function transformForJson($results): array
    {
        if ($results instanceof Collection) {
            return $results->toArray();
        }

        if (is_array($results)) {
            return array_map(function ($item) {
                return $this->handleMixedFormat($item);
            }, $results);
        }

        return $this->handleMixedFormat($results);
    }
}
