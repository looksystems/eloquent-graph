<?php

namespace Look\EloquentCypher\Patterns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

class ShortestPathBuilder
{
    protected ConnectionInterface $connection;

    protected $fromId;

    protected $toId;

    protected string $toNodeType = '';

    protected int $maxLength = 15;

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
     * Set the target node ID and optionally its type.
     */
    public function to($id, ?string $nodeType = null): self
    {
        $this->toId = $id;
        if ($nodeType) {
            $this->toNodeType = $nodeType;
        }

        return $this;
    }

    /**
     * Set maximum path length.
     */
    public function maxLength(int $length): self
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * Execute the shortest path query and return results.
     */
    public function get(): Collection
    {
        $cypher = $this->buildShortestPathCypher();
        $results = $this->connection->select($cypher, [
            'fromId' => $this->fromId,
            'toId' => $this->toId,
        ]);

        return collect($results)->map(function ($result) {
            return (object) $result;
        });
    }

    /**
     * Build the shortest path Cypher query.
     */
    protected function buildShortestPathCypher(): string
    {
        $toPattern = $this->toNodeType ? ":{$this->toNodeType}" : '';

        $cypher = "
            MATCH (start), (end{$toPattern})
            WHERE id(start) = \$fromId AND id(end) = \$toId
            MATCH path = shortestPath((start)-[*1..{$this->maxLength}]-(end))
            RETURN path, length(path) as path_length, nodes(path) as path_nodes, relationships(path) as path_relationships
        ";

        return $cypher;
    }
}
