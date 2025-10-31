<?php

namespace Look\EloquentCypher\Registry;

use Illuminate\Database\Eloquent\Relations\Relation;

class RelationHandlerRegistry
{
    /**
     * Get the handler method name for a relation's has query.
     */
    public static function getHasHandler(Relation $relation): ?string
    {
        return match (true) {
            $relation instanceof \Look\EloquentCypher\Relations\GraphHasMany => 'addNeo4jHasWhere',
            $relation instanceof \Look\EloquentCypher\Relations\GraphBelongsTo => 'addNeo4jHasWhereForBelongsTo',
            $relation instanceof \Look\EloquentCypher\Relations\GraphBelongsToMany => 'addNeo4jHasWhereForBelongsToMany',
            $relation instanceof \Look\EloquentCypher\Relations\GraphHasOne => 'addNeo4jHasWhereForHasOne',
            $relation instanceof \Look\EloquentCypher\Relations\GraphHasManyThrough => 'addNeo4jHasWhereForHasManyThrough',
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOne => 'addNeo4jHasWhereForMorphOne',
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany => 'addNeo4jHasWhereForMorphMany',
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo => 'addNeo4jHasWhereForMorphTo',
            default => null,
        };
    }

    /**
     * Get the count value for a relation.
     */
    public static function getCountValue(Relation $relation): int
    {
        return match (true) {
            $relation instanceof \Look\EloquentCypher\Relations\GraphHasMany,
            $relation instanceof \Look\EloquentCypher\Relations\GraphBelongsToMany,
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany,
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphToMany,
            $relation instanceof \Look\EloquentCypher\Relations\GraphMorphToMany => $relation->count(),

            $relation instanceof \Look\EloquentCypher\Relations\GraphHasOne,
            $relation instanceof \Look\EloquentCypher\Relations\GraphBelongsTo,
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOne => $relation->getQuery()->getQuery()->count(),

            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo => $relation->first() ? 1 : 0,

            default => 0,
        };
    }

    /**
     * Check if relation supports through queries.
     */
    public static function supportsThroughQuery(Relation $relation): bool
    {
        return $relation instanceof \Look\EloquentCypher\Relations\GraphHasMany;
    }
}
