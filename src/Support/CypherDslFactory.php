<?php

namespace Look\EloquentCypher\Support;

use Illuminate\Database\DatabaseManager;
use WikibaseSolutions\CypherDSL\Parameter;
use WikibaseSolutions\CypherDSL\Patterns\Node;
use WikibaseSolutions\CypherDSL\Patterns\Path;
use WikibaseSolutions\CypherDSL\Query;

class CypherDslFactory
{
    protected DatabaseManager $db;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new DSL builder instance.
     */
    public function query(): \Look\EloquentCypher\Builders\GraphCypherDslBuilder
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = $this->db->connection('graph');

        return new \Look\EloquentCypher\Builders\GraphCypherDslBuilder($connection);
    }

    /**
     * Create a node with optional label.
     */
    public function node(?string $label = null): Node
    {
        if ($label === null) {
            return Query::node();
        }

        return Query::node($label);
    }

    /**
     * Create a parameter with name.
     */
    public function parameter(string $name): Parameter
    {
        return Query::parameter($name);
    }

    /**
     * Create a basic relationship path between two nodes.
     * For simplicity, returns a Path which can be used in queries.
     */
    public function relationship(): Path
    {
        // Create a simple relationship pattern
        $node1 = Query::node();
        $node2 = Query::node();

        // Use undirected relationship (can go either way)
        return Query::relationship($node1, $node2, Path::DIR_UNI);
    }
}
