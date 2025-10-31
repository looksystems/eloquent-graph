<?php

namespace Look\EloquentCypher\Helpers;

class ExistsClauseBuilder
{
    /**
     * Build a basic EXISTS clause for a relationship check.
     */
    public static function buildBasicExists(
        string $matchPattern,
        array $conditions = [],
        array $additionalClauses = []
    ): string {
        $exists = 'EXISTS { '.$matchPattern;

        if (! empty($conditions)) {
            $exists .= ' WHERE '.implode(' AND ', $conditions);
        }

        foreach ($additionalClauses as $clause) {
            $exists .= ' '.$clause;
        }

        $exists .= ' }';

        return $exists;
    }

    /**
     * Build an EXISTS clause for a simple relationship.
     */
    public static function buildRelationExists(
        string $parentAlias,
        string $relatedAlias,
        string $relatedTable,
        string $foreignKey,
        string $parentKey,
        array $additionalConditions = []
    ): string {
        $matchPattern = "MATCH ($relatedAlias:$relatedTable)";
        $conditions = ["$relatedAlias.$foreignKey = $parentAlias.$parentKey"];

        if (! empty($additionalConditions)) {
            $conditions = array_merge($conditions, $additionalConditions);
        }

        return self::buildBasicExists($matchPattern, $conditions);
    }

    /**
     * Build an EXISTS clause for a morphable relationship.
     */
    public static function buildMorphExists(
        string $parentAlias,
        string $relatedAlias,
        string $relatedTable,
        string $foreignKey,
        string $parentKey,
        string $morphType,
        string $morphClass,
        array $additionalConditions = []
    ): string {
        $matchPattern = "MATCH ($relatedAlias:$relatedTable)";
        $conditions = [
            "$relatedAlias.$foreignKey = $parentAlias.$parentKey",
            "$relatedAlias.$morphType = '$morphClass'",
        ];

        if (! empty($additionalConditions)) {
            $conditions = array_merge($conditions, $additionalConditions);
        }

        return self::buildBasicExists($matchPattern, $conditions);
    }

    /**
     * Build an EXISTS clause for a pivot/many-to-many relationship.
     */
    public static function buildPivotExists(
        string $parentAlias,
        string $pivotAlias,
        string $relatedAlias,
        string $pivotLabel,
        string $relatedLabel,
        string $foreignPivotKey,
        string $parentKey,
        string $relatedPivotKey,
        string $relatedKey,
        array $additionalConditions = []
    ): string {
        $matchPattern = "MATCH ($pivotAlias:$pivotLabel), ($relatedAlias:$relatedLabel)";
        $conditions = [
            "$pivotAlias.$foreignPivotKey = $parentAlias.$parentKey",
            "$pivotAlias.$relatedPivotKey = $relatedAlias.$relatedKey",
        ];

        if (! empty($additionalConditions)) {
            $conditions = array_merge($conditions, $additionalConditions);
        }

        return self::buildBasicExists($matchPattern, $conditions);
    }

    /**
     * Build an EXISTS clause with a subquery.
     */
    public static function buildSubqueryExists(string $subquery): string
    {
        return "EXISTS { $subquery }";
    }

    /**
     * Build a NOT EXISTS clause.
     */
    public static function buildNotExists(string $existsClause): string
    {
        // If it already starts with EXISTS, replace with NOT EXISTS
        if (str_starts_with($existsClause, 'EXISTS')) {
            return 'NOT '.$existsClause;
        }

        // Otherwise wrap in NOT EXISTS
        return "NOT EXISTS { $existsClause }";
    }

    /**
     * Build an EXISTS clause for has-many-through relationships.
     */
    public static function buildHasManyThroughExists(
        string $parentAlias,
        string $throughAlias,
        string $relatedAlias,
        string $throughLabel,
        string $relatedLabel,
        string $firstKey,
        string $parentKey,
        string $secondKey,
        string $throughKey,
        array $additionalConditions = []
    ): string {
        $matchPattern = "MATCH ($throughAlias:$throughLabel), ($relatedAlias:$relatedLabel)";
        $conditions = [
            "$throughAlias.$firstKey = $parentAlias.$parentKey",
            "$relatedAlias.$secondKey = $throughAlias.$throughKey",
        ];

        if (! empty($additionalConditions)) {
            $conditions = array_merge($conditions, $additionalConditions);
        }

        return self::buildBasicExists($matchPattern, $conditions);
    }

    /**
     * Add count condition to an EXISTS clause.
     */
    public static function addCountCondition(
        string $existsClause,
        string $countAlias,
        string $operator,
        int $count
    ): string {
        // Remove the closing }
        $existsClause = rtrim($existsClause, ' }');

        // Add WITH COUNT and condition
        $existsClause .= " WITH COUNT($countAlias) as count WHERE count $operator $count }";

        return $existsClause;
    }
}
