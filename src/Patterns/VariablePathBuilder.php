<?php

namespace Look\EloquentCypher\Patterns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

class VariablePathBuilder
{
    protected ConnectionInterface $connection;

    protected $fromId;

    protected int $minHops = 1;

    protected int $maxHops = 5;

    protected array $nodeTypes = [];

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Set the starting node ID.
     */
    public function from($id): self
    {
        $this->fromId = $id;

        return $this;
    }

    /**
     * Set minimum number of hops.
     */
    public function minHops(int $hops): self
    {
        $this->minHops = $hops;

        return $this;
    }

    /**
     * Set maximum number of hops.
     */
    public function maxHops(int $hops): self
    {
        $this->maxHops = $hops;

        return $this;
    }

    /**
     * Filter by node types.
     */
    public function nodeTypes(array $types): self
    {
        $this->nodeTypes = $types;

        return $this;
    }

    /**
     * Execute the variable path query and return results.
     */
    public function get(): Collection
    {
        $cypher = $this->buildVariablePathCypher();
        $results = $this->connection->select($cypher, [
            'fromId' => $this->fromId,
        ]);

        return collect($results)->map(function ($result) {
            return (object) $result;
        });
    }

    /**
     * Build the variable path Cypher query.
     */
    protected function buildVariablePathCypher(): string
    {
        $nodeFilter = '';
        if (! empty($this->nodeTypes)) {
            $typePatterns = array_map(function ($type) {
                return ":{$type}";
            }, $this->nodeTypes);
            $nodeFilter = implode('|', $typePatterns);
        }

        $cypher = "
            MATCH (start)
            WHERE id(start) = \$fromId
            MATCH (start)-[*{$this->minHops}..{$this->maxHops}]-(connected{$nodeFilter})
            RETURN DISTINCT connected, labels(connected) as node_labels, id(connected) as node_id
        ";

        return $cypher;
    }
}
