<?php

namespace Look\EloquentCypher\Builders;

use Look\EloquentCypher\Traits\CypherOperatorConverter;

class WhereClauseBuilder
{
    use CypherOperatorConverter;

    protected \Look\EloquentCypher\GraphQueryBuilder $query;

    protected array $bindings = [];

    public function __construct(\Look\EloquentCypher\GraphQueryBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Check if APOC should be used for JSON operations.
     */
    protected function shouldUseApoc(): bool
    {
        $connection = $this->query->getConnection();

        return method_exists($connection, 'shouldUseApocForJson') && $connection->shouldUseApocForJson();
    }

    public function build(array $wheres, array &$bindings, ?array $context = null): string
    {
        $clauses = [];

        foreach ($wheres as $index => $where) {
            $clause = $this->buildSingleClause($where, $index, $bindings, $context);

            if ($clause) {
                if (! empty($clauses)) {
                    $boolean = strtoupper($where['boolean'] ?? 'AND');
                    $clauses[] = "{$boolean} {$clause}";
                } else {
                    $clauses[] = $clause;
                }
            }
        }

        return implode(' ', $clauses);
    }

    public function buildSingleWhereClause(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        return $this->buildSingleClause($where, $index, $bindings, $context);
    }

    protected function buildSingleClause(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        // Handle array columns first (e.g., from firstOrCreate)
        // This needs to be checked before type-based handling
        if (isset($where['column']) && is_array($where['column'])) {
            return $this->buildArrayColumn($where['column'], $index, $bindings);
        }

        // Handle special types
        if (isset($where['type'])) {
            switch ($where['type']) {
                case 'Raw':
                    return $this->buildRaw($where, $bindings);
                case 'Nested':
                    return $this->buildNested($where, $bindings, $context);
                case 'Column':
                    return $this->buildColumn($where, $index, $context);
                case 'Exists':
                    return $this->buildExists($where, $bindings, $context);
                case 'Basic':
                    return $this->buildBasic($where, $index, $bindings, $context);
                case 'Date':
                    return $this->buildDate($where, $index, $bindings);
                case 'Month':
                    return $this->buildMonth($where, $index, $bindings);
                case 'Year':
                    return $this->buildYear($where, $index, $bindings);
                case 'Time':
                    return $this->buildTime($where, $index, $bindings);
                case 'In':
                    return $this->buildIn($where, $index, $bindings);
                case 'InRaw':
                    // InRaw is like In but for integer keys - treat it the same as In
                    return $this->buildIn($where, $index, $bindings);
                case 'NotIn':
                    return $this->buildNotIn($where, $index, $bindings);
                case 'NotInRaw':
                    // NotInRaw is like NotIn but for integer keys - treat it the same as NotIn
                    return $this->buildNotIn($where, $index, $bindings);
                case 'Null':
                    return $this->buildNull($where);
                case 'NotNull':
                    return $this->buildNotNull($where);
                case 'Between':
                    return $this->buildBetween($where, $index, $bindings);
                case 'NotBetween':
                    return $this->buildNotBetween($where, $index, $bindings);
                case 'JsonContains':
                    return $this->buildJsonContains($where, $index, $bindings);
                case 'JsonLength':
                    return $this->buildJsonLength($where, $index, $bindings);
            }
        }

        // Default basic where
        if (isset($where['column'])) {
            return $this->buildDefault($where, $index, $bindings);
        }

        return '';
    }

    protected function buildRaw(array $where, array &$bindings): string
    {
        if (! empty($where['bindings'])) {
            $bindings = array_merge($bindings, $where['bindings']);
        }

        return $where['sql'] ?? '';
    }

    protected function buildNested(array $where, array &$bindings, ?array $context): string
    {
        if (! isset($where['query']) || ! $where['query'] instanceof \Look\EloquentCypher\GraphQueryBuilder) {
            return '';
        }

        $nestedQuery = $where['query'];
        if (empty($nestedQuery->wheres)) {
            return '';
        }

        $nestedBuilder = new self($nestedQuery);
        $nestedClause = $nestedBuilder->build($nestedQuery->wheres, $bindings, $context);

        return $nestedClause ? "({$nestedClause})" : '';
    }

    protected function buildColumn(array $where, int $index, ?array $context = null): string
    {
        $first = $where['first'] ?? '';
        $second = $where['second'] ?? '';
        $operator = $where['operator'] ?? '=';

        // If first or second is empty, there's an issue
        if (empty($first) || empty($second)) {
            return '';
        }

        $cypherOperator = ($operator === '!=') ? '<>' : $operator;

        // Extract table and column names if prefixed
        $firstParts = explode('.', $first);
        $secondParts = explode('.', $second);

        // Determine aliases based on context
        $firstAlias = 'n';
        $secondAlias = 'n';

        if (count($firstParts) === 2) {
            $firstTable = $firstParts[0];
            $firstColumn = $firstParts[1];

            // If we're in EXISTS context, use appropriate alias
            if ($context && isset($context['currentAlias']) && isset($context['parentAlias'])) {
                // Check if this table matches the subquery table
                if (isset($context['tables']) && in_array($firstTable, $context['tables'])) {
                    $firstAlias = $context['currentAlias'];
                } elseif (isset($context['parentTable']) && $firstTable === $context['parentTable']) {
                    // This is the parent table reference
                    $firstAlias = $context['parentAlias'];
                } else {
                    // Default to parent alias for unmatched tables
                    $firstAlias = $context['parentAlias'];
                }
            }
        } else {
            $firstColumn = $first;
            if ($context && isset($context['currentAlias'])) {
                $firstAlias = $context['currentAlias'];
            }
        }

        if (count($secondParts) === 2) {
            $secondTable = $secondParts[0];
            $secondColumn = $secondParts[1];

            // If we're in EXISTS context, use appropriate alias
            if ($context && isset($context['currentAlias']) && isset($context['parentAlias'])) {
                // Check if this table matches the subquery table
                if (isset($context['tables']) && in_array($secondTable, $context['tables'])) {
                    $secondAlias = $context['currentAlias'];
                } elseif (isset($context['parentTable']) && $secondTable === $context['parentTable']) {
                    // This is the parent table reference
                    $secondAlias = $context['parentAlias'];
                } else {
                    // Default to parent alias for unmatched tables
                    $secondAlias = $context['parentAlias'];
                }
            }
        } else {
            $secondColumn = $second;
            if ($context && isset($context['currentAlias'])) {
                $secondAlias = $context['currentAlias'];
            }
        }

        // Handle NULL comparisons for equality operator
        if ($operator === '=') {
            // In Neo4j (and SQL), NULL = NULL doesn't return true
            // We need to check if both are NULL or both have the same non-NULL value
            return "({$firstAlias}.{$firstColumn} {$cypherOperator} {$secondAlias}.{$secondColumn} OR ({$firstAlias}.{$firstColumn} IS NULL AND {$secondAlias}.{$secondColumn} IS NULL))";
        }

        return "{$firstAlias}.{$firstColumn} {$cypherOperator} {$secondAlias}.{$secondColumn}";
    }

    protected function buildExists(array $where, array &$bindings, ?array $context): string
    {
        if (! isset($where['query'])) {
            return '';
        }

        $query = $where['query'];
        $not = $where['not'] ?? false;

        // Build EXISTS clause based on query
        if ($query instanceof \Look\EloquentCypher\GraphQueryBuilder) {
            $existsClause = $this->buildExistsFromQuery($query, $bindings, $context);

            return $not ? "NOT {$existsClause}" : $existsClause;
        }

        return '';
    }

    protected function buildBasic(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? null;

        // If value is an array, convert to IN or NOT IN clause based on operator
        if (is_array($value)) {
            if ($operator === '=' || strtoupper($operator) === 'IN') {
                $where['type'] = 'In';
                $where['values'] = $value;

                return $this->buildIn($where, $index, $bindings, $context);
            } elseif ($operator === '!=' || $operator === '<>' || strtoupper($operator) === 'NOT IN') {
                $where['type'] = 'NotIn';
                $where['values'] = $value;

                return $this->buildNotIn($where, $index, $bindings, $context);
            }
        }

        $columnRef = $this->resolveColumn($column, $context);
        $paramName = $this->generateParamName($column, $index);

        // Handle null values
        if (is_null($value)) {
            return ($operator === '=' || $operator === 'IS')
                ? "{$columnRef} IS NULL"
                : "{$columnRef} IS NOT NULL";
        }

        // Handle LIKE operators
        if (strtoupper($operator) === 'LIKE') {
            $likeValue = str_replace('%', '', $value);
            $bindings[$paramName] = $likeValue;

            return "{$columnRef} CONTAINS \${$paramName}";
        }

        if (strtoupper($operator) === 'NOT LIKE') {
            $likeValue = str_replace('%', '', $value);
            $bindings[$paramName] = $likeValue;

            return "NOT {$columnRef} CONTAINS \${$paramName}";
        }

        $cypherOperator = $this->getCypherOperator($operator);
        $bindings[$paramName] = $value;

        return "{$columnRef} {$cypherOperator} \${$paramName}";
    }

    protected function buildDate(array $where, int $index, array &$bindings): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? '';

        $columnRef = $this->resolveColumn($column);
        $paramName = $this->generateParamName($column, $index);
        $cypherOperator = $this->getCypherOperator($operator);

        $dateValue = $this->formatDateValue($value);
        $bindings[$paramName] = $dateValue;

        // Handle both string and datetime formats
        return "CASE WHEN {$columnRef} IS NULL THEN false "
            ."WHEN toString({$columnRef}) CONTAINS 'T' "
            ."THEN date({$columnRef}) {$cypherOperator} date(\${$paramName}) "
            ."ELSE substring(toString({$columnRef}), 0, 10) {$cypherOperator} \${$paramName} END";
    }

    protected function buildMonth(array $where, int $index, array &$bindings): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? '';

        $columnRef = $this->resolveColumn($column);
        $paramName = $this->generateParamName($column, $index);
        $cypherOperator = $this->getCypherOperator($operator);

        $bindings[$paramName] = (int) $value;
        $bindings[$paramName.'_str'] = str_pad($value, 2, '0', STR_PAD_LEFT);

        // Handle both string and datetime formats
        return "CASE WHEN {$columnRef} IS NULL THEN false "
            ."WHEN toString({$columnRef}) CONTAINS 'T' "
            ."THEN datetime({$columnRef}).month {$cypherOperator} \${$paramName} "
            ."ELSE substring(toString({$columnRef}), 5, 2) {$cypherOperator} \${$paramName}_str END";
    }

    protected function buildYear(array $where, int $index, array &$bindings): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? '';

        $columnRef = $this->resolveColumn($column);
        $paramName = $this->generateParamName($column, $index);
        $cypherOperator = $this->getCypherOperator($operator);

        $bindings[$paramName] = (int) $value;
        $bindings[$paramName.'_str'] = (string) $value;

        // Handle both string and datetime formats
        return "CASE WHEN {$columnRef} IS NULL THEN false "
            ."WHEN toString({$columnRef}) CONTAINS 'T' "
            ."THEN datetime({$columnRef}).year {$cypherOperator} \${$paramName} "
            ."ELSE substring(toString({$columnRef}), 0, 4) {$cypherOperator} \${$paramName}_str END";
    }

    protected function formatDateValue($dateValue): string
    {
        if ($dateValue instanceof \DateTimeInterface || $dateValue instanceof \Carbon\Carbon) {
            return $dateValue->format('Y-m-d');
        }

        if (is_string($dateValue)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateValue, $matches)) {
                return $matches[0];
            }

            try {
                return (new \DateTime($dateValue))->format('Y-m-d');
            } catch (\Exception $e) {
                return $dateValue;
            }
        }

        return $dateValue;
    }

    protected function formatValueForBetween($value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }
        if ($value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d\TH:i:s');
        }

        return $value;
    }

    protected function buildTime(array $where, int $index, array &$bindings): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? '';

        $columnRef = $this->resolveColumn($column);
        $paramName = $this->generateParamName($column, $index);
        $cypherOperator = $this->getCypherOperator($operator);

        // Format time value properly
        $timeValue = $this->formatTimeValue($value);
        $bindings[$paramName] = $timeValue;

        // Handle both string and datetime formats
        return "CASE WHEN {$columnRef} IS NULL THEN false "
            ."WHEN toString({$columnRef}) CONTAINS 'T' "
            ."THEN time({$columnRef}) {$cypherOperator} time(\${$paramName}) "
            ."ELSE substring(toString({$columnRef}), 11, 8) {$cypherOperator} \${$paramName} END";
    }

    protected function formatTimeValue($value): string
    {
        if ($value instanceof \DateTimeInterface || $value instanceof \Carbon\Carbon) {
            return $value->format('H:i:s');
        }

        if (is_string($value)) {
            if (preg_match('/\d{2}:\d{2}:\d{2}/', $value, $matches)) {
                return $matches[0];
            }

            try {
                return (new \DateTime($value))->format('H:i:s');
            } catch (\Exception $e) {
                return $value;
            }
        }

        return $value;
    }

    protected function buildIn(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        $column = $where['column'] ?? '';
        $values = $where['values'] ?? [];

        if (empty($values)) {
            return '1 = 0'; // Always false
        }

        $columnRef = $this->resolveColumn($column, $context);
        $paramName = $this->generateParamName($column, $index).'_in';

        // Handle subqueries (when values is a closure)
        if ($values instanceof \Closure) {
            // For subqueries, we need to handle them differently
            // This is a placeholder - subqueries aren't fully supported yet
            return '1 = 0';
        }

        // Ensure values is a numeric array (List) for Neo4j, not an associative array (Map)
        // Use array_values to reset keys and ensure it's an indexed array
        $bindings[$paramName] = is_array($values) ? array_values($values) : [];

        return "{$columnRef} IN \${$paramName}";
    }

    protected function buildNotIn(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        $column = $where['column'] ?? '';
        $values = $where['values'] ?? [];

        if (empty($values)) {
            return ''; // No constraint needed
        }

        $columnRef = $this->resolveColumn($column, $context);
        $paramName = $this->generateParamName($column, $index).'_notin';
        // Ensure values is a numeric array (List) for Neo4j, not an associative array (Map)
        // Use array_values to reset keys and ensure it's an indexed array
        $bindings[$paramName] = array_values($values);

        return "NOT {$columnRef} IN \${$paramName}";
    }

    protected function buildNull(array $where): string
    {
        $column = $where['column'] ?? '';
        $columnRef = $this->resolveColumn($column);

        return "{$columnRef} IS NULL";
    }

    protected function buildNotNull(array $where): string
    {
        $column = $where['column'] ?? '';
        $columnRef = $this->resolveColumn($column);

        return "{$columnRef} IS NOT NULL";
    }

    protected function buildBetween(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        $column = $where['column'] ?? '';
        $values = $where['values'] ?? [];
        $not = $where['not'] ?? false;

        if (count($values) !== 2) {
            return '';
        }

        $columnRef = $this->resolveColumn($column, $context);
        $paramMin = $this->generateParamName($column, $index).'_min';
        $paramMax = $this->generateParamName($column, $index).'_max';

        // Format date values if they are date objects
        $formattedMin = $this->formatValueForBetween($values[0]);
        $formattedMax = $this->formatValueForBetween($values[1]);

        $bindings[$paramMin] = $formattedMin;
        $bindings[$paramMax] = $formattedMax;

        // Check if this is a date comparison
        $isDateComparison = ($values[0] instanceof \DateTimeInterface) ||
                            ($values[1] instanceof \DateTimeInterface) ||
                            (is_string($formattedMin) && preg_match('/^\d{4}-\d{2}-\d{2}/', $formattedMin)) ||
                            (is_string($formattedMax) && preg_match('/^\d{4}-\d{2}-\d{2}/', $formattedMax));

        // Always use direct comparison - Neo4j handles datetime strings natively
        $clause = "{$columnRef} >= \${$paramMin} AND {$columnRef} <= \${$paramMax}";

        return $not ? "NOT ({$clause})" : $clause;
    }

    protected function buildNotBetween(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        $where['not'] = true;

        return $this->buildBetween($where, $index, $bindings, $context);
    }

    protected function buildJsonContains(array $where, int $index, array &$bindings, ?array $context = null): string
    {
        $column = $where['column'] ?? '';
        $value = $where['value'] ?? null;
        $not = $where['not'] ?? false;

        // Extract path if column contains arrow notation
        $jsonPath = '';
        if (strpos($column, '->') !== false) {
            $parts = explode('->', $column, 2);
            $baseColumn = $parts[0];
            $jsonPath = trim($parts[1], "'\" ");
        } else {
            $baseColumn = $column;
        }

        $columnRef = $this->resolveColumn($baseColumn, $context);
        $paramName = $this->generateParamName(str_replace('->', '_', $column), $index);

        // Use APOC if available and enabled
        if ($this->shouldUseApoc()) {
            return $this->buildJsonContainsWithApoc($columnRef, $jsonPath, $value, $paramName, $not, $bindings);
        }

        // Fallback to string-based approach for Neo4j without APOC
        return $this->buildJsonContainsFallback($columnRef, $jsonPath, $value, $paramName, $not, $bindings);
    }

    protected function buildJsonContainsWithApoc(string $columnRef, string $jsonPath, $value, string $paramName, bool $not, array &$bindings): string
    {
        $bindings[$paramName] = $value;

        if ($jsonPath) {
            // Parse nested paths like "profile->role" or "permissions"
            $pathParts = explode('->', $jsonPath);

            // Build direct property access path for native Neo4j types
            // Example: n.preferences.theme or n.settings.profile.role
            $nativePath = $columnRef;
            foreach ($pathParts as $part) {
                $nativePath .= '.'.$part;
            }

            // Build APOC path for JSON strings
            $apocPath = 'apoc.convert.fromJsonMap('.$columnRef.')';
            foreach ($pathParts as $part) {
                $apocPath .= '.'.$part;
            }

            // Hybrid: Handle both native types AND JSON strings
            // IMPORTANT: Check if column is STRING first to avoid accessing nested props on strings
            $condition = '(CASE '
                // If column is a JSON string, parse with APOC
                ."WHEN valueType({$columnRef}) STARTS WITH 'STRING' THEN (CASE "
                    ."WHEN valueType({$apocPath}) STARTS WITH 'LIST' THEN \${$paramName} IN {$apocPath} "
                    ."WHEN {$apocPath} IS NOT NULL THEN {$apocPath} = \${$paramName} "
                    .'ELSE false END) '
                // If it's a native list, use IN operator
                ."WHEN valueType({$nativePath}) STARTS WITH 'LIST' THEN \${$paramName} IN {$nativePath} "
                // If it's a native scalar, use equality
                ."WHEN {$nativePath} IS NOT NULL THEN {$nativePath} = \${$paramName} "
                .'ELSE false END)';
        } else {
            // No path - check if column itself is a list or JSON string
            $apocList = 'apoc.convert.fromJsonList('.$columnRef.')';

            $condition = '(CASE '
                // Native list
                ."WHEN valueType({$columnRef}) STARTS WITH 'LIST' THEN \${$paramName} IN {$columnRef} "
                // JSON string array
                ."WHEN valueType({$columnRef}) STARTS WITH 'STRING' AND {$columnRef} STARTS WITH '[' THEN \${$paramName} IN {$apocList} "
                // Scalar
                ."WHEN {$columnRef} IS NOT NULL THEN {$columnRef} = \${$paramName} "
                .'ELSE false END)';
        }

        return $not ? "NOT {$condition}" : $condition;
    }

    protected function buildJsonContainsFallback(string $columnRef, string $jsonPath, $value, string $paramName, bool $not, array &$bindings): string
    {
        // Neo4j stores JSON with escaped quotes: "{\"key\":\"value\"}"
        if ($jsonPath) {
            // Check if this is likely searching in an array (common patterns)
            $isArraySearch = in_array($jsonPath, ['languages', 'tags', 'roles', 'permissions', 'sizes', 'colors', 'items', 'values', 'skills'])
                || strpos($jsonPath, 'items') !== false
                || strpos($jsonPath, 'array') !== false
                || strpos($jsonPath, 'list') !== false;

            if ($isArraySearch) {
                // For array contains, just search for the value
                if (is_string($value)) {
                    $searchPattern = '\"'.$value.'\"';
                } elseif (is_bool($value)) {
                    $searchPattern = $value ? 'true' : 'false';
                } elseif (is_null($value)) {
                    $searchPattern = 'null';
                } else {
                    $searchPattern = (string) $value;
                }
            } else {
                // For object property search
                if (is_bool($value)) {
                    $jsonValue = $value ? 'true' : 'false';
                    $searchPattern = '\"'.$jsonPath.'\":'.$jsonValue;
                } elseif (is_null($value)) {
                    $searchPattern = '\"'.$jsonPath.'\":null';
                } elseif (is_numeric($value)) {
                    $searchPattern = '\"'.$jsonPath.'\":'.$value;
                } else {
                    // String values need quotes
                    $searchPattern = '\"'.$jsonPath.'\":\"'.$value.'\"';
                }
            }

            $bindings[$paramName] = $searchPattern;
            $condition = "({$columnRef} CONTAINS \${$paramName})";
        } else {
            // For array contains, check if the JSON string contains the value
            if (is_string($value)) {
                $bindings[$paramName] = '\"'.$value.'\"';
            } elseif (is_bool($value)) {
                $bindings[$paramName] = $value ? 'true' : 'false';
            } else {
                $bindings[$paramName] = (string) $value;
            }

            $condition = "({$columnRef} CONTAINS \${$paramName})";
        }

        return $not ? "NOT {$condition}" : $condition;
    }

    protected function buildJsonLength(array $where, int $index, array &$bindings): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? 0;

        // Extract path if column contains arrow notation
        $jsonPath = '';
        if (strpos($column, '->') !== false) {
            $parts = explode('->', $column, 2);
            $baseColumn = $parts[0];
            $jsonPath = trim($parts[1], "'\" ");
        } else {
            $baseColumn = $column;
        }

        $columnRef = $this->resolveColumn($baseColumn);
        $paramName = $this->generateParamName(str_replace('->', '_', $column), $index).'_length';
        $cypherOperator = $this->getCypherOperator($operator);
        $bindings[$paramName] = (int) $value;

        // Use APOC if available and enabled
        if ($this->shouldUseApoc()) {
            return $this->buildJsonLengthWithApoc($columnRef, $jsonPath, $cypherOperator, $paramName);
        }

        // Fallback to string-based approach for Neo4j without APOC
        return $this->buildJsonLengthFallback($columnRef, $jsonPath, $value, $cypherOperator, $paramName);
    }

    protected function buildJsonLengthWithApoc(string $columnRef, string $jsonPath, string $cypherOperator, string $paramName): string
    {
        if ($jsonPath) {
            // Parse nested paths like "metadata->comments" or "skills"
            $pathParts = explode('->', $jsonPath);

            // Build direct property access path for native Neo4j types
            $nativePath = $columnRef;
            foreach ($pathParts as $part) {
                $nativePath .= '.'.$part;
            }

            // Build APOC path for JSON strings
            $apocPath = 'apoc.convert.fromJsonMap('.$columnRef.')';
            foreach ($pathParts as $part) {
                $apocPath .= '.'.$part;
            }

            // Hybrid: Handle both native types AND JSON strings
            // IMPORTANT: Check if column is STRING first to avoid accessing nested props on strings
            return '(CASE '
                // JSON string - parse with APOC
                ."WHEN valueType({$columnRef}) STARTS WITH 'STRING' THEN (CASE "
                    ."WHEN valueType({$apocPath}) STARTS WITH 'LIST' THEN size({$apocPath}) "
                    ."WHEN valueType({$apocPath}) STARTS WITH 'MAP' THEN size(keys({$apocPath})) "
                    .'ELSE 0 END) '
                // Native list
                ."WHEN valueType({$nativePath}) STARTS WITH 'LIST' THEN size({$nativePath}) "
                // Native map (shouldn't happen for this operation, but handle it)
                ."WHEN valueType({$nativePath}) STARTS WITH 'MAP' THEN size(keys({$nativePath})) "
                ."ELSE 0 END {$cypherOperator} \${$paramName})";
        } else {
            // Top-level - can be native LIST or JSON string
            // Note: Top-level associative arrays become JSON strings (due to nesting check)
            // So we only handle LIST (native) or STRING (JSON)
            $apocList = 'apoc.convert.fromJsonList('.$columnRef.')';
            $apocMap = 'apoc.convert.fromJsonMap('.$columnRef.')';

            return '(CASE '
                // Native list (flat arrays like ['php', 'js'])
                ."WHEN valueType({$columnRef}) STARTS WITH 'LIST' THEN size({$columnRef}) "
                // JSON string array
                ."WHEN valueType({$columnRef}) STARTS WITH 'STRING' AND {$columnRef} STARTS WITH '[' THEN size({$apocList}) "
                // JSON string object (associative array stored as JSON)
                ."WHEN valueType({$columnRef}) STARTS WITH 'STRING' THEN size(keys({$apocMap})) "
                ."ELSE 0 END {$cypherOperator} \${$paramName})";
        }
    }

    protected function buildJsonLengthFallback(string $columnRef, string $jsonPath, int $value, string $cypherOperator, string $paramName): string
    {
        // String-based fallback for Neo4j without APOC
        if ($jsonPath) {
            // For nested paths, this is complex without APOC
            return '1 = 1'; // Always true as a fallback
        } else {
            // For top-level arrays, check for empty
            if ($value === 0) {
                return "({$columnRef} = '[]' OR {$columnRef} = '{}')";
            } else {
                // For non-zero counts, this is an approximation
                return '1 = 1'; // Fallback for complex length checks
            }
        }
    }

    protected function buildArrayColumn(array $columns, int $index, array &$bindings): string
    {
        $conditions = [];

        foreach ($columns as $key => $value) {
            $paramName = $this->generateParamName($key, $index).'_'.time();
            // Array columns use simple key as column name
            $conditions[] = "n.{$key} = \${$paramName}";
            $bindings[$paramName] = $value;
        }

        return '('.implode(' AND ', $conditions).')';
    }

    protected function buildDefault(array $where, int $index, array &$bindings): string
    {
        $column = $where['column'] ?? '';
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? null;

        $columnRef = $this->resolveColumn($column);
        $paramName = $this->generateParamName($column, $index);
        $cypherOperator = $this->getCypherOperator($operator);

        $bindings[$paramName] = $value;

        return "{$columnRef} {$cypherOperator} \${$paramName}";
    }

    protected function buildExistsFromQuery(\Look\EloquentCypher\GraphQueryBuilder $subQuery, array &$bindings, ?array $context): string
    {
        // Initialize context if not provided
        if (is_null($context)) {
            $context = [
                'depth' => 0,
                'aliases' => [],
                'tables' => [],
                'parentTable' => $this->query->from ?? $this->query->nodeLabel ?? 'users',
            ];
        }

        // Get the subquery's table/label
        $subLabel = $subQuery->from ?? $subQuery->nodeLabel ?? 'nodes';

        // Generate unique alias for this EXISTS level
        $currentAlias = $context['depth'] === 0 ? 'sub' : 'sub'.$context['depth'];

        // Build the EXISTS clause
        $existsClause = 'EXISTS {';
        $existsClause .= ' MATCH ('.$currentAlias.':'.$subLabel.')';

        // Build WHERE conditions from the subquery
        if (! empty($subQuery->wheres)) {
            $subBindings = [];

            // Create new context for nested queries
            $newContext = [
                'depth' => $context['depth'] + 1,
                'aliases' => array_merge($context['aliases'], [$subLabel => $currentAlias]),
                'tables' => [$subLabel],  // Only the subquery table
                'parentTable' => $context['parentTable'] ?? 'users',
                'parentAlias' => $context['depth'] === 0 ? 'n' : ($context['currentAlias'] ?? 'n'),
                'currentAlias' => $currentAlias,
            ];

            // Build the WHERE clause with context (delegate back to query for complex logic)
            $subWhereClause = $subQuery->buildWhereClause($subBindings, $newContext);
            if ($subWhereClause) {
                // No need for simple string replacement - context handles it
                $existsClause .= ' WHERE '.$subWhereClause;
            }
            // Merge the subquery bindings
            $bindings = array_merge($bindings, $subBindings);
        }

        $existsClause .= ' }';

        return $existsClause;
    }

    protected function resolveColumn($column, ?array $context = null): string
    {
        // Handle array columns or non-string columns
        if (! is_string($column)) {
            $defaultAlias = ($context && isset($context['currentAlias'])) ? $context['currentAlias'] : 'n';

            return $defaultAlias.'.'.(string) $column;
        }

        // Handle EXISTS context
        if ($context && isset($context['currentAlias']) && isset($context['parentAlias'])) {
            // Check if column has table prefix
            if (strpos($column, '.') !== false) {
                [$table, $col] = explode('.', $column, 2);

                // Check if this table matches the subquery table
                if (isset($context['tables']) && in_array($table, $context['tables'])) {
                    return $context['currentAlias'].'.'.$col;
                } elseif (isset($context['parentTable']) && $table === $context['parentTable']) {
                    // This is the parent table reference
                    return $context['parentAlias'].'.'.$col;
                } else {
                    // Default to parent alias for unmatched tables
                    return $context['parentAlias'].'.'.$col;
                }
            } else {
                // No table prefix, assume it refers to the current subquery table
                return $context['currentAlias'].'.'.$column;
            }
        }

        // Handle joins - check if column has table prefix
        if (! empty($this->query->neo4jJoins) && strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $alias = $this->query->getAliasForTable($table);

            return "{$alias}.{$col}";
        }

        // If column has dots but we're not in join context, extract just the column name
        if (strpos($column, '.') !== false) {
            $parts = explode('.', $column);
            $column = end($parts);
        }

        // Default to main table alias
        return "n.{$column}";
    }

    protected function generateParamName(string $column, int $index): string
    {
        return str_replace(['.', '->', ' '], '_', $column).'_'.$index;
    }
}
