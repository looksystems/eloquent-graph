<?php

namespace Look\EloquentCypher\Handlers;

use Closure;

class PivotHandler
{
    /**
     * Extract pivot table information from a BelongsToMany relation.
     */
    public function extractPivotTableInfo($relation): array
    {
        return [
            'parentAlias' => 'n',
            'relatedAlias' => 'related',
            'relationAlias' => 'rel',
            'relationshipType' => $relation->getRelationshipType(),
            'relatedTable' => $relation->getRelated()->getTable(),
        ];
    }

    /**
     * Build pivot constraints from relation and callback.
     */
    public function buildPivotConstraints($builder, $relation, ?Closure $callback, string $relatedAlias, string $relationAlias): array
    {
        if (! $callback) {
            return [];
        }

        $whereClauses = [];
        $callbackRelation = clone $relation;
        $callback($callbackRelation);

        // Get regular where clauses for the related model
        $relatedQuery = $callbackRelation->getQuery();
        $whereClause = $builder->buildWhereClauseFromQuery($relatedQuery, $relatedAlias);
        if ($whereClause) {
            $whereClauses[] = $whereClause;
        }

        // Check for pivot wheres (accessed via reflection)
        $pivotClause = $builder->buildPivotWhereClausesFromRelation($callbackRelation, $relationAlias);
        if ($pivotClause) {
            $whereClauses[] = $pivotClause;
        }

        return $whereClauses;
    }

    /**
     * Build the EXISTS condition for pivot relationships.
     */
    public function buildPivotExistsCondition(string $matchPattern, array $whereClauses, string $operator, int $count, array $pivotInfo): string
    {
        $baseCondition = "EXISTS { $matchPattern";

        if (! empty($whereClauses)) {
            $baseCondition .= ' WHERE '.implode(' AND ', $whereClauses);
        }

        if ($operator !== '>=' || $count != 1) {
            // For count-based conditions
            $baseCondition .= " WITH count({$pivotInfo['relatedAlias']}) as count_val WHERE count_val $operator $count";
        }

        $baseCondition .= ' }';

        if ($operator === '<' && $count == 1) {
            return 'NOT EXISTS { '.$matchPattern.' }';
        }

        return $baseCondition;
    }

    /**
     * Build count condition for pivot relationships.
     */
    public function buildPivotCountCondition(string $matchPattern, array $whereClauses, string $operator, int $count, array $pivotInfo): string
    {
        if ($operator === '>=' && $count == 1) {
            // Simple existence check
            $condition = "EXISTS { $matchPattern";
            if (! empty($whereClauses)) {
                $condition .= ' WHERE '.implode(' AND ', $whereClauses);
            }

            return $condition.' }';
        }

        if ($operator === '<' && $count == 1) {
            // NOT EXISTS
            return "NOT EXISTS { $matchPattern }";
        }

        // For other count-based conditions
        $condition = "EXISTS { $matchPattern";
        if (! empty($whereClauses)) {
            $condition .= ' WHERE '.implode(' AND ', $whereClauses);
        }
        $condition .= " WITH count({$pivotInfo['relatedAlias']}) as rel_count WHERE rel_count $operator $count RETURN rel_count }";

        return $condition;
    }

    /**
     * Format a pivot value for Neo4j storage.
     */
    public function formatPivotValue($value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * Generate a parameter name for pivot columns.
     */
    public function generatePivotParamName(string $column): string
    {
        return 'pivot_'.str_replace('.', '_', $column);
    }

    /**
     * Build a pivot WHERE condition from a where clause array.
     */
    public function buildPivotWhereCondition(array $where, string $prefix = 'rel'): ?string
    {
        $pivotColumn = $prefix.'.'.$where['column'];
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

        if (isset($where['value'])) {
            $paramName = $this->generatePivotParamName($where['column']);
            $cypherOperator = $this->convertOperator($operator);

            return $pivotColumn.' '.$cypherOperator.' $'.$paramName;
        }

        return null;
    }

    /**
     * Convert SQL operator to Cypher operator.
     */
    protected function convertOperator(string $operator): string
    {
        return $operator === '!=' ? '<>' : $operator;
    }

    /**
     * Extract pivot parameters from where conditions.
     */
    public function extractPivotParams(array $pivotWheres): array
    {
        $params = [];

        foreach ($pivotWheres as $where) {
            $operator = $where['operator'] ?? '=';

            if ($operator !== 'is' && $operator !== 'is not') {
                $paramName = $this->generatePivotParamName($where['column']);
                $value = $where['values'] ?? $where['value'] ?? null;
                $params[$paramName] = $this->formatPivotValue($value);
            }
        }

        return $params;
    }
}
