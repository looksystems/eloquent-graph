<?php

namespace Look\EloquentCypher\Casting\Casters;

use Look\EloquentCypher\Query\ParameterHelper;

/**
 * Caster for arrays that uses Neo4j's native map/list types.
 *
 * Unlike ArrayCaster which stores JSON strings, this caster
 * stores arrays as native Neo4j types (maps and lists).
 * This provides better performance and eliminates the need
 * for APOC when querying nested properties.
 */
class NativeArrayCaster extends BaseCaster
{
    public function castFromDatabase($value, array $options = []): ?array
    {
        if ($value === null) {
            return null;
        }

        // Neo4j driver already converts maps/lists to PHP arrays
        // Just return as-is
        return is_array($value) ? $value : (array) $value;
    }

    public function castForDatabase($value, array $options = [])
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $value = (array) $value;
        }

        // Use ParameterHelper to ensure proper CypherList/CypherMap types
        // This avoids ambiguous empty array issues and ensures correct typing
        return ParameterHelper::smartConvert($value);
    }
}
