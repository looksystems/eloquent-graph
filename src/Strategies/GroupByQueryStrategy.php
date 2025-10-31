<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Strategies;

class GroupByQueryStrategy extends QueryExecutionStrategy
{
    protected array $columnAliasMap = [];

    protected array $addedColumns = [];

    /**
     * Execute query with GROUP BY and optionally HAVING
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

        // Build WITH clause for aggregation
        $returnColumns = ! empty($this->builder->columns) ? $this->builder->columns : $columns;
        $withClause = $this->buildWithClause($returnColumns);

        if ($withClause) {
            $cypher .= $withClause;

            // Add HAVING conditions as WHERE after WITH
            $havingClause = $this->buildHavingClause();
            if ($havingClause) {
                $cypher .= $havingClause;
            }

            // Final RETURN clause after WITH - just return the aliased columns
            $returnParts = array_keys($this->columnAliasMap);
            $cypher .= ' RETURN '.implode(', ', $returnParts);

            // Build ORDER BY clause for WITH queries
            $cypher .= $this->buildOrderByClause($this->columnAliasMap);

            // Build LIMIT/SKIP clause
            $cypher .= $this->buildLimitClause();
        } else {
            // No valid WITH clause built, fall back to standard query
            $standardStrategy = new StandardQueryStrategy($this->builder);

            return $standardStrategy->execute($columns);
        }

        return [
            'cypher' => $cypher,
            'bindings' => $this->bindings,
        ];
    }

    /**
     * Build WITH clause for aggregation
     */
    protected function buildWithClause(array $returnColumns): string
    {
        $withParts = [];

        // Add GROUP BY columns to WITH clause
        if (! empty($this->builder->groups)) {
            $this->addGroupByColumns($withParts);
        }

        // Process columns for WITH clause
        $this->processColumnsForWith($withParts, $returnColumns);

        if (empty($withParts)) {
            return '';
        }

        return ' WITH '.implode(', ', $withParts);
    }

    /**
     * Add GROUP BY columns to WITH parts
     */
    protected function addGroupByColumns(array &$withParts): void
    {
        foreach ($this->builder->groups as $groupColumn) {
            // Parse table.column format for joins
            if (! empty($this->builder->neo4jJoins) && strpos($groupColumn, '.') !== false) {
                [$table, $col] = explode('.', $groupColumn, 2);
                $tableAlias = $this->builder->getAliasForTable($table);
                $aliasName = str_replace('.', '_', $groupColumn);
                $columnExpr = "{$tableAlias}.{$col}";

                if (! isset($this->addedColumns[$aliasName])) {
                    $withParts[] = "{$columnExpr} as {$aliasName}";
                    $this->columnAliasMap[$aliasName] = $columnExpr;
                    $this->addedColumns[$aliasName] = true;
                }
            } else {
                $columnExpr = "n.{$groupColumn}";

                if (! isset($this->addedColumns[$groupColumn])) {
                    $withParts[] = "{$columnExpr} as {$groupColumn}";
                    $this->columnAliasMap[$groupColumn] = $columnExpr;
                    $this->addedColumns[$groupColumn] = true;
                }
            }
        }
    }

    /**
     * Process columns for WITH clause
     */
    protected function processColumnsForWith(array &$withParts, array $returnColumns): void
    {
        foreach ($returnColumns as $column) {
            if (is_array($column) && isset($column['type']) && $column['type'] === 'raw') {
                $this->processRawColumn($withParts, $column);
            }
        }
    }

    /**
     * Process raw column for WITH clause
     */
    protected function processRawColumn(array &$withParts, array $column): void
    {
        $expression = $column['expression'];
        if (! is_string($expression)) {
            return;
        }

        // Handle DISTINCT prefix
        if (stripos($expression, 'DISTINCT') === 0) {
            $expression = trim(substr($expression, 8));
        }

        // Split multiple columns/expressions
        $parts = explode(',', $expression);
        foreach ($parts as $part) {
            $this->processExpressionPart($withParts, trim($part));
        }
    }

    /**
     * Process individual expression part
     */
    protected function processExpressionPart(array &$withParts, string $part): void
    {
        // Check if it has an alias
        if (preg_match('/(.+?)\s+as\s+(.+)/i', $part, $matches)) {
            $expr = trim($matches[1]);
            $alias = trim($matches[2]);

            // Skip if this alias was already added
            if (isset($this->addedColumns[$alias])) {
                return;
            }

            if (! preg_match('/^(COUNT|AVG|SUM|MAX|MIN|COLLECT)\s*\(/i', $expr)) {
                // Plain column
                $this->processPlainColumn($withParts, $expr, $alias);
            } else {
                // Aggregate function
                $this->processAggregateFunction($withParts, $expr, $alias);
            }
        } else {
            // No alias - handle plain columns that need alias
            $this->processColumnWithoutAlias($withParts, $part);
        }
    }

    /**
     * Process plain column with alias
     */
    protected function processPlainColumn(array &$withParts, string $expr, string $alias): void
    {
        if (! empty($this->builder->neo4jJoins) && strpos($expr, '.') !== false) {
            [$table, $col] = explode('.', $expr, 2);
            $tableAlias = $this->builder->getAliasForTable($table);
            $withParts[] = "{$tableAlias}.{$col} as {$alias}";
            $this->columnAliasMap[$alias] = "{$tableAlias}.{$col}";
        } else {
            // No table prefix, default to main table
            if (strpos($expr, 'n.') === 0) {
                $withParts[] = "$expr as $alias";
                $this->columnAliasMap[$alias] = $expr;
            } else {
                $withParts[] = "n.$expr as $alias";
                $this->columnAliasMap[$alias] = "n.$expr";
            }
        }
        $this->addedColumns[$alias] = true;
    }

    /**
     * Process aggregate function with alias
     */
    protected function processAggregateFunction(array &$withParts, string $expr, string $alias): void
    {
        if (! empty($this->builder->neo4jJoins)) {
            // Use parseJoinSelectExpression to handle table.column references
            $processed = $this->builder->parseJoinSelectExpression($expr);
        } else {
            // No joins, use simple prefixing
            $processed = preg_replace_callback('/\b(\w+)\b/', function ($m) {
                // Don't prefix function names or numbers
                if (is_numeric($m[1]) || in_array(strtoupper($m[1]), ['COUNT', 'AVG', 'SUM', 'MAX', 'MIN', 'COLLECT', 'AS'])) {
                    return $m[1];
                }
                // Don't prefix if already prefixed or if it's the asterisk
                if ($m[1] === '*' || strpos($m[0], '.') !== false) {
                    return $m[1];
                }

                return 'n.'.$m[1];
            }, $expr);
        }

        $withParts[] = "$processed as $alias";
        $this->columnAliasMap[$alias] = $processed;
        $this->addedColumns[$alias] = true;
    }

    /**
     * Process column without alias
     */
    protected function processColumnWithoutAlias(array &$withParts, string $part): void
    {
        // Only handle non-aggregate columns (they need aliases in WITH)
        if (preg_match('/^(COUNT|AVG|SUM|MAX|MIN|COLLECT)\s*\(/i', $part)) {
            // Aggregate without alias - this is an error in Neo4j, skip
            return;
        }

        // Plain column needs alias in WITH - parse for table.column format
        if (! empty($this->builder->neo4jJoins) && strpos($part, '.') !== false) {
            [$table, $col] = explode('.', $part, 2);
            $tableAlias = $this->builder->getAliasForTable($table);
            $aliasName = str_replace('.', '_', $part);

            if (! isset($this->addedColumns[$aliasName])) {
                $withParts[] = "{$tableAlias}.{$col} as {$aliasName}";
                $this->columnAliasMap[$aliasName] = "{$tableAlias}.{$col}";
                $this->addedColumns[$aliasName] = true;
            }
        } else {
            // No table prefix, default to main table
            if (! isset($this->addedColumns[$part])) {
                $withParts[] = "n.$part as $part";
                $this->columnAliasMap[$part] = "n.$part";
                $this->addedColumns[$part] = true;
            }
        }
    }

    /**
     * Build HAVING clause (as WHERE after WITH)
     */
    protected function buildHavingClause(): string
    {
        if (empty($this->builder->havings)) {
            return '';
        }

        $havingConditions = [];
        $havingBindingIndex = 0;

        foreach ($this->builder->havings as $having) {
            if (isset($having['type']) && $having['type'] === 'raw') {
                $this->processRawHaving($havingConditions, $having, $havingBindingIndex);
            } else {
                $this->processRegularHaving($havingConditions, $having);
            }
        }

        if (empty($havingConditions)) {
            return '';
        }

        return ' WHERE '.implode(' AND ', $havingConditions);
    }

    /**
     * Process raw HAVING condition
     */
    protected function processRawHaving(array &$havingConditions, array $having, int &$havingBindingIndex): void
    {
        $sql = $having['sql'];

        // Replace aggregate functions with their aliases from WITH clause
        foreach ($this->columnAliasMap as $alias => $expression) {
            // Check if the expression matches what's in the raw SQL
            if (stripos($sql, str_replace('n.', '', $expression)) !== false) {
                $cleanExpr = str_replace('n.', '', $expression);
                $sql = str_ireplace($cleanExpr, $alias, $sql);
            }
        }

        // Replace ? with parameter names
        while (strpos($sql, '?') !== false) {
            $paramName = 'having_raw_'.$havingBindingIndex;
            $sql = preg_replace('/\?/', '$'.$paramName, $sql, 1);

            // Get the binding value from bindings array if it exists
            if (isset($this->builder->bindings['having'][$havingBindingIndex])) {
                $this->bindings[$paramName] = $this->builder->bindings['having'][$havingBindingIndex];
            }
            $havingBindingIndex++;
        }

        $havingConditions[] = $sql;
    }

    /**
     * Process regular HAVING condition
     */
    protected function processRegularHaving(array &$havingConditions, array $having): void
    {
        $column = $having['column'];
        $operator = $having['operator'];
        $value = $having['value'];
        $paramName = 'having_'.str_replace('.', '_', $column);

        $havingConditions[] = "$column $operator \$$paramName";
        $this->bindings[$paramName] = $value;
    }
}
