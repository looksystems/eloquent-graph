<?php

namespace Look\EloquentCypher\Traits;

trait CypherOperatorConverter
{
    /**
     * Convert SQL operators to Cypher equivalents.
     */
    protected function convertToCypherOperator(string $operator): string
    {
        return match ($operator) {
            '!=' => '<>',
            'LIKE' => 'CONTAINS',
            'like' => 'CONTAINS',
            default => $operator,
        };
    }

    /**
     * Check if an operator needs conversion for Cypher.
     */
    protected function needsCypherConversion(string $operator): bool
    {
        return in_array($operator, ['!=', 'LIKE', 'like'], true);
    }

    /**
     * Convert operator for use in Cypher queries.
     * This is a convenience method that matches the existing pattern usage.
     */
    protected function getCypherOperator(string $operator): string
    {
        return $this->convertToCypherOperator($operator);
    }
}
