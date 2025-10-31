<?php

namespace Look\EloquentCypher\Query;

use Laudis\Neo4j\ParameterHelper as LaudisParameterHelper;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;

class ParameterHelper
{
    /**
     * Ensure the given array is converted to a CypherList.
     * This is used when we explicitly need a list type, regardless of array keys.
     */
    public static function ensureList(array $value): CypherList
    {
        // Use Laudis ParameterHelper to ensure proper list type
        return LaudisParameterHelper::asList($value);
    }

    /**
     * Ensure the given array is converted to a CypherMap.
     * This is used when we explicitly need a map type, regardless of array structure.
     */
    public static function ensureMap(array $value): CypherMap
    {
        // Use Laudis ParameterHelper to ensure proper map type
        return LaudisParameterHelper::asMap($value);
    }

    /**
     * Smart conversion that auto-detects whether an array should be a list or map.
     * - Sequential indexed arrays (0, 1, 2, ...) become CypherList
     * - Associative arrays or non-sequential keys become CypherMap
     * - Non-array values pass through unchanged
     * - Recursively converts nested arrays
     */
    public static function smartConvert(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Empty array defaults to list for Neo4j compatibility
        if (empty($value)) {
            return self::ensureList($value);
        }

        // Check if it's a sequential indexed array
        if (self::isSequentialArray($value)) {
            // Convert to CypherList and recursively convert elements
            $converted = [];
            foreach ($value as $item) {
                $converted[] = self::smartConvert($item);
            }

            return self::ensureList($converted);
        }

        // It's an associative array or has non-sequential keys
        // Convert to CypherMap and recursively convert values
        $converted = [];
        foreach ($value as $key => $item) {
            $converted[$key] = self::smartConvert($item);
        }

        return self::ensureMap($converted);
    }

    /**
     * Check if an array is sequential (indexed 0, 1, 2, ...).
     */
    private static function isSequentialArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        $keys = array_keys($array);
        $expectedKeys = range(0, count($array) - 1);

        return $keys === $expectedKeys;
    }

    /**
     * Prepare parameters for a Cypher query.
     * This method processes all parameters to ensure proper types.
     * For insert operations, nested structures need to be JSON-encoded
     * since Neo4j doesn't support nested maps/lists as property values.
     */
    public static function prepareParameters(array $parameters): array
    {
        $prepared = [];
        foreach ($parameters as $key => $value) {
            // For nested arrays/objects, JSON encode them for storage
            // Neo4j only supports primitive types and arrays of primitives as properties
            if (is_array($value) && ! self::isPrimitiveArray($value)) {
                $prepared[$key] = json_encode($value);
            } else {
                $prepared[$key] = self::smartConvert($value);
            }
        }

        return $prepared;
    }

    /**
     * Check if an array contains only primitive values (no nested arrays/objects).
     */
    private static function isPrimitiveArray(array $array): bool
    {
        foreach ($array as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Specifically handle whereIn parameters.
     * Empty arrays need special handling to avoid ambiguous type errors.
     */
    public static function prepareWhereInParameter(array $values): CypherList
    {
        return self::ensureList($values);
    }
}
