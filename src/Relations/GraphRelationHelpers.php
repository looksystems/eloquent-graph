<?php

namespace Look\EloquentCypher\Relations;

/**
 * Trait providing common helper methods for Neo4j relation classes.
 * Extracted from Neo4jHasManyThrough and Neo4jHasOneThrough to reduce duplication.
 */
trait GraphRelationHelpers
{
    /**
     * Process a WHERE condition for Cypher query building.
     *
     * @param  array  $where  The WHERE clause configuration
     * @param  array  $bindings  Query parameter bindings
     * @return string|null The Cypher WHERE clause fragment
     */
    protected function processWhereCondition(array $where, array &$bindings): ?string
    {
        if (! isset($where['type']) || $where['type'] === 'Basic') {
            $column = $where['column'];

            // Skip relationship constraints that are already handled in MATCH
            if (property_exists($this, 'throughParent') && strpos($column, $this->throughParent->getTable().'.') === 0) {
                return null;
            }

            $column = $this->formatColumnName($column);
            $operator = strtoupper($where['operator']);
            $paramName = 'where_'.str_replace('.', '_', $where['column']).'_'.count($bindings);

            // Handle LIKE operator conversion
            if ($operator === 'LIKE') {
                [$operator, $value] = $this->handleLikeOperator($where['value']);
                $bindings[$paramName] = $value;
            } else {
                $bindings[$paramName] = $where['value'];
            }

            return "$column $operator \$$paramName";
        }

        // Add more where types as needed (like whereIn, whereLike, etc.)
        return null;
    }

    /**
     * Format a column name for use in Cypher queries.
     *
     * @param  string  $column  The column name
     * @return string The formatted column name with proper alias
     */
    protected function formatColumnName(string $column): string
    {
        // If column doesn't have a table prefix, add 'related.'
        if (strpos($column, '.') === false) {
            return 'related.'.$column;
        }

        // Replace table name with 'related' alias
        return str_replace($this->related->getTable().'.', 'related.', $column);
    }

    /**
     * Handle LIKE operator conversion for Cypher.
     *
     * @param  string  $value  The LIKE pattern value
     * @return array [operator, value] for Cypher
     */
    protected function handleLikeOperator(string $value): array
    {
        // Handle %text% pattern (contains)
        if (strpos($value, '%') === 0 && strrpos($value, '%') === strlen($value) - 1) {
            return ['CONTAINS', substr($value, 1, -1)]; // Remove % characters
        }

        // For other LIKE patterns, use regex
        $regex = str_replace(['%', '_'], ['.*', '.'], $value);

        return ['=~', '(?i)'.$regex]; // Case insensitive
    }

    /**
     * Build ORDER BY clause for Cypher query.
     *
     * @return string The ORDER BY clause or empty string
     */
    protected function buildOrderClause(): string
    {
        $orders = $this->query->getQuery()->orders ?? [];
        $orderParts = [];

        foreach ($orders as $order) {
            $column = $order['column'];
            // If column doesn't have a table prefix, add 'related.'
            if (strpos($column, '.') === false) {
                $column = 'related.'.$column;
            } else {
                $column = str_replace($this->related->getTable().'.', 'related.', $column);
            }
            $direction = strtoupper($order['direction']);
            $orderParts[] = "$column $direction";
        }

        return $orderParts ? 'ORDER BY '.implode(', ', $orderParts) : '';
    }

    /**
     * Build WHERE clause from query builder conditions.
     *
     * @param  array  $bindings  Query parameter bindings
     * @return string The WHERE clause conditions or empty string
     */
    protected function buildWhereClause(array &$bindings): string
    {
        $wheres = $this->query->getQuery()->wheres ?? [];
        $conditions = [];

        foreach ($wheres as $where) {
            $condition = $this->processWhereCondition($where, $bindings);
            if ($condition) {
                $conditions[] = $condition;
            }
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Build LIMIT/SKIP clause for Cypher query.
     *
     * @return string The LIMIT/SKIP clause or empty string
     */
    protected function buildLimitClause(): string
    {
        $limit = $this->query->getQuery()->limit;
        $offset = $this->query->getQuery()->offset;

        $clause = '';
        if ($offset) {
            $clause .= "SKIP $offset ";
        }
        if ($limit) {
            $clause .= "LIMIT $limit";
        }

        return trim($clause);
    }
}
