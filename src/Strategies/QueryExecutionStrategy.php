<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Strategies;

abstract class QueryExecutionStrategy
{
    protected \Look\EloquentCypher\GraphQueryBuilder $builder;

    protected array $bindings = [];

    public function __construct(\Look\EloquentCypher\GraphQueryBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Execute the query strategy and return Cypher query string with bindings
     */
    abstract public function execute(array $columns = ['*']): array;

    /**
     * Build the initial MATCH clause
     */
    protected function buildMatchClause(string $label): string
    {
        if (! empty($this->builder->neo4jJoins)) {
            return $this->builder->buildMatchWithJoins($label);
        }

        return $this->builder->queryComponents->buildMatch($label, 'n');
    }

    /**
     * Build WHERE clause combining regular wheres and join conditions
     */
    protected function buildWhereClause(): ?string
    {
        $whereParts = [];

        // Add join conditions (for inner/cross joins only)
        if (! empty($this->builder->joinWhereConditions)) {
            $whereParts = array_merge($whereParts, $this->builder->joinWhereConditions);
        }

        // Add regular where conditions
        if (! empty($this->builder->wheres)) {
            $whereClause = $this->builder->buildWhereClause($this->bindings);
            if ($whereClause) {
                $whereParts[] = $whereClause;
            }
        }

        if (empty($whereParts)) {
            return null;
        }

        return ' WHERE '.implode(' AND ', $whereParts);
    }

    /**
     * Add OPTIONAL MATCH for left joins after WHERE clause
     */
    protected function addLeftJoins(string $cypher): string
    {
        if (empty($this->builder->leftJoinsToProcess)) {
            return $cypher;
        }

        foreach ($this->builder->leftJoinsToProcess as $leftJoin) {
            $cypher .= " OPTIONAL MATCH {$leftJoin['pattern']}";
            if ($leftJoin['condition']) {
                $cypher .= " WHERE {$leftJoin['condition']}";
            }
        }

        return $cypher;
    }

    /**
     * Build ORDER BY clause
     */
    protected function buildOrderByClause(array $columnAliasMap = []): string
    {
        if (empty($this->builder->orders)) {
            return '';
        }

        // When we have WITH, columns are already aliased
        if (! empty($columnAliasMap)) {
            $orderClauses = [];
            foreach ($this->builder->orders as $order) {
                $direction = strtoupper($order['direction'] ?? 'ASC');
                $column = $order['column'] ?? '';

                // Check if this column is in our alias map
                if (isset($columnAliasMap[$column])) {
                    $orderClauses[] = "$column $direction";
                } else {
                    $orderClauses[] = "$column $direction";
                }
            }

            if (! empty($orderClauses)) {
                return ' ORDER BY '.implode(', ', $orderClauses);
            }
        }

        // For non-WITH queries
        if (! empty($this->builder->neo4jJoins)) {
            return ' '.$this->builder->buildOrderByWithJoins($this->builder->orders);
        }

        return ' '.$this->builder->queryComponents->buildOrderBy($this->builder->orders, 'n');
    }

    /**
     * Build LIMIT/SKIP clause
     */
    protected function buildLimitClause(): string
    {
        $limitClause = $this->builder->queryComponents->buildLimit(
            $this->builder->limit,
            $this->builder->offset
        );

        return $limitClause ? ' '.$limitClause : '';
    }

    /**
     * Build RETURN clause
     */
    protected function buildReturnClause(array $columns, bool $isDistinct = false): string
    {
        if (! empty($this->builder->neo4jJoins)) {
            return ' '.$this->builder->buildReturnWithJoins($columns, $isDistinct);
        }

        return ' '.$this->builder->queryComponents->buildReturn($columns, 'n', $isDistinct);
    }

    /**
     * Get the qualified label
     */
    protected function getQualifiedLabel(): string
    {
        return $this->builder->getQualifiedLabel($this->builder->nodeLabel ?: 'Node');
    }

    /**
     * Get bindings array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
