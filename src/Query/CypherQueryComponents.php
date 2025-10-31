<?php

namespace Look\EloquentCypher\Query;

class CypherQueryComponents
{
    /**
     * Build a MATCH clause for Cypher query
     */
    public function buildMatch(string $label, string $alias = 'n', array $conditions = []): string
    {
        $match = "MATCH ($alias:$label";

        if (! empty($conditions)) {
            $props = [];
            foreach ($conditions as $key => $value) {
                if ($value !== null) {
                    $props[] = "$key: \$$key";
                }
            }

            if (! empty($props)) {
                $match .= ' {'.implode(', ', $props).'}';
            }
        }

        $match .= ')';

        return $match;
    }

    /**
     * Build a WHERE clause from conditions
     */
    public function buildWhere(array $conditions, string $alias = 'n'): string
    {
        if (empty($conditions)) {
            return '';
        }

        $clauses = [];
        foreach ($conditions as $condition) {
            $clause = $this->buildSingleCondition($condition, $alias);
            if ($clause) {
                $clauses[] = $clause;
            }
        }

        return ! empty($clauses) ? 'WHERE '.implode(' AND ', $clauses) : '';
    }

    /**
     * Build a RETURN clause
     */
    public function buildReturn(array $columns = ['*'], string $alias = 'n', bool $distinct = false): string
    {
        if (empty($columns) || $columns === ['*']) {
            return $distinct ? "RETURN DISTINCT $alias" : "RETURN $alias";
        }

        $returnParts = [];
        $hasFullNode = false;
        $hasRawWithStar = false;
        $hasSpecificColumns = false;
        $hasDistinctInRaw = false;

        // We need to track whether we'll include the full node
        // to determine if we need aliases for columns
        $willIncludeNode = false;

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['type']) && $column['type'] === 'raw') {
                $rawExpression = $column['expression'];

                // Check if raw expression already contains DISTINCT
                if (is_string($rawExpression) && stripos($rawExpression, 'DISTINCT') !== false) {
                    $hasDistinctInRaw = true;
                }

                // Handle array of expressions
                if (is_array($rawExpression)) {
                    foreach ($rawExpression as $expr) {
                        $returnParts[] = trim($expr);
                    }
                } else {
                    // Single string expression
                    $expression = trim($rawExpression);
                    if (strpos($expression, '*') === 0) {
                        $hasRawWithStar = true;
                        if (strpos($expression, ',') !== false) {
                            // Expression like "*, (views + likes) as engagement"
                            $returnParts[] = $alias;
                            $rest = trim(substr($expression, strpos($expression, ',') + 1));
                            $returnParts[] = $this->prefixColumnsInExpression($rest, $alias);
                        } else {
                            // Just "*"
                            $returnParts[] = $alias;
                        }
                    } else {
                        // No * at the beginning, prefix columns
                        $returnParts[] = $this->prefixColumnsInExpression($expression, $alias);
                    }
                }
            } elseif ($column === '*') {
                $hasRawWithStar = true;
                $returnParts[] = $alias;
            } elseif ($column !== '*') {
                $hasSpecificColumns = true;
            }
        }

        // Check if we have the base node
        $hasNodeReturn = $hasRawWithStar || in_array($alias, $returnParts) || in_array("$alias.*", $returnParts);

        // Determine if we'll include the full node
        $willIncludeNode = ! $hasNodeReturn && ! $distinct;

        // Now build the return parts with proper aliasing
        if ($hasSpecificColumns) {
            $specificColumnParts = [];
            foreach ($columns as $column) {
                if (! is_array($column) && $column !== '*') {
                    // If column contains dots, we need to sanitize the alias
                    // Neo4j doesn't allow dots in aliases
                    if (strpos($column, '.') !== false) {
                        $columnAlias = str_replace('.', '_', $column);
                        $specificColumnParts[] = "$alias.$column as $columnAlias";
                    } else {
                        // If we're not including the node or using DISTINCT, we need aliases
                        if (! $willIncludeNode || $distinct) {
                            $specificColumnParts[] = "$alias.$column as $column";
                        } else {
                            $specificColumnParts[] = "$alias.$column";
                        }
                    }
                }
            }
            $returnParts = array_merge($returnParts, $specificColumnParts);
        }

        // Include the full node unless it's already included or we're using DISTINCT
        // When using DISTINCT, including the full node can cause issues with GROUP BY
        if ($willIncludeNode) {
            array_unshift($returnParts, $alias);
        }

        // Don't add DISTINCT if raw expression already contains it
        $returnClause = ($distinct && ! $hasDistinctInRaw) ? 'RETURN DISTINCT ' : 'RETURN ';

        return $returnClause.implode(', ', $returnParts);
    }

    /**
     * Build ORDER BY clause
     */
    public function buildOrderBy(array $orders, string $alias = 'n'): string
    {
        if (empty($orders)) {
            return '';
        }

        $orderClauses = [];
        foreach ($orders as $order) {
            if (isset($order['type']) && $order['type'] === 'raw') {
                $orderClauses[] = $this->prefixColumnsInExpression($order['expression'], $alias);
            } else {
                $direction = strtoupper($order['direction'] ?? 'ASC');
                $column = $order['column'];
                // If column contains a dot, it might have been aliased in RETURN
                // Use the sanitized alias for ORDER BY
                if (strpos($column, '.') !== false) {
                    $columnAlias = str_replace('.', '_', $column);
                    $orderClauses[] = "$columnAlias $direction";
                } else {
                    $orderClauses[] = "$alias.$column $direction";
                }
            }
        }

        return 'ORDER BY '.implode(', ', $orderClauses);
    }

    /**
     * Build LIMIT clause with optional SKIP
     */
    public function buildLimit(?int $limit, ?int $offset = null): string
    {
        $clause = '';

        if ($offset !== null && $offset > 0) {
            $clause .= "SKIP $offset";
        }

        if ($limit !== null && $limit >= 0) {
            if ($clause) {
                $clause .= ' ';
            }
            $clause .= "LIMIT $limit";
        }

        return $clause;
    }

    /**
     * Build SET clause for updates
     */
    public function buildSet(array $values, string $alias = 'n', array &$bindings = []): string
    {
        $sets = [];

        foreach ($values as $key => $value) {
            // Strip table prefix if present
            $column = $this->stripTablePrefix($key);

            // Use a clean parameter name
            $paramName = str_replace('.', '_', $column);
            $sets[] = "$alias.$column = \$$paramName";
            $bindings[$paramName] = $value;
        }

        return 'SET '.implode(', ', $sets);
    }

    /**
     * Build CREATE clause for inserts
     */
    public function buildCreate(string $label, array $attributes, string $alias = 'n'): string
    {
        $properties = [];

        foreach ($attributes as $key => $value) {
            $properties[] = "$key: \$$key";
        }

        $propertiesString = ! empty($properties) ? ' {'.implode(', ', $properties).'}' : '';

        return "CREATE ($alias:$label$propertiesString)";
    }

    /**
     * Build DELETE clause
     */
    public function buildDelete(string $alias = 'n', bool $detach = true): string
    {
        return $detach ? "DETACH DELETE $alias" : "DELETE $alias";
    }

    /**
     * Build a single WHERE condition
     */
    protected function buildSingleCondition(array $condition, string $alias): ?string
    {
        $type = $condition['type'] ?? 'Basic';

        switch ($type) {
            case 'Basic':
                return $this->buildBasicCondition($condition, $alias);
            case 'In':
                return $this->buildInCondition($condition, $alias);
            case 'NotIn':
                return $this->buildNotInCondition($condition, $alias);
            case 'Null':
                return $this->buildNullCondition($condition, $alias);
            case 'NotNull':
                return $this->buildNotNullCondition($condition, $alias);
            case 'between':
                return $this->buildBetweenCondition($condition, $alias);
            case 'Raw':
                return $condition['sql'] ?? $condition['expression'] ?? null;
            default:
                return null;
        }
    }

    /**
     * Build a basic WHERE condition
     */
    protected function buildBasicCondition(array $condition, string $alias): string
    {
        $column = $this->prefixColumn($condition['column'], $alias);
        $operator = $this->translateOperator($condition['operator'] ?? '=');

        if (isset($condition['value'])) {
            $paramName = $this->getParameterName($condition['column']);

            return "$column $operator \$$paramName";
        }

        return "$column $operator";
    }

    /**
     * Build an IN condition
     */
    protected function buildInCondition(array $condition, string $alias): string
    {
        $column = $this->prefixColumn($condition['column'], $alias);
        $paramName = $this->getParameterName($condition['column']);

        return "$column IN \$$paramName";
    }

    /**
     * Build a NOT IN condition
     */
    protected function buildNotInCondition(array $condition, string $alias): string
    {
        $column = $this->prefixColumn($condition['column'], $alias);
        $paramName = $this->getParameterName($condition['column']);

        return "NOT $column IN \$$paramName";
    }

    /**
     * Build a NULL condition
     */
    protected function buildNullCondition(array $condition, string $alias): string
    {
        $column = $this->prefixColumn($condition['column'], $alias);

        return "$column IS NULL";
    }

    /**
     * Build a NOT NULL condition
     */
    protected function buildNotNullCondition(array $condition, string $alias): string
    {
        $column = $this->prefixColumn($condition['column'], $alias);

        return "$column IS NOT NULL";
    }

    /**
     * Build a BETWEEN condition
     */
    protected function buildBetweenCondition(array $condition, string $alias): string
    {
        $column = $this->prefixColumn($condition['column'], $alias);
        $paramMin = $this->getParameterName($condition['column'].'_min');
        $paramMax = $this->getParameterName($condition['column'].'_max');

        $not = $condition['not'] ?? false;

        if ($not) {
            return "NOT ($column >= \$$paramMin AND $column <= \$$paramMax)";
        }

        return "$column >= \$$paramMin AND $column <= \$$paramMax";
    }

    /**
     * Translate SQL operators to Cypher
     */
    protected function translateOperator(string $operator): string
    {
        $translations = [
            '!=' => '<>',
            'like' => '=~',
            'not like' => 'NOT =~',
            'LIKE' => '=~',
            'NOT LIKE' => 'NOT =~',
        ];

        return $translations[$operator] ?? $operator;
    }

    /**
     * Prefix a column with alias if not already prefixed
     */
    protected function prefixColumn(string $column, string $alias): string
    {
        if (strpos($column, '.') !== false) {
            return $column;
        }

        if ($column === 'id') {
            return "id($alias)";
        }

        return "$alias.$column";
    }

    /**
     * Strip table prefix from column name
     */
    protected function stripTablePrefix(string $column): string
    {
        if (strpos($column, '.') !== false) {
            $parts = explode('.', $column);

            return end($parts);
        }

        return $column;
    }

    /**
     * Get a clean parameter name from a column
     */
    protected function getParameterName(string $column): string
    {
        $column = $this->stripTablePrefix($column);

        return str_replace('.', '_', $column);
    }

    /**
     * Process raw column expression
     */
    protected function processRawColumn(array $column, string $alias): string
    {
        $expression = $column['expression'] ?? '';

        if (is_array($expression)) {
            // When array is provided, expressions are already fully qualified
            // Just return them as-is
            $processed = [];
            foreach ($expression as $expr) {
                $processed[] = trim($expr);
            }

            return implode(', ', $processed);
        }

        $expression = trim($expression);

        // Handle expressions starting with *
        if (strpos($expression, '*') === 0) {
            if (strpos($expression, ',') !== false) {
                // Expression like "*, (views + likes) as engagement"
                $parts = [$alias];
                $rest = trim(substr($expression, strpos($expression, ',') + 1));
                $parts[] = $this->prefixColumnsInExpression($rest, $alias);

                return implode(', ', $parts);
            }

            return $alias;
        }

        return $this->prefixColumnsInExpression($expression, $alias);
    }

    /**
     * Prefix column references in an expression
     */
    public function prefixColumnsInExpression(string $expression, string $alias = 'n'): string
    {
        // Split by commas to handle multiple expressions
        $expressions = explode(',', $expression);
        $processedExpressions = [];

        foreach ($expressions as $expr) {
            $processedExpressions[] = $this->prefixSingleExpression(trim($expr), $alias);
        }

        return implode(', ', $processedExpressions);
    }

    /**
     * Prefix columns in a single expression
     */
    protected function prefixSingleExpression(string $expression, string $alias = 'n'): string
    {
        // If expression already contains the correct alias prefix, return as-is
        if (strpos($expression, "$alias.") !== false) {
            return $expression;
        }

        // Check if expression has any single-letter alias prefixes (e.g., u., p.)
        // If yes, replace them with the correct alias
        if (preg_match('/\b[a-z]\./i', $expression)) {
            return preg_replace('/\b([a-z])\./i', "$alias.", $expression);
        }

        // Split by 'as' or 'AS' to separate expression from alias
        $parts = preg_split('/\s+[aA][sS]\s+/', $expression, 2);
        $mainExpression = $parts[0];
        $aliasName = isset($parts[1]) ? $parts[1] : null;

        // First handle aggregate functions specifically
        // Match aggregate functions and prefix columns inside them
        $aggregateFunctions = ['COUNT', 'count', 'AVG', 'avg', 'SUM', 'sum',
            'MAX', 'max', 'MIN', 'min', 'COLLECT', 'collect'];

        $aggregatePattern = '/\b('.implode('|', $aggregateFunctions).')\s*\(\s*([^)]+)\s*\)/';

        $mainExpression = preg_replace_callback($aggregatePattern, function ($matches) use ($alias) {
            $function = $matches[1];
            $content = $matches[2];

            // If the content is *, don't prefix it
            if (trim($content) === '*') {
                return $function.'(*)';
            }

            // Otherwise, prefix the column name if it doesn't already have one
            if (strpos($content, '.') === false && ! is_numeric($content)) {
                $content = $alias.'.'.trim($content);
            }

            return $function.'('.$content.')';
        }, $mainExpression);

        // Pattern to match column names that should be prefixed (not followed by parentheses)
        $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/';

        // List of keywords and functions that should not be prefixed
        $keywords = ['AND', 'and', 'OR', 'or', 'NOT', 'not', 'IN', 'in',
            'IS', 'is', 'NULL', 'null', 'TRUE', 'true', 'FALSE', 'false',
            'ASC', 'asc', 'DESC', 'desc', 'DISTINCT', 'distinct',
            'CASE', 'case', 'WHEN', 'when', 'THEN', 'then', 'END', 'end', 'ELSE', 'else'];

        $prefixedExpression = preg_replace_callback($pattern, function ($matches) use ($alias, $keywords) {
            $word = $matches[1];

            // Skip if it's a keyword
            if (in_array($word, $keywords)) {
                return $matches[0];
            }

            // Skip if it's the alias itself
            if ($word === $alias) {
                return $matches[0];
            }

            // Skip if it's a number or quoted string
            if (is_numeric($word) || preg_match('/^["\']/', $word)) {
                return $matches[0];
            }

            return "$alias.$word";
        }, $mainExpression);

        // Recombine with the alias if it exists (without prefixing the alias)
        if ($aliasName !== null) {
            return $prefixedExpression.' as '.$aliasName;
        }

        return $prefixedExpression;
    }
}
