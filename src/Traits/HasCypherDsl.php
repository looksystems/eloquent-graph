<?php

namespace Look\EloquentCypher\Traits;

use WikibaseSolutions\CypherDSL\Query;

trait HasCypherDsl
{
    /**
     * Start a new DSL query for this model (static).
     */
    public static function match(): \Look\EloquentCypher\Builders\GraphCypherDslBuilder
    {
        $instance = new static;
        $connection = $instance->getConnection();

        return $connection->cypher()
            ->withModel(static::class)
            ->match(Query::node($instance->getTable())->named('n'));
    }

    /**
     * Start a DSL query from this specific node instance.
     */
    public function matchFrom(): \Look\EloquentCypher\Builders\GraphCypherDslBuilder
    {
        // Create a node with property condition
        $node = Query::node($this->getTable())->named('n');

        return $this->getConnection()
            ->cypher()
            ->withModel(static::class)
            ->withSourceNode($this)
            ->match($node)
            ->where(
                Query::variable('n')->property($this->getKeyName())
                    ->equals(Query::literal($this->getKey()))
            );
    }
}
