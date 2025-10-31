<?php

namespace Look\EloquentCypher\Patterns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

class GraphPatternBuilder
{
    protected ConnectionInterface $connection;

    protected string $pattern = '';

    protected array $whereConditions = [];

    protected array $returnFields = [];

    protected array $groupByFields = [];

    protected array $bindings = [];

    public function __construct(ConnectionInterface $connection, ?string $pattern = null, ?array $select = null)
    {
        $this->connection = $connection;
        if ($pattern) {
            $this->pattern = $pattern;
        }
        if ($select) {
            $this->returnFields = $select;
        }
    }

    /**
     * Set the graph pattern to match.
     */
    public function match(string $pattern): self
    {
        $this->pattern = $pattern;

        return $this;
    }

    /**
     * Add a where condition.
     */
    public function where(string $condition): self
    {
        $this->whereConditions[] = $condition;

        return $this;
    }

    /**
     * Set the return fields.
     */
    public function return(array $fields): self
    {
        $this->returnFields = $fields;

        return $this;
    }

    /**
     * Set select fields for joinPattern method.
     */
    public function select(array $fields): self
    {
        $this->returnFields = $fields;

        return $this;
    }

    /**
     * Add group by clause.
     */
    public function groupBy(string $field): self
    {
        $this->groupByFields[] = $field;

        return $this;
    }

    /**
     * Execute the pattern query and return results.
     */
    public function get(): Collection
    {
        $cypher = $this->buildCypher();
        $results = $this->connection->select($cypher, $this->bindings);

        return collect($results)->map(function ($result) {
            return (object) $result;
        });
    }

    /**
     * Build the Cypher query from the pattern.
     */
    protected function buildCypher(): string
    {
        $cypher = "MATCH {$this->pattern}";

        if (! empty($this->whereConditions)) {
            $cypher .= ' WHERE '.implode(' AND ', $this->whereConditions);
        }

        if (! empty($this->returnFields)) {
            $cypher .= ' RETURN '.implode(', ', $this->returnFields);
        } else {
            // Default return based on pattern variables
            $variables = $this->extractVariablesFromPattern();
            if (! empty($variables)) {
                $cypher .= ' RETURN '.implode(', ', $variables);
            }
        }

        if (! empty($this->groupByFields)) {
            $cypher .= ' GROUP BY '.implode(', ', $this->groupByFields);
        }

        return $cypher;
    }

    /**
     * Extract variable names from the pattern.
     */
    protected function extractVariablesFromPattern(): array
    {
        $variables = [];

        // Simple regex to extract variables like (u:User), (p:Post), etc.
        if (preg_match_all('/\((\w+):[^)]+\)/', $this->pattern, $matches)) {
            foreach ($matches[1] as $variable) {
                $variables[] = $variable;
            }
        }

        return array_unique($variables);
    }
}
