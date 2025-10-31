<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Strategies;

class StandardQueryStrategy extends QueryExecutionStrategy
{
    /**
     * Execute standard query without joins or GROUP BY
     */
    public function execute(array $columns = ['*']): array
    {
        $label = $this->getQualifiedLabel();
        $cypher = $this->buildMatchClause($label);

        // Add WHERE clause
        $whereClause = $this->buildWhereClause();
        if ($whereClause) {
            $cypher .= $whereClause;
        }

        // Add OPTIONAL MATCH for left joins
        $cypher = $this->addLeftJoins($cypher);

        // Determine columns and distinct
        $returnColumns = ! empty($this->builder->columns) ? $this->builder->columns : $columns;
        $isDistinct = property_exists($this->builder, 'distinct') && $this->builder->distinct !== null;

        // Handle DISTINCT with ORDER BY - need to include ORDER BY columns in RETURN
        if ($isDistinct && ! empty($this->builder->orders)) {
            $returnColumns = $this->addOrderColumnsForDistinct($returnColumns);
        }

        // Build RETURN clause
        $cypher .= $this->buildReturnClause($returnColumns, $isDistinct);

        // Build ORDER BY clause
        if (! empty($this->builder->orders)) {
            if ($isDistinct && ! empty($returnColumns) && ! in_array('*', $returnColumns)) {
                $cypher .= $this->buildDistinctOrderByClause($returnColumns);
            } else {
                $cypher .= $this->buildOrderByClause();
            }
        }

        // Add LIMIT/SKIP
        $cypher .= $this->buildLimitClause();

        return [
            'cypher' => $cypher,
            'bindings' => $this->bindings,
        ];
    }

    /**
     * Add ORDER BY columns to RETURN when using DISTINCT
     */
    protected function addOrderColumnsForDistinct(array $returnColumns): array
    {
        $orderColumns = [];

        foreach ($this->builder->orders as $order) {
            if (isset($order['type']) && $order['type'] === 'raw') {
                // For raw expressions, extract columns that are being used
                $rawExpression = $order['expression'];
                if (preg_match_all('/\b[pnu]\.(\w+)/', $rawExpression, $matches)) {
                    foreach ($matches[1] as $col) {
                        if (! $this->isColumnIncluded($col, $returnColumns)) {
                            $orderColumns[] = $col;
                        }
                    }
                }
            } else {
                $orderColumn = $order['column'];
                if (! $this->isColumnIncluded($orderColumn, $returnColumns)) {
                    $orderColumns[] = $orderColumn;
                }
            }
        }

        if (! empty($orderColumns)) {
            $returnColumns = array_merge($returnColumns, $orderColumns);
        }

        return $returnColumns;
    }

    /**
     * Check if a column is already included in return columns
     */
    protected function isColumnIncluded(string $column, array $returnColumns): bool
    {
        foreach ($returnColumns as $col) {
            if ((is_string($col) && $col === $column) ||
                (is_array($col) && isset($col['expression']) && strpos($col['expression'], $column) !== false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build ORDER BY clause for DISTINCT queries
     */
    protected function buildDistinctOrderByClause(array $returnColumns): string
    {
        $orderClauses = [];

        // First, parse raw columns to extract aliases
        $parsedAliases = $this->parseColumnAliases($returnColumns);

        foreach ($this->builder->orders as $order) {
            if (isset($order['type']) && $order['type'] === 'raw') {
                $orderClause = $this->buildRawOrderClause($order, $parsedAliases);
            } else {
                $orderClause = $this->buildRegularOrderClause($order);
            }

            if ($orderClause) {
                $orderClauses[] = $orderClause;
            }
        }

        if (! empty($orderClauses)) {
            return ' ORDER BY '.implode(', ', $orderClauses);
        }

        return '';
    }

    /**
     * Parse column aliases from return columns
     */
    protected function parseColumnAliases(array $returnColumns): array
    {
        $parsedAliases = [];

        foreach ($returnColumns as $col) {
            if (is_array($col) && isset($col['type']) && $col['type'] === 'raw') {
                $rawExpression = $col['expression'];
                if (is_string($rawExpression)) {
                    // Parse expressions like "*, (views + likes) as engagement"
                    $parts = explode(',', $rawExpression);
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if ($part !== '*' && preg_match('/(.+?)\s+as\s+(.+)/i', $part, $matches)) {
                            $expr = trim($matches[1]);
                            $alias = trim($matches[2]);
                            $parsedAliases[] = ['expression' => $expr, 'alias' => $alias];
                        }
                    }
                }
            } elseif (is_array($col) && isset($col['expression']) && isset($col['alias'])) {
                $parsedAliases[] = $col;
            }
        }

        return $parsedAliases;
    }

    /**
     * Build raw ORDER BY clause
     */
    protected function buildRawOrderClause(array $order, array $parsedAliases): string
    {
        $rawExpression = $order['expression'];

        // Check if this raw expression matches any computed column alias
        foreach ($parsedAliases as $col) {
            $orderExprBase = trim(preg_replace('/\s+(ASC|DESC)$/i', '', $rawExpression));

            // Normalize expressions for comparison
            $colExprNormalized = str_replace([' ', '(', ')'], '', str_replace('n.', '', $col['expression']));
            $orderExprNormalized = str_replace([' ', '(', ')'], '', $orderExprBase);

            if ($colExprNormalized === $orderExprNormalized) {
                // Use the alias in ORDER BY
                $direction = '';
                if (preg_match('/\s+(ASC|DESC)$/i', $rawExpression, $matches)) {
                    $direction = ' '.$matches[1];
                }

                return $col['alias'].$direction;
            }
        }

        // No alias found, handle as before
        // Replace p.column, n.column, or u.column with just column
        return preg_replace('/\b[pnu]\.(\w+)/', '$1', $rawExpression);
    }

    /**
     * Build regular ORDER BY clause
     */
    protected function buildRegularOrderClause(array $order): string
    {
        $direction = strtoupper($order['direction'] ?? 'ASC');
        $column = $order['column'];

        // Handle table.column format for joins
        if (! empty($this->builder->neo4jJoins) && strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $alias = $this->builder->getAliasForTable($table);

            return "$alias.$col $direction";
        }

        // Use the column without prefix for DISTINCT queries
        return "$column $direction";
    }
}
