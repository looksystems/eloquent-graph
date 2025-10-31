<?php

namespace Look\EloquentCypher\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Look\EloquentCypher\Builders\Neo4jCypherDslBuilder query()
 * @method static \WikibaseSolutions\CypherDSL\Patterns\Node node(?string $label = null)
 * @method static \WikibaseSolutions\CypherDSL\Parameter parameter(string $name)
 * @method static \WikibaseSolutions\CypherDSL\Patterns\Path relationship()
 *
 * @see \Look\EloquentCypher\Support\CypherDslFactory
 */
class Cypher extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'neo4j.cypher.dsl';
    }
}
