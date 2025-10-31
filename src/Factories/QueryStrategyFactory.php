<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Factories;

use Look\EloquentCypher\Strategies\GroupByQueryStrategy;
use Look\EloquentCypher\Strategies\QueryExecutionStrategy;
use Look\EloquentCypher\Strategies\StandardQueryStrategy;

class QueryStrategyFactory
{
    /**
     * Create the appropriate query strategy based on builder state
     */
    public static function create(\Look\EloquentCypher\GraphQueryBuilder $builder): QueryExecutionStrategy
    {
        // GROUP BY queries need special handling with WITH clause
        if (! empty($builder->groups) || ! empty($builder->havings)) {
            return new GroupByQueryStrategy($builder);
        }

        // Standard query handles joins, DISTINCT, and regular queries
        return new StandardQueryStrategy($builder);
    }
}
